<script setup>
import { t } from '../i18n/index.js'
import { planName, subscriptionDate } from '../services/subscription.js'
import SubscriptionQuotas from './SubscriptionQuotas.vue'

defineProps({ subscription: Object, usage: Object, owner: Boolean })
</script>

<template>
  <section class="subscription-current surface">
    <p class="eyebrow">{{ t(owner ? 'subscriptionUx.myPlan' : 'subscriptionUx.current') }}</p>
    <template v-if="subscription">
      <h2>{{ planName(subscription) }}</h2>
      <p>
        {{ t('account.subscriptionPhase.' + subscription.phase) }} ·
        {{ t('subscriptionUx.assignment.' + subscription.assignment) }}
      </p>
      <p>{{ t('manualSubscription.started') }} · {{ subscriptionDate(subscription.startsAt) }}</p>
      <p>{{ t('subscriptionUx.ends') }} · {{ subscriptionDate(subscription.endsAt) }}</p>
      <slot name="dates" />
      <SubscriptionQuotas
        v-if="Object.keys(subscription.limits || {}).length"
        :limits="subscription.limits"
        :usage="usage"
      />
    </template>
    <p v-else>{{ t('subscriptionUx.noSubscription') }}</p>
    <slot />
  </section>
</template>

<style scoped>
.subscription-current {
  display: grid;
  gap: 10px;
  padding: clamp(16px, 4vw, 26px);
}
.subscription-current :is(h2, p) {
  margin: 0;
}
</style>
