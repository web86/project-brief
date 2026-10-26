import ru from '../locales/ru.js'
import en from '../locales/en.js'
import tr from '../locales/tr.js'

export const LANGUAGE_CATALOG = [
  { code: 'ru', name: 'Русский', intl: 'ru-RU', messages: ru },
  { code: 'en', name: 'English', intl: 'en-GB', messages: en },
  { code: 'tr', name: 'Türkçe', intl: 'tr-TR', messages: tr },
]
export const SUPPORTED_LOCALES = LANGUAGE_CATALOG.map(({ code }) => code)
export const INTL_LOCALES = Object.fromEntries(
  LANGUAGE_CATALOG.map(({ code, intl }) => [code, intl]),
)
