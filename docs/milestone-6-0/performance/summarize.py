#!/usr/bin/env python3
"""Aggregate raw measurements; document parsing runs after the timed exports."""
import csv
import json
import statistics
import os
from collections import defaultdict
from pathlib import Path

HERE = Path(os.environ.get('PB_EXPORT_PERF_RESULTS', Path(__file__).resolve().parent)).resolve()
MIB = 1048576


def read(name):
    path = HERE / name
    return [json.loads(line) for line in path.read_text().splitlines()] if path.exists() else []


def save_csv(name, rows):
    if not rows:
        return
    with (HERE / name).open('w', newline='') as stream:
        writer = csv.DictWriter(stream, fieldnames=list(rows[0]))
        writer.writeheader()
        writer.writerows(rows)


def matrix_summary():
    groups = defaultdict(list)
    for group in read('matrix.jsonl'):
        item = group['workers'][0]
        job = item['job']
        groups[(job['profile'], job['tasks'], job['format'], job['locale'])].append(item)
    rows = []
    for (profile, count, format, locale), workers in sorted(groups.items()):
        valid = [worker for worker in workers if worker['status'] == 'ok']
        errors = [worker for worker in workers if worker['status'] != 'ok']
        elapsed = [worker['elapsed_ms'] / 1000 for worker in workers if 'elapsed_ms' in worker]
        row = {'profile': profile, 'tasks': count, 'format': format, 'locale': locale,
               'repetitions': len(workers), 'successes': len(valid),
               'median_seconds': statistics.median(elapsed) if elapsed else None,
               'min_seconds': min(elapsed) if elapsed else None, 'max_seconds': max(elapsed) if elapsed else None,
               'max_php_peak_mib': max(worker.get('peak_php_bytes', 0) for worker in workers) / MIB,
               'median_output_mib': statistics.median(worker['output_bytes'] / MIB for worker in valid) if valid else None,
               'median_build_ms': statistics.median(worker['build_ms'] for worker in workers if 'build_ms' in worker),
               'median_render_ms': statistics.median(worker['render_ms'] for worker in valid) if valid else None,
               'max_os_rss_mib': max(worker.get('os_maxrss', 0) for worker in workers) / MIB,
               'cpu_seconds_median': statistics.median(worker.get('cpu_seconds', 0) for worker in workers),
               'cjk_needed': workers[0].get('cjk_needed'),
               'failure': '; '.join(sorted(set(worker.get('error', {}).get('message', worker['status']) for worker in errors)))}
        rows.append(row)
    save_csv('matrix-summary.csv', rows)
    tables = []
    for format in ['pdf', 'docx']:
        tables += [f'### {format.upper()}', '', 'Ячейка: секунды / пик PHP МиБ / файл МиБ. Время — медиана трёх свежих процессов; память — максимум. OOM: файл не создан.', '', '| Пользовательский текст | Задач | RU | EN | TR | ZH-CN |', '|---|---:|---:|---:|---:|---:|']
        for profile in ['mixed', 'cjk']:
            for count in [10, 100, 500]:
                cells = []
                for locale in ['ru', 'en', 'tr', 'zh-CN']:
                    row = next(row for row in rows if (row['profile'], row['tasks'], row['format'], row['locale']) == (profile, count, format, locale))
                    cells.append(f"{row['median_seconds']:.2f} / {row['max_php_peak_mib']:.1f} / {row['median_output_mib']:.3f}" if row['successes'] == 3 else f"OOM / {row['max_php_peak_mib']:.0f} / —")
                tables.append('| ' + ('Без Han' if profile == 'mixed' else 'С Han') + f' | {count} | ' + ' | '.join(cells) + ' |')
        tables.append('')
    (HERE / 'matrix-tables.md').write_text('\n'.join(tables))
    return rows


