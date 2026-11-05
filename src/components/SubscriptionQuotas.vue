<script setup>
import { t } from '../i18n/index.js'
import { formatLimit } from '../services/subscription.js'

defineProps({ limits: Object, usage: Object })
</script>

<template>
  <dl class="subscription-quotas">
    <div v-for="(limit, key) in limits" :key="key">
      <dt>{{ t('inventory.resources.' + key) }}</dt>
      <dd>
        <template v-if="usage">
          <strong>{{ usage[key]?.state === 'ready' ? usage[key].count : '—' }}</strong>
          <span> / </span>
        </template>
        {{ formatLimit(limit) }}
        <small v-if="key === 'developers.max' && usage?.[key]?.state === 'ready'">
          {{
            t('subscriptionUx.seatBreakdown', {
              members: usage[key].human_count,
              invitations: usage[key].invitation_slots,
            })
          }}
        </small>
      </dd>
    </div>
  </dl>
</template>

<style scoped>
.subscription-quotas {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 150px), 1fr));
  gap: 12px;
  margin: 0;
}
.subscription-quotas > div {
  padding: 12px;
  border: 1px solid var(--border);
  border-radius: 8px;
  min-width: 0;
}
dt,
small {
  color: var(--muted);
}
dd {
  margin: 6px 0 0;
}
small {
  display: block;
  margin-top: 4px;
}
</style>
