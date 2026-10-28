<script setup>
import { computed, watch, ref, onBeforeUnmount, onMounted } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
import { useWorkspaceBrandingStore } from '../stores/workspaceBranding.js'
import { t, formatApiError } from '../i18n/index.js'
import { theme } from '../services/theme.js'
import { brandingFields, brandTokens, brandPresets } from '../services/branding.js'
import { toast } from '../services/toast.js'
import AppIcon from '../components/AppIcon.vue'
import { isSecurityCancellation } from '../services/stepUp.js'
import LoadingButton from '../components/LoadingButton.vue'
import NotificationToggle from '../components/NotificationToggle.vue'
import BrandLogo from '../components/BrandLogo.vue'
const nav = useAdminNavigationStore(),
  store = useWorkspaceBrandingStore(),
  tab = ref('visual')
const tabs = ['visual', 'business', 'contact', 'export', 'email', 'future']
watch(
  () => nav.workspace?.id,
  (id) => {
    if (id) store.load(id)
    else store.clear()
  },
  { immediate: true },
)
onBeforeRouteLeave(() => store.confirmLeave())
onBeforeUnmount(store.clear)
const tokens = computed(() =>
  brandTokens(store.draft?.visual.primaryColor, theme.resolved.value === 'dark'),
)
const toggles = {
  export: {
    logo: 'showLogo',
    companyName: 'showName',
    address: 'showAddress',
    contact: 'showContact',
    business: 'showBusiness',
    platform: 'showPlatform',
  },
  email: {
    enabled: 'emailEnabled',
    logo: 'showLogo',
    companyName: 'showName',
    contact: 'showContact',
    accent: 'emailAccent',
  },
}
function beforeUnload(event) {
  if (store.dirty || store.busy) {
    event.preventDefault()
    event.returnValue = ''
  }
}
onMounted(() => window.addEventListener('beforeunload', beforeUnload))
onBeforeUnmount(() => window.removeEventListener('beforeunload', beforeUnload))
watch(
  () => store.settings,
  (settings) => {
    if (settings) nav.adoptBranding(store.workspaceId, settings)
  },
  { deep: true },
)
watch(
  () => store.error,
  (cause) => {
    if (!cause || isSecurityCancellation(cause)) return
    if (cause.code === 'branding_conflict') toast.warning(() => t('branding.conflict'))
    else if (cause.code === 'security_retry_required') toast.info(() => t('security.retryAction'))
    else toast.error(() => formatApiError(cause))
  },
)
async function save() {
  if (await store.save()) {
    if (store.dirty) toast.info(() => t('branding.newDraft'))
    else toast.success(() => t('branding.saved'))
  } else {
    const section = Object.keys(store.error?.errors || {})
      .map((key) => key.split('.')[1])
      .find((key) => tabs.includes(key))
    if (section) tab.value = section
  }
}
async function rebase() {
  if (await store.rebase()) toast.info(() => t('branding.reviewDraft'))
}
const imageSize = (kind) => store.settings?.assetInfo?.[kind]?.bytes
const sizeLabel = (bytes) =>
  new Intl.NumberFormat(undefined, { maximumFractionDigits: 1 }).format(bytes / 1024) + ' KB'
async function reset() {
  if (await store.reset()) toast.success(() => t('branding.saved'))
}
async function retryUpload() {
  if (await store.retryAsset()) toast.success(() => t('branding.saved'))
}
async function remove(kind) {
  if (await store.asset(kind, null)) toast.success(() => t('branding.saved'))
}
async function upload(kind, event) {
  const file = event.target.files?.[0]
  if (file && (await store.asset(kind, file))) toast.success(() => t('branding.saved'))
  event.target.value = ''
}
const changed = (section) =>
  JSON.stringify(store.settings?.[section]) !== JSON.stringify(store.draft?.[section])
