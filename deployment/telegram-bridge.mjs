// Independent Telegram service. No AI configuration, routes or credentials are read.
export function createTelegramHandler({ env, fetch: request = fetch, log = console.info }) {
  const json = (body, status = 200) =>
    Response.json(body, { status, headers: { 'Cache-Control': 'no-store' } })
  return async (req) => {
    if (new URL(req.url).pathname === '/telegram/webhook')
      return telegramWebhook(req, env, request, json, log)
    const secret = env('TELEGRAM_BRIDGE_SECRET') || ''
    if (new URL(req.url).pathname !== '/telegram/send' || req.method !== 'POST')
      return json({ ok: false }, 404)
    if (secret.length < 32 || req.headers.get('Authorization') !== `Bearer ${secret}`)
      return json({ ok: false }, 401)
    const token = env('TELEGRAM_BOT_TOKEN') || ''
    if (!/^[0-9]+:[A-Za-z0-9_-]{20,}$/.test(token)) return json({ ok: false, error_code: 503 }, 503)
    try {
      const reader = req.body?.getReader()
      if (!reader) return json({ ok: false }, 400)
      let bytes = 0,
        text = ''
      const decoder = new TextDecoder()
      while (true) {
        const { value, done } = await reader.read()
        if (done) break
        bytes += value.byteLength
        if (bytes > 24000) {
          await reader.cancel()
          return json({ ok: false }, 413)
        }
        text += decoder.decode(value, { stream: true })
      }
      text += decoder.decode()
      const { method, payload } = JSON.parse(text)
      if (
        !['sendMessage', 'getMe'].includes(method) ||
        !payload ||
        typeof payload !== 'object' ||
        Array.isArray(payload)
      )
        return json({ ok: false }, 400)
      const allowed =
        method === 'getMe'
          ? []
          : ['chat_id', 'text', 'parse_mode', 'link_preview_options', 'reply_markup']
      if (Object.keys(payload).some((key) => !allowed.includes(key)))
        return json({ ok: false }, 400)
      if (
        method === 'sendMessage' &&
        (!/^[1-9][0-9]{0,19}$/.test(String(payload.chat_id)) ||
          typeof payload.text !== 'string' ||
          [...payload.text].length > 20000 ||
          (payload.parse_mode && payload.parse_mode !== 'HTML'))
      )
        return json({ ok: false }, 400)
      const result = await request(`https://api.telegram.org/bot${token}/${method}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
        redirect: 'error',
        signal: AbortSignal.timeout(6000),
      })
      const data = await result.json()
      // Return no chat/user details or upstream descriptions, which can include private text.
      return json(
        {
          ok: result.ok && data.ok === true,
          ...(data.ok === true ? {} : { error_code: Number(data.error_code) || result.status }),
        },
        result.ok ? 200 : result.status,
      )
    } catch {
      return json({ ok: false, error_code: 503 }, 503)
    }
  }
}
if (import.meta.main && globalThis.Deno)
  Deno.serve(createTelegramHandler({ env: (name) => Deno.env.get(name) }))

// The callback is server configuration, pinned to this application's public HTTPS endpoint.
async function telegramWebhook(req, env, request, json, log) {
  if (req.method !== 'POST') return json({ ok: false }, 404)
  const secret = env('TELEGRAM_WEBHOOK_SECRET') || ''
  if (!/^[A-Za-z0-9_-]{32,256}$/.test(secret)) return json({ ok: false }, 503)
  if (req.headers.get('X-Telegram-Bot-Api-Secret-Token') !== secret) return json({ ok: false }, 401)
  const forwardSecret = env('TELEGRAM_FORWARD_SECRET') || ''
  const callback = env('TELEGRAM_LARAVEL_WEBHOOK_URL') || ''
  if (
    !/^[A-Za-z0-9_-]{32,256}$/.test(forwardSecret) ||
    forwardSecret === secret ||
    forwardSecret === env('TELEGRAM_BRIDGE_SECRET') ||
    callback !== 'https://brief.web86.site/api/telegram/webhook'
  )
    return json({ ok: false }, 503)
  if (new URL(req.url).search) return json({ ok: false }, 400)

  let body
  try {
    body = await boundedText(req.body, 16384)
    const update = JSON.parse(body)
    if (
      !update ||
      Array.isArray(update) ||
      !Number.isSafeInteger(update.update_id) ||
      update.update_id < 0
    )
      return json({ ok: false }, 400)
  } catch (error) {
    return json({ ok: false }, error instanceof RangeError ? 413 : 400)
  }

  const started = performance.now()
  const record = (status, errorCode = null) =>
    log({
      event: 'telegram_forward',
      status,
      durationMs: Math.round(performance.now() - started),
      errorCode,
    })
  try {
    const signal = AbortSignal.timeout(8000)
    const response = await request(callback, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Telegram-Forward-Secret': forwardSecret,
      },
      body,
      redirect: 'manual',
      signal,
    })
    if (!response.ok) {
      void response.body?.cancel().catch(() => {})
      record(response.status, 'laravel_http_error')
      return json({ ok: false }, response.status === 429 || response.status === 503 ? 503 : 502)
    }
    const data = JSON.parse(await boundedText(response.body, 1024, signal))
    if (data?.ok !== true) {
      record(response.status, 'laravel_invalid_ack')
      return json({ ok: false }, 502)
    }
    record(response.status)
    return json({ ok: true })
  } catch (error) {
    const timeout = ['TimeoutError', 'AbortError'].includes(error?.name)
    record(null, timeout ? 'timeout' : 'laravel_unavailable')
    return json({ ok: false }, timeout ? 504 : 502)
  }
}

async function boundedText(stream, limit, signal) {
  const reader = stream?.getReader()
  if (!reader) throw new SyntaxError()
  const cancel = () => {
    void reader.cancel().catch(() => {})
  }
  const decoder = new TextDecoder('utf-8', { fatal: true })
  let bytes = 0,
    text = ''
  try {
    signal?.throwIfAborted()
    signal?.addEventListener('abort', cancel, { once: true })
    while (true) {
      const { value, done } = await reader.read()
      signal?.throwIfAborted()
      if (done) break
      bytes += value.byteLength
      if (bytes > limit) {
        void reader.cancel().catch(() => {})
        throw new RangeError()
      }
      text += decoder.decode(value, { stream: true })
    }
    return text + decoder.decode()
  } catch (error) {
    cancel()
    throw error
  } finally {
    signal?.removeEventListener('abort', cancel)
    reader.releaseLock()
  }
}
