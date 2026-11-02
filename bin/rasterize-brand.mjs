// Developer-only SVG rasterization. Release verification uses committed byte checksums.
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
let source = ''
for await (const chunk of process.stdin) source += chunk
const input = JSON.parse(source)
const browser = await chromium.launch({ headless: true })
try {
  const page = await browser.newPage()
  const result = await page.evaluate(async (specifications) => {
    const result = {}
    for (const { name, svg, width, height } of specifications) {
      const blob = new Blob([svg], { type: 'image/svg+xml' })
      const url = URL.createObjectURL(blob)
      try {
        const image = new Image()
        image.src = url
        await image.decode()
        const canvas = document.createElement('canvas')
        canvas.width = width
        canvas.height = height
        canvas.getContext('2d').drawImage(image, 0, 0, width, height)
        result[name] = canvas.toDataURL('image/png').split(',')[1]
      } finally {
        URL.revokeObjectURL(url)
      }
    }
    return result
  }, input)
  process.stdout.write(JSON.stringify(result))
} finally {
  await browser.close()
}
