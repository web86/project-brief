<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import AppIcon from '../../src/components/AppIcon.vue'
import { createDemoData } from '../../src/data/demo.js'
import { createThemePreferences } from '../../src/services/theme.js'
import WorkspacePreview from './WorkspacePreview.vue'
import { copy } from './copy.js'

const route = useRoute()
const isDemo = computed(() => route.path === '/demo')
const language = ref(new URLSearchParams(location.search).get('lang') === 'en' ? 'en' : 'ru')
const c = computed(() => copy[language.value])
// Reuse the application's theme resolver with no access to persistent preferences.
const theme = createThemePreferences({ document, matchMedia: window.matchMedia.bind(window) })
theme.initialize()
const mode = theme.mode
onBeforeUnmount(theme.dispose)
const role = ref('client')
const filter = ref('all')
const selectedId = ref('demo-1')
const aiVisible = ref(false)
const added = ref([])
const notice = ref('')
const showForm = ref(false)
const form = ref(null)
let formOpener
const title = ref('')
const description = ref('')
const seed = createDemoData().tasks
const sectionIndex = [0, 0, 0, 1, 1, 2, 2, 3, 3]
const tasks = computed(() => [
  ...seed.map((task, index) => ({
    ...task,
    index,
    title: c.value.titles[index],
    description: c.value.descriptions[index],
    expectedResult: c.value.results[index],
    section: c.value.sectionNames[sectionIndex[index]],
    comments:
      index === 0
        ? [
            { author: 'developer', text: c.value.comment },
            { author: 'client', text: c.value.reply },
          ]
        : index === 4
          ? c.value.stockDiscussion.map((text, i) => ({ author: i ? 'client' : 'developer', text }))
          : index === 5
            ? [{ author: 'developer', text: c.value.galleryDiscussion }]
            : [],
  })),
  ...added.value.map((task) => ({ ...task, section: c.value.home })),
])
const selected = computed(
  () => tasks.value.find((task) => task.id === selectedId.value) || tasks.value[0],
)
function select(id) {
  selectedId.value = id
  aiVisible.value = false
}
function changeFilter(value) {
  filter.value = value
  const first = tasks.value.find(
    (task) => value === 'all' || task.status === (value === 'active' ? 'in_progress' : 'done'),
  )
  if (first && value !== 'all') select(first.id)
}
async function openForm() {
  formOpener = document.activeElement
  showForm.value = true
  await nextTick()
  form.value.showModal()
}
async function closeForm() {
  form.value.close()
  showForm.value = false
  await nextTick()
  formOpener?.focus()
}
function addIdea() {
  if (!title.value.trim() || !description.value.trim()) return
  const id = `local-${added.value.length + 1}`
  added.value.push({
    id,
    title: title.value.trim(),
    description: description.value.trim(),
    expectedResult: '',
    status: 'new',
    estimateHours: null,
    clientApproved: false,
    comments: [],
    local: true,
  })
  filter.value = 'all'
  select(id)
  notice.value = 'localAdded'
  title.value = ''
  description.value = ''
  closeForm()
}
function reset() {
  added.value = []
  filter.value = 'all'
  select('demo-1')
  notice.value = ''
}
watch(
  [language, isDemo],
  () => {
    document.documentElement.lang = language.value
    document.title = isDemo.value
      ? `${c.value.demoTitle} — ProjectBrief`
      : `${c.value.headline1} ${c.value.headline2} — ProjectBrief`
    document.querySelector('meta[name="description"]').content = c.value.intro
  },
  { immediate: true },
)
watch(
  () => route.path,
  async () => {
    await nextTick()
    document.getElementById('main-content')?.focus({ preventScroll: true })
  },
)
</script>

