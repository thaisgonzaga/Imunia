<script setup>
import { computed } from 'vue'
import { CircleHelp, Eye, FilePenLine, UserRound, UserRoundCheck } from '@lucide/vue'

/**
 * O componente mais importante do sistema (§5.2 do briefing): declara a
 * origem de qualquer informação clínica. Nenhum registro clínico é
 * renderizado em tela alguma sem um `ProvenanceChip` — se o desenho não
 * comporta o chip, o desenho está errado.
 */
const props = defineProps({
  variante: {
    type: String,
    required: true,
    validator: (v) => ['professional', 'unverified', 'owner-declared', 'other-provider', 'rectified'].includes(v),
  },
  texto: { type: String, required: true },
})

const ICONES = {
  professional: UserRoundCheck,
  unverified: CircleHelp,
  'owner-declared': UserRound,
  'other-provider': UserRoundCheck,
  rectified: FilePenLine,
}

const icone = computed(() => ICONES[props.variante])
</script>

<template>
  <span class="provenance-chip" :class="`provenance-chip--${variante}`">
    <component :is="icone" :size="16" :stroke-width="1.75" class="provenance-chip__icone" />
    <span>{{ texto }}</span>
    <Eye v-if="variante === 'other-provider'" :size="14" :stroke-width="1.75" class="provenance-chip__olho" />
  </span>
</template>

<style scoped>
.provenance-chip {
  display: inline-flex;
  align-items: flex-start;
  gap: var(--space-2);
  padding: 6px 10px;
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-xs);
  font-size: 12px;
  line-height: 16px;
  color: var(--ink);
}

.provenance-chip__icone {
  flex: none;
  color: var(--brand);
}

.provenance-chip__olho {
  flex: none;
  margin-left: auto;
  color: var(--ink-muted);
}

/* unverified — RN24: nunca verde, nunca âmbar; ausência de responsabilidade
   técnica, não uma situação intermediária. */
.provenance-chip--unverified {
  position: relative;
  padding-left: 14px;
  border: 1px dashed var(--unverified);
  background: repeating-linear-gradient(
    45deg,
    var(--unverified) 0,
    var(--unverified) 1.5px,
    transparent 1.5px,
    transparent 5px
  ) left / 4px 100% no-repeat, var(--surface-card);
}

.provenance-chip--unverified .provenance-chip__icone {
  color: var(--unverified);
}

.provenance-chip--owner-declared {
  border-style: dotted;
  border-color: var(--consent);
}

.provenance-chip--owner-declared .provenance-chip__icone {
  color: var(--consent);
}

.provenance-chip--rectified {
  border-color: var(--status-due);
}

.provenance-chip--rectified .provenance-chip__icone {
  color: var(--status-due-text);
}
</style>
