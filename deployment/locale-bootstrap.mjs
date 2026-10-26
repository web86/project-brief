import { resolveBrowserLocale } from '../src/i18n/browserLocale.js'
import { LANGUAGE_CATALOG } from '../src/i18n/catalog.js'

// The pre-Vue failure screen and browser detection use the same catalog as the application.
export function localizeHtmlShell(html) {
  const messages = Object.fromEntries(
    LANGUAGE_CATALOG.map(({ code, messages }) => [code, messages.bootstrap]),
  )
  const catalog = LANGUAGE_CATALOG.map(({ code, browserAliases }) => ({ code, browserAliases }))
  return html
    .replace("/* browser-locale-resolver */ () => 'en'", resolveBrowserLocale.toString())
    .replace('/* browser-locale-catalog */ []', JSON.stringify(catalog))
    .replace('/* locale-bootstrap */ {}', JSON.stringify(messages).replaceAll('<', '\\u003c'))
}
export const localeBootstrap = () => ({
  name: 'projectbrief-locale-bootstrap',
  transformIndexHtml: localizeHtmlShell,
})