def concurrency_summary():
    groups = defaultdict(list)
    for group in read('concurrency.jsonl'):
        job = group['workers'][0]['job']
        groups[(job['profile'], job['tasks'], job['format'], job['locale'], group['worker_count'])].append(group)
    rows = []
    for (profile, count, format, locale, workers), entries in sorted(groups.items()):
        rows.append({'profile': profile, 'tasks': count, 'format': format, 'locale': locale, 'workers': workers,
                     'groups': len(entries), 'successes': sum(worker['status'] == 'ok' for entry in entries for worker in entry['workers']),
                     'wall_seconds_median': statistics.median(entry['group_wall_seconds'] for entry in entries),
                     'render_group_seconds_median': statistics.median(entry['render_group_wall_seconds'] or entry['group_wall_seconds'] for entry in entries),
                     'rss_peak_mib_max_sampled': max(entry['aggregate_peak_rss_bytes_sampled'] for entry in entries) / MIB,
                     'php_peak_per_worker_mib_max': max(worker.get('peak_php_bytes', 0) for entry in entries for worker in entry['workers']) / MIB,
                     'cpu_one_core_percent_median': statistics.median(entry['cpu_percent_one_core'] for entry in entries),
                     'cpu_seconds_sum_median': statistics.median(entry['cpu_seconds_sum'] for entry in entries)})
    save_csv('concurrency-summary.csv', rows)
    if rows:
        lines = ['Ячейка: время группы, с / суммарный RSS, МиБ / CPU в % одного ядра. По 2 группы; время и CPU — медианы, RSS — максимум выборок 100 мс.', '', '| Формат / текст / язык / задач | 1 экспорт | 2 одновременно | 3 одновременно |', '|---|---:|---:|---:|']
        for profile, count, format, locale in sorted(set((row['profile'], row['tasks'], row['format'], row['locale']) for row in rows)):
            cells = []
            for number in [1, 2, 3]:
                row = next(row for row in rows if (row['profile'], row['tasks'], row['format'], row['locale'], row['workers']) == (profile, count, format, locale, number))
                status = '' if row['successes'] == row['groups'] * number else ' OOM'
                cells.append(f"{row['wall_seconds_median']:.2f} / {row['rss_peak_mib_max_sampled']:.0f} / {row['cpu_one_core_percent_median']:.0f}%{status}")
            lines.append(f'| {format.upper()} / {profile} / {locale} / {count} | ' + ' | '.join(cells) + ' |')
        (HERE / 'concurrency-table.md').write_text('\n'.join(lines) + '\n')
    return rows


def font_analysis():
    from pypdf import PdfReader
    import zipfile
    stand = Path(json.loads((HERE / 'stand.json').read_text())['stand'])
    rows = []
    for path in sorted((stand / 'samples').iterdir()):
        row = {'file': path.name, 'output_bytes': path.stat().st_size, 'cjk_font_compressed_bytes': 0,
               'all_font_compressed_bytes': 0, 'cjk_font_uncompressed_bytes': 0, 'cjk_font_faces': 0, 'pages': None}
        if path.suffix == '.docx':
            with zipfile.ZipFile(path) as doc:
                for info in doc.infolist():
                    if info.filename.endswith('.odttf'):
                        row['cjk_font_compressed_bytes'] += info.compress_size
                        row['all_font_compressed_bytes'] += info.compress_size
                        row['cjk_font_uncompressed_bytes'] += info.file_size
                        row['cjk_font_faces'] += 1
        else:
            pdf = PdfReader(path)
            row['pages'] = len(pdf.pages)
            seen = set()
            for page in pdf.pages:
                for reference in page['/Resources']['/Font'].values():
                    font = reference.get_object()
                    descendants = font.get('/DescendantFonts')
                    actual = descendants[0].get_object() if descendants else font
                    descriptor = actual.get('/FontDescriptor')
                    if not descriptor:
                        continue
                    descriptor = descriptor.get_object()
                    resource = descriptor.get('/FontFile2')
                    if resource is None:
                        continue
                    identity = resource.idnum
                    if identity in seen:
                        continue
                    seen.add(identity)
                    binary = resource.get_object()
                    compressed = len(binary._data)
                    row['all_font_compressed_bytes'] += compressed
                    if 'NotoSansSC' in str(descriptor.get('/FontName')):
                        row['cjk_font_compressed_bytes'] += compressed
                        row['cjk_font_uncompressed_bytes'] += len(binary.get_data())
                        row['cjk_font_faces'] += 1
        rows.append(row)
    save_csv('font-sizes.csv', rows)
    (HERE / 'font-sizes.json').write_text(json.dumps(rows, ensure_ascii=False, indent=2) + '\n')
    return rows


if __name__ == '__main__':
    matrix_summary()
    concurrency_summary()
    font_analysis()
    print('Summary CSV, Markdown tables and font payload measurements written.')
