<script setup>
import AppIcon from './AppIcon.vue'
import { t, intlLocale } from '../i18n/index.js'
defineProps({ agreement: Object, expanded: Boolean })
function date(value) {
  return value
    ? new Date(
        value.length === 10
          ? value + 'T12:00:00'
          : value.replace(' ', 'T') + (value.endsWith('Z') ? '' : 'Z'),
      ).toLocaleDateString(intlLocale())
    : ''
}
</script>
<template>
  <div
    v-if="agreement && agreement.status !== 'notRequired'"
    class="agreement-status small"
    :class="agreement.status"
  >
    <AppIcon :name="agreement.status === 'accepted' ? 'file-check' : 'file'" :size="15" />
    <span
      >{{ t('agreement.label') }}: {{ t('agreement.' + agreement.status)
      }}<template v-if="agreement.status === 'accepted'">
        · {{ date(agreement.acceptedAt) }}</template
      ><template v-if="expanded && agreement.acceptedAt"
        ><br />{{ t('agreement.version') }}: {{ agreement.version }} · {{ agreement.locale }} ·
        {{ date(agreement.acceptedAt) }}</template
      ></span
    >
  </div>
</template>
<style scoped>
.agreement-status {
  display: flex;
  align-items: baseline;
  gap: 6px;
  margin-block: 8px;
  color: var(--muted);
}
.agreement-status svg {
  flex-shrink: 0;
  align-self: flex-start;
  margin-top: 3px;
}
.agreement-status.accepted {
  color: var(--success, #25854b);
}
.agreement-status.renewal {
  color: var(--warning, #ac6a15);
}
</style>
