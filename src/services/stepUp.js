import { shallowRef } from 'vue'

// Advisory browser cache only. Every mutation still checks the server's proof and ACL.
export function createStepUpFlow({ now = () => performance.now() } = {}) {
  const request = shallowRef(null),
    state = shallowRef(null)
  let adapters,
    status,
    deadline = 0,
    prepared,
    preparing,
    epoch = 0,
    active
  function remember(value) {
    status = value
    deadline = now() + Math.max(0, Math.min(300, value?.freshFor || 0)) * 1000
  }
  function fresh() {
    return status?.fresh === true && now() < deadline
  }
  function update(values) {
    state.value = { ...state.value, ...values }
  }
  function finish(accepted) {
    const previous = active
    active = null
    request.value = null
    state.value = null
    previous?.resolve(accepted)
  }
  function invalidate(cancel = false) {
    epoch++
    status = prepared = preparing = null
    deadline = 0
    if (cancel) finish(false)
  }
  function configure(value) {
    adapters = value
    invalidate(true)
  }
  async function prepare() {
    if (!adapters || active) return
    if (preparing) return preparing
    const version = epoch
    const task = (async () => {
      const result = await adapters.status()
      if (version !== epoch || active) return
      if (status?.confirmationContext !== result.confirmationContext) prepared = null
      remember(result)
      if (
        result.signedIn &&
        result.passkeys?.length &&
        adapters.supported() &&
        (!prepared || prepared.until <= now())
      ) {
        const { options } = await adapters.options()
        if (version === epoch) prepared = { options, until: now() + 90000 }
      }
    })()
    preparing = task
    try {
      await task
    } finally {
      if (preparing === task) preparing = null
    }
  }
  function accept(result) {
    if (!active) return
    if (!result?.fresh || (active.context && active.context !== result.confirmationContext)) {
      adapters?.changed?.()
      invalidate(true)
      return
    }
    prepared = null
    epoch++
    remember(result)
    finish(true)
  }
  function native() {
    if (!active || state.value?.busy) return
    if (!prepared || prepared.until <= now() || !adapters.activated()) {
      update({ mode: 'retry', busy: false })
      return
    }
    const options = prepared.options
    prepared = null
    const operation = active
    update({ mode: 'native', busy: true, error: null })
    // This invocation must remain synchronous with the original click (no await first).
    adapters.passkey(options).then(
      (result) => {
        if (active === operation) accept(result)
      },
      (cause) => {
        if (active !== operation) return
        update({
          mode: 'retry',
          busy: false,
          error: cause.cancelled || cause.unavailable ? null : cause,
        })
        if (!cause.cancelled && !cause.unavailable) adapters.error?.(cause)
        warmRetry(operation)
      },
    )
  }
  async function warmRetry(operation = active) {
    if (!operation || prepared?.until > now()) return
    update({ busy: true })
    try {
      const { options } = await adapters.options()
      if (active === operation) prepared = { options, until: now() + 90000 }
    } catch (cause) {
      if (active === operation) {
        update({ error: cause })
        adapters.error?.(cause)
      }
    } finally {
      if (active === operation) update({ busy: false })
    }
  }
  async function start(operation) {
    try {
      if (preparing) await preparing
      if (active !== operation) return
      if (!status) remember(await adapters.status())
      if (active !== operation) return
      operation.context ||= status.confirmationContext
      update({ status })
      if (!status.signedIn) {
        update({ mode: 'retry' })
        return
      }
      if (status.passkeys?.length && !adapters.supported()) {
        update({ mode: 'retry' })
      } else if (status.passkeys?.length) {
        await warmRetry(operation)
        if (active === operation) native()
      } else update({ mode: 'alternative' })
    } catch (cause) {
      if (active === operation) {
        update({ mode: 'retry', error: cause })
        adapters.error?.(cause)
      }
    }
  }
  function ensure(force = false) {
    if (!adapters) {
      if (active) return active.promise
    } else if (!force && fresh()) return null
    if (active) return active.promise
    const operation = { context: status?.confirmationContext }
    operation.promise = new Promise((resolve) => {
      operation.resolve = resolve
    })
    active = operation
    request.value = (accepted) => finish(accepted === true)
    state.value = { status, mode: 'native', busy: false, error: null }
    if (!adapters) update({ mode: 'alternative' })
    else if (status?.signedIn && status.passkeys?.length && prepared?.until > now()) native()
    else start(operation)
    return operation.promise
  }
  function preflight(path, method) {
    if (!adapters || !status?.signedIn || fresh() || !isSensitiveMutation(path, method)) return null
    if (/^\/api\/security\/totp\/(?:setup|confirm)$/.test(path) && status.setupFresh) return null
    if (path === '/api/security/recovery-codes/acknowledge' && status.recoveryFresh) return null
    return ensure()
  }
  function observe(path, method, data, code) {
    if ([401, 419].includes(code)) invalidate()
    if (code >= 400 || method === 'GET') return
    if (/^\/api\/security\/(?:confirm|passkey\/confirm)$/.test(path)) {
      epoch++
      prepared = null
      remember(data)
    } else if (
      /^\/api\/security\//.test(path) ||
      /^\/api\/(?:admin\/(?:login|logout|account\/(?:email|password)|workspaces\/[^/]+\/select)|invitations\/accept)$/.test(
        path,
      )
    )
      invalidate(!path.startsWith('/api/security/'))
  }
  return {
    request,
    state,
    leave: () => finish(false),
    configure,
    prepare,
    ensure,
    preflight,
    observe,
    accept,
    invalidate,
    cancel: () => {
      if (active) adapters?.cancelled?.()
      finish(false)
    },
    retry: native,
    alternative: () => update({ mode: 'alternative', error: null }),
  }
}

export function isSecurityConfirmationScreen(path) {
  return /^\/admin(?:\/?$|\/account(?:\/|$)|\/settings\/integrations(?:\/|$)|\/projects\/[^/]+\/(?:team|clients)(?:\/|$))/.test(
    path,
  )
}

export function isSensitiveMutation(path, method) {
  if (!['POST', 'PATCH', 'DELETE'].includes(method)) return false
  if (
    /^\/api\/security\/(?:totp(?:\/(?:setup|confirm))?|recovery-codes(?:\/acknowledge)?|passkeys\/\d+)$/.test(
      path,
    )
  )
    return true
  return /^\/api\/admin\/(?:account\/(?:email(?:\/confirm)?|password)|integrations(?:\/|$)|projects\/(?:trash(?:\/confirmation)?|[^/]+\/(?:team\/|clients\/|permanent(?:\/confirmation)?$)))/.test(
    path,
  )
}
export const stepUp = createStepUpFlow()
export const stepUpRequest = stepUp.request
export const stepUpState = stepUp.state
export const requestStepUp = () => stepUp.ensure(true)
export const isSecurityCancellation = (cause) => cause?.code === 'security_confirmation_cancelled'
