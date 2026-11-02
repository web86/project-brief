// Fixed brand filenames must revalidate; only fingerprinted assets are immutable.
export const staticImmutablePattern = String.raw`(?:-[A-Za-z0-9_-]{8}\.(?:js|css)|[a-f0-9]{64}\.(?:json|png|svg|webp|txt))$`
const immutable = new RegExp(staticImmutablePattern)
export const isImmutableStaticAsset = (file) => immutable.test(file)
