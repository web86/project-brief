#!/usr/bin/env python3
"""Read-only checks of app-generated smoke PDFs against authorized server JSON."""
from pathlib import Path
import json, re, unicodedata
from pypdf import PdfReader

root = Path(__file__).resolve().parent
normalize = lambda text: re.sub(r'\s+', '', unicodedata.normalize('NFKC', str(text)))
results = []
for path in sorted(root.glob('*.pdf')):
    document = json.loads(path.with_suffix('.json').read_text())
    pdf = PdfReader(path)
    texts = []
    embedded = set()
    for page in pdf.pages:
        assert abs(float(page.mediabox.width) - 595.28) < 1
        assert abs(float(page.mediabox.height) - 841.89) < 1
        text = normalize(page.extract_text())
        footer = normalize(document['labels']['footer'] + ' · ' + document['generated']['date'])
        index = text.rfind(footer)
        assert index >= 0, (path.name, 'footer')
        texts.append(text[:index])
        for reference in page['/Resources']['/Font'].values():
            font = reference.get_object()
            descriptor = font['/DescendantFonts'][0].get_object()['/FontDescriptor'].get_object()
            assert descriptor.get('/FontFile2') or descriptor.get('/FontFile3')
            assert font.get('/ToUnicode')
            embedded.add(str(font['/BaseFont']))
        for reference in page['/Resources'].get('/XObject', {}).values():
            assert reference.get_object().get('/Subtype') != '/Image'
    text = ''.join(texts)
    expected = [task for section in document['sections'] for task in section['tasks']]
    actual_titles = re.findall(r'Exporttask(\d+)—Çığ/中文/задача', text)
    assert actual_titles == [re.search(r'task (\d+)', task['title'])[1] for task in expected], (path.name, 'order/count')
    assert normalize(document['title']) in text
    for task in expected:
        assert normalize(task['number'] + ' ' + task['title']) in text
        for field in task['content']:
            if field['block']:
                assert normalize(field['value']) in text, (path.name, 'original text')
        for attachment in task['attachments']:
            assert normalize(attachment) in text
        assert normalize(task['metadata']) in text
    for row in document['summary']:
        assert normalize(row['label'] + ': ' + row['value']) in text, (path.name, 'totals')
    if 'client' in path.name:
        assert 'SYNTHETIC_PRIVATE' not in text
        assert all('NotoSansSC' in name or 'DejaVuSans' in name for name in embedded)
    results.append({'file': path.name, 'pages': len(pdf.pages), 'tasks': len(expected), 'unicodeAndOriginalText': 'passed', 'orderAndTotals': 'passed', 'embeddedFonts': sorted(embedded), 'pageImages': 0})
(root / 'pdf-results.json').write_text(json.dumps(results, ensure_ascii=False, indent=2) + '\n')
print('Verified', len(results), 'PDFs,', sum(row['tasks'] for row in results), 'task instances, original Unicode, totals, A4 and embedded fonts.')
