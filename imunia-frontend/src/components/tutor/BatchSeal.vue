<script setup>
import { computed } from 'vue'
import { Syringe } from '@lucide/vue'
import ProvenanceChip from '@/components/tutor/ProvenanceChip.vue'
import { dataDeRegistro, emNumeros } from '@/lib/datas.js'
import { procedenciaDe, procedenciaPrevista } from '@/lib/procedencia.js'

/**
 * O elemento-assinatura do sistema (§5.2 do briefing): o selo de lote de uma
 * aplicação. A variante `unverified` nunca esconde um campo em branco —
 * "não informado" é a resposta honesta do que o tutor não soube dizer.
 */
const props = defineProps({
  aplicacao: { type: Object, required: true },
  compacto: { type: Boolean, default: false },
  // T09 — o mesmo selo, antes de o registro existir. Só a frase de
  // procedência muda de tempo verbal; tudo o mais é idêntico, e é isso que
  // torna a pré-visualização uma promessa verificável.
  previsto: { type: Boolean, default: false },
})

const pregresso = computed(() => props.aplicacao.origem === 'pregresso')

// RF29 — o histórico pregresso pode não trazer data alguma. Dizer "sem data"
// é a mesma escolha do "não informado" dos campos: a ausência é declarada, não
// escondida atrás de um espaço em branco.
const semData = computed(() => !props.aplicacao.data)

const dataExibida = computed(
  () => dataDeRegistro(props.aplicacao.data, props.aplicacao.data_aproximada) ?? 'sem data'
)

// Sem vacina identificada, o rótulo é o que o registro tem de menos certo — e
// esmaecê-lo é o que o distingue de uma dose nomeada.
const rotuloIncerto = computed(() => semData.value && pregresso.value)

const campos = computed(() => [
  { rotulo: 'FABRICANTE', valor: props.aplicacao.fabricante },
  { rotulo: 'LOTE', valor: props.aplicacao.lote },
  { rotulo: 'VALIDADE', valor: emNumeros(props.aplicacao.validade)?.slice(3) },
  { rotulo: 'VIA', valor: props.aplicacao.via_administracao },
])

const procedencia = computed(() => (
  props.previsto
    ? procedenciaPrevista(props.aplicacao.lancado_por)
    : procedenciaDe(props.aplicacao)
))
</script>

<template>
  <div class="batch-seal" :class="{ 'batch-seal--pregresso': pregresso, 'batch-seal--compacto': compacto }">
    <div v-if="aplicacao.validade_expirada_confirmada" class="batch-seal__tarja">
      <Syringe :size="14" :stroke-width="1.75" />
      Aplicada com validade expirada — confirmada pelo profissional
    </div>

    <div class="batch-seal__cabecalho">
      <Syringe :size="16" :stroke-width="1.75" class="batch-seal__icone" />
      <span class="batch-seal__rotulo" :class="{ 'batch-seal__rotulo--incerto': rotuloIncerto }">
        {{ aplicacao.rotulo }}
      </span>
      <span class="batch-seal__data" :class="{ 'batch-seal__data--ausente': semData }">
        {{ dataExibida }}
      </span>
    </div>

    <div class="batch-seal__grade">
      <div v-for="campo in campos" :key="campo.rotulo" class="batch-seal__campo">
        <div class="batch-seal__campo-rotulo">{{ campo.rotulo }}</div>
        <div class="batch-seal__campo-valor" :class="{ 'batch-seal__campo-valor--ausente': !campo.valor }">
          {{ campo.valor ?? 'não informado' }}
        </div>
      </div>
    </div>

    <div class="batch-seal__rodape">
      <ProvenanceChip :variante="procedencia.variante" :texto="procedencia.texto" />
    </div>
  </div>
</template>

<style scoped>
.batch-seal {
  overflow: hidden;
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-xs);
}

.batch-seal--pregresso {
  position: relative;
  border-style: dashed;
  border-color: var(--unverified);
}

.batch-seal--pregresso::before {
  content: '';
  position: absolute;
  inset: 0 auto 0 0;
  width: 4px;
  background: repeating-linear-gradient(
    45deg,
    var(--unverified) 0,
    var(--unverified) 1.5px,
    transparent 1.5px,
    transparent 5px
  );
}

.batch-seal__tarja {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  padding: var(--space-2) var(--space-3);
  background: var(--status-late-wash);
  color: var(--status-late);
  font-size: 12px;
  font-weight: 600;
}

.batch-seal__cabecalho {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  padding: var(--space-3);
  border-bottom: 1px solid var(--border-hairline);
}

.batch-seal__icone {
  flex: none;
  color: var(--brand);
}

.batch-seal--pregresso .batch-seal__icone {
  color: var(--unverified);
}

.batch-seal__rotulo {
  font-size: 16px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.batch-seal__rotulo--incerto {
  color: var(--ink-faint);
}

.batch-seal__data {
  margin-left: auto;
  font-family: var(--font-mono);
  font-size: 13px;
  color: var(--ink-muted);
  font-variant-numeric: tabular-nums;
}

.batch-seal__data--ausente {
  color: var(--ink-faint);
}

.batch-seal__grade {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--space-3) var(--space-4);
  padding: var(--space-3);
}

.batch-seal--compacto .batch-seal__grade {
  grid-template-columns: repeat(4, 1fr);
}

.batch-seal__campo-rotulo {
  font-family: var(--font-mono);
  font-size: 12px;
  line-height: 16px;
  letter-spacing: .08em;
  color: var(--ink-faint);
}

.batch-seal__campo-valor {
  font-family: var(--font-mono);
  font-size: 13px;
  line-height: 18px;
  font-weight: 500;
  color: var(--ink);
  font-variant-numeric: tabular-nums;
}

.batch-seal__campo-valor--ausente {
  font-weight: 400;
  color: var(--ink-faint);
}

.batch-seal__rodape {
  padding: var(--space-3);
  border-top: 1px solid var(--border-hairline);
}

@media (min-width: 768px) {
  .batch-seal__grade {
    grid-template-columns: repeat(4, 1fr);
  }
}
</style>
