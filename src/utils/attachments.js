import { t } from '../i18n/index.js'
export const ACCEPTED_EXTENSIONS = [
  'jpg',
  'jpeg',
  'png',
  'webp',
  'pdf',
  'doc',
  'docx',
  'txt',
  'zip',
]
export const FILE_ACCEPT = ACCEPTED_EXTENSIONS.map((extension) => `.${extension}`).join(',')
export const MAX_FILES = 10
export const MAX_FILE_SIZE = 20 * 1024 * 1024

// Read only the user's paste event; no clipboard permission or background reads.
export function getPastedImages(clipboardData) {
  const extensions = { 'image/png': 'png', 'image/jpeg': 'jpg', 'image/webp': 'webp' }
  return Array.from(clipboardData?.items || [])
    .filter((item) => item.kind === 'file' && item.type.startsWith('image/'))
    .map((item) => item.getAsFile())
    .filter(Boolean)
    .map((file) => {
      const extension = extensions[file.type]
      if (!extension) return file
      // Clipboard screenshots often share the name image.png and the same size.
      // Give each paste a unique name so two different screenshots are not deduplicated.
      const name = t('ui.screenshot', { arg0: crypto.randomUUID().slice(0, 8), arg1: extension })
      return new File([file], name, { type: file.type, lastModified: file.lastModified })
    })
}

function readDataUrl(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => resolve(reader.result)
    reader.onerror = () => reject(new Error(t('ui.weCouldNotReadThisImage')))
    reader.readAsDataURL(file)
  })
}

async function createPreview(file) {
  const source = await readDataUrl(file)
  const image = new Image()
  await new Promise((resolve, reject) => {
    image.onload = resolve
    image.onerror = reject
    image.src = source
  })
  const canvas = document.createElement('canvas')
  const scale = Math.min(1, 1000 / Math.max(image.naturalWidth, image.naturalHeight))
  canvas.width = Math.max(1, Math.round(image.naturalWidth * scale))
  canvas.height = Math.max(1, Math.round(image.naturalHeight * scale))
  const context = canvas.getContext('2d')
  context.fillStyle = '#ffffff'
  context.fillRect(0, 0, canvas.width, canvas.height)
  context.drawImage(image, 0, 0, canvas.width, canvas.height)
  const preview = canvas.toDataURL('image/jpeg', 0.7)
  return preview.length <= 180000 ? preview : null
}

export async function prepareAttachment(file) {
  const extension = file.name.split('.').pop().toLowerCase()
  if (!ACCEPTED_EXTENSIONS.includes(extension))
    throw new Error(t('ui.thisFileFormatIsNotSupported', { arg0: file.name }))
  if (file.size > MAX_FILE_SIZE) throw new Error(t('ui.theFileExceedsMb', { arg0: file.name }))
  let preview = null
  if (['jpg', 'jpeg', 'png', 'webp'].includes(extension)) {
    try {
      preview = await createPreview(file)
    } catch {
      /* Keep metadata if the browser cannot decode this image. */
    }
  }
  return {
    id: crypto.randomUUID(),
    name: file.name,
    size: file.size,
    type: file.type || 'application/octet-stream',
    preview,
  }
}
