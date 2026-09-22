<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { CircleCheck, Moon, X } from '@lucide/vue'
import AppButton from '@/components/base/AppButton.vue'
import AppInput from '@/components/base/AppInput.vue'
import ConfirmDialog from '@/components/base/ConfirmDialog.vue'
import { ApiError, apiPost } from '@/lib/api.js'
import { emNumeros } from '@/lib/datas.js'

/**
 * V12 — registrar óbito (RF22). Modal sobre V06, porque o registro é sobre o
 * animal cuja ficha está aberta, e mandar o profissional a outra tela seria
 * tratar como percurso o que é uma decisão.
 *
 * O tom é o §4.1 do briefing: sóbrio, sem cor de alerta. Óbito não é erro nem
 * emergência — é fato, e a tela o trata com a mesma serenidade da tarja que a
 * ficha exibirá depois. É uma das poucas telas em que o tom importa mais que a
 * eficiência, e a redação evita tanto o eufemismo quanto a frieza.
 *
 * A confirmação é o `ConfirmDialog` com a estrutura de P3: o que acontece
 * (cessam lembretes e calendário) e o que não acontece (o histórico fica, o
 * registro não se exclui). A segunda lista é a que decide — é ela que separa
 * "encerrar o acompanhamento" de "apagar o animal", que é o medo de quem
 * hesita diante do botão.
 */
const props = defineProps({
  aberto: { type: Boolean, default: false },

  /** O animal da ficha: `{ codigo, nome, obito }`. */
  animal: { type: Object, default: null },

  /** Id do prestador ativo, para carimbar o registro no vínculo certo (RN27). */
  prestadorId: { type: [Number, String], default: null },
})

const emit = defineEmits(['fechar', 'registrado'])

const folha = ref(null)

/** formulario → confirmando → sucesso | ja_registrado — os estados de §V12. */
const estado = ref('formulario')
const em = ref('')
const causa = ref('')
const enviando = ref(false)
const erros = ref({})
const resultado = ref(null)

let focoAnterior = null

/** A data local de hoje, que é o caso comum: o óbito assistido na clínica. */
function hojeIso() {
  const agora = new Date()

  return [
    agora.getFullYear(),
    String(agora.getMonth() + 1).padStart(2, '0'),
    String(agora.getDate()).padStart(2, '0'),
  ].join('-')
}

/** O que o estado "já registrado" exibe: o 409 recém-recebido, ou a ficha. */
const jaRegistrado = computed(() => resultado.value ?? props.animal?.obito ?? null)

function abrirConfirmacao() {
  erros.value = {}

  if (!em.value) {
    erros.value = { em: 'Informe a data do óbito.' }

    return
  }

  estado.value = 'confirmando'
}

async function confirmar() {
  if (enviando.value) return

  enviando.value = true

  try {
    const corpo = { em: em.value }

    const texto = causa.value.trim()
    if (texto) corpo.causa = texto
    if (props.prestadorId) corpo.prestador = props.prestadorId

    resultado.value = await apiPost(`/api/clinica/animais/${props.animal.codigo}/obito`, corpo)
    estado.value = 'sucesso'

    // A ficha recarrega já atrás do modal: quando ele fechar, a tarja, o selo
    // e a supressão das ações de registro estarão lá (§V12, "sucesso → V06 em
    // estado de animal inativo").
    emit('registrado', resultado.value)
  } catch (excecao) {
    if (excecao instanceof ApiError && excecao.data?.situacao === 'ja_registrado') {
      resultado.value = excecao.data
      estado.value = 'ja_registrado'

      // Outro profissional chegou primeiro: o que a ficha exibe está
      // desatualizado, e recarregá-la é o mesmo gesto do sucesso.
      emit('registrado', excecao.data)

      return
    }

    erros.value = {
      em: excecao.errors?.em?.[0],
      causa: excecao.errors?.causa?.[0],
      geral: excecao.errors?.em || excecao.errors?.causa ? '' : excecao.message,
    }
    estado.value = 'formulario'
  } finally {
    enviando.value = false
  }
}

function fechar() {
  if (enviando.value) return

  emit('fechar')
}

/**
 * O foco não escapa para a ficha enquanto a decisão está aberta — o mesmo
 * desenho de `ConfirmDialog`, pelo mesmo motivo.
 */
