import test from 'node:test'
import assert from 'node:assert/strict'
import { mkdtemp, mkdir, writeFile, rm, readFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
import {
  forbiddenPath,
  pruneVendor,
  checksums,
  containsPrivateKey,
} from '../deployment/release-files.mjs'

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
