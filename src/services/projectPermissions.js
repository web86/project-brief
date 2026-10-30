import { projectMenu } from './adminMenu.js'
export const defaultProjectPermissions = {
  manage_structure: true,
  manage_team: false,
  manage_clients: false,
  manage_project_settings: false,
  manage_project_notifications: false,
  export_project: false,
  manage_trash: false,
}
export function canCreateProjects(user) {
  return user?.membershipRole === 'owner'
}
export function hasProjectPermission(project, permission) {
  return project?.permissions?.[permission] === true
}
export function canOpenProjectSection(project, section, isOwner = false) {
  const item = projectMenu.find((item) => item.section === section)
  if (item?.owner) return isOwner
  return !item?.permission || hasProjectPermission(project, item.permission)
}
