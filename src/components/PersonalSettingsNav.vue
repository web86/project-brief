<script setup>
import { ref, computed, watch, useId } from 'vue'
import { useRoute } from 'vue-router'
import { settingsMenu } from '../services/adminMenu.js'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
import { t } from '../i18n/index.js'
import AppIcon from './AppIcon.vue'
const props = defineProps({ collapsible: Boolean })
const emit = defineEmits(['navigate'])
const route = useRoute(),
  nav = useAdminNavigationStore(),
  expanded = ref(false),
  id = useId()
const items = computed(() =>
  settingsMenu.filter(
    (item) =>
      (!item.platform || nav.user?.platformAdmin) &&
      (!item.owner || (nav.workspace && nav.user?.membershipRole === 'owner')),
  ),
)
const active = (item) => route.path === item.to
watch(
  () => route.path,
  () => {
    if (items.value.some(active)) expanded.value = true
  },
  { immediate: true },
)
const open = computed(() => !props.collapsible || expanded.value)
</script>
<template>
  <section
    class="personal-settings-group"
    :class="{ 'is-collapsible': collapsible, 'is-expanded': open }"
  >
    <button
      v-if="collapsible"
      type="button"
      class="settings-group-toggle"
      :aria-expanded="open"
      :aria-controls="id"
      @click="expanded = !expanded"
    >
      <span>{{ t('centralNotifications.mySettings') }}</span
      ><AppIcon name="chevron" :size="16" />
    </button>
    <h2 v-else>{{ t('centralNotifications.mySettings') }}</h2>
    <div class="settings-group-collapse" :inert="!open || undefined" :aria-hidden="!open">
      <nav
        :id="id"
        class="personal-settings-nav"
        :aria-label="t('centralNotifications.mySettings')"
      >
        <RouterLink
          v-for="item in items"
          :key="item.to"
          :to="item.to"
          :aria-current="active(item) ? 'page' : undefined"
          @click="emit('navigate')"
        >
          <AppIcon :name="item.icon" :size="19" /><span>{{ t(item.label) }}</span>
        </RouterLink>
      </nav>
    </div>
  </section>
</template>
