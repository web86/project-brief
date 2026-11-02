import assert from 'node:assert/strict'
import { readFile, readdir } from 'node:fs/promises'
import { createHash } from 'node:crypto'
import path from 'node:path'
import { contractKey, validateSchema } from './static-demo-schema.mjs'
const sha = (bytes) => createHash('sha256').update(bytes).digest('hex')
const uuid = /^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/i
const denied =
  /^(?:password|password_hash|two_factor_secret|two_factor_recovery_codes|remember_token|session_token|private_key|token_hash|secret|credential|credential_id|recoveryCodes|setupSecret|app_key)$/i
const clientDenied =
  /(?:agent|developerNotes|estimateHours|price|teamOnly|internalCost|scopes|webhooks|ssoEnabled|tokens|instructions)/i
export async function validateStaticDemo(directory) {
  const schema = JSON.parse(
    await readFile(new URL('../docs/demo/static/contracts-v1.schema.json', import.meta.url)),
  )
  const manifestBytes = await readFile(path.join(directory, 'manifest.json'))
  assert.equal(
    sha(manifestBytes),
    schema['x-reviewedManifestSha256'],
    'Reviewed public projection changed; review every changed snapshot before publishing',
  )
  const manifest = JSON.parse(manifestBytes)
  assert.equal(manifest.schemaVersion, 1)
  assert.equal(manifest.dataset, 'harborline-v1')
  assert.match(manifest.workspace, uuid)
  assert.match(manifest.clientProject, uuid)
  assert.deepEqual(Object.keys(manifest.roles).sort(), ['client', 'developer'])
  assert.equal(manifest.counts.projects, 10)
  assert.equal(manifest.counts.tasks, 150)
  assert.equal(manifest.counts.users, 8)
  const files = new Set(['manifest.json']),
    values = new Map()
  function scan(value, role, at = '$') {
    if (Array.isArray(value))
      return value.forEach((item, index) => scan(item, role, `${at}[${index}]`))
    if (value && typeof value === 'object') {
      for (const [key, item] of Object.entries(value)) {
        assert.ok(!denied.test(key), `Private field: ${at}.${key}`)
        if (role === 'client')
          assert.ok(!clientDenied.test(key), `Internal client field: ${at}.${key}`)
        scan(item, role, `${at}.${key}`)
      }
    }
    if (typeof value !== 'string') return
    assert.ok(
      !/(?:\$argon2|\$2y\$|BEGIN .*PRIVATE KEY|demo-unusable-signing-material|\/Users\/|\/demo-runtime\/|brief\.web86\.site\/api|base64:[A-Za-z0-9+/]{30}|pb_(?:live|secret)_)/i.test(
        value,
      ),
      `Private content at ${at}`,
    )
    for (const email of value.match(/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/gi) || [])
      assert.ok(email.endsWith('.test'), `Unexpected email at ${at}`)
    for (const link of value.match(/https?:\/\/[^\s"<>]+/g) || []) {
      const host = new URL(link).hostname
      assert.ok(
        host.endsWith('.test') || host === 'www.w3.org',
        `Unexpected host at ${at}: ${host}`,
      )
    }
    if (value.startsWith('/demo-assets/'))
      assert.ok(manifest.assets[value.slice(1)], `Missing asset at ${at}`)
  }
  for (const [role, state] of Object.entries(manifest.roles)) {
    for (const [endpoint, descriptor] of Object.entries(state.routes)) {
      assert.ok(endpoint.startsWith('/api/'))
      if (role === 'client')
        assert.ok(endpoint === '/api/session' || endpoint.startsWith('/api/client/'))
      assert.match(descriptor.file, new RegExp(`^snapshots/${role}-[a-f0-9]{64}\\.json$`))
      files.add(descriptor.file)
      if (!values.has(descriptor.file)) {
        const bytes = await readFile(path.join(directory, descriptor.file))
        assert.equal(sha(bytes), descriptor.sha256)
        const value = JSON.parse(bytes)
        scan(value, role)
        values.set(descriptor.file, value)
      }
      const snapshot = values.get(descriptor.file)
      const value = descriptor.task
        ? { data: snapshot.data.find((row) => row.id === descriptor.task) }
        : snapshot
      assert.ok(value.data !== undefined || !descriptor.task)
      const name = schema['x-contracts'][role][contractKey(endpoint)]
      assert.ok(name, `Unreviewed contract: ${role} ${endpoint}`)
      validateSchema(value, schema.$defs[name], `${role}:${endpoint}`)
    }
  }
  for (const [file, checksum] of Object.entries(manifest.assets)) {
    assert.match(file, /^demo-assets\/[a-f0-9]{64}\.(png|svg|txt|webp)$/)
    assert.equal(sha(await readFile(path.join(directory, file))), checksum)
    files.add(file)
  }
  const read = (role, endpoint) => values.get(manifest.roles[role].routes[endpoint].file)
  const projects = read('developer', '/api/admin/projects').data
  assert.equal(projects.length, 10)
  const taskIds = new Set(),
    sectionIds = new Set()
  for (const project of projects) {
    assert.match(project.id, uuid)
    const root = '/api/admin/projects/' + project.id
    const sections = read('developer', root + '/sections').data
    sections.forEach((section) => {
      assert.match(section.id, uuid)
      sectionIds.add(section.id)
    })
    const tasks = read('developer', root + '/tasks').data
    assert.equal(tasks.length, 15)
    for (const task of tasks) {
      assert.match(task.id, uuid)
      assert.ok(!taskIds.has(task.id))
      taskIds.add(task.id)
      assert.ok(
        sections.some((section) => section.id === task.sectionId),
        'Cross-project section reference',
      )
      assert.ok(Date.parse(task.createdAt) <= Date.parse(task.updatedAt))
      for (const item of [...task.comments, ...task.attachments]) assert.match(item.id, uuid)
      for (const event of task.history)
        assert.ok(Date.parse(event.createdAt) >= Date.parse(task.createdAt))
    }
  }
  assert.equal(taskIds.size, 150)
  const clientTasks = read('client', '/api/client/tasks').data
  const ownTasks = read(
    'developer',
    '/api/admin/projects/' + manifest.clientProject + '/tasks',
  ).data
  assert.equal(clientTasks.length, 15)
  for (const task of clientTasks) {
    assert.ok(ownTasks.some((row) => row.id === task.id))
    task.comments.forEach((comment) => assert.ok(['developer', 'client'].includes(comment.author)))
    task.history.forEach((event) => {
      assert.ok(
        [
          'created',
          'updated',
          'comment',
          'approved',
          'status',
          'attachment',
          'section_moved',
          'reordered',
          'restored',
        ].includes(event.type),
      )
      assert.ok(['developer', 'client', 'system'].includes(event.actorType))
    })
  }
  return {
    passed: true,
    schemaVersion: 1,
    contracts: Object.fromEntries(
      Object.entries(manifest.roles).map(([role, state]) => [
        role,
        Object.keys(state.routes).length,
      ]),
    ),
    projects: projects.length,
    tasks: taskIds.size,
    clientTasks: clientTasks.length,
    files: [...files].sort(),
    manifest,
  }
}
if (process.argv[1] === new URL(import.meta.url).pathname) {
  const result = await validateStaticDemo(
    path.resolve(process.argv[2] || '.tools/demo-runtime/static-export'),
  )
  const { files, manifest, ...report } = result
  console.log(JSON.stringify(report, null, 2))
}
