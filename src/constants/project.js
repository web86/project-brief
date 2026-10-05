export const STATUSES = {
  new: { label: 'Новая идея', tone: 'gray' },
  clarification: { label: 'Нужно уточнить', tone: 'orange' },
  approved: { label: 'Согласовано', tone: 'violet' },
  in_progress: { label: 'В работе', tone: 'blue' },
  review: { label: 'На проверке', tone: 'orange' },
  done: { label: 'Готово', tone: 'green' },
}

export const PRIORITIES = {
  low: { label: 'Можно позже', tone: 'gray' },
  normal: { label: 'Важно', tone: 'orange' },
  high: { label: 'Очень важно', tone: 'red' },
}

// Each status belongs to exactly one simple filter, so the counts add up.
export const FILTERS = [
  { key: 'all', label: 'Все', statuses: Object.keys(STATUSES) },
  { key: 'ideas', label: 'Идеи', statuses: ['new', 'clarification'] },
  { key: 'approved', label: 'Согласовано', statuses: ['approved'] },
  { key: 'working', label: 'В работе', statuses: ['in_progress', 'review'] },
  { key: 'done', label: 'Готово', statuses: ['done'] },
]

export const STORAGE_KEY = 'project-brief:v1'
export const STORAGE_VERSION = 1
export const OTHER_SECTION = 'Другое'

const dateFormatter = new Intl.DateTimeFormat('ru-RU', {
  day: 'numeric',
  month: 'long',
  hour: '2-digit',
  minute: '2-digit',
})
export const formatDate = (value) => dateFormatter.format(new Date(value))
export const formatSize = (bytes) =>
  bytes < 1024 * 1024
    ? `${Math.max(1, Math.round(bytes / 1024))} КБ`
    : `${(bytes / 1024 / 1024).toFixed(1)} МБ`

export function pluralize(count, forms = ['идея', 'идеи', 'идей']) {
  const lastTwo = count % 100
  if (lastTwo >= 11 && lastTwo <= 14) return forms[2]
  return count % 10 === 1 ? forms[0] : count % 10 >= 2 && count % 10 <= 4 ? forms[1] : forms[2]
}
