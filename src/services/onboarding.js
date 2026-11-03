import { ref } from 'vue'
import { t, formatApiError } from '../i18n/index.js'
export const pendingRegistrationEmail = ref('')
export function onboardingError(error) {
  if (error?.code === 'verification_expired') return t('onboarding.invalidLink')
  if (error?.code === 'social_account_link_required') return t('onboarding.linkRequired')
  if (error?.code === 'social_last_login_method') return t('onboarding.lastMethod')
  return formatApiError(error)
}
