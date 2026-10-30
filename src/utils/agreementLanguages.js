export const agreementLocales = ['ru', 'en', 'tr', 'zh-CN']
export function documentLanguages(document) {
  return {
    enabled: document?.enabledLocales || agreementLocales,
    default: document?.defaultLocale || 'ru',
  }
}
export function agreementLanguage(document, preferred) {
  const config = documentLanguages(document)
  return config.enabled.includes(preferred) ? preferred : config.default
}
