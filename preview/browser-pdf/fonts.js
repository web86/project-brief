// Static asset URLs: external TTF files, never a VFS/base64 payload in the application bundle.
export const fontAssets = {
  DejaVu: {
    normal: new URL('../../api/vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf', import.meta.url)
      .href,
    bold: new URL('../../api/vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf', import.meta.url)
      .href,
  },
  NotoSC: {
    normal: new URL('../../api/resources/fonts/NotoSansSC-Regular.ttf', import.meta.url).href,
    bold: new URL('../../api/resources/fonts/NotoSansSC-Bold.ttf', import.meta.url).href,
  },
}
