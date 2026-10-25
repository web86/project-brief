import { defaultProjectPermissions } from '../../src/services/projectPermissions.js'
export const allProjectPermissions = Object.fromEntries(
  Object.keys(defaultProjectPermissions).map((key) => [key, true]),
)
