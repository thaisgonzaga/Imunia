<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { ChevronDown, FilePenLine, Lock, TriangleAlert, UserRoundCheck, X } from '@lucide/vue'
import AppButton from '@/components/base/AppButton.vue'
import AppInput from '@/components/base/AppInput.vue'
import AppTextarea from '@/components/base/AppTextarea.vue'
import ConfirmDialog from '@/components/base/ConfirmDialog.vue'
import { formatarMesAno } from '@/lib/masks.js'

/**
 * V09 — retificar registro clínico (RF33, RN26, RN27).
 *
 * A materialização em interface da imutabilidade: duas colunas, o registro
 * original à esquerda em leitura esmaecida, o formulário da correção à direita,
 * e nenhum caminho que sobrescreva ou apague o que está do lado esquerdo. O
 * campo que diverge do original acende em âmbar-lote enquanto se digita — é o
 * que faz a correção ser vista como correção, e não como um segundo registro.
 *
 * Serve às duas espécies de registro clínico do sistema — o prontuário do
 * atendimento e a aplicação de vacina — porque a regra é a mesma para as duas e
 * o desenho também. O que muda é a lista de campos, que chega do servidor com o
 * rótulo, o valor atual e a forma de entrada de cada um.
 */
const props = defineProps({
  aberto: { type: Boolean, default: false },

  /** "Atendimento de Théo · 12/11/2025" — o registro que está sendo corrigido. */
  titulo: { type: String, required: true },

  /** Qual espécie de registro clínico, para a concordância dos textos. */
  registro: {
    type: String,
    default: 'atendimento',
    validator: (v) => ['atendimento', 'aplicacao'].includes(v),
  },

  /**
   * Os campos corrigíveis, do servidor:
   * `{ chave, rotulo, entrada, valor, exibido, obrigatorio }`.
   */
  campos: { type: Array, default: () => [] },

  /** Quem retifica, para o selo do rodapé: `{ nome, crmv, prestador }`. */
  autor: { type: Object, default: null },

  /** Erros 422 do servidor, indexados pelo nome do campo. */
  erros: { type: Object, default: () => ({}) },

  enviando: { type: Boolean, default: false },
})

const emit = defineEmits(['confirmar', 'cancelar'])

const folha = ref(null)
const valores = ref({})
const motivo = ref('')
const motivoTocado = ref(false)
const verOriginal = ref(false)
const confirmando = ref(false)

let focoAnterior = null

/**
 * O formulário abre preenchido com o que está gravado: a retificação é uma
 * correção, e obrigar a redigitar o prontuário inteiro para trocar uma palavra
 * do diagnóstico é o caminho mais curto para introduzir um erro novo enquanto
 * se corrige o antigo.
 */
function preencher() {
  valores.value = Object.fromEntries(props.campos.map((campo) => [campo.chave, campo.valor ?? '']))
  motivo.value = ''
  motivoTocado.value = false
  verOriginal.value = false
  confirmando.value = false
}

const originais = computed(() => Object.fromEntries(
  props.campos.map((campo) => [campo.chave, campo.valor ?? '']),
))

/** As chaves que divergem do original — as que acendem em âmbar. */
const alterados = computed(() => props.campos
  .filter((campo) => (valores.value[campo.chave] ?? '') !== originais.value[campo.chave])
  .map((campo) => campo.chave))

const resumoDasAlteracoes = computed(() => {
  const total = alterados.value.length

  if (total === 0) return 'Retificação · nenhum campo alterado'

  return `Retificação · ${total} ${total === 1 ? 'campo alterado' : 'campos alterados'}`
})

/**
 * O título do diálogo nomeia a ação, não o registro: o nome dele já está no
 * cabeçalho do modal que continua atrás, e repeti-lo aqui produziria um título
 * de três linhas justamente onde a frase precisa ser lida inteira.
 */
const tituloDaConfirmacao = computed(() => (props.registro === 'aplicacao'
  ? 'Registrar a retificação desta aplicação'
  : 'Registrar a retificação deste atendimento'))

