export const ACCEPTED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx', 'txt', 'zip']
export const FILE_ACCEPT = ACCEPTED_EXTENSIONS.map((extension) => `.${extension}`).join(',')
export const MAX_FILES = 10
export const MAX_FILE_SIZE = 20 * 1024 * 1024

function readDataUrl(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => resolve(reader.result)
    reader.onerror = () => reject(new Error('Не удалось прочитать изображение.'))
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
  if (!ACCEPTED_EXTENSIONS.includes(extension)) throw new Error(`«${file.name}»: этот формат не поддерживается.`)
  if (file.size > MAX_FILE_SIZE) throw new Error(`«${file.name}»: файл больше 20 МБ.`)
  let preview = null
  if (['jpg', 'jpeg', 'png', 'webp'].includes(extension)) {
    try { preview = await createPreview(file) } catch { /* Keep metadata if the browser cannot decode this image. */ }
  }
  return { id: crypto.randomUUID(), name: file.name, size: file.size, type: file.type || 'application/octet-stream', preview }
}