function aoTeclar(evento) {
  if (evento.key === 'Escape') {
    // Com a confirmação aberta, o Escape é dela: fechar as duas de uma vez
    // faria a desistência de confirmar descartar a data já preenchida.
    if (estado.value === 'confirmando') return

    fechar()

    return
  }

  if (evento.key !== 'Tab' || !folha.value) return

  const focaveis = folha.value.querySelectorAll(
    'button:not([disabled]), input:not([disabled])',
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
    em.value = hojeIso()
    causa.value = ''
    erros.value = {}
    resultado.value = null

    // Aberto sobre um animal que já tem óbito — por endereço guardado ou por
    // clique numa aba antiga —, o modal não oferece formulário: informa o que
    // consta. É o estado "já registrado" do briefing.
    estado.value = props.animal?.obito ? 'ja_registrado' : 'formulario'
    document.addEventListener('keydown', aoTeclar)
    await nextTick()
    folha.value?.querySelector('input, button')?.focus()

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
    <Transition name="obito">
      <div v-if="aberto && animal" class="obito__fundo" @click.self="fechar">
        <div
          ref="folha"
          class="obito"
          role="dialog"
          aria-modal="true"
          :aria-label="`Registrar óbito de ${animal.nome}`"
        >
          <div class="obito__alca" aria-hidden="true" />

          <header class="obito__cabecalho">
            <p class="obito__sobrelinha">
              <Moon :size="16" :stroke-width="1.75" />
              Óbito
            </p>
            <button
              type="button"
              class="obito__fechar"
              aria-label="Fechar sem registrar"
              :disabled="enviando"
              @click="fechar"
            >
              <X :size="20" :stroke-width="1.75" />
            </button>
          </header>

          <!-- O formulário — estados normal e confirmando do briefing; a
               confirmação em si é o ConfirmDialog montado adiante. -->
          <template v-if="estado === 'formulario' || estado === 'confirmando'">
            <h2 class="obito__titulo">Registrar o óbito de {{ animal.nome }}</h2>

            <div class="obito__corpo">
              <p class="obito__texto">
                O registro encerra o acompanhamento: o calendário deixa de ser calculado e nenhum
                lembrete é enviado ao tutor. O histórico de {{ animal.nome }} permanece como está.
              </p>

              <form novalidate @submit.prevent="abrirConfirmacao">
                <AppInput
                  id="v12-data"
                  v-model="em"
                  type="date"
                  label="Data do óbito"
                  hint="Pode ser anterior a hoje, quando o óbito não aconteceu na clínica."
                  :error="erros.em"
                />

                <AppInput
                  id="v12-causa"
                  v-model="causa"
                  label="Causa (opcional)"
                  placeholder="Ex.: Insuficiência renal crônica"
                  hint="Uma linha, se houver causa conhecida. Ela aparece no histórico, junto do registro."
                  :error="erros.causa"
                />

                <p v-if="erros.geral" class="obito__erro" role="alert">{{ erros.geral }}</p>

                <div class="obito__acoes">
                  <AppButton type="submit" :loading="enviando">
                    Continuar
                  </AppButton>
                  <AppButton variant="secondary" :disabled="enviando" @click="fechar">
                    Cancelar
                  </AppButton>
                </div>
              </form>
            </div>

            <footer class="obito__registro">
              <p>
                O registro leva seu nome e sua inscrição, e não pode ser excluído — apenas
                retificado, como todo registro clínico.
              </p>
            </footer>
          </template>

          <!-- Sucesso — a ficha atrás já está no estado inativo. -->
          <template v-else-if="estado === 'sucesso'">
            <div class="obito__desfecho">
              <CircleCheck :size="28" :stroke-width="1.75" class="obito__desfecho-icone" />
              <h2 class="obito__titulo obito__titulo--desfecho">Óbito registrado</h2>
              <p class="obito__texto">
                Registrado em {{ emNumeros(resultado.em) }}. A ficha de {{ animal.nome }} passa ao
                estado inativo: sem calendário e sem lembretes ao tutor. O histórico permanece
                consultável por ele e pelos prestadores autorizados.
              </p>

              <div class="obito__acoes">
                <AppButton variant="secondary" @click="fechar">Entendi</AppButton>
              </div>
            </div>
          </template>

          <!-- Já registrado — estado, e não erro: quem chegou depois termina
               sabendo o que consta, com data e autor. -->
          <template v-else>
            <div class="obito__desfecho">
              <Moon :size="28" :stroke-width="1.75" class="obito__desfecho-icone" />
              <h2 class="obito__titulo obito__titulo--desfecho">
                O óbito já está registrado
              </h2>
              <p class="obito__texto">
                Registrado em {{ emNumeros(jaRegistrado.em) }}<template v-if="jaRegistrado.registrado_por"> por
                {{ jaRegistrado.registrado_por }}</template>. O registro não pode ser excluído —
                apenas retificado, como todo registro clínico.
              </p>

              <div class="obito__acoes">
                <AppButton variant="secondary" @click="fechar">Entendi</AppButton>
              </div>
            </div>
          </template>
        </div>
      </div>
    </Transition>
  </Teleport>

  <!-- Montado sempre, com `aberto` alternando: um diálogo que nasce já aberto
       não dispara o observador que leva o foco para dentro dele. Os textos são
       os de §V12, na estrutura de P3. -->
  <ConfirmDialog
    :aberto="estado === 'confirmando'"
    :titulo="`Registrar o óbito de ${animal?.nome ?? 'este animal'}`"
    :acontece="[
      'Nenhum lembrete será enviado ao tutor a partir de agora.',
      'O calendário vacinal deixa de ser calculado.',
    ]"
    :nao-acontece="[
      'O histórico permanece consultável pelo tutor e pelos prestadores autorizados.',
      'Este registro não pode ser excluído — apenas retificado, como todo registro clínico.',
    ]"
    rotulo-confirmar="Registrar óbito"
    rotulo-cancelar="Voltar e revisar"
    :carregando="enviando"
    @confirmar="confirmar"
    @cancelar="estado = 'formulario'"
  />
