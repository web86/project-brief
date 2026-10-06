import { t } from '../i18n/index.js'
const actions = {
  understood: {
    id: 'understood',
    get label() {
      return t('ui.allClear')
    },
    icon: 'check',
    style: 'secondary',
  },
  clarify: {
    id: 'clarify',
    status: 'clarification',
    get label() {
      return t('ui.needsClarification')
    },
    icon: 'info',
    style: 'secondary',
  },
  start: {
    id: 'start',
    status: 'in_progress',
    get label() {
      return t('ui.startWork')
    },
    icon: 'code',
    style: 'primary',
  },
  review: {
    id: 'review',
    status: 'review',
    get label() {
      return t('ui.sendForReview')
    },
    icon: 'external',
    style: 'primary',
  },
  finish: {
    id: 'finish',
    status: 'done',
    get label() {
      return t('ui.finish')
    },
    icon: 'check',
    style: 'primary',
  },
  reopen: {
    id: 'reopen',
    status: 'in_progress',
    get label() {
      return t('ui.returnToWork')
    },
    icon: 'arrow',
    style: 'secondary',
  },
}

const byStatus = {
  new: [actions.understood, actions.clarify],
  clarification: [actions.understood, actions.start],
  approved: [actions.start],
  in_progress: [actions.review, { ...actions.finish, style: 'secondary' }],
  review: [actions.finish, actions.reopen],
  done: [actions.reopen],
}

export function getQuickStatusActions(task) {
  return Object.hasOwn(byStatus, task.status) ? byStatus[task.status] : []
}

export function applyQuickStatusAction(store, taskId, actionId) {
  const task = store.findTask(taskId)
  if (!store.isDeveloper || store.storageBlocked || !task) return null
  const action = getQuickStatusActions(task).find((item) => item.id === actionId)
  if (!action) return null
  const understood = action.id === 'understood'
  // Understanding the brief never substitutes for the client's approval of its scope.
  const status = understood ? (task.clientApproved ? 'approved' : 'new') : action.status
  const changed = store.changeStatus(taskId, status)
  const result = {
    focusComment: action.id === 'clarify',
    message: understood && !task.clientApproved ? 'ui.allClearWaitingForClientApproval' : '',
  }
  return changed?.then ? changed.then((saved) => (saved ? result : null)) : result
}
