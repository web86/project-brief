<script setup>
defineProps({ name: { type: String, required: true }, size: { type: Number, default: 20 } })
</script>
<template>
  <svg :width="size" :height="size" v-if="name === 'google'" viewBox="0 0 48 48" aria-hidden="true">
    <path
      fill="#4285F4"
      d="M43.6 24.5c0-1.5-.1-3-.4-4.5H24v8.5h11a9.5 9.5 0 0 1-4.1 6.2v5.1h6.6c3.9-3.6 6.1-8.8 6.1-15.3Z"
    />
    <path
      fill="#34A853"
      d="M24 44c5.5 0 10.1-1.8 13.5-4.9l-6.6-5.1c-1.8 1.2-4.1 1.9-6.9 1.9-5.3 0-9.8-3.6-11.4-8.5H5.8v5.3A20.4 20.4 0 0 0 24 44Z"
    />
    <path fill="#FBBC05" d="M12.6 27.4a12 12 0 0 1 0-7.8v-5.3H5.8a20.2 20.2 0 0 0 0 18.4Z" />
    <path
      fill="#EA4335"
      d="M24 12.1c3 0 5.7 1 7.8 3.1l5.8-5.8A19.6 19.6 0 0 0 24 4 20.4 20.4 0 0 0 5.8 15.3l6.8 5.3C14.2 15.7 18.7 12.1 24 12.1Z"
    />
  </svg>
  <svg
    :width="size"
    :height="size"
    v-else-if="name === 'yandex'"
    viewBox="0 0 24 24"
    aria-hidden="true"
  >
    <rect width="24" height="24" rx="5" fill="#FC3F1D" />
    <path
      fill="white"
      d="M14.3 20h3V4h-4.5C8.3 4 6 6.3 6 9.5c0 2.6 1.2 4.1 3.4 5.7L6 20h3.4l3.8-5.8-1.5-1c-1.8-1.2-2.7-2.1-2.7-3.9 0-1.6 1.1-2.7 3.3-2.7h2Z"
    />
  </svg>
  <svg :width="size" :height="size" v-else viewBox="0 0 24 24" aria-hidden="true">
    <path
      fill="currentColor"
      d="M12 .8a11.3 11.3 0 0 0-3.6 22c.6.1.8-.2.8-.5v-2.1c-3.2.7-3.9-1.4-3.9-1.4-.5-1.3-1.2-1.6-1.2-1.6-1-.7.1-.7.1-.7 1.1.1 1.7 1.2 1.7 1.2 1 1.7 2.6 1.2 3.3.9.1-.7.4-1.2.7-1.5-2.6-.3-5.2-1.3-5.2-5.6 0-1.2.4-2.2 1.2-3-.1-.3-.5-1.5.1-3 0 0 1-.3 3.1 1.2a10.8 10.8 0 0 1 5.7 0c2.2-1.5 3.1-1.2 3.1-1.2.6 1.5.2 2.7.1 3 .8.8 1.2 1.8 1.2 3 0 4.3-2.6 5.3-5.2 5.6.4.4.8 1.1.8 2.1v3.1c0 .3.2.6.8.5A11.3 11.3 0 0 0 12 .8Z"
    />
  </svg>
</template>
