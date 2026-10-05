export class ApiError extends Error {
  constructor(status, message, errors = {}) {
    super(message)
    this.status = status
    this.errors = errors
  }
}

export function createApiClient({ fetchImpl = (...args) => fetch(...args), baseUrl = '' } = {}) {
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
    try {
      response = await fetchImpl(`${baseUrl}${path}`, {
        method,
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          ...(body && !multipart ? { 'Content-Type': 'application/json' } : {}),
          ...(method !== 'GET' ? { 'X-CSRF-TOKEN': csrf } : {}),
        },
        ...(body ? { body: multipart ? body : JSON.stringify(body) } : {}),
      })
    } catch {
      throw new ApiError(0, 'Нет связи с сервером. Проверьте подключение и повторите попытку.')
    }
    const data = await response.json().catch(() => ({}))
    if (!response.ok) {
      if ([401, 419].includes(response.status)) csrf = null
      const messages = {
        401: 'Сессия завершена. Войдите снова или откройте ссылку клиента.',
        403: 'Это действие недоступно. Обсудите изменения в комментариях.',
        404: 'Запись не найдена или недоступна.',
        419: 'Сессия обновилась. Повторите действие.',
        422: 'Проверьте заполненные поля.',
        429: 'Слишком много запросов. Подождите немного.',
      }
      throw new ApiError(
        response.status,
        messages[response.status] || 'Не удалось выполнить действие. Повторите попытку.',
        data.errors || {},
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
  for (const attachment of input.attachments || []) {
    if (!(attachment.file instanceof Blob))
      throw new Error('Выберите файлы ещё раз перед отправкой.')
    form.append('attachments[]', attachment.file, attachment.name)
  }
  return form
}
