<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { Cat, CircleCheck, Dog, Eye, Hourglass, KeyRound, TriangleAlert, X } from '@lucide/vue'
import AppButton from '@/components/base/AppButton.vue'
import AppTextarea from '@/components/base/AppTextarea.vue'
import { ApiError, apiPost } from '@/lib/api.js'
import { descreverEspecie } from '@/lib/animais.js'
import { emNumeros } from '@/lib/datas.js'
import { formatarCpf } from '@/lib/masks.js'

/**
 * V10 — solicitar autorização ao tutor (RF38). Modal sobre V03, V04, V06 e
 * V07a/V08a, porque o pedido nasce onde a falta de autorização apareceu, e
 * mandar o profissional a outra tela seria perder o contexto que ele acabou de
 * ver.
 *
 * O desenho diz duas coisas antes do botão, e nesta ordem: o que o envio faz
 * (uma pergunta chega ao tutor) e o que ele não faz (nenhum acesso se abre).
 * A segunda é a que importa — é RF38a virada para quem pede, e é o que impede
 * o profissional de esperar da tela seguinte um histórico que não virá.
 *
 * O alvo viaja como termo — o CPF ou o código que o profissional já tinha —,
 * nunca como identificador interno: a busca não entrega id de cadastro alheio
 * (RN12), e o pedido não exige da tela um dado que ela nunca recebeu.
 */
const props = defineProps({
  aberto: { type: Boolean, default: false },

  /** Nome do prestador ativo — é em nome dele que o pedido se faz. */
  prestador: { type: String, required: true },

  /** Id do prestador ativo, para carimbar o pedido no vínculo certo. */
  prestadorId: { type: [Number, String], default: null },

  /**
   * O que se está solicitando:
   * `{ tipo: 'animal', termo, nome, especie }` quando o animal é conhecido;
   * `{ tipo: 'cpf', termo }` quando só o CPF foi localizado.
   */
  alvo: { type: Object, default: null },
})

const emit = defineEmits(['fechar', 'enviada'])

const folha = ref(null)
const mensagem = ref('')
const enviando = ref(false)
const erro = ref('')

/** formulario → enviada | ja_pendente — os estados do briefing (§V10). */
const estado = ref('formulario')
const resultado = ref(null)

let focoAnterior = null

const ehAnimal = computed(() => props.alvo?.tipo === 'animal')
const iconeDaEspecie = computed(() => (props.alvo?.especie === 'gato' ? Cat : Dog))
const cpfExibido = computed(() => formatarCpf(props.alvo?.termo ?? ''))

const prazoEmDias = computed(() => resultado.value?.prazo_em_dias ?? 7)

async function enviar() {
  if (enviando.value) return

  enviando.value = true
  erro.value = ''

  try {
    const corpo = { termo: props.alvo.termo }

    const texto = mensagem.value.trim()
    if (texto) corpo.mensagem = texto
    if (props.prestadorId) corpo.prestador = props.prestadorId

    resultado.value = await apiPost('/api/clinica/solicitacoes', corpo)
    estado.value = 'enviada'

    // A tela de origem troca o botão pela etiqueta de espera já atrás do
    // modal: quando ele fechar, o estado novo estará lá.
    emit('enviada', resultado.value)
  } catch (excecao) {
    if (excecao instanceof ApiError && excecao.data?.situacao === 'ja_pendente') {
      resultado.value = excecao.data
      estado.value = 'ja_pendente'
      emit('enviada', excecao.data)

      return
    }

    erro.value = excecao.errors?.termo?.[0]
      ?? excecao.errors?.mensagem?.[0]
      ?? excecao.message
  } finally {
    enviando.value = false
  }
}

function fechar() {
  if (enviando.value) return

  emit('fechar')
}

/**
 * O foco não escapa para a tela de trás enquanto o pedido está aberto — o
 * mesmo desenho de `ConfirmDialog`, pelo mesmo motivo.
 */
