<script setup>
/**
 * O cartão que dá nome à família: mesma largura, mesma marca no topo, mesma
 * hierarquia — título, corpo, campos, ação primária em largura total e ligação
 * secundária abaixo. A cor da borda é o único portador de tom, e nunca o único
 * portador de significado: os estados sempre trazem ícone e rótulo (P6).
 */
defineProps({
  // `md` (480 px) é exceção do convite, para caber o cabeçalho de quem convidou.
  largura: {
    type: String,
    default: 'sm',
    validator: (v) => ['sm', 'md'].includes(v),
  },
  tom: {
    type: String,
    default: 'neutro',
    validator: (v) => ['neutro', 'atencao', 'sucesso', 'consentimento'].includes(v),
  },
})
</script>

<template>
  <section class="auth-card" :class="[`auth-card--${largura}`, `auth-card--${tom}`]">
    <div v-if="$slots.cabecalho" class="auth-card__banner">
      <slot name="cabecalho" />
    </div>

    <div class="auth-card__body">
      <span class="auth-card__brand">Imunia</span>
      <slot />
    </div>
  </section>
</template>

<style scoped>
.auth-card {
  width: 100%;
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
  overflow: hidden;
}

.auth-card--sm {
  max-width: 400px;
}

.auth-card--md {
  max-width: 480px;
}

.auth-card--atencao {
  border-color: var(--status-due);
}

.auth-card--sucesso {
  border-color: var(--brand);
}

.auth-card--consentimento {
  border-color: var(--consent);
}

.auth-card__banner {
  padding: 20px var(--space-6);
  background: var(--brand-wash);
  border-bottom: 1px solid var(--border-hairline);
}

.auth-card__body {
  padding: 20px var(--space-4);
}

.auth-card__brand {
  display: none;
  font-family: var(--font-display);
  font-size: 22px;
  line-height: 28px;
  font-weight: 600;
  color: var(--brand);
}

@media (min-width: 768px) {
  .auth-card__body {
    padding: var(--space-6);
  }

  .auth-card__brand {
    display: block;
  }
}
</style>
