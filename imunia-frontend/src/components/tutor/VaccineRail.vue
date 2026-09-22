<script setup>
import { computed } from 'vue'
import { CircleCheck, CircleHelp, ClockAlert, TriangleAlert } from '@lucide/vue'

/**
 * O trilho vacinal (§5.2 do briefing): a série de um imunobiológico, estação
 * por dose. Vertical em celular, horizontal com rolagem a partir de `md` — a
 * mesma regra de responsividade de toda a carteira (RF28b).
 */
const props = defineProps({
  estacoes: { type: Array, required: true },
  notaRegra: { type: String, default: '' },
})

function emNumeros(iso) {
  if (!iso) return null
  return new Intl.DateTimeFormat('pt-BR').format(new Date(`${iso}T00:00:00`))
}

const estacoesPreparadas = computed(() => props.estacoes.map((estacao) => ({
  ...estacao,
  dataExibida: estacao.data_aproximada
    ? new Date(`${estacao.data}T00:00:00`).getFullYear()
    : emNumeros(estacao.data),
})))

const ICONES = {
  aplicada: CircleCheck,
  'aplicada-pregresso': CircleHelp,
  prevista: null,
  proxima: ClockAlert,
  atrasada: TriangleAlert,
  extra: TriangleAlert,
}

function iconePara(estacao) {
  if (estacao.tipo === 'aplicada' && estacao.origem === 'pregresso') return ICONES['aplicada-pregresso']
  return ICONES[estacao.tipo]
}

function classeDaEstacao(estacao) {
  if (estacao.tipo === 'aplicada' && estacao.origem === 'pregresso') return 'vaccine-rail__marca--pregresso'
  return `vaccine-rail__marca--${estacao.tipo}`
}
</script>

<template>
  <div class="vaccine-rail">
    <div class="vaccine-rail__linha">
      <div v-for="(estacao, indice) in estacoesPreparadas" :key="indice" class="vaccine-rail__estacao">
        <span class="vaccine-rail__marca" :class="classeDaEstacao(estacao)">
          <component :is="iconePara(estacao)" v-if="iconePara(estacao)" :size="14" :stroke-width="2" />
        </span>
        <div class="vaccine-rail__texto">
          <p class="vaccine-rail__rotulo">{{ estacao.rotulo }}</p>
          <p class="vaccine-rail__data">{{ estacao.dataExibida ?? 'a calcular' }}</p>
        </div>
      </div>
    </div>

    <p v-if="notaRegra" class="vaccine-rail__nota">{{ notaRegra }}</p>
  </div>
</template>

<style scoped>
.vaccine-rail {
  display: flex;
  flex-direction: column;
}

.vaccine-rail__linha {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: var(--space-5, 20px);
  padding-left: 4px;
}

.vaccine-rail__linha::before {
  content: '';
  position: absolute;
  left: 15px;
  top: 16px;
  bottom: 16px;
  width: 2px;
  background: var(--border-strong);
}

.vaccine-rail__estacao {
  position: relative;
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
}

.vaccine-rail__marca {
  position: relative;
  z-index: 1;
  display: flex;
  flex: none;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  border-radius: var(--radius-pill);
  background: var(--surface-card);
  color: var(--surface-card);
  box-shadow: 0 0 0 4px var(--surface-card);
}

.vaccine-rail__marca--aplicada {
  background: var(--status-ok);
}

.vaccine-rail__marca--pregresso {
  border: 2px dashed var(--unverified);
  background: var(--surface-card);
  color: var(--unverified);
}

.vaccine-rail__marca--prevista {
  border: 2px dashed var(--border-strong);
  background: var(--surface-card);
}

.vaccine-rail__marca--proxima {
  border: 2px solid var(--status-due);
  background: var(--surface-card);
  color: var(--status-due-text);
}

.vaccine-rail__marca--atrasada {
  background: var(--status-late);
}

.vaccine-rail__marca--extra {
  border-radius: var(--radius-xs);
  transform: rotate(45deg);
  border: 2px dashed var(--status-due);
  background: var(--surface-card);
  color: var(--status-due-text);
}

.vaccine-rail__marca--extra > * {
  transform: rotate(-45deg);
}

.vaccine-rail__texto {
  flex: 1;
  min-width: 0;
}

.vaccine-rail__rotulo {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.vaccine-rail__data {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
  font-variant-numeric: tabular-nums;
}

.vaccine-rail__nota {
  margin: var(--space-4) 0 0;
  padding: var(--space-3);
  background: var(--surface-sunken);
  border-radius: var(--radius-sm);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

/* Horizontal com rolagem a partir de md — a série lê-se em linha quando há
   espaço, mantendo a mesma ordem cronológica. */
@media (min-width: 768px) {
  .vaccine-rail__linha {
    flex-direction: row;
    gap: var(--space-6);
    overflow-x: auto;
    padding: 4px 4px 8px;
  }

  .vaccine-rail__linha::before {
    left: 16px;
    right: 16px;
    top: 15px;
    bottom: auto;
    width: auto;
    height: 2px;
  }

  .vaccine-rail__estacao {
    flex-direction: column;
    align-items: center;
    flex: none;
    width: 140px;
    text-align: center;
  }

  .vaccine-rail__texto {
    text-align: center;
  }
}
</style>
