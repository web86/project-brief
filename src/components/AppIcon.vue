<script setup>
defineProps({
  name: { type: String, required: true },
  size: { type: [Number, String], default: 20 },
})
const paths = {
  mail: 'M3 5h18v14H3zM3 5l9 7 9-7',
  users:
    'M16 21v-2a6 6 0 0 0-12 0v2M10 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8ZM17 4a4 4 0 0 1 0 8M20 21v-2a6 6 0 0 0-3-5',
  settings: 'M4 7h16M4 17h16M8 4v6M16 14v6',
  'list-tree': 'M4 3v14h5M4 7h5M12 4h8v6h-8zM12 14h8v6h-8z',
  grip: 'M9 5h.01M15 5h.01M9 12h.01M15 12h.01M9 19h.01M15 19h.01',
  logout: 'M9 3H4v18h5M14 7l5 5-5 5M8 12h12',
  panel: 'M3 3h18v18H3zM9 3v18M5.5 8h1M5.5 12h1M5.5 16h1',
  money: 'M3 5h18v14H3zM12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8ZM6 9v.01M18 15v.01',
  bell: 'M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9ZM10 21h4',
  sun: 'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8ZM12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5',
  moon: 'M20.5 14A8.5 8.5 0 0 1 10 3.5 8.5 8.5 0 1 0 20.5 14Z',
  menu: 'M4 6h16M4 12h16M4 18h16',
  plus: 'M12 5v14M5 12h14',
  arrow: 'M19 12H5m6-6-6 6 6 6',
  chevron: 'm9 5 7 7-7 7',
  check: 'm5 12 4 4L19 6',
  close: 'm6 6 12 12M6 18 18 6',
  grid: 'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z',
  home: 'm3 10 9-7 9 7v10H3V10Zm6 10v-7h6v7',
  bag: 'M5 7h14l2 14H3L5 7Zm3 0V6a4 4 0 0 1 8 0v1',
  box: 'm3 7 9-4 9 4-9 4-9-4Zm0 0v10l9 4 9-4V7M12 11v10',
  pin: 'M20 10c0 6-8 11-8 11S4 16 4 10a8 8 0 1 1 16 0ZM12 7a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z',
  sparkles: 'm12 3 2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5L12 3ZM20 2v4m-2-2h4',
  comment: 'M21 11a8 8 0 0 1-8 8H8l-5 3V11a9 9 0 0 1 18 0ZM7 10h10M7 14h6',
  paperclip: 'm8 12 6-6a3 3 0 0 1 4 4l-8 8a5 5 0 0 1-7-7l9-9a6 6 0 0 1 8 8l-8 8',
  upload: 'M12 16V3m-5 5 5-5 5 5M4 16v5h16v-5',
  globe: 'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0ZM3 12h18M12 3c5 5 5 13 0 18-5-5-5-13 0-18Z',
  external: 'M14 3h7v7M21 3l-11 11M10 3H3v18h18v-7',
  leaf: 'M20 3c0 14-4 17-10 17a7 7 0 0 1-7-7c0-6 6-10 17-10ZM3 21 15 9',
  code: 'm8 6-6 6 6 6m8-12 6 6-6 6M14 3l-4 18',
  user: 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0ZM4 21v-2a8 8 0 0 1 16 0v2',
  info: 'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0ZM12 11v6M12 7v.01',
  clock: 'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0ZM12 7v5l3 2',
  file: 'M14 2H5v20h14V7l-5-5ZM14 2v5h5M8 12h8M8 16h5',
  trash: 'M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7m4-7v7',
  send: 'm3 3 18 9-18 9 3-9-3-9ZM6 12h15',
}
</script>

<template>
  <svg
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.6"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
  >
    <path :d="paths[name] || paths.file" />
  </svg>
</template>
