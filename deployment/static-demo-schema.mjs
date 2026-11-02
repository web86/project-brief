// Restricted JSON Schema 2020-12 evaluator used for pinned, reviewed read contracts.
// Schemas are committed; exporting never regenerates or broadens them.
export function contractKey(path) {
  return path.replace(/[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}/g, ':uuid')
}
export function validateSchema(value, schema, at = '$') {
  if (schema.anyOf) {
    if (
      schema.anyOf.some((candidate) => {
        try {
          validateSchema(value, candidate, at)
          return true
        } catch {
          return false
        }
      })
    )
      return
    throw new Error(`${at}: no allowed schema matches`)
  }
  const type = value === null ? 'null' : Array.isArray(value) ? 'array' : typeof value
  if (schema.type && schema.type !== type)
    throw new Error(`${at}: expected ${schema.type}, got ${type}`)
  if (schema.enum && !schema.enum.includes(value)) throw new Error(`${at}: value is not allowed`)
  if (type === 'object') {
    for (const key of schema.required || [])
      if (!(key in value)) throw new Error(`${at}: missing ${key}`)
    for (const [key, item] of Object.entries(value)) {
      if (!Object.hasOwn(schema.properties || {}, key)) {
        if (schema.additionalProperties === false) throw new Error(`${at}: unexpected field ${key}`)
      } else validateSchema(item, schema.properties[key], `${at}.${key}`)
    }
  }
  if (type === 'array') {
    if (schema.maxItems !== undefined && value.length > schema.maxItems)
      throw new Error(`${at}: unreviewed array contents`)
    value.forEach((item, index) => validateSchema(item, schema.items || {}, `${at}[${index}]`))
  }
  if (schema.format === 'uuid' && !/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/i.test(value))
    throw new Error(`${at}: invalid UUID`)
}
