import { documentDefinition, needsCjk } from './definition.js'
import { fontAssets } from './fonts.js'
import { loadPublicFont } from './font-cache.js'

self.onmessage = async ({ data }) => {
  try {
    const started = performance.now()
    self.postMessage({ stage: 'library' })
    const module = await import('pdfmake/build/pdfmake.js')
    const pdfMake = module.default || module
    const libraryMs = performance.now() - started
    self.postMessage({ stage: 'fonts' })
    const fontStarted = performance.now()
    const families = needsCjk(data.document) ? ['DejaVu', 'NotoSC'] : ['DejaVu']
    const files = {}
    const fonts = {}
    let fontBytes = 0
    let fontCacheHits = 0
    await Promise.all(
      families.flatMap((family) =>
        ['normal', 'bold'].map(async (weight) => {
          const url = fontAssets[family][weight]
          const { response, cached } = await loadPublicFont(url)
          if (cached) fontCacheHits++
          if (!response.ok) throw new Error('font_load_failed')
          const bytes = new Uint8Array(await response.arrayBuffer())
          fontBytes += bytes.byteLength
          const name = `${family}-${weight}.ttf`
          files[name] = { data: bytes, encoding: 'binary' }
          fonts[family] ||= {}
          fonts[family][weight] = name
        }),
      ),
    )
    for (const family of families) {
      fonts[family].italics = fonts[family].normal
      fonts[family].bolditalics = fonts[family].bold
    }
    pdfMake.addVirtualFileSystem(files)
    pdfMake.addFonts(fonts)
    const fontsMs = performance.now() - fontStarted
    self.postMessage({ stage: 'generating' })
    const renderStarted = performance.now()
    const result = await pdfMake.createPdf(documentDefinition(data.document)).getBuffer()
    const buffer = result.buffer.slice(result.byteOffset, result.byteOffset + result.byteLength)
    const metrics = {
      libraryMs,
      fontsMs,
      fontBytes,
      fontCacheHits,
      renderMs: performance.now() - renderStarted,
      workerMs: performance.now() - started,
      cjk: families.includes('NotoSC'),
      fontResources: performance
        .getEntriesByType('resource')
        .filter((entry) => /\.ttf(?:\?|$)/.test(entry.name))
        .map((entry) => ({
          transferSize: entry.transferSize,
          decodedBodySize: entry.decodedBodySize,
          duration: entry.duration,
        })),
    }
    self.postMessage({ stage: 'complete', buffer, metrics }, [buffer])
  } catch (error) {
    self.postMessage({
      stage: 'error',
      code: error.message === 'font_load_failed' ? 'font_load_failed' : 'pdf_generation_failed',
    })
  }
}
