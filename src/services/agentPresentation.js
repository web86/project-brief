// Presentation only: kinds never grant permissions or start an executor.
export const agentIcon = (kind) =>
  ({ ai: 'bot', automation: 'settings', service: 'globe' })[kind] || 'bot'

export function agentCredentials(agent, now = Date.now()) {
  if (!Array.isArray(agent.tokens)) return null
  const valid = agent.tokens.some(
    (token) =>
      !token.revokedAt &&
      (!token.expiresAt ||
        Date.parse(
          token.expiresAt.includes('T') ? token.expiresAt : token.expiresAt.replace(' ', 'T') + 'Z',
        ) > now),
  )
  return valid ? 'issued' : 'missing'
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
    summary: comment?.text || '',
  }
}
