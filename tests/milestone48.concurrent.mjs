import assert from 'node:assert/strict'
import { randomBytes } from 'node:crypto'
import { spawn, spawnSync } from 'node:child_process'
import { writeFile } from 'node:fs/promises'
assert.equal(
  process.env.DB_DATABASE,
  'projectbrief_m48_qa',
  'Use only the dedicated disposable MariaDB',
)
const cwd = new URL('../api', import.meta.url).pathname
const results = []
for (const kind of ['existing', 'new'])
  for (let round = 0; round < 3; round++) {
    const env = {
      ...process.env,
      APP_ENV: 'testing',
      ARGON_MEMORY: '1024',
      ARGON_TIME: '1',
      MAIL_MAILER: 'array',
      LOG_CHANNEL: 'null',
      M48_INVITATION_TOKEN: randomBytes(32).toString('hex'),
    }
    function run(...args) {
      const r = spawnSync('php', ['tests/milestone48-concurrent.php', ...args], {
        cwd,
        env,
        encoding: 'utf8',
      })
      assert.equal(r.status, 0, r.stderr)
      return JSON.parse(r.stdout)
    }
    const fixture = run('prepare', kind)
    env.M48_RACE_EMAIL = fixture.email
    const workers = Array.from({ length: 6 }, () => {
      const process = spawn('php', ['tests/milestone48-concurrent.php', 'accept'], { cwd, env })
      let text = '',
        error = '',
        readyResolve
      const ready = new Promise((resolve) => {
        readyResolve = resolve
      })
      process.stdout.on('data', (bytes) => {
        text += bytes
        if (text.startsWith('ready\n')) readyResolve()
      })
      process.stderr.on('data', (bytes) => (error += bytes))
      const done = new Promise((resolve, reject) =>
        process.on('close', (code) => {
          try {
            assert.equal(code, 0, error + text)
            resolve(JSON.parse(text.replace('ready\n', '')))
          } catch (e) {
            reject(e)
          }
        }),
      )
      return { process, ready, done }
    })
    await Promise.all(workers.map((worker) => worker.ready))
    workers.forEach((worker) => worker.process.stdin.end('go\n'))
    const statuses = (await Promise.all(workers.map((worker) => worker.done)))
      .map((result) => result.status)
      .sort()
    assert.deepEqual(statuses, [200, 410, 410, 410, 410, 410])
    assert.deepEqual(run('inspect', fixture.project.toString()), {
      users: 1,
      memberships: 1,
      projects: 1,
    })
    results.push({
      kind,
      round: round + 1,
      workers: 6,
      accepted: 1,
      unavailable: 5,
      duplicateUsers: 0,
      duplicateMemberships: 0,
    })
  }
await writeFile(
  new URL('../docs/milestone-4-8/concurrency-results.json', import.meta.url),
  JSON.stringify({ database: 'MariaDB 11.8.9; disposable QA container', results }, null, 2) + '\n',
)
console.log(
  'PASS: 6 invitation races × 6 PHP processes, one acceptance each; no duplicate users or memberships',
)
