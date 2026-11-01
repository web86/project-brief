<script setup>
import { computed, ref, watch, onBeforeUnmount } from 'vue'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
import { api } from '../api/client.js'
import { t, formatDate, formatApiError } from '../i18n/index.js'
import AppIcon from '../components/AppIcon.vue'
import LoadingButton from '../components/LoadingButton.vue'
const nav = useAdminNavigationStore()
const allowed = computed(() => !!nav.workspace && nav.user?.membershipRole === 'owner')
const rows = ref([]),
  meta = ref(null),
  from = ref(''),
  to = ref(''),
  loading = ref(false),
  error = ref('')
let revision = 0,
  attempt = 0
async function load(page = 1) {
  if (!allowed.value) return
  const current = revision,
    request = ++attempt
  loading.value = true
  error.value = ''
  const params = new URLSearchParams({
    page: String(page),
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
  })
  if (from.value) params.set('from', from.value)
  if (to.value) params.set('to', to.value)
  try {
    const result = await api.request('/api/admin/reviews?' + params)
    if (current === revision && request === attempt) {
      rows.value = result.data
      meta.value = result.meta
    }
  } catch (cause) {
    if (current === revision && request === attempt) {
      rows.value = []
      meta.value = null
      error.value = formatApiError(cause)
    }
  } finally {
    if (current === revision && request === attempt) loading.value = false
  }
}
function reset() {
  from.value = ''
  to.value = ''
  load()
}
watch(
  () => [nav.workspace?.id, nav.user?.membershipRole],
  () => {
    revision++
    attempt++
    rows.value = []
    meta.value = null
    from.value = ''
    to.value = ''
    error.value = ''
    loading.value = false
    load()
  },
  { immediate: true },
)
onBeforeUnmount(() => {
  revision++
  attempt++
})
</script>
<template>
  <div v-if="allowed" class="reviews-page">
    <div class="page-heading">
      <div>
        <h1>{{ t('release910.reviews') }}</h1>
        <p class="muted">{{ t('release910.reviewsHint') }}</p>
      </div>
    </div>
    <form class="surface review-filters" @submit.prevent="load()">
      <label class="field" for="reviews-from"
        >{{ t('release910.from')
        }}<input
          id="reviews-from"
          v-model="from"
          type="date"
          :max="to || undefined"
          :disabled="loading"
      /></label>
      <label class="field" for="reviews-to"
        >{{ t('release910.to')
        }}<input
          id="reviews-to"
          v-model="to"
          type="date"
          :min="from || undefined"
          :disabled="loading"
      /></label>
      <LoadingButton type="submit" class="button primary" :busy="loading" :disabled="loading">{{
        t('release910.apply')
      }}</LoadingButton>
      <button type="button" class="button secondary" :disabled="loading" @click="reset">
        {{ t('release910.allDates') }}
      </button>
    </form>
    <p v-if="loading" role="status">{{ t('nav.loading') }}</p>
    <p v-if="error" role="alert" class="field-error">
      {{ error }} <button class="text-button" @click="load()">{{ t('ui.tryAgain') }}</button>
    </p>
    <p v-else-if="!loading && !rows.length" class="surface review-empty">
      {{ t('release910.emptyReviews') }}
    </p>
    <div class="review-list" :aria-busy="loading">
      <article v-for="review in rows" :key="review.id" class="surface review-card">
        <header>
          <span class="review-rating" :aria-label="t('feedback.stars', { count: review.rating })"
            ><AppIcon
              v-for="star in 5"
              :key="star"
              name="star"
              :size="17"
              :class="{ filled: star <= review.rating }"
            /><strong>{{ review.rating }} / 5</strong></span
          ><time :datetime="review.submittedAt">{{ formatDate(review.submittedAt) }}</time>
        </header>
        <p v-if="review.text" class="review-text">{{ review.text }}</p>
        <div class="review-author">
          <strong>{{ review.author || t('release910.unknownAuthor') }}</strong
          ><span v-if="review.email">{{ review.email }}</span>
        </div>
        <RouterLink
          :to="
            review.project.archived
              ? '/admin#project-recycle-bin'
              : '/admin/projects/' + review.project.id + '/tasks'
          "
          >{{ review.project.name }}</RouterLink
        >
        <span v-if="review.project.archived" class="small muted">
          · {{ t('release910.archived') }}</span
        >
      </article>
    </div>
    <nav v-if="meta?.lastPage > 1" class="review-pagination" :aria-label="t('release910.reviews')">
      <button
        class="button secondary"
        :disabled="loading || meta.page <= 1"
        @click="load(meta.page - 1)"
      >
        {{ t('integrations.previous') }}</button
      ><span>{{ meta.page }} / {{ meta.lastPage }}</span
      ><button
        class="button secondary"
        :disabled="loading || meta.page >= meta.lastPage"
        @click="load(meta.page + 1)"
      >
        {{ t('integrations.next') }}
      </button>
    </nav>
  </div>
</template>
<style scoped>
.review-filters {
  display: flex;
  flex-wrap: wrap;
  align-items: end;
  gap: 12px;
  padding: 20px;
  margin-bottom: 20px;
}
.review-filters .field {
  flex: 1 1 180px;
  margin: 0;
}
.review-list {
  display: grid;
  gap: 16px;
}
.review-card,
.review-empty {
  padding: 20px;
  overflow-wrap: anywhere;
}
.review-card header {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 10px;
  align-items: center;
}
.review-card time {
  font-size: 0.8rem;
  color: var(--muted);
}
.review-rating {
  display: flex;
  align-items: center;
  gap: 3px;
}
.review-rating .filled {
  fill: currentColor;
  color: var(--accent);
}
.review-rating strong {
  margin-left: 8px;
}
.review-text {
  white-space: pre-wrap;
  line-height: 1.6;
}
.review-author {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 12px;
  margin: 16px 0 8px;
}
.review-author span {
  color: var(--muted);
}
.review-pagination {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 12px;
  margin-top: 20px;
}
</style>
