import assert from 'node:assert/strict'
import { spawn, spawnSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { mkdtemp, writeFile, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
const root = new URL('..', import.meta.url).pathname
const scratch = await mkdtemp(path.join(tmpdir(), 'milestone501-e2e-'))
const database = path.join(scratch, 'concurrent.sqlite')
await writeFile(database, '')
const env = {
  ...process.env,
  APP_ENV: 'testing',
  APP_DEBUG: 'false',
  APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: database,
  DB_URL: '',
  LOG_CHANNEL: 'null',
  CACHE_STORE: 'array',
  SESSION_DRIVER: 'array',
  MAIL_MAILER: 'array',
}
function run(args) {
  const result = spawnSync('php', args, { cwd: root + '/api', env, encoding: 'utf8' })
  assert.equal(result.status, 0, result.stderr + result.stdout)
  return result.stdout
}
let worker
try {
  run(['artisan', 'migrate', '--force', '--no-interaction'])
  run(['tests/milestone501-fixture.php'])
  const fixture = JSON.parse(run(['tests/milestone501-fixture.php', 'concurrency-seed']))
  let output = '',
    errors = ''
  worker = spawn('php', ['tests/milestone501-fixture.php', 'inflight'], {
    cwd: root + '/api',
    env,
    stdio: ['ignore', 'pipe', 'pipe'],
  })
  worker.stdout.on('data', (bytes) => (output += bytes))
  worker.stderr.on('data', (bytes) => (errors += bytes))
  const done = new Promise((resolve) => worker.on('exit', (code) => resolve(code)))
  for (let i = 0; i < 100; i++) {
    try {
      await import('node:fs/promises').then((fs) => fs.access(database + '.inflight'))
      break
    } catch {}
    await new Promise((resolve) => setTimeout(resolve, 20))
    if (i === 99) throw new Error('Worker did not enter fake HTTP attempt: ' + errors)
  }
  const started = Date.now()
  run([
    'tests/milestone501-fixture.php',
    'change-filter',
    String(fixture.endpoint),
    fixture.targetProject,
  ])
  assert.ok(Date.now() - started >= 300, 'Filter update waits for in-flight delivery transaction')
  assert.equal(await done, 0, errors)
  assert.equal(JSON.parse(output).processed, 1)
  assert.equal(JSON.parse(run(['tests/milestone501-fixture.php', 'process'])).processed, 0)
  assert.deepEqual(JSON.parse(run(['tests/milestone501-fixture.php', 'stats'])).statuses, [
    'succeeded',
    'cancelled',
  ])
  console.log(
    'PASS: separate-process SQLite lock serialization: accepted in-flight attempt completes; filter update cancels pending; no old-filter retry',
  )
} finally {
  worker?.kill()
  await rm(scratch, { recursive: true, force: true })
}