const motivoVazio = computed(() => motivo.value.trim() === '')

const erroDoMotivo = computed(() => {
  if (props.erros.motivo_retificacao) return props.erros.motivo_retificacao
  if (motivoTocado.value && motivoVazio.value) {
    return 'Escreva o motivo da retificação. Ele fica visível ao tutor ao lado das duas versões, e é o '
      + 'que explica a correção a quem ler o registro depois.'
  }

  return ''
})

/**
 * Sem alteração não há o que registrar: uma retificação idêntica criaria uma
 * segunda versão sem informação nova, e confundiria quem lesse o histórico
 * depois. O botão fica desabilitado **com a explicação ao lado** — desabilitar
 * em silêncio deixaria a pessoa procurando o que fez de errado.
 */
const podeRegistrar = computed(() => alterados.value.length > 0)

function campoAlterado(chave) {
  return alterados.value.includes(chave)
}

function aoDigitar(campo, valor) {
  valores.value[campo.chave] = campo.entrada === 'mes_ano' ? formatarMesAno(valor) : valor
}

function abrirConfirmacao() {
  motivoTocado.value = true

  if (!podeRegistrar.value || motivoVazio.value) return

  confirmando.value = true
}

function confirmar() {
  confirmando.value = false
  emit('confirmar', { ...valores.value, motivo_retificacao: motivo.value.trim() })
}

function fechar() {
  if (props.enviando) return

  emit('cancelar')
}

/**
 * O foco não escapa para a tela de trás enquanto a correção está aberta: quem
 * navega por teclado precisa percorrer o formulário inteiro — inclusive a
 * coluna do original — sem sair dele por engano.
 */
function aoTeclar(evento) {
  if (evento.key === 'Escape') {
    // Com o diálogo de confirmação aberto, o Escape é dele: fechar os dois de
    // uma vez faria a desistência de confirmar apagar o texto já redigido.
    if (confirmando.value) return

    fechar()

    return
  }

  if (evento.key !== 'Tab' || !folha.value) return

  const focaveis = folha.value.querySelectorAll(
    'button:not([disabled]), input:not([disabled]), textarea:not([disabled])',
  )

  if (focaveis.length === 0) return

  const primeiro = focaveis[0]
  const ultimo = focaveis[focaveis.length - 1]

  if (evento.shiftKey && document.activeElement === primeiro) {
    evento.preventDefault()
    ultimo.focus()
  } else if (!evento.shiftKey && document.activeElement === ultimo) {
    evento.preventDefault()
    primeiro.focus()
  }
}

watch(() => props.aberto, async (aberto) => {
  if (aberto) {
    focoAnterior = document.activeElement
    preencher()
    document.addEventListener('keydown', aoTeclar)
    await nextTick()
    folha.value?.querySelector('textarea, input')?.focus()

    return
  }

  document.removeEventListener('keydown', aoTeclar)
  focoAnterior?.focus?.()
  focoAnterior = null
})

onBeforeUnmount(() => document.removeEventListener('keydown', aoTeclar))
</script>

