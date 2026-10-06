<script setup>
import { t } from '../i18n/index.js'

import { formatDate } from '../constants/project'
import AppIcon from './AppIcon.vue'
defineProps({ comments: Array })
</script>
<template>
  <div v-if="comments.length" class="comment-list" :aria-label="t('ui.comments2')">
    <article v-for="comment in comments" :key="comment.id" class="comment" :class="comment.author">
      <span class="comment-avatar"
        ><AppIcon :name="comment.author === 'developer' ? 'code' : 'user'" :size="17"
      /></span>
      <div class="comment-content">
        <div class="comment-heading">
          <strong>{{
            (comment.authorId ? comment.authorName : null) ||
            (comment.author === 'developer' ? t('ui.developer') : t('ui.client'))
          }}</strong
          ><time :datetime="comment.createdAt">{{ formatDate(comment.createdAt) }}</time>
        </div>
        <p>{{ comment.text }}</p>
      </div>
    </article>
  </div>
  <div v-else class="comments-empty">
    <p>{{ t('ui.noCommentsYet') }}</p>
  </div>
</template>
