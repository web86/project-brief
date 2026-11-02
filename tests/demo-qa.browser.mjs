import assert from 'node:assert/strict'
import { readFile, mkdir } from 'node:fs/promises'
import { createHmac } from 'node:crypto'
import path from 'node:path'
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const root = path.resolve(import.meta.dirname, '..')
const access = JSON.parse(
  await readFile(
    path.join(root, '.tools/demo-runtime/api/storage/demo-access.private.json'),
    'utf8',
  ),
)
const origin = 'http://127.0.0.1:18081'
function otp(secret) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'
  const bits = [...secret.toUpperCase().replace(/=+$/, '')]
    .map((letter) => alphabet.indexOf(letter).toString(2).padStart(5, '0'))
    .join('')
  const key = Buffer.from(bits.match(/.{8}/g).map((byte) => parseInt(byte, 2)))
  const counter = Buffer.alloc(8)
  counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)))
  const digest = createHmac('sha1', key).update(counter).digest(),
    offset = digest[19] & 15
  return String((digest.readUInt32BE(offset) & 0x7fffffff) % 1000000).padStart(6, '0')
}
const browser = await chromium.launch({ headless: true })
try {
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } })
  async function post(url, data) {
    const csrf = await (await context.request.get(origin + '/api/csrf')).json()
    return context.request.post(origin + url, {
      data,
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf.token },
    })
  }
  const login = await post('/api/admin/login', { email: access.owner, password: access.password })
  assert.equal(login.status(), 200)
  assert.equal((await login.json()).state, 'pending_2fa')
  const challenge = await post('/api/security/challenge', { code: otp(access.totpSecret) })
  assert.equal(challenge.status(), 200)
  assert.equal((await challenge.json()).state, 'authenticated')
  const status = await (await context.request.get(origin + '/api/security/status')).json()
  assert.equal(status.recommendationsPending, false)
  assert.equal(status.totp, true)
  assert.equal(status.recoverySaved, true)
  const page = await context.newPage(),
    errors = []
  page.on('pageerror', (error) => errors.push(error.message))
  await page.goto(origin + '/admin/account')
  await page.locator('#bootstrap-splash').waitFor({ state: 'detached' })
  await page.locator('.loading-overlay').waitFor({ state: 'hidden' })
  assert.ok((await page.locator('body').innerText()).includes('Morgan Ellis'))
  assert.equal(await page.locator('.demo-view-indicator').count(), 0)
  await mkdir(path.join(root, 'artifacts/demo'), { recursive: true })
  await page.screenshot({ path: path.join(root, 'artifacts/demo/full-qa-account.png') })
  assert.deepEqual(errors, [])
  console.log(
    JSON.stringify({
      passed: true,
      normalOwnerMfa: true,
      recommendationsPending: false,
      fullQaProfile: true,
    }),
  )
} finally {
  await browser.close()
}
