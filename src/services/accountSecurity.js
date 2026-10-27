import { Passkeys } from '@laravel/passkeys'
import { api } from '../api/client.js'
import { locale } from '../i18n/index.js'

export const passkeysSupported = () => globalThis.isSecureContext === true && Passkeys.isSupported()
export async function passkeyCeremony(purpose, name) {
  const { token } = await api.request('/api/csrf')
  Passkeys.configure({
    fetch: { headers: { 'X-CSRF-TOKEN': token, 'Accept-Language': locale.value } },
  })
  const routes = {
    options: `/api/security/passkey/${purpose}/options`,
    submit: `/api/security/passkey/${purpose}`,
  }
  try {
    return purpose === 'register'
      ? await Passkeys.register({ name, routes })
      : await Passkeys.verify({ routes })
  } finally {
    api.resetCsrf()
  }
}
