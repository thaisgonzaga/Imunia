<script setup>
import { computed } from 'vue'
import { CircleHelp, FilePenLine, FileText, Moon, Paperclip, Stethoscope, Syringe } from '@lucide/vue'
// A entrada vinculada é desenhada aqui dentro, e por isso precisa das mesmas
// duas funções que a entrada principal usa para data e procedência.
import ProvenanceChip from '@/components/tutor/ProvenanceChip.vue'
import { dataDeRegistro } from '@/lib/datas.js'
import { procedenciaNoAmbienteClinico } from '@/lib/procedencia.js'

/**
 * Uma entrada da linha do tempo de T07. O marcador diz o tipo do registro, o
 * chip diz de quem ele é: nenhuma entrada é desenhada sem procedência.
 *
 * A entrada não sabe nada sobre a origem dos dados nem para onde navega — quem
 * a monta decide o destino. Assim ela serve às vacinações de hoje e aos
 * atendimentos, exames e retificações das fatias seguintes sem mudar.
 */
const props = defineProps({
  entrada: { type: Object, required: true },
  destino: { type: String, required: true },

  // As entradas que existem por causa desta — hoje, as retificações do
  // atendimento (RF33). Vêm coladas ao cartão, ligadas por um fio: soltas na
  // cronologia, pareceriam registros clínicos sem relação com o que corrigem.
  vinculadas: { type: Array, default: () => [] },

  // O prestador em cujo contexto se lê a linha do tempo (V06). Presente apenas
  // no ambiente clínico: o registro produzido por outro prestador ganha ali o
  // chip com o ícone de olho, que anuncia que aquela leitura fica registrada
  // (RF52, RN49). O tutor lendo o próprio animal não tem contexto de prestador
  // algum, e por isso a prop é opcional.
  prestadorAtivo: { type: String, default: null },
})

const ICONES_POR_TIPO = {
  vacinacao: Syringe,
  atendimento: Stethoscope,
  exame: FileText,
  retificacao: FilePenLine,
  obito: Moon,
}

const pregresso = computed(() => props.entrada.origem === 'pregresso')

// RN24 — o registro não verificado se anuncia antes de ser lido: no marcador,
// na borda tracejada e na hachura, não só no chip do rodapé.
const icone = computed(() => (pregresso.value ? CircleHelp : ICONES_POR_TIPO[props.entrada.tipo]))

// RF29 — o registro pregresso pode não trazer data. Declarar a ausência é a
// mesma escolha do "não informado" do selo de lote.
const dataExibida = computed(
  () => dataDeRegistro(props.entrada.data, props.entrada.data_aproximada) ?? 'sem data'
)

const procedencia = computed(() =>
  procedenciaNoAmbienteClinico(props.entrada, props.prestadorAtivo),
)
</script>

<template>
  <li class="entrada" :class="{ 'entrada--pregresso': pregresso }">
    <span class="entrada__marcador" aria-hidden="true">
      <component :is="icone" :size="14" :stroke-width="1.75" />
    </span>

    <div class="entrada__pilha">
      <RouterLink
        :to="destino"
        class="entrada__cartao"
        :class="{ 'entrada__cartao--encadeado': vinculadas.length > 0 }"
      >
        <span class="entrada__topo">
          <span class="entrada__titulo">{{ entrada.titulo }}</span>
          <span class="entrada__data">{{ dataExibida }}</span>
        </span>

        <span v-if="entrada.resumo" class="entrada__resumo">{{ entrada.resumo }}</span>

        <span class="entrada__rodape">
          <ProvenanceChip :variante="procedencia.variante" :texto="procedencia.texto" />
          <span v-if="entrada.anexos > 0" class="entrada__anexos">
            <Paperclip :size="14" :stroke-width="1.75" />
            {{ entrada.anexos }} anexo{{ entrada.anexos === 1 ? '' : 's' }}
          </span>
        </span>
      </RouterLink>

      <RouterLink
        v-for="vinculada in vinculadas"
        :key="`${vinculada.entrada.tipo}-${vinculada.entrada.id}`"
        :to="vinculada.destino"
        class="vinculada"
      >
        <span class="vinculada__fio" aria-hidden="true" />
        <span class="vinculada__corpo">
          <span class="entrada__topo">
            <span class="vinculada__titulo">
              <FilePenLine :size="16" :stroke-width="1.75" class="vinculada__icone" />
              {{ vinculada.entrada.titulo }}
            </span>
            <span class="entrada__data">
              {{ dataDeRegistro(vinculada.entrada.data, vinculada.entrada.data_aproximada) }}
            </span>
          </span>
          <span v-if="vinculada.entrada.resumo" class="entrada__resumo">
            {{ vinculada.entrada.resumo }}
          </span>
          <ProvenanceChip
            :variante="procedenciaNoAmbienteClinico(vinculada.entrada, prestadorAtivo).variante"
            :texto="procedenciaNoAmbienteClinico(vinculada.entrada, prestadorAtivo).texto"
          />
        </span>
      </RouterLink>
    </div>
  </li>
