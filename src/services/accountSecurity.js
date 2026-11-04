// Keep the WebAuthn serializer used by @laravel/passkeys (locked transitive dependency).
// Use the project's transport so HTTP status and safe diagnostic codes survive errors.
import {
  browserSupportsWebAuthn,
  startRegistration,
  startAuthentication,
  WebAuthnAbortService,
} from '@simplewebauthn/browser'
import { STATIC_DEMO } from './demo.js'
import { api } from '../api/client.js'

export const cancelPasskeyCeremony = () => WebAuthnAbortService.cancelCeremony()

export const passkeysSupported = () =>
  globalThis.isSecureContext === true && browserSupportsWebAuthn()

export function createPasskeyClient({
  client = api,
  register = startRegistration,
  authenticate = startAuthentication,
} = {}) {
  return async function ceremony(purpose, name, preparedOptions) {
    if (STATIC_DEMO) throw { status: 403, code: 'demo_read_only', message: 'Demo · View only' }
    let stage = 'options'
    let created = false
    let timer
    client.resetCsrf()
    try {
      let options = preparedOptions?.options || preparedOptions
      let attemptId = preparedOptions?.attemptId
      if (!options)
        ({ options, attemptId } = await client.request(`/api/security/passkey/${purpose}/options`))
      stage = 'authenticator'
      const authentication = (purpose === 'register' ? register : authenticate)({
        optionsJSON: options,
      })
      const credential =
        purpose === 'confirm'
          ? await Promise.race([
              authentication,
              new Promise((_, reject) => {
                timer = setTimeout(() => {
                  reject({ code: 'timeout' })
                  cancelPasskeyCeremony()
                }, options.timeout || 60_000)
              }),
            ])
          : await authentication
      clearTimeout(timer)
      created = purpose === 'register'
      stage = 'submit'
      return await client.request(`/api/security/passkey/${purpose}`, {
        method: 'POST',
        body: {
          ...(purpose === 'register' ? { name } : {}),
          ...(attemptId ? { attemptId } : {}),
          credential,
        },
        securityRetry: false,
      })
    } catch (cause) {
      // Do not retain native errors: extension implementations can attach credential data.
      const nativeName = cause.cause?.name || cause.name
      const nativeKey =
        nativeName === 'NotAllowedError' || nativeName === 'AbortError'
          ? 'passkeyCancelled'
          : nativeName === 'InvalidStateError'
            ? 'passkeyExists'
            : nativeName === 'NotSupportedError'
              ? 'unsupported'
              : nativeName === 'SecurityError'
                ? 'passkeyOrigin'
                : 'passkeyFailed'
      throw {
        status: cause.status,
        code:
          typeof cause.code === 'string' &&
          /^(?:(?:PK|SEC)_[A-Z_]+|security_(?:step_up_required|retry_required)|timeout)$/.test(
            cause.code,
          )
            ? cause.code
            : undefined,
        diagnosticId: cause.diagnosticId,
        stage: cause.stage || stage,
        ...(stage === 'authenticator' && cause.code !== 'timeout'
          ? { messageKey: `security.${nativeKey}` }
          : {}),
        credentialCreated: created,
        unavailable: stage === 'authenticator' && nativeName === 'NotSupportedError',
        cancelled:
          stage === 'authenticator' && ['NotAllowedError', 'AbortError'].includes(nativeName),
      }
    } finally {
      clearTimeout(timer)
      client.resetCsrf()
    }
  }
}
export const passkeyCeremony = createPasskeyClient()
