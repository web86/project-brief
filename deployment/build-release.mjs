import { productMarkdown, productOpenApi } from './product-presentation.mjs'
import { spawnSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { cp, mkdir, mkdtemp, readFile, rename, rm, writeFile, access } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import {
  checksums,
  entries,
  demoOnlyRuntimePath,
  environmentSecrets,
  normalizePermissions,
  pruneVendor,
  validateRelease,
} from './release-files.mjs'

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)))
const php = process.env.PHP_BIN || 'php'
const releaseRoot = path.join(root, 'release')
let staging
let archiveTemporary
let verification

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

async function copyRelease(source, destination, options = {}) {
  await cp(source, destination, {
    ...options,
    filter: (entry) =>
      path.basename(entry) !== '.DS_Store' &&
      !demoOnlyRuntimePath(path.relative(root, entry).split(path.sep).join('/')),
  })
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
    values.push(...environmentSecrets(text))
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
  const demoCredentials = await readFile(
    path.join(root, '.tools/demo-runtime/access.private.json'),
    'utf8',
  ).catch(() => '{}')
  collect(JSON.parse(demoCredentials))
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
  run('npm', ['run', 'brand:check'])
  run('npm', ['run', 'build'], root, { VITE_DATA_SOURCE: 'api', VITE_API_BASE_URL: '' })

  // Keep development dependencies for tests; install production vendor in staging only.
  run(
    composer,
    [...composerPrefix, 'install', '--prefer-dist', '--no-interaction'],
    path.join(root, 'api'),
  )
  run(php, ['-d', 'memory_limit=512M', 'vendor/bin/phpunit'], path.join(root, 'api'), {
    APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
  })
  await mkdir(releaseRoot, { recursive: true })
  staging = await mkdtemp(path.join(releaseRoot, '.building-'))
  const application = path.join(staging, 'private/project-brief-app')
  await mkdir(application, { recursive: true })
  for (const entry of [
    'app',
    'config',
    'lang',
    'resources/views',
    'resources/fonts',
    'routes',
    'database/migrations',
    'artisan',
    'composer.json',
    'composer.lock',
    '.env.production.example',
    'bin',
  ]) {
    await copyRelease(path.join(root, 'api', entry), path.join(application, entry), {
      recursive: true,
    })
  }
  for (const entry of ['app.php', 'providers.php']) {
    await copyRelease(
      path.join(root, 'api/bootstrap', entry),
      path.join(application, 'bootstrap', entry),
    )
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
  await copyRelease(
    path.join(root, 'api/database/seeders/PlansFoundationSeeder.php'),
    path.join(application, 'database/seeders/PlansFoundationSeeder.php'),
  )
  await copyRelease(path.join(root, 'dist'), path.join(staging, 'public'), { recursive: true })
  await copyRelease(path.join(root, 'deployment/public'), path.join(staging, 'public'), {
    recursive: true,
  })
  await copyRelease(path.join(root, 'DEPLOY-JINO.md'), path.join(staging, 'DEPLOY-JINO.md'))
  await copyRelease(
    path.join(root, 'deployment/telegram-bridge.mjs'),
    path.join(staging, 'deployment/telegram-bridge.mjs'),
  )
  await copyRelease(
    path.join(root, 'deployment/rollback-public.htaccess'),
    path.join(staging, 'deployment/rollback-public.htaccess'),
  )
  for (const [source, target] of [
    ['JINO.md', 'PRODUCTION.md'],
    ['CANARY.md', 'CANARY.md'],
    ['ROLLBACK.md', 'ROLLBACK.md'],
  ]) {
    await copyRelease(
      path.join(root, 'docs/milestone-10-0/phase-4b2', source),
      path.join(staging, target),
    )
  }
  await mkdir(path.join(staging, 'agent-api/examples'), { recursive: true })
  for (const file of [
    'AGENT-API.md',
    'AGENT-WEBHOOKS.md',
    'AGENT-SECURITY.md',
    'AGENT-EXAMPLE.md',
    'openapi.json',
    'PRODUCTION.md',
    'examples/README.md',
    'examples/agent.mjs',
  ]) {
    await copyRelease(
      file === 'PRODUCTION.md'
        ? path.join(root, 'docs/agent-ux-shared-keys/PRODUCTION.md')
        : path.join(root, 'docs/milestone-9-6', file),
      path.join(staging, 'agent-api', file),
    )
  }
  await copyRelease(
    path.join(root, 'docs/agent-ux-shared-keys/API.md'),
    path.join(staging, 'agent-api/COLLABORATION.md'),
  )
  await copyRelease(
    path.join(root, 'docs/agent-ux-shared-keys/examples/collaboration.mjs'),
    path.join(staging, 'agent-api/examples/collaboration.mjs'),
  )
  await copyRelease(
    path.join(root, 'AGENT-LIVE-SMOKE-RU.md'),
    path.join(staging, 'agent-api/AGENT-LIVE-SMOKE-RU.md'),
  )
  const collaborationGuide = path.join(staging, 'agent-api/COLLABORATION.md')
  await writeFile(
    collaborationGuide,
    (await readFile(collaborationGuide, 'utf8')).replaceAll(
      '(../milestone-9-6/openapi.json)',
      '(openapi.json)',
    ),
  )
  const deploymentGuide = path.join(staging, 'DEPLOY-JINO.md')
  await writeFile(
    deploymentGuide,
    (await readFile(deploymentGuide, 'utf8'))
      .replaceAll('(docs/milestone-9-6-2/PRODUCTION.md)', '(PRODUCTION.md)')
      .replaceAll('(docs/milestone-9-6-1/PRODUCTION.md)', '(PRODUCTION.md)')
      .replaceAll('(docs/milestone-9-6/PRODUCTION.md)', '(PRODUCTION.md)')
      .replaceAll('(docs/milestone-9-4/PRODUCTION.md)', '(PRODUCTION.md)')
      .replaceAll('(docs/milestone-9-2/PRODUCTION.md)', '(PRODUCTION.md)')
      .replaceAll('(docs/milestone-8-5/PRODUCTION.md)', '(PRODUCTION.md)')
      .replaceAll('(docs/milestone-9-0/PRODUCTION.md)', '(PRODUCTION.md)')
      .replaceAll('(docs/milestone-8-4/PRODUCTION.md)', '(PRODUCTION.md)')
      .replaceAll('(docs/milestone-8-2/PRODUCTION.md)', '(PRODUCTION.md)')
      .replaceAll('(docs/milestone-8-1/PRODUCTION.md)', '(PRODUCTION.md)')
      .replaceAll('(docs/client-entry-0-8-1/PRODUCTION.md)', '(PRODUCTION.md)')
      .replaceAll('(docs/milestone-8-0/PRODUCTION.md)', '(PRODUCTION.md)')
      .replaceAll('(docs/live-sync-ui/PRODUCTION.md)', '(PRODUCTION.md)')
      .replaceAll('(docs/milestone-7-4/PRODUCTION.md)', '(PRODUCTION.md)')
      .replaceAll('(docs/milestone-7-3/PRODUCTION.md)', '(PRODUCTION.md)')
      .replaceAll('(docs/milestone-7-2-1/PRODUCTION.md)', '(PRODUCTION.md)')
      .replaceAll('(docs/project-trash/PRODUCTION.md)', '(PRODUCTION.md)')
      .replaceAll('(docs/milestone-6-2/PRODUCTION.md)', '(PRODUCTION.md)'),
  )
  for (const file of await entries(path.join(staging, 'agent-api'))) {
    const target = path.join(staging, 'agent-api', file.relative)
    if (file.relative.endsWith('.md'))
      await writeFile(target, productMarkdown(await readFile(target, 'utf8')))
    if (file.relative.endsWith('openapi.json'))
      await writeFile(
        target,
        JSON.stringify(productOpenApi(JSON.parse(await readFile(target, 'utf8'))), null, 2) + '\n',
      )
  }
  await writeFile(
    path.join(staging, 'release.json'),
    JSON.stringify(
      {
        version: '0.9.19',
        product: 'Dev2Done',
        commit,
        builtAt: new Date().toISOString(),
        phpTarget: '8.4',
        domain: 'brief.web86.site',
        upgradeFrom: '0.9.18',
        migrations: [],
        defaults: {
          publicRegistration: false,
          trialActivation: false,
          compatibilityMode: 'observe',
        },
      },
      null,
      2,
    ) + '\n',
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
  await writeFile(path.join(staging, 'checksums.txt'), await checksums(staging))
  const archive = path.join(releaseRoot, `dev2done-v0.9.19-${commit.slice(0, 7)}.zip`)
  archiveTemporary = `${archive}.partial`
  run(php, ['deployment/archive.php', staging, archiveTemporary])
  // Test the actual ZIP in a fresh directory, never an existing installation.
  verification = await mkdtemp(path.join(releaseRoot, '.verifying-'))
  run(php, [
    '-r',
    '$zip = new ZipArchive; if ($zip->open($argv[1], ZipArchive::CHECKCONS) !== true || !$zip->extractTo($argv[2])) { throw new RuntimeException("Cannot unpack release"); } $zip->close();',
    archiveTemporary,
    verification,
  ])
  await validateRelease(verification, secrets)
  if (
    (await checksums(verification)) !==
    (await readFile(path.join(verification, 'checksums.txt'), 'utf8'))
  )
    throw new Error('Unpacked ZIP does not match its checksum manifest')
  run(process.execPath, ['tests/account-security.package.mjs', verification], root, {
    PHP_BIN: php,
  })
  run(process.execPath, ['tests/release-runtime.mjs', verification], root, { PHP_BIN: php })
  run(process.execPath, ['tests/platform-plans.package.mjs', verification], root, { PHP_BIN: php })
  run(process.execPath, ['tests/onboarding.package.mjs', verification], root, { PHP_BIN: php })
  run(process.execPath, ['tests/ux-polish.package.mjs', verification], root, { PHP_BIN: php })
  await validateRelease(verification, secrets)
  await rm(verification, { recursive: true, force: true })
  verification = undefined
  cleanTree()
  await rm(path.join(releaseRoot, 'project-brief'), { recursive: true, force: true })
  await rename(staging, path.join(releaseRoot, 'project-brief'))
  staging = undefined
  await rename(archiveTemporary, archive)
  archiveTemporary = undefined
  console.log(
    `PASS: Release validation (${fileCount} files), checksums and runtime tests.\nArchive: ${archive}`,
  )
} catch (error) {
  if (verification) await rm(verification, { recursive: true, force: true })
  if (staging) await rm(staging, { recursive: true, force: true })
  if (archiveTemporary) await rm(archiveTemporary, { force: true })
  console.error(`Release failed: ${error.message}. No final archive created.`)
  process.exitCode = 1
}
