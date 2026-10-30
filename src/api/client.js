import { stepUp, isSensitiveMutation } from '../services/stepUp.js'
import { t, locale } from '../i18n/index.js'
export class ApiError extends Error {
  constructor(status, message, errors = {}, code = null) {
    super(message)
    this.status = status
    this.errors = errors
    this.code = code
  }
}

function waitForRequest(promise, signal) {
  if (!signal) return promise
  signal.throwIfAborted()
  return new Promise((resolve, reject) => {
    const abort = () => reject(signal.reason || new DOMException('Cancelled', 'AbortError'))
    signal.addEventListener('abort', abort, { once: true })
    promise.then(
      (value) => {
        signal.removeEventListener('abort', abort)
        resolve(value)
      },
      (error) => {
        signal.removeEventListener('abort', abort)
        reject(error)
      },
    )
  })
}

export function uploadRequest(
  url,
  { method, headers, body, signal },
  { xhrFactory, onUploadProgress, onUploadComplete, onUploadStart },
) {
  return new Promise((resolve, reject) => {
    const xhr = xhrFactory()
    const abort = () => xhr.abort()
    const cleanup = () => signal?.removeEventListener('abort', abort)
    xhr.open(method, url)
    xhr.withCredentials = false // Same-origin cookies still apply, matching fetch credentials: same-origin.
    for (const [key, value] of Object.entries(headers)) xhr.setRequestHeader(key, value)
    xhr.upload.onprogress = (event) =>
      onUploadProgress?.({
        loaded: event.loaded,
        total: event.lengthComputable ? event.total : null,
      })
    xhr.upload.onload = () => onUploadComplete?.()
    xhr.onload = () => {
      cleanup()
      resolve({
        ok: xhr.status >= 200 && xhr.status < 300,
        status: xhr.status,
        headers: { get: (name) => xhr.getResponseHeader(name) },
        json: async () => JSON.parse(xhr.responseText),
      })
    }
    xhr.onerror = () => {
      cleanup()
      reject(new Error('upload_failed'))
    }
    xhr.onabort = () => {
      cleanup()
      reject(new DOMException('Cancelled', 'AbortError'))
    }
    signal?.addEventListener('abort', abort, { once: true })
    if (signal?.aborted) {
      cleanup()
      reject(signal.reason)
      return
    }
    onUploadStart?.()
    xhr.send(body)
  })
}

