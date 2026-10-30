export const notificationEvents = [
  'client.task_created',
  'client.comment_created',
  'client.task_approved',
  'client.task_status_changed',
  'admin.clarification_requested',
  'admin.comment_created',
  'admin.task_review',
  'admin.task_done',
  'client.project_comment_created',
  'admin.project_comment_created',
  'team.message_created',
]
export function createNotificationSettings(request, projectId) {
  const path = `/api/admin/projects/${projectId}/notifications`
  return {
    load: () => request(path),
    save: (value) =>
      request(path, {
        method: 'PATCH',
        body: value.eventRules
          ? {
              deliveryMode: 'personal',
              eventRules: value.eventRules,
              confirmMigration: !!value.confirmMigration,
            }
          : { events: value.events, notificationEmail: value.notificationEmail || null },
      }),
    test: (channel, endpoint = null) =>
      request(path + '/test', {
        method: 'POST',
        body: { channel, ...(channel === 'push' ? { endpoint } : {}) },
      }),
  }
}
