import { t } from '../i18n/index.js'
export class ApiError extends Error {
  constructor(status, message, errors = {}, code = null) {
    super(message)
    this.status = status
    this.errors = errors
    this.code = code
  }
}

export function createApiClient({
  fetchImpl = (...args) => fetch(...args),
  baseUrl = '',
  timeoutMs = 20000,
} = {}) {
  let csrf = null
  let csrfRequest = null
  async function request(path, { method = 'GET', body } = {}) {
    if (method !== 'GET' && !csrf) {
      csrfRequest ||= request('/api/csrf')
        .then((data) => (csrf = data.token))
        .finally(() => {
          csrfRequest = null
        })
      await csrfRequest
    }
    const multipart = body instanceof FormData
    let response
    let data
    const controller = new AbortController()
    const hasFiles = multipart && [...body.values()].some((value) => value instanceof Blob)
    const timer = setTimeout(
      () => controller.abort(),
      hasFiles ? Math.max(timeoutMs, 120000) : timeoutMs,
    )
    try {
      response = await fetchImpl(`${baseUrl}${path}`, {
        method,
        credentials: 'same-origin',
        signal: controller.signal,
        headers: {
          Accept: 'application/json',
          ...(body && !multipart ? { 'Content-Type': 'application/json' } : {}),
          ...(method !== 'GET' ? { 'X-CSRF-TOKEN': csrf } : {}),
        },
        ...(body ? { body: multipart ? body : JSON.stringify(body) } : {}),
      })
      data = await response.json().catch((error) => {
        if (controller.signal.aborted) throw error
        return {}
      })
    } catch {
      throw new ApiError(
        0,
        t('ui.weCouldNotConnectToTheServerCheck'),
        {},
        controller.signal.aborted ? 'timeout' : null,
      )
    } finally {
      clearTimeout(timer)
    }
    if (!response.ok) {
      if ([401, 419].includes(response.status)) csrf = null
      const messages = {
        401: t('ui.yourSessionHasEndedSignInAgainOr'),
        403: t('ui.thisActionIsUnavailableDiscussChangesInThe'),
        404: t('ui.thisRecordWasNotFoundOrIsUnavailable'),
        419: t('ui.yourSessionHasChangedTryThisActionAgain'),
        422: t('ui.checkTheFields'),
        429: t('ui.tooManyRequestsPleaseWaitALittle'),
      }
      throw new ApiError(
        response.status,
        messages[response.status] || t('ui.weCouldNotCompleteThisActionPleaseTry'),
        data.errors || {},
        data.code || null,
      )
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
