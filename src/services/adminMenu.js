export const projectMenu = [
  { section: 'brief', icon: 'grid' },
  { section: 'discussions', icon: 'comment' },
  { section: 'clients', icon: 'users', permission: 'manage_clients' },
  { section: 'team', icon: 'user-check', owner: true },
  { section: 'structure', permission: 'manage_structure', icon: 'list-tree' },
  { section: 'notifications', permission: 'manage_project_notifications', icon: 'bell' },
  { section: 'settings', permission: 'manage_project_settings', icon: 'settings' },
  { section: 'export', permission: 'export_project', icon: 'file' },
]
export const accountMenu = [
  { to: '/admin/account', label: 'account.profile', icon: 'user' },
  { to: '/admin/account/security', label: 'account.security', icon: 'shield' },
  { to: '/admin/settings/notifications', label: 'account.notifications', icon: 'bell' },
]

export const settingsMenu = [
  ...accountMenu,
  {
    to: '/admin/settings/integrations',
    label: 'integrations.title',
    icon: 'settings',
    owner: true,
  },
  { to: '/admin/settings/branding', label: 'branding.title', icon: 'palette', owner: true },
]
