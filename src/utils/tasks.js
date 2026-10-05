// Stable partition for display only: preserve both groups and never mutate stored tasks.
export function sortTasksByCompletion(tasks) {
  const active = []
  const done = []
  for (const task of tasks) {
    const group = task.status === 'done' ? done : active
    group.push(task)
  }
  return [...active, ...done]
}
