const key = (projectId, sectionId) => `projectbrief:collapsed:v1:${projectId}:${sectionId}`
export function readSectionCollapsed(projectId, sectionId) {
  try {
    return (
      !!projectId &&
      !!sectionId &&
      JSON.parse(globalThis.localStorage?.getItem(key(projectId, sectionId)) || 'false') === true
    )
  } catch {
    return false
  }
}
export function writeSectionCollapsed(projectId, sectionId, collapsed) {
  try {
    if (!projectId || !sectionId) return
    if (collapsed) globalThis.localStorage?.setItem(key(projectId, sectionId), 'true')
    else globalThis.localStorage?.removeItem(key(projectId, sectionId))
  } catch {
    // Preferences must never block access to the brief.
  }
}
