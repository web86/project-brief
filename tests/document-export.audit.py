"""Audit real PDF and LibreOffice-rendered DOCX output; dependencies are QA-only."""
import argparse
import hashlib
import json
import re
from pathlib import Path
from zipfile import ZipFile
import pdfplumber
from pypdf import PdfReader


def compact(value):
    return re.sub(r'\s+', '', value)


def pdf_resources(reader):
    fonts, images = {}, {}
    for page in reader.pages:
        resources = page.get('/Resources', {})
        for ref in resources.get('/Font', {}).values():
            font = ref.get_object()
            for face in font.get('/DescendantFonts', [font]):
                descriptor = face.get_object().get('/FontDescriptor')
                if descriptor:
                    descriptor = descriptor.get_object()
                    for key in ['/FontFile', '/FontFile2', '/FontFile3']:
                        if key in descriptor:
                            stream = descriptor[key].get_object()
                            fonts[hashlib.sha256(stream._data).hexdigest()] = len(stream._data)
        for ref in resources.get('/XObject', {}).values():
            obj = ref.get_object()
            if obj.get('/Subtype') == '/Image':
                images[str(ref)] = {'width': obj['/Width'], 'height': obj['/Height'], 'compressedBytes': len(obj._data)}
    return {'fontStreamBytes': sum(fonts.values()), 'fontStreams': len(fonts), 'images': list(images.values())}


def audit(path, document):
    reader = PdfReader(path)
    result = {'pages': len(reader.pages), 'bytes': path.stat().st_size, **pdf_resources(reader)}
    texts, pages, columns = [], [], []
    with pdfplumber.open(path) as pdf:
        for index, page in enumerate(pdf.pages):
            text = reader.pages[index].extract_text() or ''
            assert text.strip(), f'{path.name}: empty page {index + 1}'
            assert '\ufffd' not in text and '\x00' not in text, f'{path.name}: invalid glyph on page {index + 1}'
            chars = [char for char in page.chars if char['text'].strip()]
            assert chars, f'{path.name}: no visible text on page {index + 1}'
            outside = [char['text'] for char in chars if char['x0'] < 46 or char['x1'] > page.width - 46 or char['top'] < 44 or char['bottom'] > page.height - 7]
            assert not outside, f'{path.name}: text outside A4 margins on page {index + 1}: {outside[:20]}'
            body = [char for char in chars if char['size'] >= 7.8]
            foot = [char for char in chars if char['size'] < 7.8]
            assert body, f'{path.name}: footer-only page {index + 1}'
            assert max(char['bottom'] for char in body) <= 792, f'{path.name}: body/footer overlap on page {index + 1}'
            if foot:
                assert min(char['top'] for char in foot) > max(char['bottom'] for char in body), f'{path.name}: footer overlaps body on page {index + 1}'
            pages.append({'page': index + 1, 'bodyTop': round(min(char['top'] for char in body), 1), 'bodyBottom': round(max(char['bottom'] for char in body), 1), 'characters': len(chars)})
            visible = page.filter(lambda obj: obj['object_type'] != 'char' or obj['size'] >= 7.8)
            texts.append(visible.extract_text() or '')
            columns.append((visible.crop((46, 40, 299, 792)).extract_text() or '') + '\n' + (visible.crop((305, 40, page.width - 46, 792)).extract_text() or ''))
            page.close()
    text = compact('\n'.join(texts))
    expected = [document['title'], document['project']['name'], document['generated']['date']]
    for section in document['sections']:
        expected.append(str(section['number']) + '. ' + section['name'])
        for task in section['tasks']:
            expected.append(task['number'] + ' ' + task['title'])
            expected.extend(field['value'] for field in task['content'] if field['block'])
            expected.extend(task['attachments'])
            if task['metadata']:
                expected.append(task['metadata'])
    for row in document['summary']:
        expected.extend([row['label'], row['value']])
        if row['coverage']:
            expected.append(row['coverage'])
    for row in document['branding'].get('business', []):
        expected.extend([row['label'], row['value']])
    for value in expected:
        assert compact(value) in text or compact(value) in compact('\n'.join(columns)), f'{path.name}: missing content: {value[:100]}'
    # Page-position checks use title prefixes so the actual rendered line wrapping is respected.
    normalized = [compact(value) for value in texts]
    for section in document['sections']:
        title = compact(str(section['number']) + '. ' + section['name'])
        heading_page = next(i for i, value in enumerate(normalized) if title in value)
        first = section['tasks'][0]
        prefix = compact(first['number'] + ' ' + first['title'])[:45]
        assert prefix in normalized[heading_page], f'{path.name}: orphan section {section["name"]}'
    result['contentChecks'] = len(expected)
    result['pageGeometry'] = pages
    return result


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('root', type=Path)
    args = parser.parse_args()
    results = {}
    for payload in sorted((args.root / 'after').glob('*.json')):
        data = json.loads(payload.read_text())
        if not isinstance(data, dict) or 'document' not in data:
            continue
        name, document = payload.stem, data['document']
        pdf = audit(payload.with_suffix('.pdf'), document)
        rendered = args.root / 'render' / 'after' / name / (name + '.pdf')
        docx = audit(rendered, document)
        with ZipFile(payload.with_suffix('.docx')) as archive:
            fonts = [entry for entry in archive.infolist() if entry.filename.startswith('word/fonts/') and entry.filename.endswith('.odttf')]
            docx['embeddedFontBytes'] = sum(entry.file_size for entry in fonts)
            docx['compressedFontBytes'] = sum(entry.compress_size for entry in fonts)
            docx['bytes'] = payload.with_suffix('.docx').stat().st_size
            docx['media'] = [{'name': entry.filename, 'bytes': entry.file_size} for entry in archive.infolist() if entry.filename.startswith('word/media/')]
        before_pdf = args.root / 'before' / (name + '.pdf')
        before_word = args.root / 'render' / 'before' / name / (name + '.pdf')
        results[name] = {'tasks': document['totals']['taskCount'], 'pdf': pdf, 'docx': docx, 'beforePdf': {'pages': len(PdfReader(before_pdf).pages), 'bytes': before_pdf.stat().st_size}, 'beforeDocx': {'pages': len(PdfReader(before_word).pages), 'bytes': (args.root / 'before' / (name + '.docx')).stat().st_size}}
        print('PASS', name, 'PDF', pdf['pages'], 'DOCX', docx['pages'], 'pages')
    (args.root / 'document-audit.json').write_text(json.dumps(results, ensure_ascii=False, indent=2) + '\n')


if __name__ == '__main__':
    main()
