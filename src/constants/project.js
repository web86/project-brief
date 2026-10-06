import { t } from '../i18n/index.js'
export const STATUSES = {
  new: {
    get label() {
      return t('ui.newIdea')
    },
    tone: 'gray',
  },
  clarification: {
    get label() {
      return t('ui.needsClarification')
    },
    tone: 'orange',
  },
  approved: {
    get label() {
      return t('ui.approved')
    },
    tone: 'violet',
  },
  in_progress: {
    get label() {
      return t('ui.inProgress')
    },
    tone: 'blue',
  },
  review: {
    get label() {
      return t('ui.inReview')
    },
    tone: 'orange',
  },
  done: {
    get label() {
      return t('ui.done')
    },
    tone: 'green',
  },
}

export const PRIORITIES = {
  low: {
    get label() {
      return t('ui.canWait')
    },
    tone: 'gray',
  },
  normal: {
    get label() {
      return t('ui.important')
    },
    tone: 'orange',
  },
  high: {
    get label() {
      return t('ui.veryImportant')
    },
    tone: 'red',
  },
}

// Each status belongs to exactly one simple filter, so the counts add up.
export const FILTERS = [
  {
    key: 'all',
    get label() {
      return t('ui.all')
    },
    statuses: Object.keys(STATUSES),
  },
  {
    key: 'ideas',
    get label() {
      return t('ui.new')
    },
    statuses: ['new', 'clarification'],
  },
  {
    key: 'approved',
    get label() {
      return t('ui.approved')
    },
    statuses: ['approved'],
  },
  {
    key: 'working',
    get label() {
      return t('ui.inProgress')
    },
    statuses: ['in_progress', 'review'],
  },
  {
    key: 'done',
    get label() {
      return t('ui.done')
    },
    statuses: ['done'],
  },
]

export const STORAGE_KEY = 'project-brief:v1'
export const STORAGE_VERSION = 1
// Legacy projects only have section; newer ideas may describe any place or URL.
export const getTaskLocation = (task) =>
  task.location || (typeof task.section === 'string' ? task.section : task.section?.name) || ''

export { formatDate } from '../i18n/index.js'
export const formatSize = (bytes) =>
  bytes < 1024 * 1024
    ? t('ui.kb', { arg0: Math.max(1, Math.round(bytes / 1024)) })
    : t('ui.mb', { arg0: (bytes / 1024 / 1024).toFixed(1) })

export const pluralize = (count) => t('counts.ideas', count)
