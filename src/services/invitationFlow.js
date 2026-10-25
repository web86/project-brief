import { ref, computed } from 'vue'

// The bearer token stays in this page's closure, never in browser storage or navigation URLs.
export function createInvitationFlow({ token, request, resetCsrf, onSignedOut, onAccepted }) {
  const state = ref(null),
    register = ref(true),
    name = ref(''),
    password = ref(''),
    confirmation = ref('')
  const busy = ref(false),
    error = ref(null),
    signInRequired = ref(false)
  const mismatch = computed(() => !!state.value?.authenticated && !state.value.accountMatches)
  async function resolve() {
    state.value = await request('/api/invitations/resolve', { method: 'POST', body: { token } })
    name.value ||= state.value.name || ''
  }
  function failed(cause) {
    error.value = cause
    if (cause.status === 410) state.value = null
  }
  async function load() {
    if (busy.value) return
    busy.value = true
    error.value = null
    try {
      await resolve()
    } catch (cause) {
      failed(cause)
    } finally {
      busy.value = false
    }
  }
  function chooseRegistration(value) {
    if (busy.value) return
    register.value = value
    error.value = null
    signInRequired.value = false
    password.value = ''
    confirmation.value = ''
  }
  async function accept() {
    if (busy.value || !state.value || mismatch.value) return false
    busy.value = true
    error.value = null
    signInRequired.value = false
    try {
      if (!state.value.authenticated && !register.value) {
        await request('/api/admin/login', {
          method: 'POST',
          body: { email: state.value.email, password: password.value, invitation_token: token },
        })
        resetCsrf()
        await resolve()
        if (mismatch.value) return false
      }
      await request('/api/invitations/accept', {
        method: 'POST',
        body: {
          token,
          ...(!state.value.authenticated && register.value
            ? {
                name: name.value,
                password: password.value,
                password_confirmation: confirmation.value,
              }
            : {}),
        },
      })
      resetCsrf()
      password.value = ''
      confirmation.value = ''
      await onAccepted()
      return true
    } catch (cause) {
      if ([401, 403, 419].includes(cause.status)) {
        try {
          await resolve()
          if (mismatch.value) return false
          if (cause.status === 401 && !state.value.authenticated) {
            register.value = false
            password.value = ''
            confirmation.value = ''
            signInRequired.value = true
            return false
          }
        } catch (refreshError) {
          failed(refreshError)
          return false
        }
      }
      failed(cause)
      return false
    } finally {
      busy.value = false
    }
  }
  async function logout() {
    if (busy.value) return
    busy.value = true
    error.value = null
    try {
      await request('/api/admin/logout', { method: 'POST' })
      resetCsrf()
      onSignedOut()
      password.value = ''
      confirmation.value = ''
      register.value = false
      await resolve()
    } catch (cause) {
      failed(cause)
    } finally {
      busy.value = false
    }
  }
  return {
    state,
    register,
    name,
    password,
    confirmation,
    busy,
    error,
    signInRequired,
    mismatch,
    load,
    chooseRegistration,
    accept,
    logout,
  }
}
