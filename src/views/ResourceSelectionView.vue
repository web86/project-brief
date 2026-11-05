<script setup>
import { computed, ref, watch } from 'vue'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
import { api } from '../api/client.js'
import { t } from '../i18n/index.js'
import { useRequestFeedback } from '../services/requestFeedback.js'
import ResourceSelectionPanel from '../components/ResourceSelectionPanel.vue'
import SubscriptionLoadError from '../components/SubscriptionLoadError.vue'
import SubscriptionOverview from '../components/SubscriptionOverview.vue'
import SubscriptionPlanCard from '../components/SubscriptionPlanCard.vue'

const nav = useAdminNavigationStore()
const model = ref(null)
const preparationOpen = ref(false)
const { busy, error, retrySeconds, run, cancel } = useRequestFeedback()
const remaining = computed(() => {
  if (!model.value?.subscription?.endsAt) return null
  return Math.max(0, Math.ceil((new Date(model.value.subscription.endsAt) - Date.now()) / 86400000))
})
async function load() {
  if (!nav.workspace?.id) return
  const result = await run((signal) =>
    api.request('/api/admin/resource-selection/summary', { signal }),
  )
  if (result) model.value = result.data
}
watch(
  () => nav.workspace?.id,
  () => {
    cancel()
    model.value = null
    preparationOpen.value = false
    load()
  },
  { immediate: true },
)
</script>

<template>
  <section class="owner-resource-preview">
    <div class="page-heading">
      <h1>{{ t('account.subscription') }}</h1>
      <button
        v-if="model && !error"
        type="button"
        class="button secondary"
        :disabled="busy || retrySeconds > 0"
        @click="load"
      >
        {{ t('platform.refresh') }}
      </button>
    </div>
    <SubscriptionLoadError :error="error" :seconds="retrySeconds" :retry="load" />
    <p v-if="busy" role="status">{{ t('nav.loading') }}</p>
    <template v-if="model">
      <SubscriptionOverview owner :subscription="model.subscription" :usage="model.usage">
        <template #dates>
          <p v-if="remaining !== null" class="muted">
            {{ t('subscriptionUx.remainingDays', { days: remaining }) }}
          </p>
        </template>
      </SubscriptionOverview>
      <section class="surface subscription-explainer">
        <h2>{{ t('subscriptionUx.whatChanges') }}</h2>
        <p v-if="model.subscription?.phase === 'trial'">{{ t('subscriptionUx.trialEnds') }}</p>
        <p v-else>{{ t('subscriptionUx.holds') }}</p>
        <details v-if="model.selectionTarget" @toggle="preparationOpen = $event.target.open">
          <summary>{{ t('subscriptionUx.keepResources') }}</summary>
          <ResourceSelectionPanel
            v-if="preparationOpen"
            embedded
            :workspace-id="nav.workspace.id"
            :target-id="model.selectionTarget"
            @changed="load"
          />
        </details>
      </section>
      <section>
        <h2>{{ t('subscriptionUx.compare') }}</h2>
        <p>{{ t('subscriptionUx.compareHint') }}</p>
        <div class="subscription-catalog">
          <SubscriptionPlanCard v-for="plan in model.catalog" :key="plan.code" :plan="plan" />
        </div>
        <p v-if="!model.catalog.length">{{ t('subscriptionUx.noCatalog') }}</p>
      </section>
    </template>
  </section>
</template>

<style scoped>
.owner-resource-preview {
  max-width: 1100px;
  padding: clamp(14px, 4vw, 24px);
  margin: 0 auto;
  min-width: 0;
  display: grid;
  gap: 20px;
}
.subscription-explainer {
  padding: clamp(16px, 4vw, 24px);
}
.subscription-explainer summary {
  cursor: pointer;
}
.subscription-catalog {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 270px), 1fr));
  gap: 14px;
}
</style>
