// Presentation only: kinds never grant permissions or start an executor.
export const agentIcon = (kind) =>
  ({ ai: 'bot', automation: 'settings', service: 'globe' })[kind] || 'bot'

export const agentTimestamp = (value) =>
  value.includes('T') ? value : value.replace(' ', 'T') + 'Z'
export const usableAgentToken = (token, now = Date.now()) =>
  !token.revokedAt && (!token.expiresAt || Date.parse(agentTimestamp(token.expiresAt)) > now)
export function agentCredentials(agent, now = Date.now()) {
  if (!Array.isArray(agent.tokens)) return null
  return agent.tokens.some((token) => usableAgentToken(token, now)) ? 'issued' : 'missing'
}

export function latestAgentReport(task, assignment) {
  const report = assignment.reports?.at(-1)
  if (!report) return null
  const comment = task.comments?.find((item) => item.agentReport?.id === report.id)
  return {
    ...report,
    ...(report.createdAt
      ? {
          createdAt: report.createdAt.includes('T')
            ? report.createdAt
            : report.createdAt.replace(' ', 'T') + 'Z',
        }
      : {}),
    commentId: comment?.id || null,
    teamOnly: comment?.teamOnly || false,
    summary: comment?.text || '',
  }
}
