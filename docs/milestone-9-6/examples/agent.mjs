// Standalone Node.js 20.19+ example. No LLM or third-party dependencies.
import { createHash } from 'node:crypto'
import { realpathSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

export async function runAgent({
  baseUrl = process.env.PROJECT_BRIEF_URL,
  token = process.env.PROJECT_BRIEF_AGENT_TOKEN,
} = {}) {
  const base = new URL(baseUrl)
  if (
    base.username ||
    base.password ||
    base.search ||
    base.hash ||
    (base.protocol !== 'https:' &&
      !(base.protocol === 'http:' && ['localhost', '127.0.0.1', '[::1]'].includes(base.hostname)))
  )
    throw new Error('Use an HTTPS application origin (HTTP loopback is allowed for local testing).')
  if (!/^pb_[a-f0-9]{64}$/.test(token || ''))
    throw new Error('Set PROJECT_BRIEF_AGENT_TOKEN to the one-time bearer credential.')
  async function request(path, body, key) {
    const response = await fetch(new URL('/api/v1/agent/' + path, base), {
      method: body ? 'POST' : 'GET',
      redirect: 'error',
      signal: AbortSignal.timeout(15000),
      headers: {
        Accept: 'application/json',
        Authorization: 'Bearer ' + token,
        ...(body ? { 'Content-Type': 'application/json', 'Idempotency-Key': key } : {}),
      },
      ...(body ? { body: JSON.stringify(body) } : {}),
    })
    if (!response.ok) throw new Error(`Agent API rejected request (${response.status()}).`)
    return response.json()
  }
  const me = (await request('me')).data
  const results = []
  for (let page = 1; ; page++) {
    const list = await request('tasks?page=' + page)
    for (const item of list.data) {
      // Re-running this process is safe; assignment UUIDs change after unassign/reassign.
      if (['submitted', 'cancelled', 'failed'].includes(item.executionState)) continue
      const task = (await request('tasks/' + item.id)).data
      const key = 'example-v1-' + task.assignmentId
      const digest = createHash('sha256')
        .update(task.description || '')
        .digest('hex')
        .slice(0, 16)
      await request(
        'tasks/' + task.id + '/comments',
        {
          assignmentId: task.assignmentId,
          text: `Demo executor read the assigned description. SHA-256 prefix: ${digest}. Human review required.`,
        },
        key + '-comment',
      )
      const result = await request(
        'tasks/' + task.id + '/reports',
        {
          assignmentId: task.assignmentId,
          state: 'submitted',
          summary:
            'Demonstration completed: received assigned task through the documented API and returned this report. No application code was executed. Please review the result.',
        },
        key + '-report',
      )
      results.push({ taskId: task.id, reportId: result.data.id })
    }
    if (page >= list.meta.lastPage) break
    // At most 25 tasks per page. A hosted executor must additionally respect Retry-After/429.
  }
  return { agentId: me.id, results }
}

if (process.argv[1] && fileURLToPath(import.meta.url) === realpathSync(process.argv[1])) {
  try {
    process.stdout.write(JSON.stringify(await runAgent()) + '\n')
  } catch (error) {
    process.stderr.write(error.message + '\n')
    process.exitCode = 1
  }
}
