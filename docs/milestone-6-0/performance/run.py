#!/usr/bin/env python3
"""Disposable export measurements; never boots the working checkout or loads its .env."""
import argparse
import base64
import hashlib
import itertools
import json
import os
import platform
import random
import shutil
import subprocess
import tempfile
import time
from pathlib import Path

HERE = Path(__file__).resolve().parent
REPO = HERE.parents[2]
RESULTS = Path(os.environ.get('PB_EXPORT_PERF_RESULTS', HERE)).resolve()
STATE = RESULTS / 'stand.json'
PHP = shutil.which('php')


def write_json(path, data):
    path.write_text(json.dumps(data, ensure_ascii=False, indent=2) + '\n')


def setup():
    RESULTS.mkdir(parents=True, exist_ok=True)
    root = Path(tempfile.mkdtemp(prefix='projectbrief-export-perf-'))
    app = root / 'app'
    app.mkdir()
    for name in ['app', 'config', 'lang', 'resources', 'routes', 'vendor']:
        shutil.copytree(REPO / 'api' / name, app / name, ignore=shutil.ignore_patterns('.DS_Store'))
    shutil.copytree(REPO / 'api/database/migrations', app / 'database/migrations')
    shutil.copytree(REPO / 'api/database/factories', app / 'database/factories')
    for name in ['composer.json', 'composer.lock']:
        shutil.copy(REPO / 'api' / name, app / name)
    (app / '.env').write_text('')
    (app / 'bootstrap/cache').mkdir(parents=True)
    for name in ['app.php', 'providers.php']:
        shutil.copy(REPO / 'api/bootstrap' / name, app / 'bootstrap' / name)
    for path in ['storage/framework/views', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/logs']:
        (app / path).mkdir(parents=True)
    (root / 'benchmark.sqlite').touch()
    (root / 'jobs').mkdir()
    (root / 'samples').mkdir()
    environment = {
        'PATH': os.environ.get('PATH', ''), 'HOME': str(root), 'TMPDIR': str(root),
        'APP_ENV': 'testing', 'APP_DEBUG': 'false', 'APP_KEY': 'base64:' + base64.b64encode(os.urandom(32)).decode(),
        'APP_URL': 'http://benchmark.invalid', 'DB_CONNECTION': 'sqlite', 'DB_DATABASE': str(root / 'benchmark.sqlite'),
        'CACHE_STORE': 'array', 'SESSION_DRIVER': 'array', 'MAIL_MAILER': 'array',
        'LOG_CHANNEL': 'null', 'LOG_DEPRECATIONS_CHANNEL': 'null', 'QUEUE_CONNECTION': 'sync',
        'BCRYPT_ROUNDS': '4', 'AI_ENABLED': 'false', 'BROADCAST_CONNECTION': 'null',
    }
    write_json(root / 'environment.json', environment)
    meta = {'stand': str(root), 'sourceCommit': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=REPO, text=True).strip(),
            'platform': platform.platform(), 'architecture': platform.machine(), 'php': PHP,
            'cpuCount': os.cpu_count(), 'descriptionChars': 300, 'expectedResultChars': 100, 'developerNotesChars': 80,
            'mainFields': 'all export fields enabled; one attachment filename per task, no image embedding; three sections',
            'memoryLimit': '128M', 'phpExecutionLimitSeconds': 30, 'externalWallLimitSeconds': 45, 'repetitions': 3,
            'coldInterpreter': True, 'coldFilesystemCache': False, 'opcacheCli': False,
            'rssSamplingSeconds': 0.1, 'rssNote': 'Sum of RSS may double count shared pages; sample peak is a lower bound, not exact aggregate physical usage.',
            'modifiedApplicationCode': False, 'productionAccess': False}
    for name in ['hw.memsize', 'hw.logicalcpu', 'hw.physicalcpu', 'machdep.cpu.brand_string']:
        result = subprocess.run(['/usr/sbin/sysctl', '-n', name], capture_output=True, text=True)
        meta[name] = result.stdout.strip() if result.returncode == 0 else None
    write_json(STATE, meta)
    print('Stand prepared', root, flush=True)
    record = run_group([{'mode': 'seed'}], 'seed', limit='128M', execution=30, wall=45, monitor=False)
    if record['workers'][0]['status'] != 'ok':
        raise RuntimeError(record)
    print('Disposable fixture seeded', flush=True)
    return root


