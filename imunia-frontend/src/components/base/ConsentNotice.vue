<script setup>
import { CircleHelp } from '@lucide/vue'

/**
 * `ConsentNotice` (§5.2 do briefing) — bloco na cor de consentimento com o
 * texto aplicável ao que está prestes a acontecer. Aparece antes da ação, e
 * nunca como nota de rodapé: o que ele explica é a consequência, e explicar
 * consequência depois de consumada não é explicar.
 *
 * O conteúdo vem por slot porque o texto muda a cada tela — a concessão de
 * autorização, a exportação de PDF e o lançamento pregresso falam de coisas
 * diferentes. O que não muda é a forma, e é ela que o componente guarda.
 */
defineProps({
  // O ícone acompanha o assunto: `circle-help` para o que é incerto (T09),
  // `key-round` para autorização, `file-check` para exportação.
  icone: { type: [Object, Function], default: () => CircleHelp },
})
</script>

<template>
  <div class="consent-notice">
    <component :is="icone" :size="20" :stroke-width="1.75" class="consent-notice__icone" />
    <div class="consent-notice__corpo">
      <slot />
    </div>
  </div>
</template>

<style scoped>
.consent-notice {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  padding: var(--space-4);
  background: var(--consent-wash);
  border: 1px solid var(--consent);
  border-radius: var(--radius-sm);
}

.consent-notice__icone {
  flex: none;
  margin-top: 2px;
  color: var(--consent);
}

/* O briefing fixa 75 caracteres por linha: é texto para leigo, e linha longa
   é o que faz o leigo desistir de ler. */
.consent-notice__corpo {
  max-width: 75ch;
}

.consent-notice__corpo :deep(p) {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);
}

.consent-notice__corpo :deep(p + p) {
  margin-top: var(--space-2);
}

.consent-notice__corpo :deep(strong) {
  font-weight: 600;
}
</style>
