<script setup>
import { t } from '../i18n/index.js'
import { planName, quotaGroups } from '../services/subscription.js'
import SubscriptionQuotas from './SubscriptionQuotas.vue'

const props = defineProps({ plan: Object, code: String })
</script>

<template>
  <article class="subscription-plan surface">
    <h3>{{ plan ? planName(plan) : props.code }}</h3>
    <template v-if="plan">
      <SubscriptionQuotas
        :limits="
          Object.fromEntries(
            Object.values(quotaGroups)
              .filter((key) => plan.limits[key])
              .map((key) => [key, plan.limits[key]]),
          )
        "
      />
      <p v-if="plan.limits['branding.level']">
        {{ t('brandingLevels.' + plan.limits['branding.level'].value) }}
      </p>
      <p class="muted">
        {{
          Object.keys(plan.limits)
            .filter(
              (key) =>
                [
                  'api.external.enabled',
                  'api.agents.enabled',
                  'webhooks.enabled',
                  'sso.enabled',
                ].includes(key) && plan.limits[key].value,
            )
            .map((key) => t('inventory.resources.' + key))
            .join(' · ')
        }}
      </p>
    </template>
    <p v-else>{{ t('subscriptionUx.planUnavailable') }}</p>
    <slot />
  </article>
</template>

<style scoped>
.subscription-plan {
  display: grid;
  align-content: start;
  gap: 12px;
  padding: 16px;
  min-width: 0;
}
h3,
p {
  margin: 0;
}
p:empty {
  display: none;
}
</style>
