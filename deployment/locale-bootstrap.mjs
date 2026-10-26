import { LANGUAGE_CATALOG } from '../src/i18n/catalog.js'

// The pre-Vue failure screen is generated from the same dictionaries as the application.
export function localizeHtmlShell(html) {
  const messages = Object.fromEntries(
    LANGUAGE_CATALOG.map(({ code, messages }) => [code, messages.bootstrap]),
  )
  return html.replace(
    '/* locale-bootstrap */ {}',
    JSON.stringify(messages).replaceAll('<', '\\u003c'),
  )
}
export const localeBootstrap = () => ({
  name: 'projectbrief-locale-bootstrap',
  transformIndexHtml: localizeHtmlShell,
})
