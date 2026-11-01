import test from 'node:test'
import assert from 'node:assert/strict'
import { blocksDescription, plainDescriptionBlocks, mergeDescriptionText } from '../src/utils/taskRichText.js'

test('description serialization escapes text and permits only safe links and formatting', () => {
  const result = blocksDescription([{type:'paragraph',content:[{text:'<img onerror=bad>',bold:true,href:'javascript:alert(1)'},{text:'Spec',italic:true,href:'https://example.com/spec'}]}])
  assert.ok(!result.html.includes('<img'))
  assert.ok(!result.html.includes('javascript:'))
  assert.ok(result.html.includes('<strong>&lt;img onerror=bad&gt;</strong>'))
  assert.ok(result.html.includes('<a href="https://example.com/spec"><em>Spec</em></a>'))
  assert.ok(result.text.includes('Spec (https://example.com/spec)'))
})
test('paragraphs, ordered lists and bullets retain their structure', () => {
  const result=blocksDescription(plainDescriptionBlocks('Paragraph\n\n• First\n• Second\n1. One\n2. Two'))
  assert.equal(result.text,'Paragraph\n\n• First\n• Second\n1. One\n2. Two')
  assert.ok(result.html.includes('<p><br></p><ul><li>First</li><li>Second</li></ul><ol><li>One</li><li>Two</li></ol>'))
})
test('manual AI apply preserves formatting of unchanged paragraphs', () => {
  const original=[{type:'paragraph',content:[{text:'Retain formatting',bold:true}]}]
  const result=blocksDescription(mergeDescriptionText('Retain formatting\n• New criterion',original))
  assert.ok(result.html.includes('<strong>Retain formatting</strong>'))
  assert.ok(result.html.includes('<ul><li>New criterion</li></ul>'))
})
