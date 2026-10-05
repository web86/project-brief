import { test } from 'node:test'
import assert from 'node:assert/strict'
import { sortTasksByCompletion } from '../src/utils/tasks.js'
import { getTaskLocation } from '../src/constants/project.js'

test('done tasks follow active tasks, preserving order, identity and all card data', () => {
  const tasks = Object.freeze(
    ['done', 'review', 'new', 'done', 'clarification', 'approved', 'in_progress'].map(
      (status, index) =>
        Object.freeze({
          id: String(index),
          status,
          title: `Idea ${index}`,
          section: 'Главная',
          comments: [{ text: 'Comment' }],
          attachments: [{ name: 'image.png' }],
        }),
    ),
  )
  const sorted = sortTasksByCompletion(tasks)
  assert.deepEqual(
    sorted.map((task) => task.id),
    ['1', '2', '4', '5', '6', '0', '3'],
  )
  for (const task of sorted) assert.equal(task, tasks[Number(task.id)])
  assert.deepEqual(
    tasks.map((task) => task.id),
    ['0', '1', '2', '3', '4', '5', '6'],
  )
})

test('each location keeps its tasks; done-only and empty lists remain stable', () => {
  const tasks = [
    { id: 'a', status: 'done', section: 'Главная' },
    { id: 'b', status: 'done', location: 'https://example.com/catalog/' },
    { id: 'c', status: 'new', section: 'Главная' },
    { id: 'd', status: 'clarification', location: 'https://example.com/catalog/' },
    { id: 'e', status: 'done', section: 'Главная' },
  ]
  const byLocation = (location) =>
    sortTasksByCompletion(tasks.filter((task) => getTaskLocation(task) === location)).map(
      (task) => task.id,
    )
  assert.deepEqual(byLocation('Главная'), ['c', 'a', 'e'])
  assert.deepEqual(byLocation('https://example.com/catalog/'), ['d', 'b'])
  const done = tasks.filter((task) => task.status === 'done')
  assert.deepEqual(sortTasksByCompletion(done), done)
  assert.deepEqual(sortTasksByCompletion([]), [])
})

test('a status change updates display order without reordering the source', () => {
  const tasks = [
    { id: 'a', status: 'new' },
    { id: 'b', status: 'in_progress' },
    { id: 'c', status: 'done' },
  ]
  tasks[0].status = 'done'
  assert.deepEqual(
    sortTasksByCompletion(tasks).map((task) => task.id),
    ['b', 'a', 'c'],
  )
  tasks[0].status = 'new'
  assert.deepEqual(
    sortTasksByCompletion(tasks).map((task) => task.id),
    ['a', 'b', 'c'],
  )
})