<template>
  <Teleport to="body">
    <Transition name="retificar">
      <div v-if="aberto" class="retificar__fundo" @click.self="fechar">
        <div
          ref="folha"
          class="retificar"
          role="dialog"
          aria-modal="true"
          :aria-label="`Retificar ${titulo}`"
        >
          <div class="retificar__alca" aria-hidden="true" />

          <header class="retificar__cabecalho">
            <div class="retificar__identificacao">
              <p class="retificar__sobrelinha">
                <FilePenLine :size="16" :stroke-width="1.75" />
                Retificação
              </p>
              <h2 class="retificar__titulo">{{ titulo }}</h2>
            </div>
            <button
              type="button"
              class="retificar__fechar"
              aria-label="Fechar sem retificar"
              :disabled="enviando"
              @click="fechar"
            >
              <X :size="20" :stroke-width="1.75" />
            </button>
          </header>

          <!-- RN26 — a regra é dita antes do formulário, e no tempo verbal do
               que vai acontecer: não é aviso de bloqueio, é explicação do que a
               confirmação faz. -->
          <div class="retificar__imutabilidade">
            <Lock :size="20" :stroke-width="1.75" class="retificar__cadeado" />
            <p>
              A versão original não é apagada nem alterada. A retificação entra como registro novo
              vinculado a ela, e o tutor vê as duas versões, com o motivo e a data da correção.
            </p>
          </div>

          <div class="retificar__colunas">
            <section class="original">
              <!-- Abaixo de `lg` as colunas empilham e o original vem recolhido:
                   quem corrige tem o texto na cabeça, e a comparação lado a lado
                   não cabe. -->
              <button
                type="button"
                class="original__disclosure"
                :aria-expanded="verOriginal"
                @click="verOriginal = !verOriginal"
              >
                {{ verOriginal ? 'Ocultar versão original' : 'Ver versão original' }}
                <ChevronDown
                  :size="16"
                  :stroke-width="1.75"
                  class="original__seta"
                  :class="{ 'original__seta--aberta': verOriginal }"
                />
              </button>

              <div class="original__conteudo" :class="{ 'original__conteudo--aberto': verOriginal }">
                <span class="pilula pilula--neutra">
                  <Lock :size="14" :stroke-width="1.75" />
                  Original · somente leitura
                </span>

                <dl class="original__campos">
                  <div v-for="campo in campos" :key="campo.chave" class="original__campo">
                    <dt class="original__rotulo">{{ campo.rotulo }}</dt>
                    <dd class="original__texto">{{ campo.exibido || 'Não informado' }}</dd>
                  </div>
                </dl>
              </div>
            </section>

            <section class="correcao">
              <span
                class="pilula"
                :class="alterados.length ? 'pilula--ambar' : 'pilula--neutra'"
                aria-live="polite"
              >
                <FilePenLine v-if="alterados.length" :size="14" :stroke-width="1.75" />
                {{ resumoDasAlteracoes }}
              </span>

              <div class="correcao__campos">
                <div
                  v-for="campo in campos"
                  :key="campo.chave"
                  class="correcao__campo"
                  :class="{ 'correcao__campo--alterado': campoAlterado(campo.chave) }"
                >
                  <AppTextarea
                    v-if="campo.entrada === 'texto_longo'"
                    :id="`retificar-campo-${campo.chave}`"
                    :label="campo.rotulo"
                    :model-value="valores[campo.chave] ?? ''"
                    :error="erros[campo.chave] ?? ''"
                    auto-expansivel
                    @update:model-value="aoDigitar(campo, $event)"
                  />
                  <AppInput
                    v-else
                    :id="`retificar-campo-${campo.chave}`"
                    :label="campo.rotulo"
                    :type="campo.entrada === 'data_hora' ? 'datetime-local' : 'text'"
                    :inputmode="campo.entrada === 'numero' || campo.entrada === 'mes_ano'
                      ? 'numeric' : undefined"
                    :placeholder="campo.entrada === 'mes_ano' ? 'MM/AAAA' : undefined"
                    :model-value="valores[campo.chave] ?? ''"
                    :error="erros[campo.chave] ?? ''"
                    @update:model-value="aoDigitar(campo, $event)"
                  />

                  <span v-if="campoAlterado(campo.chave)" class="correcao__marca">alterado</span>
                </div>
              </div>

              <!-- RF33 — o motivo é obrigatório, e a razão está escrita embaixo
                   dele: não é formalidade de sistema, é o que o tutor vai ler. -->
              <div class="motivo" :class="{ 'motivo--invalido': erroDoMotivo }">
                <!-- O identificador não é `retificar-motivo`: o prontuário tem
                     um campo de chave `motivo` — o motivo da consulta —, e dois
                     `id` iguais fariam este rótulo apontar para aquele campo.
                     Um clique no rótulo levaria o cursor para a caixa errada. -->
                <AppTextarea
                  id="retificar-motivo-da-correcao"
                  label="Motivo da retificação · obrigatório"
                  :model-value="motivo"
                  :error="erroDoMotivo"
                  :hint="erroDoMotivo ? '' : 'O motivo fica visível ao tutor junto das duas versões.'"
                  placeholder="Explique o que motivou a correção"
                  auto-expansivel
                  @update:model-value="motivo = $event"
                  @blur="motivoTocado = true"
                />
              </div>
            </section>
          </div>

          <footer class="retificar__rodape">
            <p v-if="autor" class="selo">
              <UserRoundCheck :size="16" :stroke-width="1.75" class="selo__icone" />
              <span>
                Retificando como {{ autor.nome }}
                <span v-if="autor.crmv" class="selo__crmv">· {{ autor.crmv }}</span>
                · autor do registro
              </span>
            </p>

            <div class="retificar__acoes">
              <p v-if="!podeRegistrar" class="retificar__explicacao">
                <TriangleAlert :size="16" :stroke-width="1.75" />
                Altere ao menos um campo para habilitar. Uma retificação idêntica criaria uma segunda
                versão sem informação nova.
              </p>
              <div class="retificar__botoes">
                <AppButton variant="secondary" :disabled="enviando" @click="fechar">
                  Cancelar
                </AppButton>
                <AppButton :disabled="!podeRegistrar" :loading="enviando" @click="abrirConfirmacao">
                  Registrar retificação
                </AppButton>
              </div>
            </div>
          </footer>
        </div>
      </div>
    </Transition>
  </Teleport>

  <!-- Montado sempre, com `aberto` alternando: um diálogo que nasce já aberto
       não dispara o observador que leva o foco para dentro dele. -->
  <ConfirmDialog
    :aberto="confirmando"
    :titulo="tituloDaConfirmacao"
    :acontece="[
      'Entra uma versão nova, vinculada à original, com o seu nome, o seu CRMV e o motivo da correção.',
      'O tutor passa a ver as duas versões e a data em que a correção foi feita.',
    ]"
    :nao-acontece="[
      'A versão original não é apagada nem alterada: ela continua no sistema como foi confirmada.',
      'A retificação, uma vez registrada, também não pode ser editada nem excluída.',
    ]"
    rotulo-confirmar="Registrar retificação"
    rotulo-cancelar="Revisar"
    :carregando="enviando"
    @confirmar="confirmar"
    @cancelar="confirmando = false"
  />
