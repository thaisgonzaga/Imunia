<script setup>
/**
 * Campo de escolha com busca por digitação — o seletor de imunobiológico de
 * V07 (RNF15).
 *
 * Não é `AppSelect`. O `<select>` nativo obriga a percorrer a lista inteira, e
 * o critério de noventa segundos se decide justamente aqui: o profissional
 * digita "antir", confirma com Enter e o resto do formulário se preenche
 * sozinho a partir da última aplicação. Com um `<select>` de trinta itens, o
 * primeiro campo já custaria metade do tempo que a tela inteira tem.
 *
 * O padrão de acessibilidade é o `combobox` do ARIA: o campo anuncia a lista
 * que controla, a opção em foco viaja em `aria-activedescendant` (o foco do
 * teclado nunca sai do campo, para que quem digita continue digitando) e a
 * quantidade de resultados é dita em região viva a cada filtro.
 */
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { Check, ChevronDown, TriangleAlert } from '@lucide/vue'

const props = defineProps({
  /** O valor escolhido — a chave estável da opção, nunca o rótulo exibido. */
  modelValue: { type: String, default: '' },
  label: { type: String, required: true },
  id: { type: String, required: true },
  /** `[{ valor, rotulo, detalhe }]` */
  opcoes: { type: Array, default: () => [] },
  placeholder: { type: String, default: 'Digite para filtrar' },
  error: { type: String, default: '' },
  hint: { type: String, default: '' },
  autofoco: { type: Boolean, default: false },
  readonly: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])

const campo = ref(null)
const aberto = ref(false)
const termo = ref('')
const emFoco = ref(0)

const escolhida = computed(
  () => props.opcoes.find((opcao) => opcao.valor === props.modelValue) ?? null,
)

/**
 * O texto do campo é o rótulo da opção escolhida enquanto a lista está
 * fechada, e o que se está digitando enquanto está aberta. Sem essa troca, o
 * campo mostraria a busca depois de escolhida a vacina — e a tela diria que
 * nada foi escolhido.
 */
const texto = computed(() => (aberto.value ? termo.value : escolhida.value?.rotulo ?? ''))

const filtradas = computed(() => {
  const busca = normalizar(termo.value)

  if (busca === '') return props.opcoes

  return props.opcoes.filter(
    (opcao) => normalizar(opcao.rotulo).includes(busca) || normalizar(opcao.detalhe ?? '').includes(busca),
  )
})

/**
 * "antirrábica" tem de ser encontrada por "antirrabica": quem digita depressa
 * não põe acento, e o critério de noventa segundos não sobrevive a uma busca
 * que exige ortografia perfeita.
 */
function normalizar(valor) {
  return valor
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '')
    .toLowerCase()
    .trim()
}

function abrir() {
  if (props.readonly) return

  aberto.value = true
  termo.value = ''
  emFoco.value = Math.max(0, filtradas.value.findIndex((opcao) => opcao.valor === props.modelValue))
}

function fechar() {
  aberto.value = false
  termo.value = ''
}

function escolher(opcao) {
  if (!opcao) return

  emit('update:modelValue', opcao.valor)
  fechar()
}

function aoDigitar(evento) {
  termo.value = evento.target.value
  aberto.value = true
  emFoco.value = 0
}

function aoTeclar(evento) {
  if (evento.key === 'Escape' && aberto.value) {
    evento.preventDefault()
    fechar()

    return
  }

  if (evento.key === 'Enter' && aberto.value) {
    // Só consome o Enter quando há o que escolher. Com a lista vazia, o atalho
    // de confirmação da tela (Ctrl/Cmd + Enter) continua sendo o dono da tecla.
    if (filtradas.value.length > 0) {
      evento.preventDefault()
      escolher(filtradas.value[emFoco.value])
    }

    return
  }

  if (evento.key !== 'ArrowDown' && evento.key !== 'ArrowUp') return

  evento.preventDefault()

  if (!aberto.value) {
    abrir()

    return
  }

  const total = filtradas.value.length
  if (total === 0) return

  emFoco.value = evento.key === 'ArrowDown'
    ? (emFoco.value + 1) % total
    : (emFoco.value - 1 + total) % total
}

/** A opção em foco tem de ficar visível quando se percorre a lista pelo teclado. */
watch(emFoco, async () => {
  await nextTick()
  document.getElementById(`${props.id}-opcao-${emFoco.value}`)?.scrollIntoView({ block: 'nearest' })
})

function aoPerderFoco(evento) {
  // `relatedTarget` nulo acontece quando o clique cai fora de qualquer alvo
  // focalizável; fechar nesse caso é o comportamento esperado.
  if (evento.relatedTarget?.closest?.(`#${props.id}-lista`)) return

  fechar()
}

