<script setup>
import { CircleHelp } from '@lucide/vue'

/**
 * E02 — não encontrado. Comunica o impedimento sem expor detalhe de
 * implementação (RNF17) e sempre oferece uma saída.
 */
</script>

<template>
  <div class="not-found">
    <section class="not-found__card">
      <CircleHelp :size="40" class="not-found__icon" />
      <h1 class="not-found__title">Esta página não existe</h1>
      <p class="not-found__text">
        O endereço pode ter sido digitado com algum engano, ou a página ainda não faz parte
        desta versão do Imunia.
      </p>
      <RouterLink to="/entrar" class="not-found__action">Voltar para entrar</RouterLink>
    </section>
  </div>
</template>

<style scoped>
.not-found {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  padding: var(--space-4);
  background: var(--surface-page);
}

.not-found__card {
  width: 100%;
  max-width: 400px;
  text-align: center;
}

.not-found__icon {
  color: var(--ink-faint);
}

.not-found__title {
  margin: var(--space-4) 0 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  color: var(--ink);
}

.not-found__text {
  margin: var(--space-2) 0 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
}

.not-found__action {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 48px;
  padding: 0 var(--space-6);
  margin: var(--space-6) 0 0;
  background: var(--brand);
  border-radius: var(--radius-sm);
  font-size: 16px;
  font-weight: 600;
  color: #FFFFFF;
}

.not-found__action:hover {
  background: var(--brand-hover);
  color: #FFFFFF;
}
</style>
