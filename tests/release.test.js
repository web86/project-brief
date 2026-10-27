import test from 'node:test'
import assert from 'node:assert/strict'
import { mkdtemp, mkdir, writeFile, rm, readFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
import {
  forbiddenPath,
  forbiddenProductionPath,
  pruneVendor,
  checksums,
  containsPrivateKey,
  environmentSecrets,
} from '../deployment/release-files.mjs'

test('production package rejects historical guides and verification artifacts at any depth', () => {
  for (const file of [
    'GUIDES/milestone-6-2/README.md',
    'INTEGRATIONS/openapi.json',
    'docs/milestone-6-0/sample-ru.docx',
    'RELEASE-NOTES.md',
    'browser-results.json',
    'deployment/browser-results.json',
    'private/project-brief-app/sample-zh-CN.docx',
    'private/project-brief-app/storage/export.pdf',
    'public/assets/sample-en.docx',
    'public/assets/screenshots/desktop.png',
    'public/assets/desktop-screenshot.png',
    'private/project-brief-app/reports/checks.json',
    'private/project-brief-app/milestone-7-0/README.md',
    'private/other-app/index.php',
    'public/assets/frontend.js.map',
  ])
    assert.equal(forbiddenProductionPath(file), true, file)
  for (const file of [
    'DEPLOY-JINO.md',
    'PRODUCTION.md',
    'release.json',
    'checksums.txt',
    'deployment/telegram-bridge.mjs',
    'public/index.php',
    'public/icon-192.png',
    'public/assets/pdf.worker-abc.js',
    'public/assets/NotoSansSC-Regular-abc.ttf',
    'public/font-licenses/NotoSansSC-OFL.txt',
    'private/project-brief-app/resources/fonts/NotoSansSC-Regular.ttf',
    'private/project-brief-app/resources/fonts/SOURCE.md',
    'private/project-brief-app/.env.production.example',
    'private/project-brief-app/vendor/phpoffice/phpword/src/PhpWord/resources/doc.png',
    'private/project-brief-app/vendor/phpoffice/phpword/LICENSE',
  ])
    assert.equal(forbiddenProductionPath(file), false, file)
})

test('release scans API keys and bridge secrets without treating AI settings as secrets', () => {
  assert.deepEqual(
    environmentSecrets(
      [
        'GROQ_API_KEY="synthetic-groq-key"',
        'ZVENO_API_KEY=synthetic-zveno-key',
        'AI_BRIDGE_SECRET=synthetic-bridge-secret',
        'DB_PASSWORD=synthetic-db-password',
        'APP_KEY=base64:synthetic-key',
        'AI_ENABLED=false',
        'AI_PROVIDER=groq_bridge',
        'GROQ_MODEL=openai/gpt-oss-120b',
        'GROQ_API_KEY=',
      ].join('\n'),
    ),
    [
      'synthetic-groq-key',
      'synthetic-zveno-key',
      'synthetic-bridge-secret',
      'synthetic-db-password',
      'base64:synthetic-key',
    ],
  )
})

test('release rejects local secrets, development directories and source maps at any depth', () => {
  for (const file of [
    '.env',
    'app/.DS_Store',
    'private/app/.env.local',
    'private/app/local-admin.json',
    'vendor/package/tests/Fixture.php',
    'vendor/package/Test/Example.php',
    '.tools/composer.phar',
    'public/assets/app.js.map',
    'private/id_rsa.key',
    'credentials.json',
    'secrets/token.txt',
    '.git/config',
    'node_modules/vue/index.js',
    'private/database/database.sqlite',
    'public/vendor/autoload.php',
    'public/config/app.php',
    'public/.env.production.example',
    'public/composer.json',
    'public/source.vue',
  ]) {
    assert.equal(forbiddenPath(file), true, file)
  }
  for (const file of [
    'private/project-brief-app/.env.production.example',
    'vendor/autoload.php',
    'vendor/symfony/console/Tester/CommandTester.php',
    'app/Rules/SafeAttachment.php',
  ]) {
    assert.equal(forbiddenPath(file), false, file)
  }
})

test('vendor cleanup removes development fixtures while retaining runtime code and licenses', async () => {
  const root = await mkdtemp(path.join(tmpdir(), 'project-brief-vendor-'))
  try {
    await mkdir(path.join(root, 'package/tests'), { recursive: true })
    await writeFile(path.join(root, 'package/tests/private.key'), 'fixture')
    await writeFile(path.join(root, 'package/runtime.php'), '<?php')
    await writeFile(path.join(root, 'package/LICENSE'), 'license')
    await pruneVendor(root)
    assert.equal(await readFile(path.join(root, 'package/runtime.php'), 'utf8'), '<?php')
    assert.equal(await readFile(path.join(root, 'package/LICENSE'), 'utf8'), 'license')
    await assert.rejects(readFile(path.join(root, 'package/tests/private.key')), { code: 'ENOENT' })
  } finally {
    await rm(root, { recursive: true, force: true })
  }
})

test('vendor cleanup preserves the WebAuthn Credential runtime class without allowing credential secrets', async () => {
  const root = await mkdtemp(path.join(tmpdir(), 'project-brief-vendor-webauthn-'))
  const runtime = 'web-auth/webauthn-lib/src/Credential.php'
  try {
    await mkdir(path.join(root, path.dirname(runtime)), { recursive: true })
    await writeFile(
      path.join(root, runtime),
      '<?php namespace Webauthn; abstract class Credential {}',
    )
    await writeFile(path.join(root, 'credentials.json'), 'secret')
    const consoleRuntime = 'laravel/framework/src/Illuminate/Console/View/Components/Secret.php'
    await mkdir(path.join(root, path.dirname(consoleRuntime)), { recursive: true })
    await writeFile(path.join(root, consoleRuntime), '<?php namespace Illuminate; class Secret {}')
    await pruneVendor(root)
    assert.match(await readFile(path.join(root, runtime), 'utf8'), /abstract class Credential/)
    assert.match(await readFile(path.join(root, consoleRuntime), 'utf8'), /class Secret/)
    await assert.rejects(readFile(path.join(root, 'credentials.json')), { code: 'ENOENT' })
    assert.equal(forbiddenProductionPath('private/project-brief-app/vendor/' + runtime), false)
    for (const file of [
      'Credential.php',
      'app/Credential.php',
      'private/project-brief-app/Credential.php',
      'vendor/package/Credential.php',
      'credentials.json',
      'secrets.php',
      'public/vendor/' + runtime,
    ])
      assert.equal(forbiddenPath(file), true, file)
  } finally {
    await rm(root, { recursive: true, force: true })
  }
})

test('release checksums are stable, relative and exclude their own manifest', async () => {
  const root = await mkdtemp(path.join(tmpdir(), 'project-brief-checksums-'))
  try {
    await writeFile(path.join(root, 'b.txt'), 'abc')
    await writeFile(path.join(root, 'checksums.txt'), 'previous')
    assert.equal(
      await checksums(root),
      'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad  b.txt\n',
    )
  } finally {
    await rm(root, { recursive: true, force: true })
  }
})

test('release rejects PEM material including escaped JSON keys while allowing crypto serializer source', () => {
  const material = 'M'.repeat(64) + '='
  const key = '-----BEGIN EC PRIVATE KEY-----\n' + material + '\n-----END EC PRIVATE KEY-----'
  assert.equal(containsPrivateKey(key), true)
  assert.equal(containsPrivateKey(JSON.stringify({ key })), true)
  assert.equal(
    containsPrivateKey(
      '-----BEGIN RSA PRIVATE KEY-----\nProc-Type: 4,ENCRYPTED\nDEK-Info: AES-256-CBC,FFFF\n\n' +
        material +
        '\n-----END RSA PRIVATE KEY-----',
    ),
    true,
  )
  assert.equal(containsPrivateKey('$pem = \'-----BEGIN EC PRIVATE KEY-----\' . "\\n";'), false)
  assert.equal(containsPrivateKey('-----BEGIN PRIVATE KEY-----'), false)
})
