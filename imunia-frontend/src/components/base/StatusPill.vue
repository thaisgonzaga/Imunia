<script setup>
import { computed } from 'vue'
import { CircleCheck, CircleHelp, ClockAlert, Moon, TriangleAlert } from '@lucide/vue'

/**
 * Situação de uma dose (§5.2 do briefing). A regra que não se negocia: ícone e
 * texto sempre juntos. A cor é reforço, nunca portadora única do significado —
 * exigência de acessibilidade e, aqui, de segurança clínica.
 *
 * O texto vem pronto do servidor ("há 42 dias", "em 6 dias"), porque quem
 * calcula o prazo é quem conhece a versão do protocolo aplicada (RF26).
 */
const props = defineProps({
  tipo: {
    type: String,
    required: true,
    validator: (v) => ['em-dia', 'proxima', 'atrasada', 'nao-verificada', 'encerrada'].includes(v),
  },
  texto: { type: String, required: true },
})

const ICONES = {
  'em-dia': CircleCheck,
  proxima: ClockAlert,
  atrasada: TriangleAlert,
  'nao-verificada': CircleHelp,

  // RF22a — o calendário encerrado pelo óbito. Lua e neutro, como toda menção
  // a óbito (§4.1): não é pendência de ninguém, e não pede cor de alerta.
  encerrada: Moon,
}

const icone = computed(() => ICONES[props.tipo])
</script>

<template>
  <span class="status-pill" :class="`status-pill--${tipo}`">
    <component :is="icone" :size="14" :stroke-width="1.75" />
    {{ texto }}
  </span>
</template>

<style scoped>
.status-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 22px;
  padding: 0 10px;
  white-space: nowrap;
  border-radius: var(--radius-pill);
  background: var(--surface-card);
  font-size: 12px;
  line-height: 16px;
  font-weight: 600;
}

.status-pill--em-dia {
  border: 1px solid var(--status-ok);
  color: var(--status-ok);
}

/* --status-due não passa em contraste como cor de texto: a borda o usa, a
   palavra usa a variante escura prevista para isso nos tokens. */
.status-pill--proxima {
  border: 1px solid var(--status-due);
  color: var(--status-due-text);
}

.status-pill--atrasada {
  border: 1px solid var(--status-late);
  color: var(--status-late);
}

.status-pill--nao-verificada {
  border: 1px dashed var(--unverified);
  color: var(--ink-muted);
}

/* Borda cheia, e não tracejada: encerrado é estado definitivo, não incerteza. */
.status-pill--encerrada {
  border: 1px solid var(--border-strong);
  color: var(--ink-muted);
}
</style>