def stand():
    root = Path(json.loads(STATE.read_text())['stand'])
    if not root.name.startswith('projectbrief-export-perf-') or not (root / 'benchmark.sqlite').is_file():
        raise RuntimeError('Disposable stand required')
    return root


def run_group(jobs, name, limit='128M', execution=30, wall=45, monitor=False):
    root = stand()
    environment = json.loads((root / 'environment.json').read_text())
    processes = []
    barrier = root / 'jobs' / (name + '.go') if len(jobs) > 1 else None
    if barrier:
        barrier.unlink(missing_ok=True)
    started = time.perf_counter()
    for index, original in enumerate(jobs):
        job = dict(original)
        if barrier:
            job['barrier'] = str(barrier)
        job_path = root / 'jobs' / f'{name}-{index}.job.json'
        result_path = root / 'jobs' / f'{name}-{index}.result.json'
        result_path.unlink(missing_ok=True)
        write_json(job_path, job)
        log_path = root / 'jobs' / f'{name}-{index}.log'
        stream = log_path.open('w')
        cmd = [PHP, '-d', 'memory_limit=' + limit, '-d', f'max_execution_time={execution}',
               '-d', 'opcache.enable_cli=0', '-d', 'display_errors=stderr', '-d', 'log_errors=0',
               str(HERE / 'worker.php'), str(root), str(job_path), str(result_path)]
        process = subprocess.Popen(cmd, cwd=root / 'app', env=environment, stdout=stream, stderr=subprocess.STDOUT)
        processes.append((process, result_path, stream, log_path))
    peak_rss_kib = 0
    sample_count = 0
    released_at = None
    timed_out = False
    while any(process.poll() is None for process, *_ in processes):
        now = time.perf_counter()
        if barrier and not released_at:
            if all((Path(str(barrier) + f'.{process.pid}.ready').exists() or process.poll() is not None) for process, *_ in processes):
                barrier.touch()
                released_at = time.perf_counter()
        if monitor:
            ids = ','.join(str(process.pid) for process, *_ in processes if process.poll() is None)
            if ids:
                result = subprocess.run(['/bin/ps', '-o', 'rss=', '-p', ids], capture_output=True, text=True)
                peak_rss_kib = max(peak_rss_kib, sum(int(line) for line in result.stdout.split() if line.isdigit()))
                sample_count += 1
        if now - started > wall:
            timed_out = True
            for process, *_ in processes:
                if process.poll() is None:
                    process.kill()
            break
        time.sleep(0.1 if monitor else 0.02)
    workers = []
    for process, result_path, stream, log_path in processes:
        process.wait()
        stream.close()
        record = json.loads(result_path.read_text()) if result_path.exists() else {'status': 'wall_timeout' if timed_out else 'missing_result', 'job': jobs[len(workers)]}
        record['exit_code'] = process.returncode
        record['log_file'] = log_path.name
        workers.append(record)
    elapsed = time.perf_counter() - started
    result = {'name': name, 'worker_count': len(jobs), 'memory_limit': limit, 'max_execution_time': execution,
              'group_wall_seconds': elapsed, 'render_group_wall_seconds': time.perf_counter() - released_at if released_at else None,
              'aggregate_peak_rss_bytes_sampled': peak_rss_kib * 1024 if monitor else None,
              'rss_samples': sample_count, 'cpu_seconds_sum': sum(worker.get('cpu_seconds', 0) for worker in workers),
              'workers': workers}
    result['cpu_percent_one_core'] = 100 * result['cpu_seconds_sum'] / elapsed
    return result


