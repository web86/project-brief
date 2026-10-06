export const notificationEvents = [
  'client.task_created',
  'client.comment_created',
  'client.task_approved',
  'admin.clarification_requested',
  'admin.comment_created',
  'admin.task_review',
  'admin.task_done',
]
export function createNotificationSettings(request, projectId) {
  const path = `/api/admin/projects/${projectId}/notifications`
  return {
    load: () => request(path),
    save: ({ events, notificationEmail }) =>
      request(path, {
        method: 'PATCH',
        body: { events, notificationEmail: notificationEmail || null },
      }),
    test: (channel, endpoint = null) =>
      request(path + '/test', {
        method: 'POST',
        body: { channel, ...(channel === 'push' ? { endpoint } : {}) },
      }),
  }
}
