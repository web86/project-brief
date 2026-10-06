import { readdir, readFile, rm, lstat, chmod } from 'node:fs/promises'
import path from 'node:path'
import { createHash } from 'node:crypto'

const forbiddenDirectories = new Set([
  '.git',
  '.github',
  '.tools',
  'node_modules',
  'tests',
  'test',
  '__tests__',
  'docs',
  'documentation',
  'examples',
  'credentials',
  'secrets',
])

export function forbiddenPath(relative) {
  const parts = relative.split('/').map((part) => part.toLowerCase())
  const name = parts.at(-1)
  if (
    parts[0] === 'public' &&
    (['app', 'bootstrap', 'config', 'database', 'storage', 'vendor', 'src'].includes(parts[1]) ||
      ['.env.production.example', 'composer.json', 'composer.lock', 'artisan'].includes(name) ||
      (/\.(php|vue)$/.test(name) && relative !== 'public/index.php'))
  )
    return true
  if (parts.some((part) => forbiddenDirectories.has(part))) return true
  if (name === '.env.production.example') return false
  return (
    name === '.env' ||
    name.startsWith('.env.') ||
    name === 'local-admin.json' ||
    /\.(key|map|sqlite(?:3)?(?:-.*)?)$/.test(name) ||
    /^(credentials?|secrets?)([._-]|$)/.test(name) ||
    ['.ds_store', '.gitignore', '.gitattributes', 'phpunit.xml', 'phpunit.xml.dist'].includes(name)
  )
}

export async function entries(root, prefix = '') {
  const result = []
  for (const entry of await readdir(path.join(root, prefix), { withFileTypes: true })) {
    const relative = prefix ? `${prefix}/${entry.name}` : entry.name
    if (entry.isSymbolicLink()) throw new Error(`Symlink is not allowed in release: ${relative}`)
    result.push({ relative, directory: entry.isDirectory() })
    if (entry.isDirectory()) result.push(...(await entries(root, relative)))
  }
  return result.sort((a, b) => a.relative.localeCompare(b.relative, 'en'))
}

export async function pruneVendor(root) {
  // Remove upstream tests/docs, not runtime helpers such as Symfony Console/Tester.
  for (const entry of (await entries(root)).reverse()) {
    if (forbiddenPath(entry.relative))
      await rm(path.join(root, entry.relative), { recursive: true, force: true })
  }
}

export async function normalizePermissions(root) {
  await chmod(root, 0o755)
  for (const entry of await entries(root)) {
    await chmod(path.join(root, entry.relative), entry.directory ? 0o755 : 0o644)
  }
}

export function containsPrivateKey(content) {
  // Crypto serializers contain PEM delimiters as source strings. Only key material is a secret.
  const text = content.toString().replace(/\\r\\n|\\n/g, '\n')
  return /-----BEGIN (?:RSA |DSA |EC |OPENSSH |ENCRYPTED )?PRIVATE KEY-----\s*(?:Proc-Type:[^\r\n]+\r?\nDEK-Info:[^\r\n]+\s*)?(?:[A-Za-z0-9+/=]{16,}\s*)+/.test(
    text,
  )
}

export async function validateRelease(root, localSecrets = []) {
  const required = [
    'public/index.html',
    'public/sw.js',
    'public/manifest.webmanifest',
    'public/icon-192.png',
    'public/icon-512.png',
    'private/project-brief-app/lang/ru/notifications.php',
    'private/project-brief-app/lang/en/notifications.php',
    'private/project-brief-app/resources/views/mail/project-activity.blade.php',
    'private/project-brief-app/resources/views/mail/project-activity-text.blade.php',
    'private/project-brief-app/vendor/minishlink/web-push/src/WebPush.php',
    'public/index.php',
    'public/.htaccess',
    'private/project-brief-app/vendor/autoload.php',
    'private/project-brief-app/bootstrap/app.php',
    'private/project-brief-app/artisan',
    'private/project-brief-app/.env.production.example',
    'private/project-brief-app/bin/check-server',
    'private/project-brief-app/bin/first-install',
    'DEPLOY-JINO.md',
    'release.json',
  ]
  for (const file of required) {
    if (!(await lstat(path.join(root, file))).isFile())
      throw new Error(`Required release file missing: ${file}`)
  }
  const files = await entries(root)
  if (!files.some((file) => /^public\/assets\/.+\.js$/.test(file.relative)))
    throw new Error('Frontend JS missing')
  if (!files.some((file) => /^public\/assets\/.+\.css$/.test(file.relative)))
    throw new Error('Frontend CSS missing')
  for (const file of files) {
    if (forbiddenPath(file.relative)) throw new Error(`Forbidden release entry: ${file.relative}`)
    if (file.directory) continue
    const content = await readFile(path.join(root, file.relative))
    if (
      localSecrets.some((secret) => secret.length >= 8 && content.includes(Buffer.from(secret)))
    ) {
      throw new Error(`Local secret detected in: ${file.relative} (value withheld)`)
    }
    if (containsPrivateKey(content)) {
      throw new Error(`Private key detected in: ${file.relative}`)
    }
  }
  const env = await readFile(
    path.join(root, 'private/project-brief-app/.env.production.example'),
    'utf8',
  )
  for (const key of [
    'APP_KEY',
    'DB_HOST',
    'DB_USERNAME',
    'DB_PASSWORD',
    'MAIL_HOST',
    'MAIL_USERNAME',
    'MAIL_PASSWORD',
    'VAPID_PUBLIC_KEY',
    'VAPID_PRIVATE_KEY',
  ]) {
    if (!new RegExp(`^${key}=$`, 'm').test(env)) throw new Error(`Example must leave ${key} empty`)
  }
  const installed = JSON.parse(
    await readFile(path.join(root, 'private/project-brief-app/vendor/composer/installed.json')),
  )
  if (
    installed.dev !== false ||
    installed.packages.some((pkg) =>
      /^(phpunit\/|laravel\/(boost|pao|pint|pail)|fakerphp\/|mockery\/)/.test(pkg.name),
    )
  ) {
    throw new Error('Development Composer dependencies in release')
  }
  return files.filter((file) => !file.directory).length
}

export async function checksums(root) {
  const lines = []
  for (const file of await entries(root)) {
    if (file.directory || file.relative === 'checksums.txt') continue
    const hash = createHash('sha256')
      .update(await readFile(path.join(root, file.relative)))
      .digest('hex')
    lines.push(`${hash}  ${file.relative}`)
  }
  return `${lines.join('\n')}\n`
}
