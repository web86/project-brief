<script setup>
import { computed } from 'vue'
import AppIcon from '../../src/components/AppIcon.vue'

const props = defineProps({
  c: Object,
  role: String,
  tasks: Array,
  selected: Object,
  interactive: Boolean,
  aiVisible: Boolean,
  filter: { type: String, default: 'all' },
})
defineEmits(['select', 'filter', 'add', 'ai'])
const shown = computed(() =>
  props.tasks.filter((task) =>
    props.filter === 'all'
      ? true
      : task.status === (props.filter === 'active' ? 'in_progress' : 'done'),
  ),
)
const rows = computed(() => (props.interactive ? shown.value : props.tasks.slice(0, 4)))
</script>

<template>
  <div class="product-window" :class="{ 'is-interactive': interactive }">
    <div class="window-bar">
      <span class="window-dots" aria-hidden="true"><i></i><i></i><i></i></span>
      <span class="window-address"><AppIcon name="bag" :size="12" /> projectbrief / studio</span>
      <span class="window-example">{{ c.sample }}</span>
    </div>
    <div class="product-heading">
      <div class="store-avatar"><AppIcon name="bag" :size="24" /></div>
      <div>
        <p class="product-overline">STUDIO / 01</p>
        <h2>{{ c.project }}</h2>
        <p class="project-summary">{{ c.projectDesc }}</p>
      </div>
      <span class="project-progress"
        ><span>{{ tasks.filter((t) => t.status === 'done').length }} / {{ tasks.length }}</span>
        {{ c.progress
        }}<i
          ><b
            :style="{
              width: `${(tasks.filter((t) => t.status === 'done').length / tasks.length) * 100}%`,
            }"
          ></b></i
      ></span>
    </div>
    <div class="product-content">
      <aside class="task-list">
        <div class="list-heading">
          <strong>{{ c.brief }}</strong
          ><span>{{ tasks.length.toString().padStart(2, '0') }}</span>
        </div>
        <div v-if="interactive" class="demo-filters" :aria-label="c.brief">
          <button
            v-for="value in ['all', 'active', 'done']"
            :key="value"
            :class="{ selected: filter === value }"
            :aria-pressed="filter === value"
            @click="$emit('filter', value)"
          >
            {{ c[value] }}
          </button>
        </div>
        <template v-for="(task, index) in rows" :key="task.id">
          <button
            v-if="interactive"
            class="task-row"
            :class="{ 'task-selected': selected.id === task.id }"
            :aria-pressed="selected.id === task.id"
            @click="$emit('select', task.id)"
          >
            <span class="task-index">{{ String(index + 1).padStart(2, '0') }}</span>
            <span
              ><strong>{{ task.title }}</strong
              ><small>{{ task.section }}</small></span
            ><i class="status-dot" :class="task.status"></i>
          </button>
          <div v-else class="task-row" :class="{ 'task-selected': selected.id === task.id }">
            <span class="task-index">{{ String(index + 1).padStart(2, '0') }}</span>
            <span
              ><strong>{{ task.title }}</strong
              ><small>{{ task.section }}</small></span
            ><i class="status-dot" :class="task.status"></i>
          </div>
        </template>
        <p v-if="!rows.length" class="no-tasks">{{ c.noTasks }}</p>
        <button v-if="interactive" class="add-idea" @click="$emit('add')">
          <AppIcon name="plus" :size="16" />{{ c.add }}
        </button>
        <div v-else class="list-footnote"><AppIcon name="check" :size="15" />{{ c.shared }}</div>
      </aside>
      <section class="task-detail" :aria-label="c.details" aria-live="polite">
        <div class="detail-topline">
          <span
            >{{ selected.section }} <span class="detail-slash">/</span>
            {{ selected.local ? 'DEMO' : '#0' + (selected.index + 1) }}</span
          ><span class="task-badge" :class="selected.status"
            ><i></i>{{ c.statuses[selected.status] }}</span
          >
        </div>
        <h3>{{ selected.title }}</h3>
        <template v-if="role === 'client'">
          <span class="field-caption">{{ c.idea }}</span>
          <div class="idea-quote">{{ selected.description }}</div>
          <div class="ai-example">
            <div>
              <AppIcon name="sparkles" :size="17" /><strong>{{ c.aiExample }}</strong>
            </div>
            <p v-if="selected.local">{{ c.aiUnavailable }}</p>
            <p v-else-if="!interactive || aiVisible">
              {{ selected.expectedResult }}
            </p>
            <button v-else class="text-action" @click="$emit('ai')">
              {{ c.aiAction }} <AppIcon name="chevron" :size="14" />
            </button>
            <small>{{ c.aiNote }}</small>
          </div>
        </template>
        <template v-else>
          <p class="developer-description">{{ selected.description }}</p>
          <div class="developer-fields">
            <div>
              <span class="field-caption">{{ c.estimate }}</span
              ><strong
                ><AppIcon name="clock" :size="16" />{{
                  selected.estimateHours ? selected.estimateHours + ' ' + c.hours : c.noEstimate
                }}</strong
              >
            </div>
            <div>
              <span class="field-caption">{{ c.status }}</span
              ><strong class="approval-label"
                ><AppIcon :name="selected.clientApproved ? 'check' : 'clock'" :size="16" />{{
                  selected.clientApproved ? c.approval : c.notApproved
                }}</strong
              >
            </div>
          </div>
          <div class="expected-result">
            <span class="field-caption">{{ c.expected }}</span>
            <p>{{ selected.expectedResult || c.noResult }}</p>
          </div>
        </template>
        <div class="discussion">
          <h4>
            <AppIcon name="comment" :size="16" />{{ c.discussion
            }}<span>{{ selected.comments.length }}</span>
          </h4>
          <div v-for="(comment, index) in selected.comments" :key="index" class="comment-row">
            <span class="person-avatar" :class="comment.author">A</span>
            <div>
              <strong>{{ comment.author === 'developer' ? c.authorDev : c.authorClient }}</strong>
              <p>{{ comment.text }}</p>
            </div>
          </div>
          <p v-if="!selected.comments.length" class="empty-discussion">{{ c.noComments }}</p>
        </div>
      </section>
    </div>
    <div class="window-footer">
      <span><i class="live-dot"></i>{{ role === 'client' ? c.readyClient : c.ready }}</span
      ><AppIcon :name="role === 'client' ? 'user' : 'code'" :size="16" />
    </div>
  </div>
</template>