</script>
<template>
  <div class="branding-page">
    <div class="admin-page-heading">
      <div>
        <p class="eyebrow">{{ nav.workspace?.name }}</p>
        <h1>{{ t('branding.title') }}</h1>
        <p class="muted">{{ t('branding.hint') }}</p>
      </div>
      <button
        class="button secondary"
        :disabled="!!store.busy || store.loading || !store.draft"
        @click="reset"
      >
        {{ t('branding.reset') }}
      </button>
    </div>
    <p v-if="store.loading" role="status">{{ t('nav.loading') }}</p>
    <p v-if="store.error && !store.draft" class="field-error" role="alert">
      {{ formatApiError(store.error) }}
    </p>
    <button
      v-if="store.error?.code === 'branding_conflict'"
      class="button secondary"
      :disabled="!!store.busy"
      @click="rebase"
    >
      {{ t('branding.reloadDraft') }}
    </button>
    <button
      v-if="!store.draft && !store.loading"
      class="button secondary"
      @click="store.load(nav.workspace.id)"
    >
      {{ t('ui.tryAgain') }}
    </button>
    <button
      v-if="store.pendingAsset && !store.busy"
      type="button"
      class="button secondary"
      @click="retryUpload"
    >
      {{ t('branding.retryUpload') }}
    </button>
    <button
      v-if="store.pendingAsset && !store.busy"
      type="button"
      class="text-button"
      @click="store.discardAsset()"
    >
      {{ t('ui.cancel') }}
    </button>
    <template v-if="store.draft">
      <nav class="branding-tabs" :aria-label="t('branding.title')">
        <button
          v-for="section in tabs"
          :key="section"
          type="button"
          :aria-current="tab === section ? 'page' : undefined"
          @click="tab = section"
        >
          {{ t('branding.' + section)
          }}<span v-if="changed(section) && section !== 'future'" aria-hidden="true"> ·</span>
        </button>
      </nav>
      <div class="branding-layout">
        <section class="surface branding-editor">
          <h2>{{ t('branding.' + tab) }}</h2>
          <form v-if="brandingFields[tab]" :key="tab" @submit.prevent="save()">
            <p class="small muted">{{ t('branding.optional') }}</p>
            <div class="branding-fields">
              <div
                v-for="(limit, key) in brandingFields[tab]"
                :key="key"
                class="field"
                :class="{ 'full-width': limit > 255 }"
              >
                <label :for="'brand-' + key">{{ t('branding.fields.' + key) }}</label>
                <textarea
                  v-if="limit > 255 && key !== 'website'"
                  :id="'brand-' + key"
                  v-model="store.draft[tab][key]"
                  @invalid="$event.target.setCustomValidity(t('flow.fieldError'))"
                  @input="$event.target.setCustomValidity('')"
                  :maxlength="limit"
                  rows="3"
                />
                <input
                  v-else
                  :id="'brand-' + key"
                  v-model="store.draft[tab][key]"
                  @invalid="$event.target.setCustomValidity(t('flow.fieldError'))"
                  @input="$event.target.setCustomValidity('')"
                  :maxlength="limit"
                  :type="
                    key === 'email'
                      ? 'email'
                      : key === 'website'
                        ? 'url'
                        : key === 'phone'
                          ? 'tel'
                          : 'text'
                  "
                  :pattern="key === 'primaryColor' ? '#[0-9a-fA-F]{6}' : undefined"
                  :placeholder="key === 'primaryColor' ? '#2454a5' : undefined"
                />
              </div>
            </div>
            <template v-if="tab === 'visual'">
              <div class="brand-presets">
                <button
                  v-for="color in brandPresets"
                  :key="color"
                  type="button"
                  :style="{ background: color }"
                  :aria-label="color"
                  :aria-pressed="store.draft.visual.primaryColor === color"
                  @click="store.draft.visual.primaryColor = color"
                /><label class="brand-color-picker"
                  ><span class="sr-only">{{ t('branding.fields.primaryColor') }}</span
                  ><input
                    type="color"
                    :value="store.draft.visual.primaryColor || '#2454a5'"
                    @input="store.draft.visual.primaryColor = $event.target.value" /></label
                ><button
                  type="button"
                  class="text-button"
                  @click="store.draft.visual.primaryColor = ''"
                >
                  {{ t('branding.default') }}
                </button>
              </div>
              <div class="brand-images">
                <div v-for="kind in ['logo', 'favicon']" :key="kind" class="brand-image-card">
                  <h3>{{ t('branding.' + kind) }}</h3>
                  <div class="brand-image-preview">
                    <img
                      v-if="store.draft.assets[kind]"
                      :src="store.draft.assets[kind]"
                      :alt="t('branding.' + kind)"
                    /><AppIcon v-else name="file" :size="28" />
                  </div>
                  <div
                    v-if="store.upload?.kind === kind"
                    class="brand-upload-progress"
                    role="status"
                  >
                    <span
                      >{{ t('branding.' + store.upload.phase)
                      }}<strong v-if="store.upload.percent !== null">
                        {{ store.upload.percent }}%</strong
                      ></span
                    >
                    <progress
                      :value="store.upload.percent ?? undefined"
                      max="100"
                      :aria-label="t('branding.' + store.upload.phase)"
                    />
                  </div>
                  <p v-if="imageSize(kind)" class="small muted brand-image-size">
                    {{ t('branding.optimizedSize', { size: sizeLabel(imageSize(kind)) }) }} ·
                    {{
                      store.settings.assetInfo[kind].mime === 'image/svg+xml'
                        ? 'SVG'
                        : store.settings.assetInfo[kind].mime === 'image/jpeg'
                          ? 'JPEG'
                          : 'PNG'
                    }}
                  </p>
                  <label class="button secondary brand-upload"
                    >{{ t('branding.upload')
                    }}<input
                      type="file"
                      :aria-label="t('branding.' + kind)"
                      accept="image/png,image/jpeg,image/webp,image/svg+xml,.svg"
                      :disabled="!!store.busy"
                      @change="upload(kind, $event)" /></label
                  ><button
                    v-if="store.draft.assets[kind]"
                    class="text-button"
                    type="button"
                    :disabled="!!store.busy"
                    @click="remove(kind)"
                  >
                    {{ t('branding.remove') }}
                  </button>
                </div>
              </div>
              <p class="small muted">{{ t('branding.imageHint') }}</p>
            </template>
            <div class="branding-save">
              <LoadingButton
                type="submit"
                class="button primary"
                :busy="store.busy === 'save'"
                :disabled="!!store.busy || !!store.pendingAsset || !store.hasChanges"
                >{{ t('branding.save') }}</LoadingButton
              >
            </div>
          </form>
          <form v-else-if="toggles[tab]" :key="tab" @submit.prevent="save()">
            <p class="muted">
              {{ t('branding.' + (tab === 'export' ? 'exportHint' : 'emailHint')) }}
            </p>
            <div class="branding-toggles">
              <NotificationToggle
                v-for="(label, key) in toggles[tab]"
                :key="key"
                :checked="store.draft[tab][key]"
                :label="t('branding.' + label)"
                @change="store.draft[tab][key] = $event.target.checked"
              />
            </div>
            <div class="branding-save">
              <LoadingButton
                type="submit"
                class="button primary"
                :busy="store.busy === 'save'"
                :disabled="!!store.busy || !!store.pendingAsset || !store.hasChanges"
                >{{ t('branding.save') }}</LoadingButton
              >
            </div>
          </form>
          <div v-else class="branding-future">
            <span class="client-state inactive">{{ t('branding.comingSoon') }}</span>
            <div class="field">
              <label for="future-domain">{{ t('branding.domain') }}</label
              ><input id="future-domain" value="projects.agency.com" disabled />
            </div>
            <p class="muted">{{ t('branding.domainHint') }}</p>
            <div class="field">
              <label for="future-sender">{{ t('branding.sender') }}</label
              ><input id="future-sender" value="notifications@agency.com" disabled />
            </div>
            <p class="muted">{{ t('branding.senderHint') }}</p>
          </div>
        </section>
        <aside class="branding-preview surface" :style="tokens">
          <p class="eyebrow">{{ t('branding.preview') }}</p>
          <div class="brand-portal-preview">
            <div class="brand-preview-heading">
              <img v-if="store.draft.assets.logo" :src="store.draft.assets.logo" alt="" /><strong
                v-if="store.draft.visual.companyName"
                >{{ store.draft.visual.companyName }}</strong
              ><BrandLogo v-if="!store.draft.visual.companyName && !store.draft.assets.logo" />
            </div>
            <p class="small muted">{{ t('branding.portalPreview') }}</p>
            <h2>{{ t('branding.previewTitle') }}</h2>
            <div class="brand-preview-progress" />
            <button type="button" class="button primary">
              {{ t('branding.previewAction') }}<AppIcon name="chevron" :size="16" />
            </button>
          </div>
          <div
            v-if="tab === 'email'"
            class="brand-email-preview"
            :style="
              brandTokens(
                store.draft.email.enabled && store.draft.email.accent
                  ? store.draft.visual.primaryColor || '#246c43'
                  : '#246c43',
                false,
              )
            "
          >
            <p class="eyebrow">{{ t('branding.email') }}</p>
            <div class="brand-preview-heading">
              <img
                v-if="
                  store.draft.email.enabled && store.draft.email.logo && store.draft.assets.logo
                "
                :src="store.draft.assets.logo"
                alt=""
              /><strong>{{
                store.draft.email.enabled && store.draft.email.companyName
                  ? store.draft.visual.companyName || 'ProjectBrief'
                  : 'ProjectBrief'
              }}</strong>
            </div>
            <h3>{{ t('branding.previewAction') }}</h3>
            <p class="muted">{{ t('branding.previewTitle') }}</p>
            <button type="button" class="button primary">{{ t('branding.previewAction') }}</button>
            <p v-if="store.draft.email.enabled && store.draft.email.contact" class="small muted">
              {{
                [store.draft.contact.phone, store.draft.contact.email, store.draft.contact.website]
                  .filter(Boolean)
                  .join(' · ')
              }}
            </p>
          </div>
        </aside>
      </div>
    </template>
  </div>
</template>
