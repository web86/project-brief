// The static build serves these read contracts from immutable, role-specific snapshots.
// No API URL is ever passed to fetch, including unknown routes and all mutations.
export function createStaticDemoClient({
  ApiError,
  fetchImpl = (...args) => fetch(...args),
  storage = globalThis.sessionStorage,
  pathname = () => globalThis.location?.pathname || '/demo',
} = {}) {
  let manifestPromise
  const snapshots = new Map()
  const fail = (status, code) => {
    throw new ApiError(
      status,
      code === 'demo_read_only'
        ? 'Demo · View only. Changes are unavailable.'
        : 'This page is unavailable in the demo.',
      {},
      code,
    )
  }
  async function json(file, manifest = false) {
    const response = await fetchImpl('/' + file, {
      credentials: 'omit',
      cache: manifest ? 'no-store' : 'default',
    })
    if (!response.ok) fail(503, 'demo_snapshot_unavailable')
    return response.json()
  }
  async function inventory() {
    manifestPromise ||= json('manifest.json', true).then((value) => {
      if (value.schemaVersion !== 1 || value.dataset !== 'harborline-v1')
        fail(503, 'demo_schema_unsupported')
      return value
    })
    return manifestPromise
  }
  function persona() {
    // A direct URL always establishes its existing product role; shared settings retain the choice.
    if (pathname().startsWith('/admin')) return 'developer'
    if (pathname().startsWith('/project/')) return 'client'
    return storage?.getItem('projectbrief.staticDemo.role') === 'client' ? 'client' : 'developer'
  }
  async function request(path, { method = 'GET', signal } = {}) {
    if (!['GET', 'HEAD'].includes(method.toUpperCase())) fail(403, 'demo_read_only')
    signal?.throwIfAborted()
    if (!/^\/api\//.test(path) || path.startsWith('//') || /[\\#]/.test(path))
      fail(404, 'demo_route_unavailable')
    const url = new URL(path, 'https://static-demo.invalid')
    if (url.origin !== 'https://static-demo.invalid') fail(404, 'demo_route_unavailable')
    if (/^\/api\/security\/passkey\//.test(url.pathname)) fail(403, 'demo_read_only')
    const manifest = await inventory()
    if (url.pathname === '/api/demo/status') return { enabled: true, readOnly: true }
    const entering = /^\/api\/demo\/enter\/(developer|client)$/.exec(url.pathname)
    if (entering) {
      storage?.setItem('projectbrief.staticDemo.role', entering[1])
      return {
        destination: entering[1] === 'developer' ? '/admin' : '/project/' + manifest.clientProject,
      }
    }
    if (url.pathname === '/api/csrf') fail(403, 'demo_read_only')
    const descriptor = manifest.roles[persona()].routes[url.pathname]
    if (!descriptor)
      fail(url.pathname.startsWith('/api/platform/') ? 403 : 404, 'demo_route_unavailable')
    if (!/^snapshots\/(developer|client)-[a-f0-9]{64}\.json$/.test(descriptor.file))
      fail(503, 'demo_snapshot_unavailable')
    if (!snapshots.has(descriptor.file)) snapshots.set(descriptor.file, json(descriptor.file))
    const value = structuredClone(await snapshots.get(descriptor.file))
    signal?.throwIfAborted()
    if (descriptor.task) {
      const task = value.data.find((row) => row.id === descriptor.task)
      if (!task) fail(404, 'demo_route_unavailable')
      return { data: task }
    }
    return querySnapshot(url.pathname, value, url.searchParams)
  }
  return { request, resetCsrf() {} }
}

export function querySnapshot(path, value, params) {
  if (!Array.isArray(value.data)) return value
  let rows = value.data
  const projects = path === '/api/admin/projects'
  const requests = path === '/api/admin/project-requests'
  const reviews = path === '/api/admin/reviews'
  const search = (params.get(projects ? 'q' : 'search') || '').trim().toLowerCase()
  if (search && (!projects || [...search].length >= 3))
    rows = rows.filter((row) =>
      JSON.stringify(
        projects ? [row.name, row.clients] : [row.client, row.project, row.externalId, row.source],
      )
        .toLowerCase()
        .includes(search),
    )
  const status = params.get('status')
  if ((projects || requests) && status) rows = rows.filter((row) => row.status === status)
  if (reviews) {
    const from = params.get('from'),
      to = params.get('to')
    rows = rows.filter(
      (row) =>
        (!from || row.submittedAt.slice(0, 10) >= from) &&
        (!to || row.submittedAt.slice(0, 10) <= to),
    )
  }
  const sort = params.get('sort')
  if (projects) {
    rows.sort((a, b) =>
      sort === 'name'
        ? a.name.localeCompare(b.name)
        : String(b[sort === 'created' ? 'createdAt' : 'updatedAt']).localeCompare(
            String(a[sort === 'created' ? 'createdAt' : 'updatedAt']),
          ),
    )
  } else if (requests)
    rows.sort((a, b) => (sort === 'asc' ? 1 : -1) * a.createdAt.localeCompare(b.createdAt))
  if (!value.meta) return { ...value, data: rows }
  const perPage = projects ? 20 : Math.max(1, Math.min(100, Number(params.get('perPage')) || 25))
  const total = rows.length,
    last = Math.max(1, Math.ceil(total / perPage))
  const page = Math.max(1, Math.min(last, Number.parseInt(params.get('page')) || 1))
  const meta = { ...value.meta, total }
  if ('current_page' in meta)
    Object.assign(meta, {
      current_page: page,
      last_page: last,
      per_page: perPage,
      from: total ? (page - 1) * perPage + 1 : null,
      to: Math.min(page * perPage, total),
      links: [],
    })
  else Object.assign(meta, { page, lastPage: last })
  return {
    ...value,
    data: rows.slice((page - 1) * perPage, page * perPage),
    meta,
    ...(value.links ? { links: {} } : {}),
  }
}
