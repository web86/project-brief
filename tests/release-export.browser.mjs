import assert from 'node:assert/strict'
import { mkdir, writeFile } from 'node:fs/promises'
import path from 'node:path'

// Optional release-runtime hook: all app assets/vendor come from the unpacked ZIP.
// Downloads and screenshots stay outside the installation and production package.
export async function verifyPackagedExports(origin, project, outputDirectory) {
  const { chromium, firefox } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
  const output = path.resolve(outputDirectory)
  await mkdir(output, { recursive: true })
  const results = []
  for (const engine of [chromium, firefox]) {
    const browser = await engine.launch({ headless: true })
    try {
      for (const width of [1440, 390]) {
        const context = await browser.newContext({
          viewport: { width, height: width === 390 ? 844 : 1000 },
          acceptDownloads: true,
        })
        try {
          const page = await context.newPage()
          const errors = []
          page.on('pageerror', (error) => errors.push(error.message))
          await page.goto(origin + '/admin/login')
          await page.locator('#admin-email').fill('release@example.test')
          await page.locator('#admin-password').fill('Release-only-long-password')
          await page.locator('form button[type=submit]').click()
          await page.waitForURL(origin + '/admin')
          for (const locale of ['ru', 'en', 'tr', 'zh-CN']) {
            await page.goto(origin + `/admin/projects/${project}/export`)
            await page.locator('.export-form').waitFor()
            await page.locator('.language-switcher select').selectOption(locale)
            await page.locator('#export-language').selectOption(locale)
            assert.ok(
              await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
            )
            const name = `${engine.name()}-${width}-${locale}`
            await page.screenshot({ path: path.join(output, name + '.png'), fullPage: true })
            for (const format of width === 1440 && engine === chromium
              ? ['pdf', 'docx']
              : ['pdf']) {
              const downloaded = page.waitForEvent('download', { timeout: 60000 })
              await page
                .locator(
                  format === 'pdf'
                    ? '.export-actions button[type=submit]'
                    : '.export-actions button[type=button]',
                )
                .first()
                .click()
              const file = await downloaded
              assert.equal(await file.failure(), null)
              await file.saveAs(path.join(output, name + '.' + format))
            }
            results.push({
              engine: engine.name(),
              version: browser.version(),
              width,
              locale,
              status: 'passed',
            })
            console.log('PASS: Clean ZIP export', name)
          }
          assert.deepEqual(errors, [])
        } finally {
          await context.close()
        }
      }
    } finally {
      await browser.close()
    }
  }
  await writeFile(
    path.join(output, 'browser-results.json'),
    JSON.stringify(
      {
        source: 'clean production ZIP',
        results,
        pdfDownloads: 16,
        docxDownloads: 4,
        physicalMobile: 'not tested',
        safari: 'not tested',
      },
      null,
      2,
    ) + '\n',
  )
}
