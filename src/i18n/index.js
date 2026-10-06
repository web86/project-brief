import { createI18n } from 'vue-i18n'
import { watch } from 'vue'
import ru from '../locales/ru.js'
import en from '../locales/en.js'

export const LOCALE_KEY = 'projectbrief.locale'
export const SUPPORTED_LOCALES = ['ru', 'en']
export const INTL_LOCALES = { ru: 'ru-RU', en: 'en-GB' }
export const intlLocale = () => INTL_LOCALES[i18n.global.locale.value] || INTL_LOCALES.en
export function resolveLocale({ manual, preferred, languages, language } = {}) {
  if (SUPPORTED_LOCALES.includes(manual)) return manual
  if (SUPPORTED_LOCALES.includes(preferred)) return preferred
  const browserLanguage =
    languages?.find((value) => typeof value === 'string' && value.trim()) || language || 'en'
  return /^ru/i.test(browserLanguage) ? 'ru' : 'en'
}
function storedChoice() {
  try {
    return globalThis.localStorage?.getItem(LOCALE_KEY)
  } catch {
    return null
  }
}
let manualChoice = storedChoice()
export const i18n = createI18n({
  legacy: false,
  globalInjection: true,
  locale: resolveLocale({
    manual: manualChoice,
    languages: globalThis.navigator?.languages,
    language: globalThis.navigator?.language,
  }),
  fallbackLocale: 'en',
  messages: { ru, en },
  pluralRules: {
    ru: (choice, length) => {
      const n = Math.abs(choice)
      if (length === 2) return n === 1 ? 0 : 1
      return n % 10 === 1 && n % 100 !== 11
        ? 0
        : n % 10 >= 2 && n % 10 <= 4 && !(n % 100 >= 12 && n % 100 <= 14)
          ? 1
          : 2
    },
  },
})
export const locale = i18n.global.locale
export const t = (...args) => i18n.global.t(...args)
export function initializeLocale({
  storage = globalThis.localStorage,
  browser = globalThis.navigator,
  preferred,
} = {}) {
  try {
    manualChoice = storage?.getItem(LOCALE_KEY)
  } catch {
    manualChoice = null
  }
  locale.value = resolveLocale({
    manual: manualChoice,
    preferred,
    languages: browser?.languages,
    language: browser?.language,
  })
  if (globalThis.document) document.documentElement.lang = locale.value
  return locale.value
}
export function applyClientLocale(preferred) {
  locale.value = resolveLocale({
    manual: manualChoice || storedChoice(),
    preferred,
    languages: globalThis.navigator?.languages,
    language: globalThis.navigator?.language,
  })
}
export function setManualLocale(value) {
  if (!SUPPORTED_LOCALES.includes(value)) return false
  manualChoice = value
  locale.value = value
  if (globalThis.document) document.documentElement.lang = value
  try {
    globalThis.localStorage?.setItem(LOCALE_KEY, value)
  } catch {
    return false
  }
  return true
}
watch(
  locale,
  (value) => {
    if (globalThis.document) document.documentElement.lang = value
  },
  { immediate: true, flush: 'sync' },
)
export const formatDate = (value) =>
  new Intl.DateTimeFormat(intlLocale(), {
    day: 'numeric',
    month: 'long',
    hour: '2-digit',
    minute: '2-digit',
  }).format(new Date(value))
export function formatCurrency(value, project) {
  const configured =
    typeof project?.currency === 'string' ? project.currency.trim().toUpperCase() : ''
  const currency = /^[A-Z]{3}$/.test(configured) ? configured : 'RUB'
  return new Intl.NumberFormat(intlLocale(), { style: 'currency', currency }).format(value)
}
export function formatApiError(error) {
  if (!error) return ''
  if (typeof error === 'string') return error
  if (error.code === 'timeout') return t('flow.requestTimeout')
  if (error.code === 'invalid_credentials') return t('flow.invalidCredentials')
  if (error.messageKey) return t(error.messageKey, error.params || {})
  const messages = {
    0: 'ui.weCouldNotConnectToTheServerCheck',
    401: 'ui.yourSessionHasEndedSignInAgainOr',
    403: 'ui.thisActionIsUnavailableDiscussChangesInThe',
    404: 'ui.thisRecordWasNotFoundOrIsUnavailable',
    419: 'ui.yourSessionHasChangedTryThisActionAgain',
    422: 'ui.checkTheFields',
    429: 'ui.tooManyRequestsPleaseWaitALittle',
    500: 'ui.weCouldNotCompleteThisActionPleaseTry',
    503: 'ui.weCouldNotCompleteThisActionPleaseTry',
  }
  return messages[error.status]
    ? t(messages[error.status])
    : error.message || t('ui.weCouldNotCompleteThisActionPleaseTry')
}
