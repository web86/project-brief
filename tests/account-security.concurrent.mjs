import assert from 'node:assert/strict'
import { randomBytes } from 'node:crypto'
import { spawn, spawnSync } from 'node:child_process'
assert.equal(
  process.env.DB_DATABASE,
  'projectbrief_m7_security_qa',
  'Dedicated disposable MariaDB required',
)
const cwd = new URL('../api', import.meta.url).pathname
const env = {
  ...process.env,
  APP_ENV: 'testing',
  APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
  CACHE_STORE: 'array',
  SESSION_DRIVER: 'array',
  LOG_CHANNEL: 'null',
  BCRYPT_ROUNDS: '4',
}
function run(...args) {
  const result = spawnSync('php', args, { cwd, env, encoding: 'utf8' })
  assert.equal(result.status, 0, result.stderr + result.stdout)
  return result.stdout
}
run('artisan', 'migrate', '--force', '--no-interaction')
const results = []
for (const kind of ['recovery', 'totp', 'challenge', 'delete', 'acl']) {
  const fixture = JSON.parse(run('tests/account-security-concurrent.php', 'prepare', kind))
  env.M7_RACE_USER = String(fixture.id)
  env.M7_RACE_CODE = fixture.code
  env.M7_RACE_CHALLENGE = fixture.challenge
  env.M72_RACE_PROJECT = String(fixture.project || '')
  const workers = Array.from({ length: 6 }, (_, i) => {
    const worker = spawn(
      'php',
      [
        'tests/account-security-concurrent.php',
        'worker',
        kind,
        String(fixture.keys[i % fixture.keys.length] || 0),
      ],
      { cwd, env },
    )
    let output = '',
      errors = '',
      readyResolve
    const ready = new Promise((resolve) => (readyResolve = resolve))
    worker.stdout.on('data', (bytes) => {
      output += bytes
      if (output.startsWith('ready\n')) readyResolve()
    })
    worker.stderr.on('data', (bytes) => (errors += bytes))
    const done = new Promise((resolve, reject) =>
      worker.on('exit', (code) => {
        try {
          assert.equal(code, 0, errors + output)
          resolve(JSON.parse(output.replace('ready\n', '')).status)
        } catch (error) {
          reject(new Error(errors + output + String(error)))
        }
      }),
    )
    return { worker, ready, done }
  })
  await Promise.all(workers.map((worker) => worker.ready))
  if (kind === 'acl') run('tests/account-security-concurrent.php', 'revoke-acl', kind)
  workers.forEach(({ worker }) => worker.stdin.end('go\n'))
  const statuses = await Promise.all(workers.map((worker) => worker.done))
  assert.equal(
    statuses.filter((status) => status === 200).length,
    kind === 'acl' ? 0 : 1,
    JSON.stringify(statuses),
  )
  assert.ok(
    statuses.every((status) =>
      kind === 'acl'
        ? [403].includes(status)
        : kind === 'delete'
          ? [200, 404, 409, 422, 423].includes(status)
          : [200, 422].includes(status),
    ),
  )
  const state = JSON.parse(run('tests/account-security-concurrent.php', 'inspect', kind))
  if (kind === 'acl') assert.equal(state.links, 0)
  if (kind === 'delete') assert.equal(state.passkeys, 1)
  if (kind === 'recovery') assert.equal(state.recoveryCodes, 0)
  results.push({ kind, workers: 6, statuses, state })
}
console.log(JSON.stringify({ passed: results.length, results }))
