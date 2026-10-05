<script setup>
import TaskForm from '../components/TaskForm.vue'
import AppIcon from '../components/AppIcon.vue'
</script>
<template>
  <div class="form-page"><RouterLink to="/" class="back-link"><AppIcon name="arrow" :size="17" />Назад к проекту</RouterLink><div class="page-heading"><div class="eyebrow">ДАВАЙТЕ СДЕЛАЕМ САЙТ ЛУЧШЕ</div><h1>С чего начнём?</h1><p>Поделитесь идеей — детали мы разберём вместе.</p></div><div class="form-layout"><div class="surface"><TaskForm /></div><aside class="form-guidance"><span class="guidance-icon"><AppIcon name="leaf" :size="30" /></span><h2>Ваша идея важнее<br />правильных слов</h2><p>Не переживайте, если пока не знаете, как всё должно работать. Для начала достаточно рассказать, что вам хочется изменить.</p><div class="guidance-step"><span>01</span><p>Опишите, что сейчас не нравится</p></div><div class="guidance-step"><span>02</span><p>Расскажите, как хотелось бы</p></div><div class="guidance-step"><span>03</span><p>Добавьте пример, если он есть</p></div><div class="guidance-footer"><AppIcon name="comment" :size="18" />Остальное обсудим в комментариях</div></aside></div></div>
</template>
