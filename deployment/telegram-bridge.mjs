// Independent Telegram service. No AI configuration, routes or credentials are read.
export function createTelegramHandler({ env, fetch: request = fetch }) {
  const json = (body, status = 200) =>
    Response.json(body, { status, headers: { 'Cache-Control': 'no-store' } })
  return async (req) => {
    if (new URL(req.url).pathname === '/telegram/diagnostics')
      return telegramDiagnostics(req, env, request, json)
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

// Temporary opt-in probe. Targets, methods and payloads are never supplied by callers.
async function telegramDiagnostics(req, env, request, json) {
  if (env('TELEGRAM_DIAGNOSTICS') !== '1' || req.method !== 'POST') return json({ ok: false }, 404)
  const secret = env('TELEGRAM_BRIDGE_SECRET') || ''
  if (secret.length < 32 || req.headers.get('Authorization') !== `Bearer ${secret}`)
    return json({ ok: false }, 401)
  if (new URL(req.url).search) return json({ ok: false }, 400)
  try {
    const reader = req.body?.getReader()
    const decoder = new TextDecoder('utf-8', { fatal: true })
    let bytes = 0,
      body = ''
    if (reader) {
      while (true) {
        const { value, done } = await reader.read()
        if (done) break
        bytes += value.byteLength
        if (bytes > 1024) {
          await reader.cancel()
          return json({ ok: false }, 413)
        }
        body += decoder.decode(value, { stream: true })
      }
      body += decoder.decode()
    }
    const input = body.trim() ? JSON.parse(body) : {}
    if (!input || typeof input !== 'object' || Array.isArray(input) || Object.keys(input).length)
      return json({ ok: false }, 400)
  } catch {
    return json({ ok: false }, 400)
  }

  const checks = await Promise.all(
    [
      ['health', 'GET', 'https://brief.web86.site/api/health'],
      ['webhook', 'POST', 'https://brief.web86.site/api/telegram/webhook'],
    ].map(async ([name, method, url]) => {
      const started = performance.now()
      try {
        const response = await request(url, {
          method,
          redirect: 'manual',
          signal: AbortSignal.timeout(8000),
          ...(method === 'POST'
            ? { headers: { 'Content-Type': 'application/json' }, body: '{}' }
            : {}),
        })
        const durationMs = Math.round(performance.now() - started)
        // Cancel the stream without reading the response body or returning headers.
        void response.body?.cancel().catch(() => {})
        return { name, status: response.status, durationMs, errorCode: null }
      } catch (error) {
        return {
          name,
          status: null,
          durationMs: Math.round(performance.now() - started),
          errorCode: diagnosticError(error),
        }
      }
    }),
  )
  return json({ ok: checks[0].status === 200 && checks[1].status === 403, checks })
}

function diagnosticError(error) {
  if (['TimeoutError', 'AbortError'].includes(error?.name)) return 'timeout'
  const code = String(error?.cause?.code || error?.code || '')
  const detail = String(error?.message || '')
  if (/ETIMEDOUT|UND_ERR_CONNECT_TIMEOUT/.test(code)) return 'timeout'
  if (/CERT|TLS|SSL/i.test(code) || /certificate|tls handshake/i.test(detail)) return 'tls_error'
  if (/ENOTFOUND|EAI_AGAIN/.test(code) || /dns|name resolution/i.test(detail)) return 'dns_error'
  if (/ECONN|ENETUNREACH|EHOSTUNREACH/.test(code)) return 'connection_error'
  return 'network_error'
}