def append_result(file, result):
    with (RESULTS / file).open('a') as stream:
        stream.write(json.dumps(result, ensure_ascii=False) + '\n')


def matrix():
    configurations = list(itertools.product(['mixed', 'cjk'], [10, 100, 500], ['pdf', 'docx'], ['ru', 'en', 'tr', 'zh-CN']))
    complete = set()
    file = RESULTS / 'matrix.jsonl'
    if file.exists():
        complete = {json.loads(line)['name'] for line in file.read_text().splitlines()}
    for repeat in range(3):
        random.Random(600 + repeat).shuffle(configurations)
        for profile, count, format, locale in configurations:
            name = f'matrix-{profile}-{count}-{format}-{locale}-r{repeat}'
            if name in complete:
                continue
            job = {'tasks': count, 'profile': profile, 'format': format, 'locale': locale, 'repeat': repeat}
            if repeat == 0 and count == 10:
                job['output'] = str(stand() / 'samples' / f'{profile}-{locale}.{format}')
            result = run_group([job], name)
            append_result('matrix.jsonl', result)
            worker = result['workers'][0]
            print(name, worker['status'], round(worker.get('elapsed_ms', 0)), 'ms', round(worker.get('peak_php_bytes', 0) / 1048576, 1), 'MiB', flush=True)


def concurrency():
    records = [json.loads(line) for line in (HERE / 'matrix.jsonl').read_text().splitlines()]
    selections = []
    for profile, locale in [('mixed', 'en'), ('mixed', 'zh-CN'), ('cjk', 'en'), ('cjk', 'zh-CN')]:
        for format in ['pdf', 'docx']:
            safe = []
            for count in [10, 100, 500]:
                subset = [entry['workers'][0] for entry in records if all(entry['workers'][0]['job'][key] == value for key, value in {'profile': profile, 'locale': locale, 'format': format, 'tasks': count}.items())]
                if len(subset) == 3 and all(item['status'] == 'ok' for item in subset):
                    safe.append(count)
            for count in sorted(set([max(safe)] if 500 in safe else [max(safe), 500])):
                selections.append({'tasks': count, 'profile': profile, 'locale': locale, 'format': format})
    done = set()
    output = RESULTS / 'concurrency.jsonl'
    if output.exists():
        done = {json.loads(line)['name'] for line in output.read_text().splitlines()}
    for selection in selections:
        for workers in [1, 2, 3]:
            for repeat in range(2):
                name = f'parallel-{selection["profile"]}-{selection["tasks"]}-{selection["format"]}-{selection["locale"]}-w{workers}-r{repeat}'
                if name in done:
                    continue
                result = run_group([selection] * workers, name, monitor=True)
                append_result('concurrency.jsonl', result)
                print(name, [worker['status'] for worker in result['workers']], round(result['group_wall_seconds'], 2), 's', round(result['aggregate_peak_rss_bytes_sampled'] / 1048576), 'MiB RSS', flush=True)


def font_control():
    root = stand()
    directory = root / 'app/resources/fonts'
    hidden = root / 'disabled-cjk-fonts'
    hidden.mkdir(exist_ok=True)
    assets = list(directory.glob('NotoSansSC*'))
    try:
        for asset in assets:
            asset.rename(hidden / asset.name)
        for locale, format in itertools.product(['ru', 'en', 'tr', 'zh-CN'], ['pdf', 'docx']):
            result = run_group([{'tasks': 10, 'profile': 'mixed', 'format': format, 'locale': locale}], f'font-control-{locale}-{format}')
            append_result('font-control.jsonl', result)
            print(result['name'], result['workers'][0]['status'], flush=True)
    finally:
        for asset in hidden.iterdir():
            asset.rename(directory / asset.name)


