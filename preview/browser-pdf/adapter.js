import { api } from '../../src/api/client.js'

export function createBrowserPdfAdapter({
  request = api.request,
  workerFactory = () => new Worker(new URL('./pdf.worker.js', import.meta.url), { type: 'module' }),
} = {}) {
  return async function exportPdf({ projectId, client, options, signal, onStage = () => {} }) {
    signal?.throwIfAborted()
    const started = performance.now()
    onStage('preparing')
    const payload = await request(
      client
        ? `/api/client/project/${projectId}/export-data`
        : `/api/admin/projects/${projectId}/export-data`,
      {
        method: 'POST',
        body: { ...options, format: 'pdf' },
        signal,
      },
    )
    signal?.throwIfAborted()
    if (payload.schemaVersion !== 1 || !payload.document?.sections?.length)
      throw new Error('invalid_export_data')
    const apiMs = performance.now() - started
    const worker = workerFactory()
    try {
      const result = await new Promise((resolve, reject) => {
        const timeout = setTimeout(
          () => finish(reject, new Error('pdf_generation_timeout')),
          120000,
        )
        const abort = () =>
          finish(reject, signal.reason || new DOMException('Cancelled', 'AbortError'))
        signal?.addEventListener('abort', abort, { once: true })
        const finish = (callback, value) => {
          clearTimeout(timeout)
          signal?.removeEventListener('abort', abort)
          callback(value)
        }
        worker.onmessage = ({ data }) => {
          if (data.stage === 'error') finish(reject, new Error(data.code))
          else if (data.stage === 'complete') finish(resolve, data)
          else onStage(data.stage)
        }
        worker.onerror = () => finish(reject, new Error('pdf_generation_failed'))
        if (signal?.aborted) abort()
        else worker.postMessage({ document: payload.document })
      })
      signal?.throwIfAborted()
      onStage('downloading')
      return {
        blob: new Blob([result.buffer], { type: 'application/pdf' }),
        filename: payload.filename,
        metrics: { apiMs, ...result.metrics, totalMs: performance.now() - started },
      }
    } finally {
      worker.terminate()
    }
  }
}
