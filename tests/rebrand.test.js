import { productMarkdown, productOpenApi } from '../deployment/product-presentation.mjs'
import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { contrast, brandTokens } from '../src/services/branding.js'

test('Dev2Done action, text/link and focus tokens retain accessible contrast in both themes', async () => {
  const css = await readFile(new URL('../src/assets/main.css', import.meta.url), 'utf8')
  const blocks = [
    css.match(/:root \{([\s\S]*?)\n\}/)[1],
    css.match(/:root\[data-theme='dark'\] \{([\s\S]*?)\n\}/)[1],
  ]
  for (const [index, block] of blocks.entries()) {
    const tokens = Object.fromEntries(
      [...block.matchAll(/--([a-z-]+):\s*(#[a-f0-9]{6}|#fff);/g)].map(([, key, value]) => [
        key,
        value === '#fff' ? '#ffffff' : value,
      ]),
    )
    assert.ok(contrast(tokens.action, tokens['on-action']) >= 4.5)
    assert.ok(contrast(tokens['action-hover'], tokens['on-action']) >= 4.5)
    assert.ok(contrast(tokens.link, tokens.surface) >= 4.5)
    assert.ok(contrast(tokens.focus, tokens.page) >= 3)
    assert.ok(contrast(tokens['nav-selected-text'], tokens['nav-selected-bg']) >= 4.5)
    const demo = brandTokens('#5b4bd6', index === 1)
    assert.ok(contrast(demo['--action'], demo['--on-action']) >= 4.5)
    assert.ok(contrast(demo['--link'], index ? '#1e252e' : '#ffffff') >= 4.5)
  }
})
test('the provided D/check SVG remains the single master and installed labels use Dev2Done/D2D', async () => {
  const svg = await readFile(new URL('../src/assets/brand-mark.svg', import.meta.url), 'utf8')
  assert.match(svg, /viewBox="0 0 220 128"/)
  assert.equal((svg.match(/<linearGradient\b/g) || []).length, 3)
  assert.equal((svg.match(/<path\b/g) || []).length, 4)
  assert.ok(svg.includes('M109 13H153C191 13 212 34 212 64S191 115 153 115H109'))
  assert.ok(svg.includes('m150 64 12 12 24-26'))
  assert.ok(svg.includes('stroke="#FFFFFF"'))
  const manifest = JSON.parse(
    await readFile(new URL('../public/manifest.webmanifest', import.meta.url)),
  )
  assert.equal(manifest.name, 'Dev2Done')
  assert.equal(manifest.short_name, 'D2D')
  assert.equal(manifest.id, '/')
  assert.equal(manifest.start_url, '/app')
})

test('portable integration prose rebrands without changing legacy API headers or executable examples', () => {
  const source =
    '# Project Brief API\nProjectBrief integrates with CRM. Header: `X-ProjectBrief-Agent-ID`.\n```js\nconst ProjectBrief = "stable-identifier"\n```\n'
  const prose = productMarkdown(source)
  assert.ok(prose.startsWith('# Dev2Done API'))
  assert.ok(prose.includes('Dev2Done integrates'))
  assert.ok(prose.includes('X-ProjectBrief-Agent-ID'))
  assert.ok(prose.includes('const ProjectBrief = "stable-identifier"'))
  const api = {
    info: { title: 'Project Brief API' },
    components: { securitySchemes: { agent: { name: 'X-ProjectBrief-Agent-ID' } } },
  }
  assert.equal(productOpenApi(api).info.title, 'Dev2Done API')
  assert.equal(productOpenApi(api).components.securitySchemes.agent.name, 'X-ProjectBrief-Agent-ID')
  assert.equal(api.info.title, 'Project Brief API')
})