export function createApiClient({
  fetchImpl = (...args) => fetch(...args),
  baseUrl = '',
  timeoutMs = 20000,
  securityFlow = stepUp,
  xhrFactory = () => new XMLHttpRequest(),
} = {}) {
  let csrf = null
  let csrfRequest = null
  const sensitiveRequests = new Map()
  function request(path, options = {}) {
    const method = options.method || 'GET'
    const key =
      options.securityRetry !== false &&
      isSensitiveMutation(path, method) &&
      !(options.body instanceof FormData)
        ? JSON.stringify([path, method, options.body, options.headers])
        : null
    if (key && sensitiveRequests.has(key)) return sensitiveRequests.get(key)
    const operation = send(path, options)
    if (!key) return operation
    const tracked = operation.finally(() => sensitiveRequests.delete(key))
    sensitiveRequests.set(key, tracked)
    return tracked
  }
  async function send(
    path,
    {
      method = 'GET',
      body,
      headers: requestHeaders = {},
      responseType = 'json',
      timeoutMs: requestTimeoutMs = timeoutMs,
      signal: externalSignal,
      securityRetry = true,
      onUploadProgress,
      onUploadComplete,
      onUploadStart,
      onSecurityRequired,
    } = {},
  ) {
    externalSignal?.throwIfAborted()
    // Snapshot before confirmation so edits during a native prompt cannot alter the saved intent.
    if (body && !(body instanceof FormData)) body = JSON.parse(JSON.stringify(body))
    if (securityRetry) {
      const confirmation = securityFlow.preflight(path, method)
      if (confirmation) onSecurityRequired?.()
      if (confirmation && !(await waitForRequest(confirmation, externalSignal)))
        throw new ApiError(
          423,
          t('security.confirmationCancelled'),
          {},
          'security_confirmation_cancelled',
        )
    }
    if (method !== 'GET' && !csrf) {
      csrfRequest ||= request('/api/csrf')
        .then((data) => (csrf = data.token))
        .finally(() => {
          csrfRequest = null
        })
      await waitForRequest(csrfRequest, externalSignal)
    }
    externalSignal?.throwIfAborted()
    const multipart = body instanceof FormData
    let response
    let data
    const controller = new AbortController()
    const abortExternal = () => controller.abort(externalSignal.reason)
    externalSignal?.addEventListener('abort', abortExternal, { once: true })
    const hasFiles = multipart && [...body.values()].some((value) => value instanceof Blob)
    const timer = setTimeout(
      () => controller.abort(),
      hasFiles || responseType === 'blob' ? Math.max(requestTimeoutMs, 120000) : requestTimeoutMs,
    )
    try {
      const transport =
        multipart && onUploadProgress
          ? (url, options) =>
              uploadRequest(url, options, {
                xhrFactory,
                onUploadProgress,
                onUploadComplete,
                onUploadStart,
              })
          : fetchImpl
      response = await transport(`${baseUrl}${path}`, {
        method,
        credentials: 'same-origin',
        signal: controller.signal,
        headers: {
          ...Object.fromEntries(
            Object.entries(requestHeaders).filter(
              ([name]) =>
                !['accept', 'accept-language', 'content-type', 'x-csrf-token'].includes(
                  name.toLowerCase(),
                ),
            ),
          ),
          Accept: 'application/json',
          'Accept-Language': locale.value,
          ...(body && !multipart ? { 'Content-Type': 'application/json' } : {}),
          ...(method !== 'GET' ? { 'X-CSRF-TOKEN': csrf } : {}),
        },
        ...(body ? { body: multipart ? body : JSON.stringify(body) } : {}),
      })
      if (response.ok && responseType === 'blob') {
        const blob = await response.blob()
        const disposition = response.headers.get('Content-Disposition') || ''
        const filename = disposition.match(
          /filename="?([a-zA-Z0-9_-]+\.(?:pdf|docx))"?(?:;|$)/,
        )?.[1]
        return { blob, filename: filename || 'project-specification' }
      }
      data = await response.json().catch((error) => {
        if (controller.signal.aborted) throw error
        return {}
      })
    } catch {
      if (externalSignal?.aborted)
        throw externalSignal.reason || new DOMException('Cancelled', 'AbortError')
      throw new ApiError(
        0,
        t('ui.weCouldNotConnectToTheServerCheck'),
        {},
        controller.signal.aborted ? 'timeout' : null,
      )
    } finally {
      clearTimeout(timer)
      externalSignal?.removeEventListener('abort', abortExternal)
    }
    securityFlow.observe(path, method, data, response.status)
    if (
      response.status === 423 &&
      data.code === 'security_step_up_required' &&
      securityRetry &&
      !/^\/api\/security\/(?:confirm|challenge|passkey\/(?:login|confirm)(?:\/|$))/.test(path)
    ) {
      onSecurityRequired?.()
      if (await waitForRequest(securityFlow.ensure(true), externalSignal)) {
        csrf = null
        if (
          data.mutationNotStarted === true &&
          (isSensitiveMutation(path, method) || method === 'GET')
        )
          return request(path, {
            method,
            body,
            headers: requestHeaders,
            responseType,
            timeoutMs: requestTimeoutMs,
            signal: externalSignal,
            securityRetry: false,
            onUploadProgress,
            onUploadComplete,
            onUploadStart,
            onSecurityRequired,
          })
        throw new ApiError(423, t('security.retryAction'), {}, 'security_retry_required')
      }
      throw new ApiError(
        423,
        t('security.confirmationCancelled'),
        {},
        'security_confirmation_cancelled',
      )
    }
    if (data.code === 'administrator_agreement_required')
      globalThis.window?.dispatchEvent(new Event('administrator-agreement-required'))
    if (!response.ok) {
      if ([401, 419].includes(response.status)) csrf = null
      const messages = {
        400: t('ui.checkTheFields'),
        401: t('ui.yourSessionHasEndedSignInAgainOr'),
        403: t('ui.thisActionIsUnavailableDiscussChangesInThe'),
        404: t('ui.thisRecordWasNotFoundOrIsUnavailable'),
        413: t('commentFiles.serverLimit'),
        419: t('ui.yourSessionHasChangedTryThisActionAgain'),
        422: t('ui.checkTheFields'),
        429: t('ui.tooManyRequestsPleaseWaitALittle'),
      }
      const error = new ApiError(
        response.status,
        messages[response.status] || t('ui.weCouldNotCompleteThisActionPleaseTry'),
        data.errors || {},
        data.code || null,
      )
      if (typeof data.diagnosticId === 'string' && /^[a-f0-9-]{36}$/.test(data.diagnosticId))
        error.diagnosticId = data.diagnosticId
      if (typeof data.stage === 'string' && /^[a-z_]{1,32}$/.test(data.stage))
        error.stage = data.stage
      throw error
    }
    return data
  }
  return {
    request,
    resetCsrf: () => {
      csrf = null
    },
  }
}
export const api = createApiClient({ baseUrl: import.meta.env?.VITE_API_BASE_URL || '' })
export const API_MODE = !!import.meta.env?.PROD || import.meta.env?.VITE_DATA_SOURCE === 'api'

export function commentBody(text, attachments = [], submissionId) {
  if (!attachments.length && !submissionId) return { text: text.trim() }
  const form = new FormData()
  form.append('text', text.trim())
  if (submissionId) form.append('submissionId', submissionId)
  for (const item of attachments) {
    if (!(item.file instanceof Blob)) throw new Error(t('ui.chooseTheFilesAgainBeforeSubmitting'))
    form.append('attachments[]', item.file, item.name)
  }
  return form
}

export function taskBody(input) {
  const form = new FormData()
  for (const key of ['title', 'location', 'description', 'expectedResult', 'priority'])
    form.append(key, input[key] || '')
  if (typeof input.section === 'string' && input.section.trim())
    form.append('section', input.section)
  for (const attachment of input.attachments || []) {
    if (!(attachment.file instanceof Blob))
      throw new Error(t('ui.chooseTheFilesAgainBeforeSubmitting'))
    form.append('attachments[]', attachment.file, attachment.name)
  }
  return form
}
