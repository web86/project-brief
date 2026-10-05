import { spawnSync } from 'node:child_process'
import { cp, mkdir, mkdtemp, readFile, rename, rm, writeFile, access } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { checksums, normalizePermissions, pruneVendor, validateRelease } from './release-files.mjs'

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)))
const php = process.env.PHP_BIN || 'php'
const releaseRoot = path.join(root, 'release')
let staging
let archiveTemporary

function run(command, args, cwd = root, extraEnv = {}, capture = false) {
  const result = spawnSync(command, args, {
    cwd,
    env: { ...process.env, ...extraEnv },
    stdio: capture ? 'pipe' : 'inherit',
    encoding: 'utf8',
  })
  if (result.error || result.status !== 0) throw new Error(`Failed: ${command} ${args.join(' ')}`)
  return result.stdout?.trim()
}

function cleanTree() {
  if (run('git', ['status', '--porcelain'], root, {}, true)) {
    throw new Error('Working tree is not clean. Commit or stash changes before building a release.')
  }
}

async function composerCommand() {
  if (process.env.COMPOSER_BIN)
    return process.env.COMPOSER_BIN.endsWith('.phar')
      ? [php, [process.env.COMPOSER_BIN]]
      : [process.env.COMPOSER_BIN, []]
  if (spawnSync('composer', ['--version'], { stdio: 'ignore' }).status === 0)
    return ['composer', []]
  const local = path.join(root, '.tools/composer.phar')
  await access(local)
  return [php, [local]]
}

async function knownLocalSecrets() {
  const values = []
  for (const file of ['.env', 'api/.env']) {
    const text = await readFile(path.join(root, file), 'utf8').catch(() => '')
    for (const line of text.split('\n')) {
      const match = line.match(/^([A-Z_]*(?:PASSWORD|SECRET|TOKEN|APP_KEY)[A-Z_]*)=(.*)$/)
      if (match) values.push(match[2].trim().replace(/^['"]|['"]$/g, ''))
    }
  }
  const credentials = await readFile(path.join(root, '.tools/local-admin.json'), 'utf8').catch(
    () => '{}',
  )
  const collect = (value) => {
    if (typeof value === 'string') values.push(value)
    else if (value && typeof value === 'object') Object.values(value).forEach(collect)
  }
  // The email/name may be intentional UI examples; only the password is a secret.
  collect(JSON.parse(credentials).password)
  return values.filter((value) => value.length >= 8)
}

try {
  if (process.argv.length > 2)
    throw new Error(
      'Usage: ./bin/build-release (PHP_BIN and COMPOSER_BIN are optional environment variables)',
    )
  cleanTree()
  const commit = run('git', ['rev-parse', 'HEAD'], root, {}, true)
  const [composer, composerPrefix] = await composerCommand()
  run(php, ['-r', 'exit(PHP_VERSION_ID >= 80300 && extension_loaded("zip") ? 0 : 1);'])
  run('npm', ['ci'])
  run('npm', ['test'])
  run('npm', ['run', 'build'], root, { VITE_DATA_SOURCE: 'api', VITE_API_BASE_URL: '' })

  // Keep development dependencies for tests; install production vendor in staging only.
  run(
    composer,
    [...composerPrefix, 'install', '--prefer-dist', '--no-interaction'],
    path.join(root, 'api'),
  )
  run(php, ['artisan', 'test'], path.join(root, 'api'))
  await mkdir(releaseRoot, { recursive: true })
  staging = await mkdtemp(path.join(releaseRoot, '.building-'))
  const application = path.join(staging, 'private/project-brief-app')
  await mkdir(application, { recursive: true })
  for (const entry of [
    'app',
    'config',
    'routes',
    'database/migrations',
    'artisan',
    'composer.json',
    'composer.lock',
    '.env.production.example',
    'bin',
  ]) {
    await cp(path.join(root, 'api', entry), path.join(application, entry), { recursive: true })
  }
  for (const entry of ['app.php', 'providers.php']) {
    await cp(path.join(root, 'api/bootstrap', entry), path.join(application, 'bootstrap', entry))
  }
  for (const directory of [
    'bootstrap/cache',
    'resources/views',
    'database/factories',
    'database/seeders',
    'storage/app/private',
    'storage/framework/cache/data',
    'storage/framework/sessions',
    'storage/framework/views',
    'storage/logs',
  ]) {
    await mkdir(path.join(application, directory), { recursive: true })
  }
  await cp(path.join(root, 'dist'), path.join(staging, 'public'), { recursive: true })
  await cp(path.join(root, 'deployment/public'), path.join(staging, 'public'), { recursive: true })
  await cp(path.join(root, 'DEPLOY-JINO.md'), path.join(staging, 'DEPLOY-JINO.md'))
  await writeFile(
    path.join(staging, 'release.json'),
    `${JSON.stringify({ commit, builtAt: new Date().toISOString(), phpTarget: '8.4', domain: 'brief.web86.site' }, null, 2)}\n`,
  )

  const productionEnv = { APP_ENV: 'production', APP_DEBUG: 'false' }
  run(
    composer,
    [
      ...composerPrefix,
      'install',
      '--no-dev',
      '--prefer-dist',
      '--optimize-autoloader',
      '--no-interaction',
    ],
    application,
    productionEnv,
  )
  await pruneVendor(path.join(application, 'vendor'))
  run(
    composer,
    [...composerPrefix, 'dump-autoload', '--no-dev', '--optimize', '--no-interaction'],
    application,
    productionEnv,
  )
  run(composer, [...composerPrefix, 'check-platform-reqs', '--no-dev'], application)
  await normalizePermissions(staging)
  const secrets = await knownLocalSecrets()
  const fileCount = await validateRelease(staging, secrets)
  run(process.execPath, ['tests/release-runtime.mjs', staging], root, { PHP_BIN: php })
  // Runtime tests use a disposable copy and must not leave anything in the upload package.
  await validateRelease(staging, secrets)
  cleanTree()
  await writeFile(path.join(staging, 'checksums.txt'), await checksums(staging))
  const stamp = new Date().toISOString().replace(/[-:]/g, '').replace('T', '-').slice(0, 15)
  const archive = path.join(releaseRoot, `project-brief-${stamp}-${commit.slice(0, 7)}.zip`)
  archiveTemporary = `${archive}.partial`
  run(php, ['deployment/archive.php', staging, archiveTemporary])
  await rm(path.join(releaseRoot, 'project-brief'), { recursive: true, force: true })
  await rename(staging, path.join(releaseRoot, 'project-brief'))
  staging = undefined
  await rename(archiveTemporary, archive)
  archiveTemporary = undefined
  console.log(
    `PASS: Release validation (${fileCount} files), checksums and runtime tests.\nArchive: ${archive}`,
  )
} catch (error) {
  if (staging) await rm(staging, { recursive: true, force: true })
  if (archiveTemporary) await rm(archiveTemporary, { force: true })
  console.error(`Release failed: ${error.message}. No final archive created.`)
  process.exitCode = 1
}