onMounted(() => {
  if (props.autofoco) campo.value?.focus()
})

defineExpose({ focar: () => campo.value?.focus() })
</script>

<template>
  <div class="combo">
    <label :for="id" class="combo__label">{{ label }}</label>

    <div class="combo__moldura" :class="{ 'combo__moldura--error': error }">
      <slot name="icone" />
      <input
        :id="id"
        ref="campo"
        class="combo__campo"
        type="text"
        role="combobox"
        autocomplete="off"
        :aria-expanded="aberto"
        :aria-controls="`${id}-lista`"
        :aria-activedescendant="aberto && filtradas.length ? `${id}-opcao-${emFoco}` : undefined"
        :aria-invalid="error ? 'true' : undefined"
        :placeholder="placeholder"
        :readonly="readonly"
        :value="texto"
        @input="aoDigitar"
        @keydown="aoTeclar"
        @focus="abrir"
        @blur="aoPerderFoco"
      >
      <ChevronDown :size="16" class="combo__seta" />
    </div>

    <ul v-if="aberto" :id="`${id}-lista`" class="combo__lista" role="listbox">
      <li v-if="filtradas.length === 0" class="combo__vazio">
        Nenhuma vacina do catálogo corresponde a “{{ termo }}”.
      </li>
      <li
        v-for="(opcao, indice) in filtradas"
        :id="`${id}-opcao-${indice}`"
        :key="opcao.valor"
        class="combo__opcao"
        :class="{ 'combo__opcao--foco': indice === emFoco }"
        role="option"
        :aria-selected="opcao.valor === modelValue"
        @mousedown.prevent="escolher(opcao)"
        @mousemove="emFoco = indice"
      >
        <span class="combo__opcao-texto">
          <span class="combo__opcao-rotulo">{{ opcao.rotulo }}</span>
          <span v-if="opcao.detalhe" class="combo__opcao-detalhe">{{ opcao.detalhe }}</span>
        </span>
        <Check v-if="opcao.valor === modelValue" :size="16" class="combo__marca" />
      </li>
    </ul>

    <p class="visually-hidden" aria-live="polite">
      {{ aberto ? `${filtradas.length} opções` : '' }}
    </p>

    <p v-if="error" class="combo__error">
      <TriangleAlert :size="16" class="combo__error-icone" />
      <span>{{ error }}</span>
    </p>
    <p v-else-if="hint" class="combo__hint">{{ hint }}</p>
  </div>
</template>

<style scoped>
.combo {
  position: relative;
  display: flex;
  flex-direction: column;
}

.combo__label {
  display: block;
  margin: 0 0 6px;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink);
}

.combo__moldura {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  height: 48px;
  padding: 0 var(--space-3);
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
}

.combo__moldura:focus-within {
  border-color: var(--brand-bright);
}

.combo__moldura--error {
  border-color: var(--status-late);
}

.combo__campo {
  flex: 1;
  min-width: 0;
  height: 100%;
  border: none;
  outline: none;
  background: transparent;
  font-family: var(--font-body);
  font-size: 16px;
  color: var(--ink);
}

.combo__seta {
  flex: none;
  color: var(--ink-muted);
}

.combo__lista {
  position: absolute;
  z-index: 20;
  top: 100%;
  left: 0;
  right: 0;
  max-height: 280px;
  margin: var(--space-1) 0 0;
  padding: var(--space-1);
  overflow-y: auto;
  list-style: none;
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-sm);
  box-shadow: var(--shadow-overlay);
}

.combo__opcao {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  min-height: 44px;
  padding: var(--space-2) var(--space-3);
  border-radius: var(--radius-xs);
  cursor: pointer;
}

.combo__opcao--foco {
  background: var(--brand-wash);
}

.combo__opcao-texto {
  flex: 1;
  min-width: 0;
}

.combo__opcao-rotulo {
  display: block;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

.combo__opcao-detalhe {
  display: block;
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
}

.combo__marca {
  flex: none;
  color: var(--brand);
}

.combo__vazio {
  padding: var(--space-3);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.combo__error {
  display: flex;
  align-items: flex-start;
  gap: var(--space-2);
  margin: 6px 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--status-late);
}

.combo__error-icone {
  flex: none;
  margin-top: 2px;
}

.combo__hint {
  margin: 6px 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip-path: inset(50%);
  white-space: nowrap;
}

@media (min-width: 1024px) {
  .combo__moldura {
    height: 40px;
  }

  .combo__campo {
    font-size: 14px;
  }
}
</style>
