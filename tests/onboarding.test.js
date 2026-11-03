import { test } from 'node:test'
import assert from 'node:assert/strict'
import { appDestination } from '../src/services/session.js'
import { isSensitiveMutation, isSecurityConfirmationScreen } from '../src/services/stepUp.js'
import { onboardingMessages } from '../src/locales/onboarding.js'

test('onboarding destinations stay inside the server-owned entry and required-agreement screens', async () => {
  for (const nextPath of [
    '/admin/verify-email',
    '/admin/security',
    '/admin/onboarding',
    '/admin/agreement',
  ])
    assert.equal(
      await appDestination(async () => ({ authenticated: true, type: 'onboarding', nextPath })),
      nextPath,
    )
  assert.equal(
    await appDestination(async () => ({
      authenticated: true,
      type: 'onboarding',
      nextPath: 'https://evil.example.test/',
    })),
    null,
  )
})

test('social linking and unlinking use fresh security, while public sign-in never starts an MFA step-up', () => {
  for (const provider of ['google', 'yandex', 'github']) {
    assert.equal(isSensitiveMutation(`/api/auth/${provider}/start`, 'POST'), false)
    assert.equal(isSensitiveMutation(`/api/auth/${provider}/link`, 'POST'), true)
    assert.equal(isSensitiveMutation(`/api/auth/${provider}`, 'DELETE'), true)
    assert.equal(isSensitiveMutation(`/api/auth/${provider}`, 'GET'), false)
  }
  assert.equal(isSecurityConfirmationScreen('/admin/onboarding'), true)
  assert.equal(isSecurityConfirmationScreen('/register'), false)
})

test('public registration, verification, social errors and connections have complete RU/EN/TR/ZH-CN messages', () => {
  const keys = Object.keys(onboardingMessages.en).sort()
  for (const language of ['ru', 'en', 'tr', 'zh-CN']) {
    const values = onboardingMessages[language]
    assert.deepEqual(Object.keys(values).sort(), keys)
    for (const key of keys)
      assert.ok(typeof values[key] === 'string' && values[key].trim(), `${language}.${key}`)
    assert.ok(values.cooldown.includes('{seconds}'))
  }
})
