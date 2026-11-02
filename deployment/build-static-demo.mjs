import { staticImmutablePattern } from './static-demo-cache.mjs'
import { spawnSync } from 'node:child_process'
import { cp, mkdir, readFile, readdir, rm, writeFile, rename, mkdtemp } from 'node:fs/promises'
import { createHash } from 'node:crypto'
import path from 'node:path'
import { tmpdir } from 'node:os'
import { validateStaticDemo } from './validate-static-demo.mjs'
const root = new URL('..', import.meta.url).pathname
process.chdir(root)
const preview = process.argv.includes('--preview')
function run(command, args, env = {}) {
  const result = spawnSync(command, args, {
    encoding: 'utf8',
    env: { ...process.env, ...env },
    stdio: ['ignore', 'pipe', 'pipe'],
  })
  if (result.status !== 0) throw new Error(`${command} failed: ${result.stdout}\n${result.stderr}`)
  return result.stdout.trim()
}
const commit = run('git', ['rev-parse', 'HEAD'])
if (!preview && run('git', ['status', '--porcelain']))
  throw new Error('Commit the static demo source before packaging its immutable release.')
run(process.execPath, ['deployment/export-static-demo.mjs'])
const exported = path.join(root, '.tools/demo-runtime/static-export')
const result = await validateStaticDemo(exported)
run('npm', ['exec', '--', 'vite', 'build'], { PROJECTBRIEF_STATIC_DEMO: '1' })
const output = path.join(root, '.tools/static-demo-build')
for (const file of result.files) {
  await mkdir(path.dirname(path.join(output, file)), { recursive: true })
  await cp(path.join(exported, file), path.join(output, file))
}
await mkdir(path.join(output, 'schemas'))
await cp(
  'docs/demo/static/contracts-v1.schema.json',
  path.join(output, 'schemas/contracts-v1.schema.json'),
)
await rm(path.join(output, 'sw.js'), { force: true })
const webmanifest = JSON.parse(await readFile(path.join(output, 'manifest.webmanifest')))
Object.assign(webmanifest, {
  name: 'Dev2Done · Static Demo',
  short_name: 'D2D',
  start_url: '/demo',
})
await writeFile(
  path.join(output, 'manifest.webmanifest'),
  JSON.stringify(webmanifest, null, 2) + '\n',
)
const csp =
  "default-src 'self'; connect-src 'self'; img-src 'self' data: blob:; font-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; object-src 'none'; frame-src 'none'; worker-src 'none'; form-action 'none'; base-uri 'self'"
const html = await readFile(path.join(output, 'index.html'), 'utf8')
await writeFile(
  path.join(output, 'index.html'),
  html.replace('<head>', `<head>\n<meta http-equiv="Content-Security-Policy" content="${csp}">`),
)
await writeFile(
  path.join(output, '.htaccess'),
  `Options -Indexes -MultiViews
DirectoryIndex index.html
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule ^(?:api|access|client-sso|platform)(?:/|$) - [R=404,L]
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]
RewriteRule ^(?:assets|snapshots|demo-assets|schemas)/ - [R=404,L]
RewriteRule \\.(?:json|js|css|png|svg|webp|txt|webmanifest)$ - [R=404,L]
RewriteRule ^ index.html [L]
</IfModule>
<IfModule mod_headers.c>
Header always set X-Content-Type-Options "nosniff"
Header always set Referrer-Policy "no-referrer"
Header always set Content-Security-Policy "${csp}"
<FilesMatch "^(?:index\\.html|manifest\\.json|manifest\\.webmanifest|release\\.json)$">
Header set Cache-Control "no-cache, must-revalidate"
</FilesMatch>
<FilesMatch "${staticImmutablePattern}">
Header set Cache-Control "public, max-age=31536000, immutable"
</FilesMatch>
</IfModule>
AddType application/json .json
`,
)
const { files, manifest, ...validation } = result
await writeFile(
  path.join(output, 'release.json'),
  JSON.stringify(
    {
      type: 'project-brief-static-demo',
      product: 'Dev2Done',
      version: 2,
      schemaVersion: 1,
      commit,
      preview,
      builtAt: new Date().toISOString(),
      sourceContentHash: manifest.contentHash,
      runtime: 'static HTTP only; no PHP, database or authentication server',
    },
    null,
    2,
  ) + '\n',
)
await writeFile(
  path.join(output, 'validation-report.json'),
  JSON.stringify(validation, null, 2) + '\n',
)
await writeFile(
  path.join(output, 'README.txt'),
  'Dev2Done Static Demo\nUpload this directory to a separate static web root (demo.brief.web86.site). Never upload the production PHP package here. Apache requires mod_rewrite and AllowOverride for .htaccess; configure equivalent SPA fallback elsewhere. /api always returns 404. No backend or database is needed. Entry: /demo. Keep manifest/index uncached and retain older hashed assets during updates. See the project docs/demo/static/HOSTING.md for independent installation and rollback.\n',
)
async function list(directory, prefix = '') {
  const files = []
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    const name = prefix + entry.name
    if (entry.isDirectory())
      files.push(...(await list(path.join(directory, entry.name), name + '/')))
    else files.push(name)
  }
  return files.sort()
}
const sha = (bytes) => createHash('sha256').update(bytes).digest('hex')
const contents = await list(output)
for (const file of contents) {
  if (
    /\.(?:php|sqlite|sql|env|pem|key)$/i.test(file) ||
    /(?:vendor|node_modules|storage|bootstrap)\//.test(file)
  )
    throw new Error('Server dependency in static package: ' + file)
}
if (contents.some((file) => /(?:pdf\.worker|\.ttf$|pdfmake-)/.test(file)))
  throw new Error('Unused export worker/fonts remain in static build')
const checksumText = (
  await Promise.all(
    contents.map(async (file) => `${sha(await readFile(path.join(output, file)))}  ${file}\n`),
  )
).join('')
await writeFile(path.join(output, 'checksums.txt'), checksumText)
await validateStaticDemo(output)
if (!preview) {
  await mkdir('release', { recursive: true })
  const archive = path.join(root, 'release', `dev2done-static-demo-v2-${commit.slice(0, 7)}.zip`)
  const temporary = archive + '.partial'
  const extracted = await mkdtemp(path.join(tmpdir(), 'brief-static-package-'))
  try {
    run('python3', [
      '-c',
      `import pathlib,sys,zipfile\nsource=pathlib.Path(sys.argv[1])\nwith zipfile.ZipFile(sys.argv[2],'w',zipfile.ZIP_DEFLATED,compresslevel=9) as archive:\n for file in sorted(source.rglob('*')):\n  if file.is_file(): archive.write(file,file.relative_to(source))\nwith zipfile.ZipFile(sys.argv[2]) as archive:\n assert archive.testzip() is None\n archive.extractall(sys.argv[3])`,
      output,
      temporary,
      extracted,
    ])
    for (const file of contents)
      if (
        sha(await readFile(path.join(output, file))) !==
        sha(await readFile(path.join(extracted, file)))
      )
        throw new Error('ZIP integrity mismatch: ' + file)
    await validateStaticDemo(extracted)
    await rename(temporary, archive)
    const checksum = sha(await readFile(archive))
    await writeFile(archive + '.sha256', `${checksum}  ${path.basename(archive)}\n`)
    console.log(
      JSON.stringify({ passed: true, archive, sha256: checksum, commit, ...validation }, null, 2),
    )
  } finally {
    await rm(extracted, { recursive: true, force: true })
    await rm(temporary, { force: true })
  }
} else console.log(JSON.stringify({ preview: true, output, ...validation }, null, 2))
