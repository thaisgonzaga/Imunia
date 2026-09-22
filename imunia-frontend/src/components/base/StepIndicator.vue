<script setup>
defineProps({
  current: { type: Number, required: true },
  total: { type: Number, required: true },
  label: { type: String, required: true },

  /**
   * O índigo de consentimento é o eixo visual do bloco de autorizações e não
   * aparece em nenhuma outra parte do sistema (§3 do briefing). O passo a passo
   * de T11 o carrega; o de P03, que é cadastro comum, continua na cor da marca.
   */
  tom: {
    type: String,
    default: 'marca',
    validator: (valor) => ['marca', 'consentimento'].includes(valor),
  },
})
</script>

<template>
  <div class="step-indicator" :class="`step-indicator--${tom}`">
    <span class="step-indicator__label">
      Passo {{ current }} de {{ total }}<span class="step-indicator__nome"> · {{ label }}</span>
    </span>
    <span class="step-indicator__track">
      <span
        v-for="n in total"
        :key="n"
        class="step-indicator__segment"
        :class="{ 'step-indicator__segment--filled': n <= current }"
      />
    </span>
  </div>
</template>

<style scoped>
.step-indicator {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-4) var(--space-6);
  background: var(--brand-wash);
  border-bottom: 1px solid var(--border-hairline);
}

.step-indicator__label {
  flex: none;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--brand);
}

.step-indicator__track {
  flex: 1;
  display: flex;
  gap: var(--space-1);
}

.step-indicator__segment {
  flex: 1;
  height: 4px;
  border-radius: var(--radius-pill);
  background: var(--border-strong);
}

.step-indicator__segment--filled {
  background: var(--brand);
}

.step-indicator--consentimento {
  background: var(--consent-wash);
  border-bottom-color: var(--consent);
}

.step-indicator--consentimento .step-indicator__label {
  color: var(--consent);
}

.step-indicator--consentimento .step-indicator__segment--filled {
  background: var(--consent);
}

@media (max-width: 767px) {
  .step-indicator {
    flex-direction: column;
    align-items: stretch;
    gap: var(--space-2);
  }

  /* §6.1 — no celular o passo a passo vira "Passo 2 de 3": o nome da etapa já
     está no título logo abaixo, e repeti-lo aqui rouba a linha inteira. */
  .step-indicator__nome {
    display: none;
  }
}
</style>
