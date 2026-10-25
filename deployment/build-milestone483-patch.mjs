import { spawnSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { access, cp, mkdir, mkdtemp, readFile, rename, rm, writeFile } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import {
  checksums,
  entries,
  environmentSecrets,
  normalizePermissions,
  validateRelease,
} from './release-files.mjs'
const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)))
const release = path.join(root, 'release')
const base =
  process.env.M483_BASE_ZIP || path.join(release, 'project-brief-20261025-182822-be2b883.zip')
const php = process.env.PHP_BIN || 'php'
const runtimeFiles = [
  'app/Http/Controllers/ProjectDiscussionController.php',
  'app/Services/ProjectDiscussions.php',
]

const hash = (bytes) => createHash('sha256').update(bytes).digest('hex')
function run(command, args, cwd = root) {
  const r = spawnSync(command, args, {
    cwd,
    env: { ...process.env, VITE_DATA_SOURCE: 'api', VITE_API_BASE_URL: '' },
    encoding: 'utf8',
  })
  if (r.status !== 0 || r.error)
    throw new Error(`Failed: ${command} ${args.join(' ')}\n${r.stderr || ''}`)
  return r.stdout.trim()
}
function baseFile(file) {
  const r = spawnSync('unzip', ['-p', base, file], { maxBuffer: 32 * 1024 * 1024 })
  if (r.status === 11) return null
  if (r.status !== 0) throw new Error('Cannot read base ZIP entry: ' + file)
  return r.stdout
}
function clean() {
  if (run('git', ['status', '--porcelain']))
    throw new Error('Commit changes before packaging a reproducible patch.')
}
let scratch, partial
try {
  clean()
  await access(base)
  const baseManifest = JSON.parse(baseFile('release.json'))
  const baseCommit = run('git', ['rev-parse', 'be2b883'])
  if (baseManifest.commit !== baseCommit || baseManifest.version !== '0.4.8.2')
    throw new Error('The base ZIP must be exactly Milestone 4.8.2 commit be2b883.')
  const commit = run('git', ['rev-parse', 'HEAD'])
  const changedRuntime = run('git', [
    'diff',
    '--name-only',
    baseCommit,
    'HEAD',
    '--',
    'api/app',
    'api/config',
    'api/routes',
    'api/database/migrations',
  ])
    .split('\n')
    .filter(Boolean)
  if (
    changedRuntime.length !== runtimeFiles.length ||
    changedRuntime.some((file) => !runtimeFiles.includes(file.replace(/^api\//, '')))
  )
    throw new Error('Unexpected backend changes; review the patch allowlist.')
  run('npm', ['run', 'build'])
  await mkdir(release, { recursive: true })
  scratch = await mkdtemp(path.join(release, '.building-m483-'))
  const patch = path.join(scratch, 'patch'),
    merged = path.join(scratch, 'merged')
  await mkdir(patch, { recursive: true })
  await mkdir(merged, { recursive: true })
  const manifest = []
  async function add(source, destination) {
    const bytes = await readFile(source),
      previous = baseFile(destination)
    if (previous && hash(previous) === hash(bytes)) return
    await mkdir(path.dirname(path.join(patch, destination)), { recursive: true })
    await cp(source, path.join(patch, destination))
    manifest.push({
      path: destination,
      sha256: hash(bytes),
      baseSha256: previous ? hash(previous) : null,
    })
  }
  for (const file of runtimeFiles)
    await add(path.join(root, 'api', file), 'private/project-brief-app/' + file)
  for (const entry of await entries(path.join(root, 'dist')))
    if (!entry.directory)
      await add(path.join(root, 'dist', entry.relative), 'public/' + entry.relative)
  await add(path.join(root, 'docs/milestone-4-8-3/PRODUCTION.md'), 'PRODUCTION.md')
  await add(path.join(root, 'docs/milestone-4-8-3/RELEASE.md'), 'RELEASE-NOTES.md')
  const releaseMetadata = {
    ...baseManifest,
    version: '0.4.8.3',
    commit,
    builtAt: new Date().toISOString(),
  }
  const metadataPath = path.join(scratch, 'release.json')
  await writeFile(metadataPath, JSON.stringify(releaseMetadata, null, 2) + '\n')
  await add(metadataPath, 'release.json')
  const metadata = {
    version: '0.4.8.3',
    baseVersion: '0.4.8.2',
    baseCommit,
    commit,
    builtAt: new Date().toISOString(),
    migrations: false,
    vendorUpdate: false,
    deleteOldAssets: false,
    files: manifest,
  }
  await writeFile(path.join(patch, 'PATCH.json'), JSON.stringify(metadata, null, 2) + '\n')
  await cp(path.join(root, 'docs/milestone-4-8-3/PRODUCTION.md'), path.join(patch, 'PATCH.md'))
  await writeFile(
    path.join(patch, 'BASE-CHECKSUMS.txt'),
    manifest
      .filter((file) => file.baseSha256)
      .map((file) => `${file.baseSha256}  ${file.path}`)
      .join('\n') + '\n',
  )
  await writeFile(path.join(patch, 'checksums.txt'), await checksums(patch))
  run('unzip', ['-q', base, '-d', merged])
  await cp(patch, merged, { recursive: true })
  const secrets = []
  for (const file of ['.env', 'api/.env'])
    secrets.push(
      ...environmentSecrets(await readFile(path.join(root, file), 'utf8').catch(() => '')),
    )
  await validateRelease(merged, secrets)
  run(process.execPath, ['tests/release-runtime.mjs', merged])
  await validateRelease(merged, secrets)
  await normalizePermissions(patch)
  clean()
  const name = `project-brief-0.4.8.3-patch-${commit.slice(0, 7)}.zip`,
    archive = path.join(release, name)
  partial = archive + '.partial'
  run(php, ['deployment/archive.php', patch, partial])
  await rename(partial, archive)
  partial = null
  await writeFile(archive + '.sha256', `${hash(await readFile(archive))}  ${name}\n`)
  const directory = path.join(release, 'milestone-4-8-3-patch')
  await rm(directory, { recursive: true, force: true })
  await rename(patch, directory)
  console.log(
    `PASS: ${manifest.length} changed runtime/assets files; base commit verified, secrets scan, merged packaged runtime, ZIP CRC/content checksums.\nArchive: ${archive}`,
  )
} catch (error) {
  console.error(error.message)
  process.exitCode = 1
} finally {
  if (partial) await rm(partial, { force: true })
  if (scratch) await rm(scratch, { recursive: true, force: true })
}