function aoTeclar(evento) {
  if (evento.key === 'Escape') {
    fechar()

    return
  }

  if (evento.key !== 'Tab' || !folha.value) return

  const focaveis = folha.value.querySelectorAll(
    'button:not([disabled]), textarea:not([disabled])',
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
    mensagem.value = ''
    erro.value = ''
    estado.value = 'formulario'
    resultado.value = null
    document.addEventListener('keydown', aoTeclar)
    await nextTick()
    folha.value?.querySelector('textarea')?.focus()

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
    <Transition name="solicitar">
      <div v-if="aberto && alvo" class="solicitar__fundo" @click.self="fechar">
        <div
          ref="folha"
          class="solicitar"
          role="dialog"
          aria-modal="true"
          aria-label="Solicitar autorização ao tutor"
        >
          <div class="solicitar__alca" aria-hidden="true" />

          <header class="solicitar__cabecalho">
            <p class="solicitar__sobrelinha">
              <KeyRound :size="16" :stroke-width="1.75" />
              Autorização
            </p>
            <button
              type="button"
              class="solicitar__fechar"
              aria-label="Fechar sem solicitar"
              :disabled="enviando"
              @click="fechar"
            >
              <X :size="20" :stroke-width="1.75" />
            </button>
          </header>

          <!-- O formulário — estado normal do briefing. -->
          <template v-if="estado === 'formulario'">
            <h2 class="solicitar__titulo">Solicitar autorização ao tutor</h2>

            <!-- Identificação do que se solicita: o animal, quando conhecido;
                 ou apenas o CPF localizado — que é tudo o que a busca revelou
                 (RN12), e tudo o que o pedido precisa. -->
            <div class="solicitar__alvo">
              <template v-if="ehAnimal">
                <component :is="iconeDaEspecie" :size="24" :stroke-width="1.75" class="solicitar__alvo-icone" />
                <div>
                  <p class="solicitar__alvo-nome">{{ alvo.nome }}</p>
                  <p class="solicitar__alvo-meta">{{ descreverEspecie(alvo.especie) }}</p>
                </div>
              </template>
              <template v-else>
                <KeyRound :size="24" :stroke-width="1.75" class="solicitar__alvo-icone" />
                <div>
                  <p class="solicitar__alvo-nome solicitar__alvo-nome--cpf">{{ cpfExibido }}</p>
                  <p class="solicitar__alvo-meta">CPF do tutor · cadastro localizado na plataforma</p>
                </div>
              </template>
            </div>

            <div class="solicitar__corpo">
              <p class="solicitar__texto">
                O tutor recebe o pedido em nome de <strong>{{ prestador }}</strong> por e-mail.
              </p>
              <p class="solicitar__texto solicitar__texto--regra">
                A solicitação não dá acesso a nada: até a concessão, tudo continua exatamente como
                está.
              </p>

              <form novalidate @submit.prevent="enviar">
                <AppTextarea
                  id="v10-mensagem"
                  v-model="mensagem"
                  label="Mensagem ao tutor (opcional)"
                  :linhas="2"
                  placeholder="Ex.: Atendimento de hoje no balcão."
                  hint="Uma linha de contexto ajuda o tutor a reconhecer o pedido. Ela aparece junto dele, identificada como sua."
                  :error="erro"
                  auto-expansivel
                />

                <div class="solicitar__acoes">
                  <AppButton variant="consentimento" type="submit" :loading="enviando">
                    Enviar solicitação
                  </AppButton>
                  <AppButton variant="secondary" :disabled="enviando" @click="fechar">
                    Cancelar
                  </AppButton>
                </div>
              </form>
            </div>

            <footer class="solicitar__registro">
              <Eye :size="16" :stroke-width="1.75" class="solicitar__registro-icone" />
              <p>
                O pedido fica visível ao tutor com o nome de {{ prestador }}, data e hora, e espera
                resposta por {{ prazoEmDias }} dias — depois caduca sozinho.
              </p>
            </footer>
          </template>

          <!-- Enviada — e, quando for o caso, o aviso de que o tutor ainda não
               ativou a conta e só responde depois de ativar (RF14b). -->
          <template v-else-if="estado === 'enviada'">
            <div class="solicitar__desfecho">
              <CircleCheck :size="28" :stroke-width="1.75" class="solicitar__desfecho-icone solicitar__desfecho-icone--ok" />
              <h2 class="solicitar__titulo solicitar__titulo--desfecho">Solicitação enviada</h2>
              <p class="solicitar__texto">
                Agora é com o tutor: ele tem {{ prazoEmDias }} dias para responder. Se autorizar,
                o histórico passa a aparecer no âmbito de {{ prestador }} sem mais nenhum passo
                seu.
              </p>

              <div v-if="resultado && resultado.tutor_ativado === false" class="solicitar__aviso">
                <TriangleAlert :size="18" :stroke-width="1.75" class="solicitar__aviso-icone" />
                <p>
                  Este tutor ainda não ativou o acesso dele à plataforma. O pedido fica guardado,
                  mas ele só consegue responder depois de ativar a conta pelo convite recebido por
                  e-mail.
                </p>
              </div>

              <div class="solicitar__acoes">
                <AppButton variant="consentimento" @click="fechar">Entendi</AppButton>
              </div>
            </div>
          </template>

          <!-- Já existe pedido pendente — estado, e não erro: quem pediu de
               novo termina sabendo do pedido que já espera, com a data. -->
          <template v-else>
            <div class="solicitar__desfecho">
              <Hourglass :size="28" :stroke-width="1.75" class="solicitar__desfecho-icone" />
              <h2 class="solicitar__titulo solicitar__titulo--desfecho">
                Já existe uma solicitação aguardando o tutor
              </h2>
              <p class="solicitar__texto">
                {{ prestador }} solicitou em {{ emNumeros(resultado.solicitada_em) }}, e o pedido
                espera resposta até {{ emNumeros(resultado.expira_em) }}. Não é preciso pedir de
                novo: o tutor responde quando decidir.
              </p>

              <div class="solicitar__acoes">
                <AppButton variant="consentimento" @click="fechar">Entendi</AppButton>
              </div>
            </div>
          </template>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.solicitar__fundo {
  position: fixed;
  inset: 0;
  z-index: 40;
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  background: rgba(20, 35, 31, .32);
}

/* §V10 — modal estreito, em folha branca como os demais diálogos; o assunto
   (quem pode ver o quê) fica no índigo da sobrelinha, dos ícones e da ação. */
.solicitar {
  max-height: 90vh;
  overflow-y: auto;
  background: var(--surface-card);
  border-radius: var(--radius-lg) var(--radius-lg) 0 0;
  box-shadow: var(--shadow-modal);
}

.solicitar__alca {
  width: 40px;
  height: 4px;
  margin: var(--space-2) auto 0;
  background: var(--border-strong);
  border-radius: var(--radius-pill);
}

.solicitar__cabecalho {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--space-4) var(--space-4) 0;
}

.solicitar__sobrelinha {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--consent);
}

