export const brandingFields = {
  visual: { companyName: 160, primaryColor: 7 },
  business: {
    legalName: 255,
    taxId: 80,
    bankName: 160,
    payee: 160,
    account: 100,
    bic: 40,
    note: 2000,
  },
  contact: {
    country: 100,
    city: 100,
    address: 255,
    postalCode: 30,
    phone: 80,
    email: 254,
    website: 2048,
    extra: 1000,
  },
}
export const brandPresets = ['#2454a5', '#17695d', '#7354a3', '#a23c65', '#915314', '#384655']
const rgb = (value) => value.match(/[a-f0-9]{2}/gi).map((v) => parseInt(v, 16))
export const luminance = (color) =>
  rgb(color)
    .map((v) => v / 255)
    .map((v) => (v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4))
    .reduce((sum, v, i) => sum + v * [0.2126, 0.7152, 0.0722][i], 0)
export const contrast = (a, b) =>
  (Math.max(luminance(a), luminance(b)) + 0.05) / (Math.min(luminance(a), luminance(b)) + 0.05)
export function brandTokens(color, dark = false) {
  if (!/^#[a-f0-9]{6}$/i.test(color || '')) return {}
  const background = dark ? '#1e252e' : '#ffffff'
  let link = color
  for (let i = 0; contrast(link, background) < 4.5 && i < 50; i++) {
    link =
      '#' +
      rgb(link)
        .map((v) =>
          Math.round(v * 0.9 + (dark ? 255 : 0) * 0.1)
            .toString(16)
            .padStart(2, '0'),
        )
        .join('')
  }
  return {
    '--action': color,
    '--action-hover': color,
    '--on-action': contrast(color, '#ffffff') >= 4.5 ? '#ffffff' : '#000000',
    '--link': link,
    '--progress': link,
    '--focus': link,
  }
}
export async function svgRaster(svg) {
  const url = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml' }))
  try {
    const image = new Image()
    await new Promise((resolve, reject) => {
      image.onload = resolve
      image.onerror = reject
      image.src = url
    })
    const canvas = document.createElement('canvas')
    canvas.width = Math.max(1, Math.min(1024, image.naturalWidth))
    canvas.height = Math.max(1, Math.min(1024, image.naturalHeight))
    canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height)
    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'))
    if (!blob) throw Error('image_conversion_failed')
    return new File([blob], 'preview.png', { type: 'image/png' })
  } finally {
    URL.revokeObjectURL(url)
  }
}
