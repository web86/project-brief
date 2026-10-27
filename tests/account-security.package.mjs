// Real signed enrollment with only the production vendor from an extracted ZIP.
import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { cp, mkdtemp, writeFile, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
const packageRoot = path.resolve(process.argv[2])
const temporary = await mkdtemp(path.join(tmpdir(), 'm7-passkey-package-'))
try {
  const application = path.join(temporary, 'application')
  const database = path.join(temporary, 'test.sqlite')
  await cp(path.join(packageRoot, 'private/project-brief-app'), application, { recursive: true })
  await writeFile(database, '')
  const result = spawnSync(
    process.env.PHP_BIN || 'php',
    [path.join(import.meta.dirname, 'account-security.package.php'), application],
    {
      cwd: application,
      encoding: 'utf8',
      env: {
        ...process.env,
        APP_ENV: 'testing',
        APP_DEBUG: 'false',
        APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
        APP_URL: 'https://brief.web86.site',
        PASSKEY_ORIGIN: 'https://brief.web86.site',
        PASSKEY_RP_ID: 'brief.web86.site',
        DB_CONNECTION: 'sqlite',
        DB_DATABASE: database,
        DB_URL: '',
        CACHE_STORE: 'array',
        SESSION_DRIVER: 'array',
        LOG_CHANNEL: 'null',
      },
    },
  )
  assert.equal(result.status, 0, 'Packaged security process failed')
  let data
  try {
    data = JSON.parse(result.stdout)
  } catch {
    throw new Error(
      'Packaged signed WebAuthn enrollment failed (no success JSON); inspect the production runtime class inventory.',
    )
  }
  assert.equal(data.passed, true)
  assert.equal(data.productionVendor, true)
  console.log(`PASS: Packaged signed WebAuthn enrollment ${JSON.stringify(data)}`)
} finally {
  await rm(temporary, { recursive: true, force: true })
}
