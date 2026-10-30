export async function appDestination(request) {
  const session = await request('/api/session')
  if (['pending_2fa', 'enrollment_required'].includes(session.state)) return '/admin/security'
  if (session.authenticated && session.agreementRequired) return '/admin/agreement'
  if (session.authenticated && session.type === 'platform') return '/platform'
  if (session.authenticated && session.type === 'admin')
    return session.securityRecommended ? '/admin/security' : '/admin'
  if (
    session.authenticated &&
    session.type === 'client' &&
    /^[a-f0-9-]{36}$/.test(session.project?.id || '')
  )
    return `/project/${session.project.id}`
  return null
}
