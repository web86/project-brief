import { t } from '../i18n/index.js'
// Future adapters may return several structured ideas for the same description.
// Nothing is sent outside the browser until an API adapter is explicitly added.
export function createIdeaAssistant(adapter) {
  return {
    async help(description) {
      if (adapter) return adapter({ description, allowSplit: true })
      return {
        mode: 'demo',
        messageKey: 'ui.laterAiWillBeAbleToTurnYour',
        message: t('ui.laterAiWillBeAbleToTurnYour'),
        suggestedIdeas: [],
      }
    },
  }
}
export const ideaAssistant = createIdeaAssistant()
