<script setup>
/**
 * Moldura comum das telas públicas de acesso (P02, P05, P06, P07, P08).
 * Em 360 px a marca sobe para um cabeçalho de 56 px e o cartão passa a ocupar
 * a largura toda, com margem de 16 px.
 */
defineProps({
  acaoRotulo: { type: String, default: '' },
  acaoDestino: { type: String, default: '' },
})
</script>

<template>
  <div class="auth-page">
    <header class="auth-page__header">
      <RouterLink to="/" class="auth-page__brand">Imunia</RouterLink>
      <RouterLink v-if="acaoRotulo" :to="acaoDestino" class="auth-page__action">
        {{ acaoRotulo }}
      </RouterLink>
    </header>

    <main class="auth-page__main">
      <slot />
    </main>
  </div>
</template>

<style scoped>
.auth-page {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
  background: var(--surface-page);
}

.auth-page__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
  height: 56px;
  padding: 0 var(--space-4);
  background: var(--surface-card);
  border-bottom: 1px solid var(--border-hairline);
}

.auth-page__brand {
  /* Alvo de toque de 44 px, exigência abaixo de 1024 px. */
  display: inline-flex;
  align-items: center;
  height: 44px;
  font-family: var(--font-display);
  font-size: 22px;
  line-height: 28px;
  font-weight: 600;
  color: var(--brand);
}

.auth-page__action {
  display: inline-flex;
  align-items: center;
  height: 44px;
  padding: 0 var(--space-2);
  font-size: 14px;
  line-height: 20px;
  font-weight: 600;
}

.auth-page__main {
  display: flex;
  flex: 1;
  align-items: flex-start;
  justify-content: center;
  padding: var(--space-4);
}

@media (min-width: 768px) {
  /* Acima de 768 px a marca volta para dentro do cartão, como no cabeçalho de
     cada peça da família. */
  .auth-page__header {
    display: none;
  }

  .auth-page__main {
    align-items: center;
    padding: var(--space-12) var(--space-6);
  }
}
</style>