</template>

<style scoped>
.retificar__fundo {
  position: fixed;
  inset: 0;
  /* Abaixo do `ConfirmDialog`, que decide sobre o que este formulário redigiu. */
  z-index: 30;
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  background: rgba(20, 35, 31, .32);
}

.retificar {
  display: flex;
  flex-direction: column;
  max-height: 92vh;
  overflow-y: auto;
  background: var(--surface-card);
  border-radius: var(--radius-lg) var(--radius-lg) 0 0;
  box-shadow: var(--shadow-modal);
}

.retificar__alca {
  width: 40px;
  height: 4px;
  margin: var(--space-2) auto 0;
  background: var(--border-strong);
  border-radius: var(--radius-pill);
}

.retificar__cabecalho {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--space-4);
  padding: var(--space-4);
}

.retificar__sobrelinha {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--status-due-text);
}

.retificar__titulo {
  margin: var(--space-1) 0 0;
  font-family: var(--font-display);
  font-size: 22px;
  line-height: 28px;
  font-weight: 600;
  color: var(--ink);
  text-wrap: pretty;
}

.retificar__fechar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: none;
  width: 48px;
  height: 48px;
  margin: -4px -8px 0 0;
  background: none;
  border: none;
  border-radius: var(--radius-sm);
  color: var(--ink-muted);
  cursor: pointer;
}

.retificar__fechar:hover {
  background: var(--surface-sunken);
}

.retificar__imutabilidade {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  padding: var(--space-4);
  background: var(--surface-sunken);
  border-top: 1px solid var(--border-hairline);
  border-bottom: 1px solid var(--border-hairline);
}

