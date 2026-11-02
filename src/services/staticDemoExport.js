// Build-time replacement: the static viewer includes export settings, without a PDF worker.
export function createBrowserPdfAdapter() {
  return async () => {
    throw Object.assign(new Error('Demo · View only'), { status: 403, code: 'demo_read_only' })
  }
}
