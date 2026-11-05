import { t } from '../i18n/index.js'
import { toast } from './toast.js'

const codes = [
  'plan_limit_reached',
  'plan_feature_unavailable',
  'plan_subscription_inactive',
  'plan_resource_held',
]

export function showPlanRestriction(error, details) {
  if (!codes.includes(error.code)) return
  error.presentedAsToast = true
  const limit = error.code === 'plan_limit_reached'
  const title = details?.entitlement === 'developers.max' && limit ? 'developerLimit' : error.code
  const lines = []
  if (limit && Number.isFinite(details?.maximum)) {
    const plan =
      details.entitlement === 'developers.max'
        ? { studio: 'Studio', freelancer: 'Freelancer', basic: 'Basic' }[details.plan]
        : null
    if (Number.isFinite(details.usage)) {
      lines.push(() =>
        t(plan ? 'planRestrictions.planUsage' : 'planRestrictions.usage', { ...details, plan }),
      )
    } else lines.push(() => t('planRestrictions.maximum', details))
  }
  lines.push(() => t('planRestrictions.' + (limit ? 'capacityAction' : error.code + '_action')))
  toast.error(() => t('planRestrictions.' + title), { details: lines })
}
