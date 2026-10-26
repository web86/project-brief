#!/usr/bin/env python3
"""Read-only verification of synthetic browser PDF samples and their server JSON."""
from pathlib import Path
import json,re,unicodedata
from collections import Counter
from pypdf import PdfReader

root=Path(__file__).resolve().parent
normalize=lambda value:re.sub(r'\s+','',unicodedata.normalize('NFKC',str(value)))
results=[]
for path in sorted(root.glob('*.pdf')):
 document_path=path.with_suffix('.json')
 if document_path.exists(): document=json.loads(document_path.read_text())
 else:
  match=re.match(r'(chromium|firefox|webkit)-(1440|390)-(ru|en|tr|zh-CN)-ui\.pdf',path.name)
  if not match:continue
  document=json.loads((root/f'browser-cjk-10-full-{match[3]}.json').read_text())
 pdf=PdfReader(path)
 pages=[]
 fonts=set();embedded=True;unicode=True;images=0
 for index,page in enumerate(pdf.pages):
  assert abs(float(page.mediabox.width)-595.28)<1 and abs(float(page.mediabox.height)-841.89)<1,path.name
  text=normalize(page.extract_text())
  footer=normalize(document['labels']['footer']+' · '+document['generated']['date'])
  offset=text.rfind(footer)
  assert offset>=0,(path.name,index,'footer missing')
  pages.append(text.replace(footer,'') if path.name.startswith('dompdf-') else text[:offset])
  for reference in page['/Resources']['/Font'].values():
   font=reference.get_object();descendants=font.get('/DescendantFonts');actual=descendants[0].get_object() if descendants else font
   descriptor=actual.get('/FontDescriptor');descriptor=descriptor.get_object() if descriptor else {}
   embedded=embedded and bool(descriptor.get('/FontFile2') or descriptor.get('/FontFile3'))
   unicode=unicode and bool(font.get('/ToUnicode'))
   fonts.add(str(font.get('/BaseFont')))
  for reference in page['/Resources'].get('/XObject',{}).values():
   if reference.get_object().get('/Subtype')=='/Image':images+=1
 text=''.join(pages)
 expected=[task for section in document['sections'] for task in section['tasks']]
 titles=re.findall(r'Websiteimprovement(\d+)—İpek/задача',text)
 assert titles==[re.search(r'improvement (\d+)',task['title'])[1] for task in expected],(path.name,'task order/count',len(titles),len(expected))
 values=Counter()
 for task in expected:
  assert normalize(task['number']+' '+task['title']) in text,(path.name,task['number'])
  first=next((field for field in task['content'] if field['block']),None)
  if first and not path.name.startswith('dompdf-'):
   prefix=normalize(task['number']+' '+task['title']+first['label']+first['value'][:60])
   assert any(prefix in page for page in pages),(path.name,task['number'],'orphan heading')
  for field in task['content']:
   if field['block']:values[normalize(field['value'])]+=1
  for name in task['attachments']:assert normalize(name) in text,(path.name,name)
  if task['metadata']:assert normalize(task['metadata']) in text,(path.name,'metadata')
 for value,count in values.items():assert text.count(value)>=count,(path.name,'missing repeated original text',count,text.count(value),value[:40])
 for row in document['summary']:
  assert normalize(row['label']+': '+row['value']) in text,(path.name,'summary')
  if row['coverage']:assert normalize(row['coverage']) in text
 assert normalize(document['title']) in text
 if document['presentation']['pageBreakBySection']:
  section_pages=[]
  for section in document['sections']:
   if not section['tasks']:continue
   heading=normalize(str(section['number'])+'. '+section['name'])
   page_index=next(index for index,value in enumerate(pages) if heading in value)
   section_pages.append(page_index)
   if len(section_pages)>1:assert pages[page_index].startswith(heading),(path.name,'section page break')
  assert len(set(section_pages))==len(section_pages),(path.name,'section pages')
 assert embedded and unicode and images==0,(path.name,'fonts/images')
 if 'client-ui' in path.name:assert 'PRIVATE_INTERNAL_NOTES' not in text
 results.append({'file':path.name,'pages':len(pdf.pages),'tasks':len(expected),'taskOrder':'passed','originalText':'passed','metadataAndTotals':'passed','embeddedFonts':True,'toUnicode':True,'pageImages':images,'fontNames':sorted(fonts)})
(root/'pdf-verification.json').write_text(json.dumps(results,ensure_ascii=False,indent=2)+'\n')
print('Verified',len(results),'PDFs:',sum(item['tasks'] for item in results),'task instances, text, order, metadata, totals, Unicode, embedded fonts and A4.')
