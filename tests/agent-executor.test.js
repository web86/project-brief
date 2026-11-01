import test from 'node:test'
import assert from 'node:assert/strict'
import { mkdtemp, symlink, rm } from 'node:fs/promises'
import { spawnSync } from 'node:child_process'
import { tmpdir } from 'node:os'
import path from 'node:path'

test('independent executor CLI runs through a symlink and fails safely without credentials', async () => {
  const directory = await mkdtemp(path.join(tmpdir(), 'brief-executor-entry-'))
  try {
    const entry = path.join(directory, 'executor.mjs')
    await symlink(path.resolve('docs/milestone-9-9/examples/collaboration.mjs'), entry)
    const result = spawnSync(process.execPath, [entry], {
      env: { PATH: process.env.PATH },
      encoding: 'utf8',
    })
    assert.equal(result.status, 1)
    assert.equal(result.stdout, '')
    assert.match(result.stderr, /External executor failed\. No private context was logged\./)
  } finally {
    await rm(directory, { recursive: true, force: true })
  }
})