<template>
  <div class="public-site">
    <a class="skip-link" href="#main-content">{{ c.skip }}</a>
    <header class="public-header">
      <div class="public-container header-inner">
        <RouterLink to="/" class="public-brand" aria-label="ProjectBrief"
          ><span class="brand-mark">p<span class="brand-dot">.</span></span
          ><span>project<span class="brand-light">brief</span></span></RouterLink
        >
        <nav class="public-nav" aria-label="ProjectBrief">
          <RouterLink to="/#features">{{ c.features }}</RouterLink
          ><RouterLink to="/#how-it-works">{{ c.how }}</RouterLink
          ><RouterLink to="/demo" :aria-current="isDemo ? 'page' : undefined">{{
            c.demo
          }}</RouterLink>
        </nav>
        <div class="public-controls">
          <label class="preview-theme" :title="c.theme"
            ><AppIcon :name="theme.resolved.value === 'dark' ? 'moon' : 'sun'" :size="17" /><span
              class="sr-only"
              >{{ c.theme }}</span
            ><select :value="mode" @change="theme.select($event.target.value)">
              <option v-for="value in ['light', 'dark', 'system']" :key="value" :value="value">
                {{ c[value] }}
              </option>
            </select></label
          >
          <label class="preview-language"
            ><span class="sr-only">{{ c.language }}</span
            ><select v-model="language">
              <option value="ru">RU</option>
              <option value="en">EN</option>
            </select></label
          >
          <a class="header-login" href="http://127.0.0.1:5173/app"
            >{{ c.login }}<AppIcon name="chevron" :size="15"
          /></a>
        </div>
      </div>
    </header>

    <main id="main-content" tabindex="-1">
      <template v-if="!isDemo">
        <section class="hero public-container">
          <div class="hero-copy">
            <p class="hero-eyebrow"><span></span>{{ c.eyebrow }}</p>
            <h1>
              {{ c.headline1 }}<br /><span>{{ c.headline2 }}</span>
            </h1>
            <p class="hero-description">{{ c.intro }}</p>
            <div class="hero-actions">
              <RouterLink class="public-button primary" to="/demo"
                >{{ c.try }}<AppIcon name="chevron" :size="18" /></RouterLink
              ><a class="public-button secondary" href="http://127.0.0.1:5173/app"
                >{{ c.open }}<AppIcon name="external" :size="16"
              /></a>
            </div>
            <p class="signup-note">{{ c.noSignup }}</p>
          </div>
          <div class="hero-product">
            <div class="role-control" :aria-label="c.role">
              <button
                v-for="value in ['client', 'developer']"
                :key="value"
                :aria-pressed="role === value"
                :class="{ active: role === value }"
                @click="role = value"
              >
                <AppIcon :name="value === 'client' ? 'user' : 'code'" :size="17" />{{ c[value] }}
              </button>
            </div>
            <div class="product-aura">
              <WorkspacePreview :c="c" :role="role" :tasks="tasks" :selected="tasks[0]" />
            </div>
            <p class="under-preview">
              <span>01</span> {{ c.shared }} <span class="tiny-line"></span>
              <AppIcon name="user" :size="14" /><span class="connection-dots">···</span
              ><AppIcon name="code" :size="15" />
            </p>
          </div>
        </section>

        <section id="how-it-works" class="workflow-section public-container">
          <p class="section-eyebrow">{{ c.workflowLabel }}</p>
          <h2 class="section-title">{{ c.workflowTitle }}</h2>
          <div class="workflow">
            <article v-for="(step, index) in c.steps" :key="index">
              <div class="step-top">
                <span>{{ String(index + 1).padStart(2, '0') }}</span
                ><AppIcon :name="['comment', 'check', 'leaf'][index]" :size="25" />
              </div>
              <h3>{{ step[0] }}</h3>
              <p>{{ step[1] }}</p>
            </article>
          </div>
        </section>

        <section id="features" class="audiences public-container">
          <div class="audience-row client-row">
            <div class="audience-copy">
              <p class="section-eyebrow">{{ c.clientLabel }}</p>
              <h2 class="section-title">{{ c.clientTitle }}</h2>
              <p class="section-description">{{ c.clientText }}</p>
              <ul class="feature-list">
                <li v-for="point in c.clientPoints" :key="point[0]">
                  <AppIcon name="check" :size="18" />
                  <div>
                    <h3>{{ point[0] }}</h3>
                    <p>{{ point[1] }}</p>
                  </div>
                </li>
              </ul>
            </div>
            <div class="idea-story">
              <div class="story-label">
                <span class="person-avatar client">A</span>{{ c.authorClient }}
              </div>
              <blockquote>{{ c.quote }}</blockquote>
              <div class="story-connector">
                <span></span><AppIcon name="sparkles" :size="22" /><span></span>
              </div>
              <div class="story-result">
                <p class="field-caption">{{ c.aiExample }}</p>
                <h3>{{ c.structured }}</h3>
                <p>{{ c.structuredDetail }}</p>
                <small>{{ c.aiNote }}</small>
              </div>
            </div>
          </div>
          <div class="audience-row developer-row">
            <div class="history-story">
              <div class="history-card">
                <p class="field-caption">#01 · {{ c.home }}</p>
                <h3>{{ c.titles[0] }}</h3>
                <div class="history-stats">
                  <span><AppIcon name="clock" :size="16" />5 {{ c.hours }}</span
                  ><span class="task-badge in_progress">{{ c.statuses.in_progress }}</span>
                </div>
                <h4>{{ c.history }}</h4>
                <ol>
                  <li v-for="(item, index) in c.historyItems" :key="item">
                    <span :class="{ last: index === 3 }"><AppIcon name="check" :size="12" /></span
                    >{{ item }}
                  </li>
                </ol>
                <div class="history-approval">
                  <AppIcon name="check" :size="16" />{{ c.approval }}
                </div>
              </div>
              <span class="history-caption">{{ c.sample }} / STUDIO</span>
            </div>
            <div class="audience-copy">
              <p class="section-eyebrow">{{ c.devLabel }}</p>
              <h2 class="section-title">{{ c.devTitle }}</h2>
              <p class="section-description">{{ c.devText }}</p>
              <ul class="feature-list">
                <li v-for="point in c.devPoints" :key="point[0]">
                  <AppIcon name="check" :size="18" />
                  <div>
                    <h3>{{ point[0] }}</h3>
                    <p>{{ point[1] }}</p>
                  </div>
                </li>
              </ul>
            </div>
          </div>
        </section>

        <section class="two-sides-section">
          <div class="public-container">
            <div class="section-centered">
              <p class="section-eyebrow">{{ c.twoLabel }}</p>
              <h2 class="section-title">{{ c.twoTitle }}</h2>
              <p class="section-description">{{ c.twoText }}</p>
            </div>
            <div class="two-sides">
              <article class="perspective-card">
                <div class="perspective-title">
                  <AppIcon name="user" :size="20" /><span
                    >{{ c.client }}<small>{{ c.clientSide }}</small></span
                  ><span class="perspective-number">01</span>
                </div>
                <div class="mini-task">
                  <span class="task-badge in_progress">{{ c.statuses.in_progress }}</span>
                  <h3>{{ c.titles[0] }}</h3>
                  <p>{{ c.results[0] }}</p>
                  <div class="mini-comment">
                    <span class="person-avatar developer">A</span>
                    <p>{{ c.comment }}</p>
                  </div>
                </div>
              </article>
              <article class="perspective-card">
                <div class="perspective-title">
                  <AppIcon name="code" :size="20" /><span
                    >{{ c.developer }}<small>{{ c.devSide }}</small></span
                  ><span class="perspective-number">02</span>
                </div>
                <div class="mini-task">
                  <span class="task-badge in_progress">{{ c.statuses.in_progress }}</span>
                  <h3>{{ c.titles[0] }}</h3>
                  <div class="mini-metrics">
                    <div>
                      <small>{{ c.estimate }}</small
                      ><strong>5 {{ c.hours }}</strong>
                    </div>
                    <div>
                      <small>{{ c.status }}</small
                      ><strong><AppIcon name="check" :size="14" />{{ c.approval }}</strong>
                    </div>
                  </div>
                  <div class="mini-history">
                    <AppIcon name="clock" :size="15" />{{ c.history
                    }}<AppIcon name="chevron" :size="15" />
                  </div>
                </div>
              </article>
            </div>
          </div>
        </section>

        <section class="cta-section public-container">
          <div class="cta-art" aria-hidden="true">
            <span class="cta-person"><AppIcon name="user" :size="35" /></span
            ><span class="cta-path">··············</span
            ><span class="cta-person"><AppIcon name="code" :size="35" /></span>
          </div>
          <p class="section-eyebrow">{{ c.ctaLabel }}</p>
          <h2>{{ c.ctaTitle }}</h2>
          <p>{{ c.ctaText }}</p>
          <RouterLink class="public-button primary" to="/demo"
            >{{ c.try }}<AppIcon name="chevron" :size="18" /></RouterLink
          ><small>{{ c.noSignup }}</small>
        </section>
      </template>

      <section v-else class="demo-page public-container">
        <RouterLink class="back-link" to="/"
          ><AppIcon name="arrow" :size="16" />{{ c.back }}</RouterLink
        >
        <div class="demo-intro">
          <div>
            <p class="section-eyebrow">{{ c.demoEyebrow }}</p>
            <h1>{{ c.demoTitle }}</h1>
            <p>{{ c.demoText }}</p>
          </div>
          <div class="role-control" :aria-label="c.role">
            <button
              v-for="value in ['client', 'developer']"
              :key="value"
              :class="{ active: role === value }"
              :aria-pressed="role === value"
              @click="role = value"
            >
              <AppIcon :name="value === 'client' ? 'user' : 'code'" :size="17" />{{ c[value] }}
            </button>
          </div>
        </div>
        <div class="demo-notice">
          <AppIcon name="info" :size="18" /><span>{{ c.demoNotice }}</span
          ><button @click="reset">{{ c.reset }}</button>
        </div>
        <p v-if="notice" class="demo-added" role="status">{{ c[notice] }}</p>
        <WorkspacePreview
          :c="c"
          :role="role"
          :tasks="tasks"
          :selected="selected"
          :interactive="true"
          :ai-visible="aiVisible"
          :filter="filter"
          @select="select"
          @filter="changeFilter"
          @add="openForm"
          @ai="aiVisible = true"
        />
      </section>
    </main>
    <footer class="public-footer public-container">
      <RouterLink to="/" class="public-brand"
        ><span class="brand-mark">p<span class="brand-dot">.</span></span
        ><span>project<span class="brand-light">brief</span></span></RouterLink
      >
      <p>{{ c.footer }}</p>
      <span>{{ c.footerNote }}</span>
    </footer>

    <dialog
      v-if="showForm"
      ref="form"
      class="idea-dialog"
      aria-labelledby="new-idea-title"
      @cancel.prevent="closeForm"
      @click="
        (e) => {
          if (e.target === form) closeForm()
        }
      "
    >
      <form @submit.prevent="addIdea">
        <div class="dialog-heading">
          <p class="section-eyebrow">{{ c.sample }}</p>
          <button type="button" class="dialog-close" :aria-label="c.close" @click="closeForm">
            <AppIcon name="close" :size="20" />
          </button>
        </div>
        <h2 id="new-idea-title">{{ c.newTitle }}</h2>
        <p class="dialog-note">{{ c.demoNotice }}</p>
        <label for="demo-title">{{ c.new }}</label
        ><input
          id="demo-title"
          v-model="title"
          :placeholder="c.titlePlaceholder"
          required
          maxlength="160"
          autofocus
        /><label for="demo-description">{{ c.newDescription }}</label
        ><textarea
          id="demo-description"
          v-model="description"
          :placeholder="c.descPlaceholder"
          required
          maxlength="3000"
          rows="5"
        ></textarea>
        <div class="dialog-actions">
          <button type="button" class="public-button secondary" @click="closeForm">
            {{ c.cancel }}</button
          ><button class="public-button primary" :disabled="!title.trim() || !description.trim()">
            {{ c.addLocal }}<AppIcon name="plus" :size="16" />
          </button>
        </div>
      </form>
    </dialog>
  </div>
</template>
