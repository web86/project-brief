import { test } from 'node:test'
import assert from 'node:assert/strict'
import { commentBody } from '../src/api/client.js'

test('comment multipart includes text, stable retry key and original bytes in one submission', async () => {
  const file = new File(['hello'], 'notes.txt', { type: 'text/plain' })
  const first = commentBody('  Details  ', [{ file, name: file.name }], 'retry-key')
  const retry = commentBody('  Details  ', [{ file, name: file.name }], 'retry-key')
  assert.equal(first.get('text'), 'Details')
  assert.equal(first.get('submissionId'), retry.get('submissionId'))
  assert.equal(await first.get('attachments[]').text(), 'hello')
  assert.equal(first.get('attachments[]').name, 'notes.txt')
  assert.equal(commentBody('', [{ file, name: file.name }], 'key').get('text'), '')
  assert.throws(() => commentBody('text', [{ name: 'missing.txt' }], 'key'))
})
