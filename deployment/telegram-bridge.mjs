// Independent Telegram service. No AI configuration, routes or credentials are read.
export function createTelegramHandler({ env, fetch: request = fetch }) {
  const json = (body, status = 200) =>
    Response.json(body, { status, headers: { 'Cache-Control': 'no-store' } })
  return async (req) => {
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
