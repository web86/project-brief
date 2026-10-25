import { test } from 'node:test'
import assert from 'node:assert/strict'
import { createInvitationFlow } from '../src/services/invitationFlow.js'
const token = 'a'.repeat(64)
function fixture(initial = {}) {
  let session = {
    email: 'invited@example.test',
    authenticated: false,
    authenticatedEmail: null,
    accountMatches: null,
    ...initial,
  }
  const calls = [],
    events = []
  let failure
  const flow = createInvitationFlow({
    token,
    async request(path, options) {
      calls.push({ path, ...options })
      if (failure && path === failure.path) throw failure.error
      if (path.endsWith('/resolve')) return { ...session }
      if (path.endsWith('/logout'))
        session = {
          ...session,
          authenticated: false,
          authenticatedEmail: null,
          accountMatches: null,
        }
      if (path.endsWith('/login'))
        session = {
          ...session,
          authenticated: true,
          authenticatedEmail: session.email,
          accountMatches: true,
        }
      return {}
    },
    resetCsrf() {
      events.push('csrf')
    },
    onSignedOut() {
      events.push('signedOut')
    },
    async onAccepted() {
      events.push('accepted')
    },
  })
  return {
    flow,
    calls,
    events,
    fail(path, error) {
      failure = { path, error }
    },
    session(value) {
      session = { ...session, ...value }
    },
  }
}
test('new developer gets registration and submits invited credentials without a public existence lookup', async () => {
  const { flow, calls, events } = fixture()
  await flow.load()
  assert.equal(flow.register.value, true)
  flow.name.value = 'Developer'
  flow.password.value = 'New-password!'
  flow.confirmation.value = 'New-password!'
  assert.equal(await flow.accept(), true)
  assert.deepEqual(
    calls.map((call) => call.path),
    ['/api/invitations/resolve', '/api/invitations/accept'],
  )
  assert.deepEqual(calls[1].body, {
    token,
    name: 'Developer',
    password: 'New-password!',
    password_confirmation: 'New-password!',
  })
  assert.ok(events.includes('accepted'))
})
test('existing user chooses login, refreshes actual session, then accepts with no registration payload', async () => {
  const { flow, calls } = fixture()
  await flow.load()
  flow.chooseRegistration(false)
  flow.password.value = 'Existing-password!'
  assert.equal(await flow.accept(), true)
  assert.deepEqual(
    calls.map((call) => call.path),
    [
      '/api/invitations/resolve',
      '/api/admin/login',
      '/api/invitations/resolve',
      '/api/invitations/accept',
    ],
  )
  assert.equal(calls[1].body.invitation_token, token)
  assert.deepEqual(calls.at(-1).body, { token })
})
test('mismatched authenticated account cannot issue accept; logout keeps token and resets CSRF before resolving', async () => {
  const { flow, calls, events } = fixture({
    authenticated: true,
    authenticatedEmail: 'other@example.test',
    accountMatches: false,
  })
  await flow.load()
  assert.equal(flow.mismatch.value, true)
  assert.equal(await flow.accept(), false)
  assert.equal(calls.length, 1)
  await flow.logout()
  assert.equal(flow.mismatch.value, false)
  assert.deepEqual(events, ['csrf', 'signedOut'])
  assert.deepEqual(calls.at(-1).body, { token })
  flow.password.value = 'Existing-password!'
  assert.equal(await flow.accept(), true)
  assert.deepEqual(calls.at(-1).body, { token })
  assert.ok(calls.every((call) => !call.path.includes(token) && !call.path.includes('?')))
})
test('matching authenticated user accepts directly using the current session', async () => {
  const { flow, calls } = fixture({
    authenticated: true,
    authenticatedEmail: 'Invited@example.test',
    accountMatches: true,
  })
  await flow.load()
  assert.equal(await flow.accept(), true)
  assert.deepEqual(
    calls.map((call) => call.path),
    ['/api/invitations/resolve', '/api/invitations/accept'],
  )
})
test('a session switched in another tab becomes an informational mismatch after server rejection', async () => {
  const f = fixture({ authenticated: true, accountMatches: true })
  await f.flow.load()
  f.session({ authenticatedEmail: 'other@example.test', accountMatches: false })
  f.fail('/api/invitations/accept', { status: 403 })
  assert.equal(await f.flow.accept(), false)
  assert.equal(f.flow.mismatch.value, true)
  assert.equal(f.flow.error.value, null)
})
test('registration requiring authentication offers login without claiming account existence', async () => {
  const f = fixture()
  await f.flow.load()
  f.fail('/api/invitations/accept', { status: 401 })
  assert.equal(await f.flow.accept(), false)
  assert.equal(f.flow.register.value, false)
  assert.equal(f.flow.signInRequired.value, true)
  assert.equal(f.flow.error.value, null)
})
test('expired or consumed links clear the form and never issue acceptance', async () => {
  const f = fixture()
  f.fail('/api/invitations/resolve', { status: 410 })
  await f.flow.load()
  assert.equal(f.flow.state.value, null)
  assert.equal(f.flow.error.value.status, 410)
  assert.equal(await f.flow.accept(), false)
  assert.equal(f.calls.length, 1)
})
test('failed logout keeps the invitation and mismatch state available for retry', async () => {
  const f = fixture({
    authenticated: true,
    authenticatedEmail: 'other@example.test',
    accountMatches: false,
  })
  await f.flow.load()
  f.fail('/api/admin/logout', { status: 0 })
  await f.flow.logout()
  assert.equal(f.flow.mismatch.value, true)
  assert.equal(f.flow.error.value.status, 0)
  assert.equal(f.flow.busy.value, false)
  assert.deepEqual(f.events, [])
})
