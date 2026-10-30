import assert from 'node:assert/strict'
import { spawn, spawnSync } from 'node:child_process'
import { mkdir, writeFile } from 'node:fs/promises'
assert.equal(process.env.DB_DATABASE, 'projectbrief_m90_qa')
const cwd = new URL('../api', import.meta.url).pathname
const output = new URL('../artifacts/milestone-9-0/', import.meta.url)
const dockerContainer = process.env.M90_PHP_CONTAINER
const command = dockerContainer ? 'docker' : 'php'
const args = (mode) =>
  dockerContainer
    ? [
        'exec',
        '-i',
        '-w',
        '/tmp/m90-api',
        dockerContainer,
        'php',
        'tests/platform-mariadb.php',
        mode,
      ]
    : ['tests/platform-mariadb.php', mode]
function run(mode) {
  const r = spawnSync(command, args(mode), { cwd, env: process.env, encoding: 'utf8' })
  assert.equal(r.status, 0, r.stderr + r.stdout)
  return JSON.parse(r.stdout)
}
const runtime = run('prepare'),
  start = performance.now()
function worker(mode) {
  const child = spawn(command, args(mode), { cwd, env: process.env })
  let output = '',
    error = '',
    resolveReady
  const ready = new Promise((r) => (resolveReady = r))
  child.stdout.on('data', (b) => {
    output += b
    if (output.startsWith('ready\n')) resolveReady()
  })
  child.stderr.on('data', (b) => (error += b))
  const done = new Promise((resolve, reject) =>
    child.on('exit', (code) => {
      try {
        assert.equal(code, 0, error + output)
        resolve(JSON.parse(output.replace('ready\n', '')))
      } catch (e) {
        reject(e)
      }
    }),
  )
  return { child, ready, done }
}
const workers = Array.from({ length: 8 }, () => worker('worker'))
await Promise.all(workers.map((w) => w.ready))
workers.forEach((w) => w.child.stdin.end('go\n'))
const timings = (await Promise.all(workers.map((w) => w.done))).flat().sort((a, b) => a - b)
const elapsed = performance.now() - start,
  rows = run('inspect')
assert.equal(rows.length, 1)
assert.equal(Number(rows[0].requests), 1600)
for (const key of ['responses_2xx', 'responses_5xx', 'responses_429'])
  assert.equal(Number(rows[0][key]), 400)
assert.equal(Number(rows[0].responses_4xx), 800)
assert.equal(Number(rows[0].duration_us), 8 * 20100 * 1000)
assert.equal(Number(rows[0].max_duration_us), 200000)
const benchmark = run('benchmark')
assert.equal(benchmark.sql_statements, benchmark.writes)
assert.equal(Number(benchmark.lock_wait_seconds), 1)
const http = run('http')
assert.equal(Number(http.recorded_requests), 60)
const lock = worker('lock')
await lock.ready
const failure = run('failure')
lock.child.stdin.end('release\n')
// The locker prints no JSON after release; wait for exit separately.
lock.done.catch(() => {})
assert.equal(failure.status, 200)
assert.equal(failure.body, '{"ok":true}')
assert.ok(failure.terminate_ms >= 900 && failure.terminate_ms < 2500, JSON.stringify(failure))
const httpSummary = [false, true].map((enabled) => {
  const samples = http.measurements
    .filter((x) => x.enabled === enabled)
    .flatMap((x) => x.samples)
    .slice(5)
  const values = (key) => samples.map((x) => x[key]).sort((a, b) => a - b)
  const handled = values('handled_ms'),
    total = values('total_ms')
  return {
    enabled,
    samples: samples.length,
    handled_p50_ms: handled[Math.floor(handled.length * 0.5)],
    handled_p95_ms: handled[Math.floor(handled.length * 0.95)],
    worker_p50_ms: total[Math.floor(total.length * 0.5)],
    worker_p95_ms: total[Math.floor(total.length * 0.95)],
  }
})
await mkdir(output, { recursive: true })
const result = {
  runtime,
  concurrency: {
    workers: 8,
    requests: 1600,
    elapsed_ms: elapsed,
    p50_ms: timings[799],
    p95_ms: timings[1519],
    p99_ms: timings[1583],
    counts: rows[0],
  },
  benchmark,
  http: httpSummary,
  failure,
  limits:
    'Local Docker runtime, warmed Laravel kernel, empty project list; not Jino/FPM production measurements.',
}
await writeFile(new URL('performance.json', output), JSON.stringify(result, null, 2) + '\n')
console.log(JSON.stringify(result))
