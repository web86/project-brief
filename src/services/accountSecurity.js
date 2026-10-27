// Keep the WebAuthn serializer used by @laravel/passkeys (locked transitive dependency).
// Use the project's transport so HTTP status and safe diagnostic codes survive errors.
import {
  browserSupportsWebAuthn,
  startRegistration,
  startAuthentication,
} from '@simplewebauthn/browser'
import { api } from '../api/client.js'

export const passkeysSupported = () =>
  globalThis.isSecureContext === true && browserSupportsWebAuthn()

export function createPasskeyClient({
  client = api,
  register = startRegistration,
  authenticate = startAuthentication,
} = {}) {
  return async function ceremony(purpose, name) {
    let stage = 'options'
    let created = false
    client.resetCsrf()
    try {
      const { options } = await client.request(`/api/security/passkey/${purpose}/options`)
      stage = 'authenticator'
      const credential = await (purpose === 'register' ? register : authenticate)({
        optionsJSON: options,
      })
      created = purpose === 'register'
      stage = 'submit'
      return await client.request(`/api/security/passkey/${purpose}`, {
        method: 'POST',
        body: { ...(purpose === 'register' ? { name } : {}), credential },
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
        ...(stage === 'authenticator' ? { messageKey: `security.${nativeKey}` } : {}),
        credentialCreated: created,
      }
    } finally {
      client.resetCsrf()
    }
  }
}
export const passkeyCeremony = createPasskeyClient()