</template>

<style scoped>
.obito__fundo {
  position: fixed;
  inset: 0;
  z-index: 40;
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  background: rgba(20, 35, 31, .32);
}

/* §V12 — modal estreito, tom sóbrio: a folha branca dos demais diálogos, e o
   assunto no neutro de `--ink-muted`, nunca em cor de alerta (§4.1). */
.obito {
  max-height: 90vh;
  overflow-y: auto;
  background: var(--surface-card);
  border-radius: var(--radius-lg) var(--radius-lg) 0 0;
  box-shadow: var(--shadow-modal);
}

.obito__alca {
  width: 40px;
  height: 4px;
  margin: var(--space-2) auto 0;
  background: var(--border-strong);
  border-radius: var(--radius-pill);
}

.obito__cabecalho {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--space-4) var(--space-4) 0;
}

.obito__sobrelinha {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

.obito__fechar {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  background: none;
  border: 0;
  border-radius: var(--radius-sm);
  color: var(--ink-muted);
  cursor: pointer;
}

.obito__fechar:hover {
  background: var(--surface-sunken);
  color: var(--ink);
}

.obito__titulo {
  margin: var(--space-2) 0 0;
  padding: 0 var(--space-4);
  font-family: var(--font-display);
  font-size: 22px;
  line-height: 28px;
  font-weight: 600;
  color: var(--ink);
}

.obito__corpo {
  padding: var(--space-4);
}

.obito__corpo form {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.obito__texto {
  margin: 0 0 var(--space-4);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.obito__erro {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--status-late);
}

.obito__acoes {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  margin: var(--space-2) 0 0;
}

.obito__acoes :deep(.app-button) {
  width: 100%;
}

.obito__registro {
  padding: var(--space-3) var(--space-4) var(--space-4);
  border-top: 1px solid var(--border-hairline);
}

.obito__registro p {
  margin: 0;
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-muted);
}

.obito__desfecho {
  padding: var(--space-4) var(--space-4) var(--space-6);
}

.obito__desfecho-icone {
  color: var(--ink-muted);
}

.obito__titulo--desfecho {
  padding: 0;
  margin: var(--space-3) 0 var(--space-2);
}

.obito__desfecho .obito__texto {
  margin-bottom: 0;
}

.obito-enter-active,
.obito-leave-active {
  transition: opacity 160ms cubic-bezier(.2, 0, 0, 1);
}

.obito-enter-active .obito,
.obito-leave-active .obito {
  transition: transform 160ms cubic-bezier(.2, 0, 0, 1);
}

.obito-enter-from,
.obito-leave-to {
  opacity: 0;
}

.obito-enter-from .obito,
.obito-leave-to .obito {
  transform: translateY(16px);
}

/* A partir de `md`, folha vira a caixa estreita de 480 px do briefing. */
@media (min-width: 768px) {
  .obito__fundo {
    justify-content: center;
    align-items: center;
    padding: var(--space-6);
  }

  .obito {
    width: 100%;
    max-width: 480px;
    border-radius: var(--radius-lg);
  }

  .obito__alca {
    display: none;
  }
}

@media (prefers-reduced-motion: reduce) {
  .obito-enter-active,
  .obito-leave-active,
  .obito-enter-active .obito,
  .obito-leave-active .obito {
    transition-duration: 1ms;
  }
}
</style>