</template>

<style scoped>
.entrada {
  position: relative;
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
}

.entrada__marcador {
  display: flex;
  align-items: center;
  justify-content: center;
  flex: none;
  width: 24px;
  height: 24px;
  border-radius: var(--radius-pill);
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  color: var(--brand);
  /* Abre um vão na linha vertical do trilho, sem precisar interrompê-la. */
  box-shadow: 0 0 0 4px var(--surface-page);
}

.entrada__pilha {
  flex: 1;
  min-width: 0;
}

.entrada__cartao {
  display: block;
  padding: var(--space-3) var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
  color: var(--ink);
}

.entrada__cartao:hover {
  border-color: var(--border-strong);
  color: var(--ink);
}

/* Com retificação abaixo, os dois cartões viram um bloco só: o corte reto do
   pé do primeiro é o que impede que pareçam dois registros independentes. */
.entrada__cartao--encadeado {
  border-radius: var(--radius-md) var(--radius-md) 0 0;
}

/* Retificação vinculada — RF33 -------------------------------------------- */

.vinculada {
  display: flex;
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-top: none;
  border-radius: 0 0 var(--radius-md) var(--radius-md);
  color: var(--ink);
}

.vinculada:hover {
  border-color: var(--border-strong);
  color: var(--ink);
}

.vinculada__fio {
  flex: none;
  width: 2px;
  margin: var(--space-3) 11px;
  background: var(--status-due);
}

.vinculada__corpo {
  flex: 1;
  min-width: 0;
  padding: var(--space-3) var(--space-4) var(--space-3) 0;
}

.vinculada__titulo {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  font-size: 16px;
  line-height: 24px;
  font-weight: 600;
}

.vinculada__icone {
  flex: none;
  color: var(--status-due-text);
}

.entrada__topo {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: var(--space-3);
}

.entrada__titulo {
  font-size: 18px;
  line-height: 24px;
  font-weight: 600;
}

.entrada__data {
  flex: none;
  font-family: var(--font-mono);
  font-size: 13px;
  line-height: 18px;
  color: var(--ink-muted);
  font-variant-numeric: tabular-nums;
}

.entrada__resumo {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  line-clamp: 2;
  overflow: hidden;
  margin: var(--space-1) 0 var(--space-2);
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
}

.entrada__rodape {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2);
}

.entrada__anexos {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-muted);
}

/* Pregresso — RN24: cinza e tracejado, nunca verde nem âmbar. A marca é de
   ausência de responsabilidade técnica, não de uma situação intermediária. */

.entrada--pregresso .entrada__marcador {
  border-style: dashed;
  border-color: var(--unverified);
  color: var(--unverified);
}

.entrada--pregresso .entrada__cartao {
  border-style: dashed;
  border-color: var(--unverified);
  padding-left: calc(var(--space-4) + 4px);
  background: repeating-linear-gradient(
    45deg,
    var(--unverified) 0,
    var(--unverified) 1.5px,
    transparent 1.5px,
    transparent 5px
  ) left / 4px 100% no-repeat, var(--surface-card);
}

@media (min-width: 768px) {
  .entrada {
    gap: var(--space-4);
  }

  .entrada__cartao {
    padding: var(--space-4) var(--space-5, 20px);
  }

  .entrada--pregresso .entrada__cartao {
    padding-left: calc(var(--space-5, 20px) + 4px);
  }

  .vinculada__fio {
    margin: var(--space-4) 11px;
  }

  .vinculada__corpo {
    padding: var(--space-4) var(--space-5, 20px) var(--space-4) 0;
  }
}
</style>
