export const canChangeClientStatus = (task, status) =>
  task?.status === 'review' && ['approved', 'done'].includes(status)
