import test from 'node:test'
import assert from 'node:assert/strict'
import { createApiClient } from '../src/api/client.js'
import { isSensitiveMutation, isSecurityConfirmationScreen } from '../src/services/stepUp.js'

function transport(statuses) {
  const sends = [],
    headers = [],
    events = []
  let proofs = 0,
    tokens = 0
  const client = createApiClient({
    fetchImpl: async () => ({
      ok: true,
      status: 200,
      json: async () => ({ token: String(++tokens) }),
    }),
    securityFlow: {
      preflight: () => null,
      observe() {},
      ensure: async () => {
        proofs++
        return true
      },
    },
    xhrFactory: () => ({
      upload: {},
      open() {},
      setRequestHeader(key, value) {
        headers.push([key, value])
      },
      getResponseHeader: () => null,
      send(body) {
        sends.push(body)
        queueMicrotask(() => {
          this.upload.onprogress({ loaded: 25, total: 100, lengthComputable: true })
          this.upload.onprogress({ loaded: 100, total: 100, lengthComputable: true })
          this.upload.onload()
          this.status = statuses.shift()
          this.responseText = JSON.stringify(
            this.status === 423
              ? { code: 'security_step_up_required', mutationNotStarted: true }
              : { data: { saved: true } },
          )
          this.onload()
        })
      },
      abort() {
        this.onabort()
      },
    }),
  })
  return { client, sends, headers, events, proofs: () => proofs }
}
const path = '/api/admin/workspaces/workspace-a/branding/assets/favicon'
test('branding step-up is explicitly allowlisted, with identity preparation on the branding screen', () => {
  assert.equal(isSensitiveMutation(path, 'POST'), true)
  assert.equal(isSensitiveMutation('/api/admin/workspaces/a/branding', 'PATCH'), true)
  assert.equal(isSensitiveMutation(path + '/unknown', 'POST'), false)
  assert.equal(isSensitiveMutation(path, 'GET'), false)
  assert.equal(isSecurityConfirmationScreen('/admin/settings/branding'), true)
})
test('multipart upload reports actual transport bytes, refreshes CSRF and resumes the same file once after proof', async () => {
  const state = transport([423, 200]),
    body = new FormData()
  body.append('file', new Blob(['selected file']), 'icon.png')
  const progress = [],
    phases = []
  const result = await state.client.request(path, {
    method: 'POST',
    body,
    onUploadProgress: (value) => progress.push(value.loaded),
    onUploadComplete: () => phases.push('processing'),
    onSecurityRequired: () => phases.push('confirming'),
  })
  assert.deepEqual(result, { data: { saved: true } })
  assert.equal(state.proofs(), 1)
  assert.equal(state.sends.length, 2)
  assert.equal(state.sends[0], state.sends[1])
  assert.equal(await state.sends[1].get('file').text(), 'selected file')
  assert.deepEqual(progress, [25, 100, 25, 100])
  assert.deepEqual(phases, ['processing', 'confirming', 'processing'])
  assert.deepEqual(
    state.headers.filter(([key]) => key === 'X-CSRF-TOKEN').map(([, value]) => value),
    ['1', '2'],
  )
  assert.equal(
    state.headers.some(([key]) => key === 'Content-Type'),
    false,
  )
})
test('a second denial cannot cause another proof or an unbounded upload replay', async () => {
  const state = transport([423, 423]),
    body = new FormData()
  body.append('file', new Blob(['file']), 'icon.png')
  await assert.rejects(
    state.client.request(path, { method: 'POST', body, onUploadProgress() {} }),
    (error) => error.status === 423,
  )
  assert.equal(state.proofs(), 1)
  assert.equal(state.sends.length, 2)
})
