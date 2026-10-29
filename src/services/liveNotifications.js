import { toast } from './toast.js'
import { t } from '../i18n/index.js'
import { STATUSES } from '../constants/project.js'

export function createLiveNotifications({ store = toast, navigate, env = globalThis } = {}) {
  let identity = null
  const seen = new Set()
  const clear = () => {
    for (const item of store.items.value)
      if (item.group === 'project-events') store.dismiss(item.id)
  }
  function start(key) {
    if (identity === key) return
    clear()
    seen.clear()
    identity = key
  }
  function show(events = [], additional = 0) {
    if (!identity || env.document?.visibilityState === 'hidden') return
    const key = 'projectbrief.toast-receipts:' + identity
    let receipts = []
    try {
      receipts = JSON.parse(env.localStorage?.getItem(key) || '[]')
    } catch {}
    const known = new Set(Array.isArray(receipts) ? receipts : [])
    const fresh = events.filter((event) => {
      if (!event?.id || seen.has(event.id) || known.has(event.id)) return false
      seen.add(event.id)
      known.add(event.id)
      return true
    })
    while (seen.size > 1000) seen.delete(seen.values().next().value)
    try {
      env.localStorage?.setItem(key, JSON.stringify([...known].slice(-1000)))
    } catch {}
    if (!fresh.length) return
    clear()
    for (const event of fresh.slice(0, 2)) {
      const status = (value) => STATUSES[value]?.label || value || ''
      store.info(
        () =>
          t(
            event.kind === 'created'
              ? 'liveEvents.task'
              : event.kind === 'comment'
                ? 'liveEvents.comment'
                : 'liveEvents.status',
            { author: event.author || t('liveEvents.participant') },
          ),
        {
          group: 'project-events',
          duration: 9000,
          details:
            event.kind === 'created'
              ? [event.project, event.task]
              : event.kind === 'comment'
                ? [event.task || event.project, event.preview]
                : [
                    event.task,
                    () => [status(event.old), status(event.status)].filter(Boolean).join(' → '),
                  ],
          action: () => navigate(event.url),
          actionLabel: () => t('liveEvents.open'),
        },
      )
    }
    const rest = fresh.length - 2 + additional
    if (rest > 0)
      store.info(() => t('liveEvents.more', { count: rest }), {
        group: 'project-events',
        duration: 9000,
        action: () => navigate(fresh[2]?.url || fresh[0].url),
        actionLabel: () => t('liveEvents.open'),
      })
  }
  return { start, show, clear }
}
