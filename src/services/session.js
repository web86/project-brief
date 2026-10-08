export async function appDestination(request) {
  const session = await request('/api/session')
  if (session.authenticated && session.type === 'admin') return '/admin'
  if (
    session.authenticated &&
    session.type === 'client' &&
    /^[a-f0-9-]{36}$/.test(session.project?.id || '')
  )
    return `/project/${session.project.id}`
  return null
}
