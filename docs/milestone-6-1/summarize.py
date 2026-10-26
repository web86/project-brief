#!/usr/bin/env python3
"""Summarize recorded isolated runs; does not launch exports or access application data."""
from pathlib import Path
import csv, gzip, hashlib, json

root = Path(__file__).resolve().parent
repo = root.parent.parent
records = json.loads((root / 'chromium-matrix.json').read_text())['results']
columns = ['profile', 'tasks', 'locale', 'fields', 'apiMs', 'apiBytes', 'phpPeakBytes',
           'serverTiming', 'libraryMs', 'fontsMs', 'fontBytes', 'fontCacheHits', 'renderMs', 'totalMs',
           'outputBytes', 'maxMainThreadGapMs', 'heartbeatTicks', 'browserBaselineRssBytes',
           'browserPeakRssBytesSampled', 'browserCpuSecondsSampled', 'observedWindowMs',
           'rssSamples', 'fontTransferBytes']
with (root / 'performance.csv').open('w', newline='') as file:
    writer = csv.DictWriter(file, fieldnames=columns, lineterminator='\n')
    writer.writeheader()
    for record in records:
        writer.writerow({**{key: record[key] for key in columns[:-1]},
                         'fontTransferBytes': sum(item['transferSize'] for item in record['fontResources'])})

baseline = list(csv.DictReader((repo / 'docs/milestone-6-0/performance/matrix-summary.csv').open()))
old = {(row['profile'], int(row['tasks']), row['locale']): row for row in baseline if row['format'] == 'pdf'}
table = [
    '# Dompdf vs Browser PDF — полный набор полей', '',
    'Dompdf: медиана трёх CLI-процессов, 128M. Browser: один финальный HTTP/Worker-прогон на комбинацию, localhost. Время и PHP-память имеют разные границы измерения; детали в README.', '',
    '| Текст | Задач | Язык | Dompdf, с | PHP Dompdf, МиБ | API, мс | Worker PDF, мс | Browser всего, с | PHP JSON, МиБ | PDF Browser, КиБ |',
    '| --- | ---: | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |',
]
for record in records:
    if record['fields'] != 'full':
        continue
    row = old[record['profile'], record['tasks'], record['locale']]
    old_time = f"{float(row['median_seconds']):.2f}" if int(row['successes']) else 'OOM'
    table.append(f"| {'С Han' if record['profile']=='cjk' else 'Без Han'} | {record['tasks']} | {record['locale']} | {old_time} | {float(row['max_php_peak_mib']):.1f} | {record['apiMs']:.0f} | {record['renderMs']:.0f} | {record['totalMs']/1000:.2f} | {record['phpPeakBytes']/1048576:.1f} | {record['outputBytes']/1024:.1f} |")
(root / 'comparison.md').write_text('\n'.join(table) + '\n')

bundles = {}
for name, directory in [('ordinary', repo / 'dist'), ('prototype', repo / 'artifacts/milestone-6-1/prototype-build')]:
    bundles[name] = [{'file': str(path.relative_to(directory)), 'bytes': path.stat().st_size,
                      'gzipBytes': len(gzip.compress(path.read_bytes(), mtime=0))}
                     for path in sorted(directory.glob('assets/*')) if path.suffix in ['.js', '.ttf']]
(root / 'bundle-sizes.json').write_text(json.dumps(bundles, indent=2) + '\n')

unchanged = ['api/app/Services/PdfProjectExporter.php', 'api/app/Services/DocxProjectExporter.php',
             'api/resources/views/exports/specification.blade.php']
integrity = {name: hashlib.sha256((repo / name).read_bytes()).hexdigest() for name in unchanged}
(root / 'legacy-export-sha256.json').write_text(json.dumps(integrity, indent=2) + '\n')
print('Summarized', len(records), 'browser runs; 24 full-field comparisons and bundle sizes.')