.solicitar__fechar {
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

.solicitar__fechar:hover {
  background: var(--surface-sunken);
  color: var(--ink);
}

.solicitar__titulo {
  margin: var(--space-2) 0 0;
  padding: 0 var(--space-4);
  font-family: var(--font-display);
  font-size: 22px;
  line-height: 28px;
  font-weight: 600;
  color: var(--ink);
}

.solicitar__alvo {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  margin: var(--space-4) var(--space-4) 0;
  padding: var(--space-3) var(--space-4);
  background: var(--surface-sunken);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

.solicitar__alvo-icone {
  flex: none;
  color: var(--consent);
}

.solicitar__alvo-nome {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.solicitar__alvo-nome--cpf {
  font-family: var(--font-mono);
  font-weight: 500;
  font-variant-numeric: tabular-nums;
}

.solicitar__alvo-meta {
  margin: 0;
  font-size: 13px;
  line-height: 18px;
  color: var(--ink-muted);
}

.solicitar__corpo {
  padding: var(--space-4);
}

.solicitar__texto {
  margin: 0 0 var(--space-3);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

/* RF38a dita para quem pede: destacada porque é a frase que evita a
   expectativa errada sobre a tela seguinte. */
.solicitar__texto--regra {
  margin-bottom: var(--space-4);
  color: var(--ink);
  font-weight: 500;
}

.solicitar__acoes {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  margin: var(--space-4) 0 0;
}

.solicitar__acoes :deep(.app-button) {
  width: 100%;
}

.solicitar__registro {
  display: flex;
  align-items: flex-start;
  gap: var(--space-2);
  padding: var(--space-3) var(--space-4) var(--space-4);
  border-top: 1px solid var(--border-hairline);
}

.solicitar__registro p {
  margin: 0;
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-muted);
}

.solicitar__registro-icone {
  flex: none;
  color: var(--consent);
}

.solicitar__desfecho {
  padding: var(--space-4) var(--space-4) var(--space-6);
}

.solicitar__desfecho-icone {
  color: var(--consent);
}

.solicitar__desfecho-icone--ok {
  color: var(--brand);
}

.solicitar__titulo--desfecho {
  padding: 0;
  margin: var(--space-3) 0 var(--space-2);
}

.solicitar__desfecho .solicitar__texto {
  margin-bottom: 0;
}

.solicitar__aviso {
  display: flex;
  align-items: flex-start;
  gap: var(--space-2);
  margin: var(--space-4) 0 0;
  padding: var(--space-3);
  background: var(--surface-sunken);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-md);
}

.solicitar__aviso p {
  margin: 0;
  font-size: 13px;
  line-height: 18px;
  color: var(--ink);
}

.solicitar__aviso-icone {
  flex: none;
  color: var(--status-due);
}

.solicitar-enter-active,
.solicitar-leave-active {
  transition: opacity 160ms cubic-bezier(.2, 0, 0, 1);
}

.solicitar-enter-active .solicitar,
.solicitar-leave-active .solicitar {
  transition: transform 160ms cubic-bezier(.2, 0, 0, 1);
}

.solicitar-enter-from,
.solicitar-leave-to {
  opacity: 0;
}

.solicitar-enter-from .solicitar,
.solicitar-leave-to .solicitar {
  transform: translateY(16px);
}

/* A partir de `md`, folha vira a caixa estreita de 480 px do briefing. */
@media (min-width: 768px) {
  .solicitar__fundo {
    justify-content: center;
    align-items: center;
    padding: var(--space-6);
  }

  .solicitar {
    width: 100%;
    max-width: 480px;
    border-radius: var(--radius-lg);
  }

  .solicitar__alca {
    display: none;
  }
}

@media (prefers-reduced-motion: reduce) {
  .solicitar-enter-active,
  .solicitar-leave-active,
  .solicitar-enter-active .solicitar,
  .solicitar-leave-active .solicitar {
    transition-duration: 1ms;
  }
}
</style>
