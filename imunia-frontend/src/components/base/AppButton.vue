<script setup>
defineProps({
  variant: {
    type: String,
    default: 'primary',
    // "consentimento" é a ação que fala de autorização e de verificação
    // pública — cor própria, distinta da primária, conforme §4.2.
    //
    // "destrutiva" é a que encerra alguma coisa — revogar uma autorização
    // (T12, T14). O vermelho não é alarme: é o mesmo tom que o sistema reserva
    // ao atraso, e aqui ele marca a ação de que o tutor não sai por engano.
    validator: (v) => ['primary', 'secondary', 'consentimento', 'destrutiva'].includes(v),
  },
  type: { type: String, default: 'button' },
  loading: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
})
</script>

<template>
  <button
    :type="type"
    class="app-button"
    :class="[`app-button--${variant}`, { 'app-button--loading': loading }]"
    :disabled="disabled || loading"
  >
    <span class="app-button__spinner" v-if="loading" aria-hidden="true" />
    <span class="app-button__label"><slot /></span>
  </button>
</template>

<style scoped>
.app-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-2);
  height: 48px;
  padding: 0 var(--space-6);
  border-radius: var(--radius-sm);
  font-family: var(--font-body);
  font-size: 16px;
  font-weight: 600;
  cursor: pointer;
  transition: background-color 120ms cubic-bezier(.2, 0, 0, 1), border-color 120ms cubic-bezier(.2, 0, 0, 1);
}

.app-button--primary {
  background: var(--brand);
  border: 1px solid var(--brand);
  color: #FFFFFF;
}

.app-button--primary:hover:not(:disabled) {
  background: var(--brand-hover);
  border-color: var(--brand-hover);
}

.app-button--secondary {
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  color: var(--ink);
}

.app-button--secondary:hover:not(:disabled) {
  background: var(--surface-sunken);
}

.app-button--consentimento {
  background: var(--consent);
  border: 1px solid var(--consent);
  color: #FFFFFF;
}

.app-button--consentimento:hover:not(:disabled) {
  background: #2E3E73;
  border-color: #2E3E73;
}

.app-button--destrutiva {
  background: var(--status-late);
  border: 1px solid var(--status-late);
  color: #FFFFFF;
}

.app-button--destrutiva:hover:not(:disabled) {
  background: #962E26;
  border-color: #962E26;
}

.app-button:disabled {
  opacity: .45;
  cursor: not-allowed;
}

.app-button__spinner {
  width: 16px;
  height: 16px;
  border-radius: 999px;
  border: 2px solid rgba(255, 255, 255, .5);
  border-top-color: #FFFFFF;
  animation: app-button-spin .6s linear infinite;
}

.app-button--secondary .app-button__spinner {
  border-color: rgba(20, 35, 31, .25);
  border-top-color: var(--ink);
}

@keyframes app-button-spin {
  to { transform: rotate(360deg); }
}
</style>
