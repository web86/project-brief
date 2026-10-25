import assert from 'node:assert/strict'
import { randomBytes } from 'node:crypto'
import { spawn, spawnSync } from 'node:child_process'
import { access, mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'

// Use only a dedicated disposable database. Never load credentials from api/.env.
assert.equal(process.env.DB_DATABASE, 'projectbrief_m461_qa')
const root = new URL('..', import.meta.url).pathname
const php = process.env.PHP_BIN || 'php'
const env = {
  ...process.env,
  APP_ENV: 'testing',
  APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
  DB_CONNECTION: 'mysql',
  DB_HOST: '127.0.0.1',
  DB_PORT: '33316',
  DB_DATABASE: 'projectbrief_m461_qa',
  DB_USERNAME: 'root',
  DB_PASSWORD: '',
  DB_URL: '',
  CACHE_STORE: 'array',
  SESSION_DRIVER: 'array',
  LOG_CHANNEL: 'null',
  MAIL_MAILER: 'array',
  PHP_INI_SCAN_DIR: `:${root}/api/dev-php`,
}
const scratch = await mkdtemp(path.join(tmpdir(), 'task-concurrency-'))
const output = new URL('../docs/milestone-4-6-1/', import.meta.url)
await mkdir(output, { recursive: true })
const pause = (ms) => new Promise((resolve) => setTimeout(resolve, ms))
function run(args) {
  const result = spawnSync(php, args, { cwd: root + 'api', env, encoding: 'utf8' })
  assert.equal(result.status, 0, result.stderr + result.stdout)
  return result.stdout
}
function worker(...args) {
  const child = spawn(php, ['tests/task-management-worker.php', ...args.map(String)], {
    cwd: root + 'api',
    env,
  })
  let text = '',
    error = ''
  child.stdout.on('data', (value) => (text += value))
  child.stderr.on('data', (value) => (error += value))
  return new Promise((resolve, reject) =>
    child.on('close', (code) => {
      try {
        assert.equal(code, 0, error + text)
        resolve(JSON.parse(text))
      } catch (e) {
        reject(e)
      }
    }),
  )
}
async function held(marker, first, second) {
  const a = worker(...first)
  let early
  a.then((result) => {
    early = result
  })
  for (let attempt = 0; ; attempt++) {
    try {
      await access(marker)
      break
    } catch {
      assert.ok(!early, JSON.stringify(early))
      assert.ok(attempt < 200, 'worker did not acquire lock')
      await pause(10)
    }
  }
  const b = worker(...second)
  return Promise.all([a, b])
}
const results = []
try {
  run(['artisan', 'migrate:fresh', '--force', '--no-interaction'])
  const f = JSON.parse(run(['tests/task-management-worker.php', 'seed']))
  const [a, b] = f.users,
    [main, target] = f.sections,
    [t0, t1, t2, t3, t4, t5] = f.tasks
  const marker = path.join(scratch, 'first')
  let pair = await held(marker, ['hold-place', a, t0, target, marker], ['place', b, t1, target])
  assert.deepEqual(pair, [{ ok: true }, { ok: true }])
  let state = JSON.parse(run(['tests/task-management-worker.php', 'check', String(f.project)]))
  assert.equal(state.tasks.length, 6)
  results.push(
    'Two administrators moving different ideas serialize on the project lock; all positions remain contiguous.',
  )
  const marker2 = path.join(scratch, 'same')
  pair = await held(marker2, ['hold-place', a, t2, target, marker2], ['place', b, t2, main])
  assert.deepEqual(pair, [{ ok: true }, { ok: true }])
  run(['tests/task-management-worker.php', 'check', String(f.project)])
  results.push(
    'Two administrators moving the same idea produce one current location and no duplicate positions.',
  )
  const marker3 = path.join(scratch, 'stale')
  pair = await held(
    marker3,
    ['hold-place', a, t3, target, marker3],
    ['place', b, t4, main, 'unused', t3],
  )
  assert.deepEqual(pair, [{ ok: true }, { ok: false, status: 409 }])
  run(['tests/task-management-worker.php', 'check', String(f.project)])
  results.push(
    'A destination anchor moved by another administrator is rejected with 409 without changing order.',
  )
  const marker4 = path.join(scratch, 'approval')
  pair = await held(marker4, ['hold-approve', a, t5, main, marker4], ['delete', b, t5, main])
  assert.deepEqual(pair, [{ ok: true }, { ok: false, status: 403 }])
  state = JSON.parse(run(['tests/task-management-worker.php', 'check', String(f.project)]))
  assert.equal(state.trashed, 0)
  results.push(
    'An approval committed while deletion waits on the task lock is rechecked; deletion is rejected with 403.',
  )
  await writeFile(
    new URL('concurrency-results.json', output),
    JSON.stringify(
      { passed: true, database: 'MariaDB 11.8.9 (disposable container)', results },
      null,
      2,
    ) + '\n',
  )
  console.log('PASS ' + results.length + ' real concurrent MariaDB scenarios')
} finally {
  await rm(scratch, { recursive: true, force: true })
}
