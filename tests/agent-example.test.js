import test from 'node:test'
import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { mkdtemp, rm, symlink } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'

const example = path.resolve(import.meta.dirname, '../docs/milestone-9-6/examples/agent.mjs')

test('standalone executor runs through a directory symlink with spaces and rejects absent credentials before network access', async () => {
  const scratch = await mkdtemp(path.join(tmpdir(), 'agent-cli-'))
  try {
    const directory = path.join(scratch, 'external executor')
    await symlink(path.dirname(example), directory)
    const result = spawnSync(process.execPath, [path.join(directory, 'agent.mjs')], {
      env: { PATH: process.env.PATH, PROJECT_BRIEF_URL: 'https://example.test' },
      encoding: 'utf8',
      timeout: 10000,
    })
    assert.equal(result.status, 1)
    assert.equal(result.stdout, '')
    assert.match(result.stderr, /one-time bearer credential/)
  } finally {
    await rm(scratch, { recursive: true, force: true })
  }
})

test('executor is importable without auto-running an API request', async () => {
  const example = await import('../docs/milestone-9-6/examples/agent.mjs')
  assert.equal(typeof example.runAgent, 'function')
})