.retificar__imutabilidade p {
  margin: 0;
  max-width: 75ch;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
  text-wrap: pretty;
}

.retificar__cadeado {
  flex: none;
  margin-top: 2px;
  color: var(--ink-muted);
}

.retificar__colunas {
  display: flex;
  flex-direction: column;
}

/* ── Coluna do original ─────────────────────────────────────────────────── */

.original {
  padding: var(--space-4);
  background: var(--surface-page);
  border-bottom: 1px solid var(--border-hairline);
}

.original__disclosure {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-2);
  width: 100%;
  height: 48px;
  padding: 0 var(--space-3);
  background: var(--surface-sunken);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  font-family: var(--font-body);
  font-size: 16px;
  font-weight: 600;
  color: var(--ink);
  cursor: pointer;
}

.original__seta {
  flex: none;
  color: var(--ink-muted);
  transition: transform 120ms cubic-bezier(.2, 0, 0, 1);
}

.original__seta--aberta {
  transform: rotate(180deg);
}

.original__conteudo {
  display: none;
}

.original__conteudo--aberto {
  display: block;
  margin-top: var(--space-4);
}

.original__campos {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  margin: var(--space-4) 0 0;

  /* O original é leitura, e a tela precisa dizê-lo sem palavras: é a coluna
     que não recebe o foco nem o cursor de texto. */
  opacity: .55;
}

.original__campo {
  min-width: 0;
}

.original__rotulo {
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

.original__texto {
  margin: 6px 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
  white-space: pre-wrap;
  text-wrap: pretty;
}

/* ── Coluna da correção ─────────────────────────────────────────────────── */

.correcao {
  padding: var(--space-4);
}

.correcao__campos {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  margin: var(--space-4) 0 0;
}

.correcao__campo {
  position: relative;
}

/* O destaque do campo divergente: mesma âmbar-lote do selo de lote e da tarja
   de retificação, porque é a mesma ideia — "este dado mudou de mão". */
.correcao__campo--alterado :deep(.app-textarea__campo),
.correcao__campo--alterado :deep(.app-field__input) {
  border-color: var(--status-due);
}

.correcao__marca {
  position: absolute;
  top: 0;
  right: 0;
  display: inline-flex;
  align-items: center;
  height: 22px;
  padding: 0 10px;
  background: var(--surface-card);
  border: 1px solid var(--status-due);
  border-radius: var(--radius-pill);
  font-size: 12px;
  font-weight: 600;
  color: var(--status-due-text);
}

.motivo {
  margin: var(--space-4) 0 0;
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--status-due);
  border-radius: var(--radius-md);
}

.motivo :deep(.app-textarea__label) {
  color: var(--status-due-text);
}

.motivo--invalido {
  border-color: var(--status-late);
}

.pilula {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 22px;
  padding: 2px 10px;
  border-radius: var(--radius-pill);
  font-size: 12px;
  line-height: 16px;
  font-weight: 600;
}

.pilula--neutra {
  background: var(--surface-sunken);
  color: var(--ink-muted);
}

.pilula--ambar {
  background: var(--surface-card);
  border: 1px solid var(--status-due);
  color: var(--status-due-text);
}

/* ── Rodapé ─────────────────────────────────────────────────────────────── */

.retificar__rodape {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
  padding: var(--space-4);
  border-top: 1px solid var(--border-hairline);
}

.selo {
  display: inline-flex;
  align-items: flex-start;
  gap: var(--space-2);
  margin: 0;
  padding: 6px 10px;
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-xs);
  font-size: 12px;
  line-height: 16px;
  color: var(--ink);
}

.selo__icone {
  flex: none;
  color: var(--brand);
}

.selo__crmv {
  font-family: var(--font-mono);
  font-weight: 500;
}

.retificar__explicacao {
  display: flex;
  align-items: flex-start;
  gap: var(--space-2);
  margin: 0 0 var(--space-3);
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
}

.retificar__explicacao svg {
  flex: none;
  margin-top: 1px;
}

