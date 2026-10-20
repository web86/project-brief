import { computed } from 'vue'
import { useProjectStore } from '../stores/project.js'
import { t } from '../i18n/index.js'
export function useClientNavigation() {
  const store = useProjectStore()
  return computed(() => [
    { path: store.projectPath, label: t('clientWorkspace.ideas'), icon: 'grid' },
    ...['discussions', 'notifications', 'export'].map((section) => ({
      path: `${store.projectPath}/${section}`,
      label: t('nav.' + section),
      icon: { discussions: 'comment', notifications: 'bell', export: 'file' }[section],
      unread: section === 'discussions' ? store.project.unreadCommentCount || 0 : 0,
    })),
  ])
}
