import { t } from '../i18n/index.js'
import { STATUSES } from '../constants/project.js'
const known = new Set([
  'created',
  'updated',
  'comment',
  'approved',
  'status',
  'developer',
  'estimate',
  'price',
  'developer_notes',
  'attachment',
  'section_moved',
  'reordered',
])
export function historyText(event) {
  if (event.actorType === 'agent' && event.type.startsWith('agent_'))
    return t('agents.history.' + event.type, { name: event.actorName || t('agents.kind.ai') })
  if (['trashed', 'restored'].includes(event.type)) return t(`management.${event.type}`)
  if (!known.has(event.type)) return event.text || t('flow.unknownHistory')
  const actor =
    event.actorName ||
    t(
      event.actorType === 'developer' ||
        [
          'developer',
          'estimate',
          'price',
          'developer_notes',
          'section_moved',
          'reordered',
        ].includes(event.type)
        ? 'ui.developer'
        : 'ui.client',
    )
  if (event.type === 'attachment' && event.fileName)
    return t('flow.attachmentNamed', { actor, name: event.fileName })
  return t(`flow.${event.type}`, {
    actor,
    old: STATUSES[event.oldValue]?.label || event.oldValue || '—',
    new: STATUSES[event.newValue]?.label || event.newValue || '—',
  })
}