.retificar__botoes {
  display: flex;
  flex-direction: column-reverse;
  gap: var(--space-2);
}

.retificar__botoes :deep(.app-button) {
  width: 100%;
}

/* ── Tablet: o modal deixa de ser folha inferior ────────────────────────── */

@media (min-width: 768px) {
  .retificar__fundo {
    justify-content: center;
    align-items: center;
    padding: var(--space-6);
  }

  .retificar {
    width: 100%;
    max-width: 720px;
    border: 1px solid var(--border-hairline);
    border-radius: var(--radius-lg);
  }

  .retificar__alca {
    display: none;
  }

  .retificar__cabecalho,
  .retificar__imutabilidade,
  .retificar__rodape {
    padding: var(--space-4) var(--space-6);
  }

  .original,
  .correcao {
    padding: var(--space-4) var(--space-6);
  }

  .retificar__botoes {
    flex-direction: row;
    justify-content: flex-end;
  }

  .retificar__botoes :deep(.app-button) {
    width: auto;
    padding: 0 var(--space-4);
  }
}

/* ── A partir de `lg`, a densidade do ambiente clínico ──────────────────── */

/* O alvo mínimo de 44 px de §4.3 vale **abaixo** de 1024 px, e é onde a
   tentação de aplicar as alturas de 40 px do desenho de 1440 px produz
   violação: entre 768 e 1023 px o layout já tem cara de desktop e a mão
   continua sendo a de quem toca a tela. */
@media (min-width: 1024px) {
  .retificar__botoes :deep(.app-button) {
    height: 40px;
    font-size: 14px;
  }

  .original__disclosure {
    height: 40px;
    font-size: 14px;
  }
}

/* ── Desktop largo: comparação lado a lado ──────────────────────────────── */

/* A duas colunas a partir de `xl`, e não de `lg`. Em 1024 px cada coluna
   ficaria com cerca de 430 px de campo, e o conteúdo aqui é texto clínico
   longo — a mesma razão pela qual V08 mudou a sua grade de 1280 para 1440.
   Abaixo daqui as colunas empilham e o original vem recolhido, como o desenho
   de 1024 px prescreve. */
@media (min-width: 1280px) {
  .retificar {
    max-width: 1120px;
  }

  .retificar__colunas {
    display: grid;
    grid-template-columns: 1fr 1fr;
  }

  .original {
    border-right: 1px solid var(--border-hairline);
    border-bottom: none;
  }

  /* A partir daqui as duas colunas cabem, e o original está sempre à vista:
     a comparação lado a lado é o argumento da tela, e escondê-la atrás de um
     botão numa largura que a comporta seria desfazer o desenho. */
  .original__disclosure {
    display: none;
  }

  .original__conteudo {
    display: block;
  }

  .retificar__rodape {
    flex-direction: row;
    align-items: center;
    justify-content: space-between;
  }

  .retificar__acoes {
    display: flex;
    align-items: center;
    gap: var(--space-4);
  }

  .retificar__explicacao {
    max-width: 42ch;
    margin: 0;
  }
}

/* ── Transição ──────────────────────────────────────────────────────────── */

.retificar-enter-active,
.retificar-leave-active {
  transition: opacity 160ms cubic-bezier(.2, 0, 0, 1);
}

.retificar-enter-active .retificar,
.retificar-leave-active .retificar {
  transition: transform 160ms cubic-bezier(.2, 0, 0, 1);
}

.retificar-enter-from,
.retificar-leave-to {
  opacity: 0;
}

.retificar-enter-from .retificar,
.retificar-leave-to .retificar {
  transform: translateY(16px);
}

@media (min-width: 768px) {
  .retificar-enter-from .retificar,
  .retificar-leave-to .retificar {
    transform: scale(.98);
  }
}

@media (prefers-reduced-motion: reduce) {
  .retificar-enter-active,
  .retificar-leave-active,
  .retificar-enter-active .retificar,
  .retificar-leave-active .retificar {
    transition-duration: 1ms;
  }
}
</style>
