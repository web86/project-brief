<template>
  <div class="empty-state page-empty">
    <span class="eyebrow">404</span>
    <h1>{{ t('ui.thisPageDoesNotExist') }}</h1>
    <p>{{ t('ui.theAddressMayHaveChangedYourIdeasAre') }}</p>
    <RouterLink to="/" class="button primary">{{ t('ui.backToTheProject') }}</RouterLink>
  </div>
</template>
