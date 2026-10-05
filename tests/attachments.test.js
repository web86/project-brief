import { test } from 'node:test'
import assert from 'node:assert/strict'
import { getPastedImages, prepareAttachment, MAX_FILE_SIZE } from '../src/utils/attachments.js'

const item = (file) => ({ kind: 'file', type: file.type, getAsFile: () => file })

test('text paste is left alone; image paste uses the same File API workflow', () => {
  assert.deepEqual(getPastedImages(null), [])
  assert.deepEqual(getPastedImages({ items: [{ kind: 'string', type: 'text/plain' }] }), [])
  const file = new File(['sample'], 'image.png', { type: 'image/png' })
  const data = { items: [item(file), { kind: 'string', type: 'text/plain' }] }
  const first = getPastedImages(data)[0]
  const second = getPastedImages(data)[0]
  assert.equal(first.type, 'image/png')
  assert.equal(first.size, file.size)
  assert.notEqual(first.name, second.name)
  assert.ok(first.name.endsWith('.png'))
})

test('empty clipboard file entries are ignored and unsupported images keep validation', async () => {
  assert.deepEqual(
    getPastedImages({ items: [{ kind: 'file', type: 'image/png', getAsFile: () => null }] }),
    [],
  )
  const gif = new File(['gif'], 'image.gif', { type: 'image/gif' })
  const [image] = getPastedImages({ items: [item(gif)] })
  await assert.rejects(prepareAttachment(image), /формат не поддерживается/)
})

test('attachment formats and size limits remain enforced', async () => {
  const metadata = await prepareAttachment(new File(['hello'], 'notes.txt', { type: 'text/plain' }))
  assert.equal(metadata.name, 'notes.txt')
  assert.equal(metadata.preview, null)
  await assert.rejects(
    prepareAttachment(new File(['x'], 'program.exe')),
    /формат не поддерживается/,
  )
  await assert.rejects(
    prepareAttachment({ name: 'large.png', size: MAX_FILE_SIZE + 1 }),
    /больше 20 МБ/,
  )
})
