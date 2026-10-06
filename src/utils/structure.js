import { sortTasksByCompletion } from './tasks.js'

export const canonicalOrder = (items) => [...items].sort((a, b) => a.position - b.position)
export const sectionForTask = (task, sections) =>
  sections.find((section) => section.id === task.sectionId) ||
  (typeof task.section === 'object' ? task.section : null)
export const taskNumber = (task, sections) => {
  const section = sectionForTask(task, sections)
  return section?.position && task.position ? `${section.position}.${task.position}` : ''
}
export const normalizePositions = (items) =>
  items.forEach((item, index) => {
    item.position = index + 1
  })
export function swapPosition(items, id, direction) {
  const index = items.findIndex((item) => item.id === id)
  const target = index + (direction === 'up' ? -1 : 1)
  if (index < 0 || !items[target]) return false
  ;[items[index], items[target]] = [items[target], items[index]]
  normalizePositions(items)
  return true
}
export function groupBriefTasks(tasks, sections, statuses, sectionId = '') {
  return canonicalOrder(sections)
    .filter((section) => !sectionId || section.id === sectionId)
    .map((section) => ({
      ...section,
      tasks: sortTasksByCompletion(
        canonicalOrder(
          tasks.filter((task) => task.sectionId === section.id && statuses.includes(task.status)),
        ),
      ),
    }))
}

// Add associations to a validated legacy snapshot; never use location as a section.
export function migrateLocalStructure(data) {
  const canonical = data.sections.every((section) => typeof section === 'object')
  const sections = canonical ? data.sections : []
  const byName = new Map(sections.map((section) => [section.name, section]))
  const ensure = (name) => {
    if (!byName.has(name)) {
      const section = { id: crypto.randomUUID(), name, position: sections.length + 1 }
      sections.push(section)
      byName.set(name, section)
    }
    return byName.get(name)
  }
  for (const task of data.tasks) {
    if (task.sectionId && sections.some((section) => section.id === task.sectionId)) continue
    const name = typeof task.section === 'string' ? task.section.trim() || 'Общее' : 'Общее'
    const section = ensure(name)
    task.sectionId = section.id
    task.position =
      Math.max(
        0,
        ...data.tasks
          .filter((item) => item.sectionId === section.id && item !== task)
          .map((item) => item.position || 0),
      ) + 1
  }
  if (!canonical) for (const name of data.sections) ensure(name.trim() || 'Общее')
  data.sections = sections
  return data
}