def font_cost():
    for repeat in range(3):
        result = run_group([{'mode': 'font-cost'}], f'font-cost-r{repeat}')
        append_result('font-cost.jsonl', result)
        print(result['name'], result['workers'][0], flush=True)


def cleanup():
    root = stand()
    paths = [Path(name) for name in ['app/Services/ProjectExportData.php', 'app/Services/ExportFonts.php',
             'app/Services/PdfProjectExporter.php', 'app/Services/DocxProjectExporter.php',
             'resources/views/exports/specification.blade.php', 'config/localization.php']]
    paths += [path.relative_to(REPO / 'api') for path in (REPO / 'api/resources/fonts').glob('NotoSansSC*') if path.is_file()]
    checks = []
    for relative in sorted(paths):
        original = hashlib.sha256((REPO / 'api' / relative).read_bytes()).hexdigest()
        copied = hashlib.sha256((root / 'app' / relative).read_bytes()).hexdigest()
        if original != copied:
            raise RuntimeError('Changed application source or font: ' + str(relative))
        checks.append({'path': str(relative), 'sha256': original, 'standMatchesCheckout': True})
    meta = json.loads(STATE.read_text())
    write_json(RESULTS / 'source-integrity.json', {'sourceCommit': meta['sourceCommit'], 'checkedFiles': len(checks), 'allMatch': True, 'files': checks})
    shutil.rmtree(root)
    meta['standRemovedAfterMeasurements'] = True
    write_json(STATE, meta)
    print('Verified source/font integrity and removed disposable stand.', flush=True)


def default_fields():
    fields = {'description': True, 'expectedResult': True, 'priority': True, 'status': True,
              'estimateHours': False, 'price': False, 'attachments': False, 'approvalDate': False, 'developerNotes': False}
    for profile, locale in itertools.product(['mixed', 'cjk'], ['ru', 'en', 'tr', 'zh-CN']):
        job = {'tasks': 500, 'profile': profile, 'locale': locale, 'format': 'pdf', 'fields': fields}
        name = f'default-fields-{profile}-{locale}-500-pdf'
        result = run_group([job], name)
        append_result('default-fields.jsonl', result)
        item = result['workers'][0]
        print(name, item['status'], round(item.get('elapsed_ms', 0)), 'ms', round(item.get('peak_php_bytes', 0) / 1048576), 'MiB', flush=True)


def diagnostic():
    records = [json.loads(line) for line in (HERE / 'matrix.jsonl').read_text().splitlines()]
    failed = {}
    for record in records:
        item = record['workers'][0]
        if item['status'] != 'ok':
            job = item['job']
            failed[(job['profile'], job['tasks'], job['format'], job['locale'])] = {key: value for key, value in job.items() if key in ['tasks', 'profile', 'format', 'locale']}
    done = set()
    output = RESULTS / 'diagnostic.jsonl'
    if output.exists():
        done = {json.loads(line)['name'] for line in output.read_text().splitlines()}
    for key, job in sorted(failed.items()):
        name = 'diagnostic-' + '-'.join(map(str, key))
        if name in done:
            continue
        result = run_group([job], name, limit='512M', execution=120, wall=150)
        append_result('diagnostic.jsonl', result)
        item = result['workers'][0]
        print(name, item['status'], round(item.get('elapsed_ms', 0)), 'ms', round(item.get('peak_php_bytes', 0) / 1048576), 'MiB', flush=True)


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--phase', required=True, choices=['setup', 'matrix', 'concurrency', 'font-control', 'font-cost', 'default-fields', 'diagnostic', 'cleanup'])
    args = parser.parse_args()
    {'setup': setup, 'matrix': matrix, 'concurrency': concurrency, 'font-control': font_control, 'font-cost': font_cost, 'default-fields': default_fields, 'diagnostic': diagnostic, 'cleanup': cleanup}[args.phase]()
