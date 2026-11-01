import test from 'node:test'
import assert from 'node:assert/strict'
import { executeRuns } from '../docs/agent-ux-shared-keys/examples/collaboration.mjs'
import { agentMessageBlocks } from '../src/utils/agentChat.js'

test('shared executor forwards the explicit identity on every operation and uses the exact frozen context', async () => {
  const context = {
    agentInstruction: 'AGENT',
    projectInstruction: 'PROJECT',
    task: { id: 'task', description: 'PUBLIC' },
    instruction: 'RUN',
    history: [{ author: 'human', text: 'PRIVATE-SAME-ASSIGNMENT' }],
  }
  const calls = []
  let generated = 0,
    replayed = false
  const fetchImpl = async (url, options) => {
    calls.push({ path: url.pathname, ...options })
    assert.equal(options.headers['X-ProjectBrief-Agent-ID'], 'explicit-agent')
    let body
    if (url.pathname.endsWith('/tasks'))
      body = { data: [{ id: 'task', assignmentId: 'assignment' }], meta: { lastPage: 1 } }
    else if (url.pathname.endsWith('/runs')) body = { data: [{ id: 'run', state: 'queued' }] }
    else if (url.pathname.endsWith('/start'))
      return Response.json(
        { data: { state: 'running' } },
        { headers: replayed ? { 'Idempotency-Replayed': 'true' } : {} },
      )
    else if (url.pathname.endsWith('/runs/run')) body = { data: { context } }
    else if (url.pathname.endsWith('/reports')) {
      assert.deepEqual(JSON.parse(options.body), {
        assignmentId: 'assignment',
        runId: 'run',
        state: 'submitted',
        summary: 'Reviewed synthetic task',
      })
      body = { data: { id: 'report' } }
    } else throw new Error('Unexpected operation')
    return Response.json(body)
  }
  const generate = async (input) => {
    generated++
    assert.deepEqual(input, { context, messages: context.history, runId: 'run' })
    return 'Reviewed synthetic task'
  }
  const options = {
    baseUrl: 'https://example.test',
    token: 'synthetic-test-secret',
    agentId: 'explicit-agent',
    generate,
    fetchImpl,
  }
  assert.equal((await executeRuns(options))[0].state, 'submitted')
  replayed = true
  assert.equal((await executeRuns(options))[0].state, 'already_claimed')
  assert.equal(generated, 1)
  assert.equal(calls.filter((call) => call.path.endsWith('/reports')).length, 1)
})

test('legacy individual executors do not need the new identity header', async () => {
  const fetchImpl = async (_url, options) => {
    assert.ok(!('X-ProjectBrief-Agent-ID' in options.headers))
    return Response.json({ data: [], meta: { lastPage: 1 } })
  }
  assert.deepEqual(
    await executeRuns({
      baseUrl: 'https://example.test',
      token: 'synthetic-secret',
      generate: async () => {
        throw new Error('No work expected')
      },
      fetchImpl,
    }),
    [],
  )
})

test('chat formatting retains paragraphs and lists with safe links and literal untrusted text', () => {
  const blocks = agentMessageBlocks(
    'Paragraph\n\n- Item\n1. Criterion\n[Spec](https://example.test/spec)\n<script>alert(1)</script> javascript:alert(1)',
  )
  assert.deepEqual(
    blocks.map((block) => block.type),
    ['paragraph', 'paragraph', 'bullet', 'number', 'paragraph', 'paragraph'],
  )
  assert.equal(blocks[4].content[0].href, 'https://example.test/spec')
  assert.equal(blocks[5].content[0].text, '<script>alert(1)</script> javascript:alert(1)')
  assert.equal(blocks[5].content[0].href, undefined)
})

test('optional text-file reading uses only current-run authorized metadata and stops on revoked access', async () => {
  const fileId = '01a00000-0000-7000-8000-000000000001'
  let generated = 0,
    denied = false
  const fetchImpl = async (url, options) => {
    assert.equal(options.headers['X-ProjectBrief-Agent-ID'], 'agent')
    if (url.pathname.endsWith('/tasks'))
      return Response.json({
        data: [{ id: 'task', assignmentId: 'assignment' }],
        meta: { lastPage: 1 },
      })
    if (url.pathname.endsWith('/runs'))
      return Response.json({ data: [{ id: 'run', state: 'queued' }] })
    if (url.pathname.endsWith('/start')) return Response.json({ data: { state: 'running' } })
    if (url.pathname.endsWith('/runs/run'))
      return Response.json({ data: { context: { task: { title: 'Synthetic' }, history: [] } } })
    if (url.pathname.endsWith('/messages'))
      return Response.json({
        data: [
          { runId: 'old-run', attachments: [{ id: 'old', type: 'text/plain', size: 1 }] },
          {
            runId: 'run',
            attachments: [{ id: fileId, name: 'brief.txt', type: 'text/plain', size: 9 }],
          },
        ],
      })
    if (url.pathname.endsWith('/download'))
      return denied ? new Response('', { status: 403 }) : new Response('SYNTHETIC')
    if (url.pathname.endsWith('/reports')) return Response.json({ data: { id: 'report' } })
    throw new Error('Unexpected API call')
  }
  const input = {
    baseUrl: 'https://example.test',
    token: 'synthetic',
    agentId: 'agent',
    readTextFiles: true,
    fetchImpl,
    generate: async ({ files }) => {
      generated++
      assert.deepEqual(files, [{ name: 'brief.txt', text: 'SYNTHETIC' }])
      return 'Reviewed'
    },
  }
  assert.equal((await executeRuns(input))[0].state, 'submitted')
  denied = true
  assert.equal((await executeRuns(input))[0].state, 'access_or_claim_changed')
  assert.equal(generated, 1)
})
