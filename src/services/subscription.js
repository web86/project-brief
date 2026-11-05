import { intlLocale, locale, t } from '../i18n/index.js'

export const quotaGroups = {
  projects: 'projects.active.max',
  clients: 'clients.max',
  developers: 'developers.max',
  agents: 'agents.active.max',
}
export const selectionGroups = [...Object.keys(quotaGroups), 'invitations']
export const formatLimit = (limit) =>
  limit?.kind === 'unlimited' ? t('preparation.unlimited') : (limit?.value ?? '—')
export const planName = (plan) =>
  plan?.translations?.[locale.value]?.name || plan?.translations?.en?.name || '—'

export function subscriptionDate(value) {
  return value
    ? new Intl.DateTimeFormat(intlLocale(), { dateStyle: 'medium', timeStyle: 'short' }).format(
        new Date(value),
      )
    : t('manualSubscription.noEnd')
}

export function eligibleResource(group, row, projects) {
  if (!row.id) return false
  if (group === 'clients' || group === 'agents')
    return row.projectIds.some((id) => projects.includes(id))
  if (group === 'invitations')
    return row.eligible && row.projectIds.every((id) => projects.includes(id))
  return true
}

export function selectedCounts(resources, selection) {
  const seats = new Set()
  for (const group of ['developers', 'invitations'])
    for (const row of resources[group])
      if (selection[group].includes(row.id)) seats.add(row.seatKey || row.id)
  return {
    'projects.active.max': selection.projects.length,
    'clients.max': selection.clients.length,
    'developers.max': seats.size,
    'agents.active.max': selection.agents.length,
  }
}

export const exceedsLimit = (count, limit) => limit?.kind === 'integer' && count > limit.value

export function suggestedSelection(resources, limits) {
  const selection = Object.fromEntries(selectionGroups.map((group) => [group, []]))
  const seats = new Set()
  for (const group of selectionGroups) {
    const rows = resources[group].filter((row) => eligibleResource(group, row, selection.projects))
    const usesSeat = group === 'developers' || group === 'invitations'
    if (group === 'developers') rows.sort((a, b) => Number(b.owner) - Number(a.owner))
    for (const row of rows) {
      const seat = row.seatKey || row.id
      const count = usesSeat ? seats.size + Number(!seats.has(seat)) : selection[group].length + 1
      const limit = limits[usesSeat ? quotaGroups.developers : quotaGroups[group]]
      if (row.owner || !exceedsLimit(count, limit)) {
        selection[group].push(row.id)
        if (usesSeat) seats.add(seat)
      }
    }
  }
  return selection
}
