<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  Building2,
  Cat,
  ChevronLeft,
  ClockAlert,
  Dog,
  KeyRound,
  Lock,
  TriangleAlert,
  UserRoundCheck,
} from '@lucide/vue'
import TutorShell from '@/components/tutor/TutorShell.vue'
import ConsentNotice from '@/components/base/ConsentNotice.vue'
import EmptyState from '@/components/base/EmptyState.vue'
import OtpInput from '@/components/base/OtpInput.vue'
import StepIndicator from '@/components/base/StepIndicator.vue'
import { apiGet, apiPost } from '@/lib/api.js'
import { descreverAnimal, enumerarNomes } from '@/lib/animais.js'
import { emNumeros } from '@/lib/datas.js'

/**
 * T11 — conceder autorização (RF36, RF37).
 *
 * O ato central do modelo de consentimento do sistema, e a única tela em que
 * cada passo existe para tornar mais lento o que poderia ser um clique. A
 * escolha do prestador e a dos animais não autorizam nada: o que autoriza é o
 * código que chega ao e-mail do tutor, e que esta tela nunca exibe, nem
 * pré-preenche, nem sugere (RF37c).
 *
 * O índigo de consentimento cobre a tela inteira — cabeçalho, passo a passo,
 * bordas do que está marcado. É o eixo visual do bloco de autorizações e não
 * aparece em nenhuma outra parte do sistema: quem chega aqui precisa perceber,
 * antes de ler, que esta tela não é como as outras.
 */
const route = useRoute()
const router = useRouter()

/**
 * A escolha em curso sobrevive à ida ao e-mail: quem descobre no passo 3 que
 * precisa confirmar o endereço (RF37b) sai daqui, confirma e volta — e a tela
 * promete, em texto, que a escolha estará guardada. Fica na sessão do
 * navegador, e não no servidor, porque é rascunho de uma intenção, não fato a
 * registrar.
 */
const RASCUNHO = 'imunia:autorizacao-em-curso'

const opcoes = ref(null)
const carregando = ref(true)
const erroAoCarregar = ref('')

const passo = ref(1)
const prestadorEscolhido = ref(null)
const animaisEscolhidos = ref([])
const erroDaEscolha = ref('')

const confirmacao = ref(null)
const codigo = ref('')
const enviando = ref(false)

/**
 * O desfecho do passo 3, no vocabulário que o servidor devolve:
 * `email_nao_verificado`, `codigo_incorreto`, `codigo_expirado`, `bloqueada`,
 * `muitos_pedidos`. Nulo é o estado normal — o de quem ainda vai digitar.
 */
const situacao = ref(null)
const mensagem = ref('')
const tentativasRestantes = ref(null)
const segundos = ref(0)
const segundosDeBloqueio = ref(0)
const confirmacaoReenviada = ref(false)

const concessao = ref(null)

// Contadores ---------------------------------------------------------------
// Um relógio só para os dois prazos da tela: o do código, que libera o reenvio
// ao terminar, e o da pausa por tentativas.

let relogio = null

function iniciarRelogio() {
  clearInterval(relogio)

  relogio = setInterval(() => {
    if (segundos.value > 0) {
      segundos.value--

      // Vencido o prazo, a tela troca de estado sozinha: o botão de conceder
      // sai e o de pedir código novo entra, como manda o desenho.
      if (segundos.value === 0 && situacao.value === null) {
        situacao.value = 'codigo_expirado'
        mensagem.value = 'O prazo deste código terminou.'
      }
    }

    if (segundosDeBloqueio.value > 0) {
      segundosDeBloqueio.value--

      if (segundosDeBloqueio.value === 0 && situacao.value === 'bloqueada') {
        situacao.value = 'codigo_expirado'
        mensagem.value = 'A pausa terminou. Peça um código novo para continuar.'
      }
    }
  }, 1000)
}

onBeforeUnmount(() => clearInterval(relogio))

function emMinutosESegundos(total) {
  const minutos = Math.floor(total / 60)
  const restantes = total % 60

  if (minutos === 0) return `${restantes} s`

  return `${minutos} min ${String(restantes).padStart(2, '0')} s`
}

const contador = computed(() => emMinutosESegundos(segundos.value))
const contadorDoBloqueio = computed(() => emMinutosESegundos(segundosDeBloqueio.value))

// Carregamento -------------------------------------------------------------

async function carregar() {
  carregando.value = true
  erroAoCarregar.value = ''

  const rascunho = lerRascunho()
  const daUrl = Number(route.query.prestador)
  const escolhido = Number.isInteger(daUrl) && daUrl > 0 ? daUrl : rascunho?.prestador ?? null

  try {
    const busca = escolhido ? `?prestador=${escolhido}` : ''
    const resposta = await apiGet(`/api/autorizacoes/nova${busca}`)

    opcoes.value = resposta
    prestadorEscolhido.value = resposta.prestador_escolhido ?? resposta.prestadores[0]?.id ?? null

    if (rascunho) restaurar(rascunho)

    if (resposta.bloqueio) {
      situacao.value = 'bloqueada'
      segundosDeBloqueio.value = resposta.bloqueio.segundos_restantes
      iniciarRelogio()
    }
  } catch (excecao) {
    erroAoCarregar.value = excecao.message
  } finally {
    carregando.value = false
  }
}

onMounted(async () => {
  await carregar()
  await atenderPedidoDeAcesso()
})

/**
 * A chegada de T13 (RF38). O pedido já disse quem e sobre qual animal, e repetir
 * as duas escolhas ao tutor que acabou de lê-las seria transformar em quatro
 * toques o que o briefing manda resolver em um — e estourar o limite de quatro
 * passos contados da tela inicial (RNF14).
 *
 * O que se pula é a escolha, e não a confirmação: o código continua saindo para
 * o e-mail do tutor, e é ele que autoriza. O passo 3 é o destino porque é lá
 * que o consentimento acontece.
 */
async function atenderPedidoDeAcesso() {
  const pedido = String(route.query.animal ?? '')

  if (pedido === '' || opcoes.value === null) return

  // O animal que não é do tutor, ou que este prestador já pode ver, não tem
  // pedido a atender: a tela volta a ser a de sempre, no passo em que a escolha
  // ainda faz sentido.
  if (!animais.value.some((animal) => animal.codigo === pedido)) return

  if (jaAutorizados.value.has(pedido)) {
    passo.value = 2

    return
  }

  animaisEscolhidos.value = [pedido]

  // A pausa por tentativas para o percurso onde ela está explicada, com a
  // escolha já marcada: o passo 3 sem confirmação aberta não teria o que dizer.
  if (bloqueada.value) {
    passo.value = 2

    return
  }

  await pedirCodigo()
}

function lerRascunho() {
  try {
    return JSON.parse(sessionStorage.getItem(RASCUNHO) ?? 'null')
  } catch {
    return null
  }
}

function guardarRascunho() {
  sessionStorage.setItem(
    RASCUNHO,
    JSON.stringify({ prestador: prestadorEscolhido.value, animais: animaisEscolhidos.value }),
  )
}

function esquecerRascunho() {
  sessionStorage.removeItem(RASCUNHO)
}

/**
 * A volta do e-mail confirmado cai aqui: o prestador e os animais retomam a
 * marcação, e o tutor reaparece no passo em que parou.
 */
function restaurar(rascunho) {
  if (rascunho.prestador) prestadorEscolhido.value = rascunho.prestador

  const disponiveis = new Set(selecionaveis.value.map((animal) => animal.codigo))
  animaisEscolhidos.value = (rascunho.animais ?? []).filter((codigo) => disponiveis.has(codigo))

  if (animaisEscolhidos.value.length > 0) passo.value = 2
}

// Passo 1 — o prestador ----------------------------------------------------

const prestadores = computed(() => opcoes.value?.prestadores ?? [])

const prestador = computed(
  () => prestadores.value.find((item) => item.id === prestadorEscolhido.value) ?? null,
)

function escolherPrestador(id) {
  prestadorEscolhido.value = id

  // Autorização é por par prestador-animal: trocar de estabelecimento desfaz a
  // marcação, porque o que já foi autorizado num não vale no outro.
  animaisEscolhidos.value = []
}

// Passo 2 — os animais -----------------------------------------------------

const animais = computed(() => opcoes.value?.animais ?? [])

/** Quem já está autorizado neste prestador, por código do animal. */
const jaAutorizados = computed(() => {
  const mapa = new Map()

  for (const item of prestador.value?.autorizados ?? []) mapa.set(item.codigo, item.expira_em)

  return mapa
})

const selecionaveis = computed(() =>
  animais.value.filter((animal) => !jaAutorizados.value.has(animal.codigo)),
)

function alternarAnimal(codigo) {
  const marcados = animaisEscolhidos.value

  animaisEscolhidos.value = marcados.includes(codigo)
    ? marcados.filter((item) => item !== codigo)
    : [...marcados, codigo]

  erroDaEscolha.value = ''
}

const nomesEscolhidos = computed(() =>
  enumerarNomes(
    animais.value
      .filter((animal) => animaisEscolhidos.value.includes(animal.codigo))
      .map((animal) => animal.nome),
  ),
)

const prazoEmDias = computed(() => opcoes.value?.prazo_em_dias ?? 90)

// Passo 3 — o código -------------------------------------------------------

const emailVerificado = computed(() => opcoes.value?.tutor.email_verificado ?? false)
const email = computed(() => confirmacao.value?.email ?? opcoes.value?.tutor.email ?? '')

const nomesDaConfirmacao = computed(() =>
  enumerarNomes((confirmacao.value?.animais ?? []).map((animal) => animal.nome)),
)

/**
 * De quem se está falando no passo 3. Sai da confirmação quando ela existe; da
 * marcação do passo 2 quando o fluxo parou antes dela — a pausa por tentativas
 * descoberta no pedido do código é esse caso, e "a pausa protege o histórico"
 * sem dizer de quem seria aviso sobre coisa nenhuma.
 */
const nomesEmJogo = computed(() => nomesDaConfirmacao.value || nomesEscolhidos.value)

const bloqueada = computed(() => situacao.value === 'bloqueada')
const expirado = computed(() => situacao.value === 'codigo_expirado')
const incorreto = computed(() => situacao.value === 'codigo_incorreto')

const podeConceder = computed(
  () => codigo.value.length === 6 && !enviando.value && !bloqueada.value && !expirado.value,
)

/**
 * Passo 2 → 3. O pedido do código é o que separa a escolha do consentimento:
 * até aqui nada foi autorizado, e é isto que a requisição faz — abrir a
 * confirmação e mandar o código ao endereço do tutor.
 */
async function pedirCodigo() {
  // A pausa por tentativas vale para o tutor, e não só para a confirmação que a
  // abriu: pedir código novo durante ela seria contorná-la. O servidor recusa
  // de todo modo — o que a tela evita é o percurso inútil até a recusa.
  if (bloqueada.value) return

  if (animaisEscolhidos.value.length === 0) {
    erroDaEscolha.value = 'Escolha ao menos um animal para autorizar.'

    return
  }

  guardarRascunho()

  // RF37b — sem endereço confirmado o fluxo para aqui, e o passo 3 explica o
  // que fazer em vez de pedir um código que não teria para onde ir.
  if (!emailVerificado.value) {
    situacao.value = 'email_nao_verificado'
    passo.value = 3

    return
  }

  enviando.value = true
  erroDaEscolha.value = ''

  try {
    const resposta = await apiPost('/api/autorizacoes/confirmacoes', {
      prestador: prestadorEscolhido.value,
      animais: animaisEscolhidos.value,
    })

    entrarNaConfirmacao(resposta.confirmacao)
  } catch (excecao) {
    tratarFalhaDoPedido(excecao)
  } finally {
    enviando.value = false
  }
}

function entrarNaConfirmacao(resumo) {
  confirmacao.value = resumo
  codigo.value = ''
  situacao.value = null
  mensagem.value = ''
  tentativasRestantes.value = resumo.tentativas_restantes
  segundos.value = resumo.segundos_restantes
  passo.value = 3
  iniciarRelogio()
}

function tratarFalhaDoPedido(excecao) {
  const situacaoDoServidor = excecao.data?.situacao

  if (situacaoDoServidor === 'email_nao_verificado') {
    situacao.value = 'email_nao_verificado'
    passo.value = 3

    return
  }

  if (situacaoDoServidor === 'bloqueada' || situacaoDoServidor === 'muitos_pedidos') {
    situacao.value = situacaoDoServidor
    mensagem.value = excecao.message
    segundosDeBloqueio.value = excecao.data?.segundos_restantes ?? 0
    passo.value = 3
    iniciarRelogio()

    return
  }

  // O que sobra é recusa da escolha — animal que já está autorizado ali, ou
  // que não é do tutor. A mensagem volta para o passo 2, onde a escolha está.
  erroDaEscolha.value = excecao.errors?.animais?.[0] ?? excecao.message
}

/** O ato. Daqui sai autorização — uma por animal marcado (RN37). */
async function conceder() {
  if (!podeConceder.value) return

  enviando.value = true

  try {
    const resposta = await apiPost(
      `/api/autorizacoes/confirmacoes/${confirmacao.value.id}`,
      { codigo: codigo.value },
    )

    concessao.value = resposta.concessao
    passo.value = 4
    clearInterval(relogio)
    esquecerRascunho()
  } catch (excecao) {
    tratarFalhaDaConfirmacao(excecao)
  } finally {
    enviando.value = false
  }
}

function tratarFalhaDaConfirmacao(excecao) {
  const situacaoDoServidor = excecao.data?.situacao

  situacao.value = situacaoDoServidor ?? 'codigo_incorreto'
  mensagem.value = excecao.message
  codigo.value = ''

  if (situacaoDoServidor === 'codigo_incorreto') {
    tentativasRestantes.value = excecao.data?.tentativas_restantes ?? 0
  }

  if (situacaoDoServidor === 'bloqueada') {
    segundosDeBloqueio.value = excecao.data?.segundos_restantes ?? 0
    segundos.value = 0
  }

  if (situacaoDoServidor === 'codigo_expirado') segundos.value = 0
}

async function reenviarCodigo() {
  enviando.value = true

  try {
    const resposta = await apiPost(
      `/api/autorizacoes/confirmacoes/${confirmacao.value.id}/reenviar`,
    )

    entrarNaConfirmacao(resposta.confirmacao)
  } catch (excecao) {
    situacao.value = excecao.data?.situacao ?? 'codigo_expirado'
    mensagem.value = excecao.message

    if (excecao.data?.segundos_restantes !== undefined) {
      if (situacao.value === 'bloqueada') {
        segundosDeBloqueio.value = excecao.data.segundos_restantes
      } else {
        segundos.value = excecao.data.segundos_restantes
        situacao.value = null
      }
    }
  } finally {
    enviando.value = false
  }
}

/** RF05 — a ação que o estado de e-mail não confirmado oferece. */
async function reenviarConfirmacaoDeEmail() {
  enviando.value = true

  try {
    await apiPost('/api/email/reenviar', { email: opcoes.value.tutor.email })
    confirmacaoReenviada.value = true
  } catch (excecao) {
    mensagem.value = excecao.message
  } finally {
    enviando.value = false
  }
}

// Navegação ----------------------------------------------------------------

function voltar() {
  if (passo.value === 1) {
    router.push('/prestadores')

    return
  }

  if (passo.value === 3) {
    // Voltar do passo 3 abandona o código enviado: o próximo avanço abre uma
    // confirmação nova, e a anterior vence. É o que evita dois códigos válidos
    // ao mesmo tempo.
    confirmacao.value = null
    situacao.value = bloqueada.value ? 'bloqueada' : null
    codigo.value = ''
  }

  passo.value--
}

const rotuloDoPasso = computed(
  () => ['estabelecimento', 'animais', 'confirmar'][Math.min(passo.value, 3) - 1],
)

function iconeDaEspecie(especie) {
  return especie === 'gato' ? Cat : Dog
}
</script>

<template>
  <!--
    `amplo` para que a coluna de 560 px se centre na área de conteúdo inteira:
    ela já era centrada, mas dentro do limite de 880 px da moldura, que é
    alinhado à esquerda — o que a deixava fora do centro em telas largas.
  -->
  <TutorShell amplo>
    <div class="concessao">
      <!-- Carregando: a moldura do fluxo já em pé, para que o passo 1 não
           chegue empurrando a tela. -->
      <div v-if="carregando" class="cartao" aria-busy="true" aria-live="polite">
        <div class="cartao__topo">
          <span class="cartao__titulo">Conceder autorização</span>
          <KeyRound :size="20" :stroke-width="1.75" />
        </div>
        <div class="cartao__corpo">
          <span class="visually-hidden">Carregando as opções de autorização.</span>
          <div class="esqueleto esqueleto--titulo" />
          <div class="esqueleto esqueleto--cartao" />
          <div class="esqueleto esqueleto--cartao" />
        </div>
      </div>

      <div v-else-if="erroAoCarregar" class="aviso aviso--erro" role="alert">
        <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
        <div>
          <p class="aviso__titulo">Não conseguimos abrir a autorização.</p>
          <p class="aviso__texto">{{ erroAoCarregar }}</p>
          <button type="button" class="botao botao--secundario" @click="carregar">
            Tentar novamente
          </button>
        </div>
      </div>

      <div v-else class="cartao">
        <div class="cartao__topo">
          <button
            v-if="passo < 4"
            type="button"
            class="cartao__voltar"
            aria-label="Voltar"
            @click="voltar"
          >
            <ChevronLeft :size="24" :stroke-width="1.75" />
          </button>
          <span class="cartao__titulo">Conceder autorização</span>
          <KeyRound :size="20" :stroke-width="1.75" />
        </div>

        <StepIndicator
          v-if="passo < 4"
          :current="passo"
          :total="3"
          :label="rotuloDoPasso"
          tom="consentimento"
        />

        <!-- Passo 1 — o prestador ------------------------------------------ -->
        <div v-if="passo === 1" class="cartao__corpo">
          <div v-if="bloqueada" class="aviso aviso--erro" role="alert">
            <Lock :size="20" :stroke-width="1.75" class="aviso__icone" />
            <div>
              <p class="aviso__titulo">Autorizações pausadas por segurança</p>
              <p class="aviso__texto">
                Houve códigos errados demais numa autorização sua. Nada foi autorizado, e você pode
                tentar de novo em {{ contadorDoBloqueio }}.
              </p>
            </div>
          </div>

          <div>
            <p class="sobrelinha">Escolher o estabelecimento</p>
            <h1 class="titulo">Quem vai atender?</h1>
            <p v-if="route.query.prestador" class="apoio">
              Você veio do diretório, então já deixamos a clínica escolhida.
            </p>
            <p v-else class="apoio">
              Estes são os estabelecimentos que você já conhece. Para outro, procure no diretório.
            </p>
          </div>

          <EmptyState
            v-if="prestadores.length === 0"
            :icone="Building2"
            titulo="Você ainda não escolheu um estabelecimento"
            descricao="A autorização é sempre para um estabelecimento determinado. Procure no diretório quem vai atender seu animal."
          >
            <RouterLink to="/prestadores" class="botao botao--consentimento">
              Procurar no diretório
            </RouterLink>
          </EmptyState>

          <template v-else>
            <label
              v-for="item in prestadores"
              :key="item.id"
              :for="`prestador-${item.id}`"
              class="opcao"
              :class="{ 'opcao--marcada': item.id === prestadorEscolhido }"
            >
              <input
                :id="`prestador-${item.id}`"
                type="radio"
                name="prestador"
                class="opcao__controle"
                :checked="item.id === prestadorEscolhido"
                @change="escolherPrestador(item.id)"
              >
              <span class="opcao__texto">
                <span class="opcao__nome">{{ item.nome }}</span>
                <span class="opcao__meta">
                  {{ item.tipo_rotulo }} · {{ item.municipio }}, {{ item.uf }}
                </span>
              </span>
              <component
                :is="item.tipo === 'autonomo' ? UserRoundCheck : Building2"
                :size="20"
                :stroke-width="1.75"
                class="opcao__icone"
              />
            </label>

            <RouterLink to="/prestadores" class="botao botao--secundario">
              Procurar outra clínica
            </RouterLink>
            <button
              type="button"
              class="botao botao--consentimento"
              :disabled="!prestadorEscolhido || bloqueada"
              @click="passo = 2"
            >
              Escolher os animais
            </button>
          </template>
        </div>

        <!-- Passo 2 — os animais ------------------------------------------- -->
        <div v-else-if="passo === 2" class="cartao__corpo">
          <div v-if="bloqueada" class="aviso aviso--erro" role="alert">
            <Lock :size="20" :stroke-width="1.75" class="aviso__icone" />
            <div>
              <p class="aviso__titulo">Autorizações pausadas por segurança</p>
              <p class="aviso__texto">
                Houve códigos errados demais numa autorização sua. Nada foi autorizado, e você pode
                tentar de novo em {{ contadorDoBloqueio }}.
              </p>
            </div>
          </div>

          <div>
            <p class="sobrelinha">Escolher os animais</p>
            <h1 class="titulo">Quais animais {{ prestador?.nome }} vai ver?</h1>
            <!-- RF36a — a marcação é uma decisão por animal. Não existe
                 "marcar todos", e a frase diz por quê. -->
            <p class="apoio">
              Marque um por um. Não existe "marcar todos": cada animal é uma decisão sua.
            </p>
          </div>

          <EmptyState
            v-if="animais.length === 0"
            :icone="Dog"
            titulo="Você ainda não tem animais cadastrados"
            descricao="A autorização é sempre sobre um animal determinado. Cadastre o seu para poder autorizar o acesso ao histórico dele."
          >
            <RouterLink to="/animais" class="botao botao--consentimento">
              Ver meus animais
            </RouterLink>
          </EmptyState>

          <template v-else>
            <template v-for="animal in animais" :key="animal.codigo">
              <!-- Já autorizado neste estabelecimento: fica à vista, com o
                   prazo, e fora da marcação — autorizar de novo criaria duas
                   linhas vigentes, e revogar uma não encerraria o acesso. -->
              <div v-if="jaAutorizados.has(animal.codigo)" class="opcao opcao--fora">
                <span class="opcao__avatar">
                  <component :is="iconeDaEspecie(animal.especie)" :size="24" :stroke-width="1.75" />
                </span>
                <span class="opcao__texto">
                  <span class="opcao__nome">{{ animal.nome }}</span>
                  <span class="opcao__meta">{{ descreverAnimal(animal) }}</span>
                </span>
                <span class="etiqueta">
                  <KeyRound :size="14" :stroke-width="1.75" />
                  Autorizado até {{ emNumeros(jaAutorizados.get(animal.codigo)) }}
                </span>
              </div>

              <label
                v-else
                :for="`animal-${animal.codigo}`"
                class="opcao"
                :class="{ 'opcao--marcada': animaisEscolhidos.includes(animal.codigo) }"
              >
                <input
                  :id="`animal-${animal.codigo}`"
                  type="checkbox"
                  class="opcao__controle"
                  :checked="animaisEscolhidos.includes(animal.codigo)"
                  @change="alternarAnimal(animal.codigo)"
                >
                <span class="opcao__avatar">
                  <img v-if="animal.foto_url" :src="animal.foto_url" alt="" class="opcao__foto">
                  <component
                    v-else
                    :is="iconeDaEspecie(animal.especie)"
                    :size="24"
                    :stroke-width="1.75"
                  />
                </span>
                <span class="opcao__texto">
                  <span class="opcao__nome">{{ animal.nome }}</span>
                  <span class="opcao__meta">{{ descreverAnimal(animal) }}</span>
                </span>
              </label>
            </template>

            <!-- RN39 — o prazo é de noventa dias, renováveis. Não é escolha do
                 tutor, e por isso o bloco informa em vez de perguntar. -->
            <div class="prazo">
              <p class="prazo__rotulo">Por quanto tempo</p>
              <p class="prazo__valor">{{ prazoEmDias }} dias, renováveis</p>
              <p class="prazo__texto">
                Terminando o prazo, {{ prestador?.nome }} deixa de ver o histórico até você
                autorizar de novo.
              </p>
            </div>

            <p v-if="erroDaEscolha" class="erro" role="alert">{{ erroDaEscolha }}</p>

            <button
              type="button"
              class="botao botao--consentimento"
              :disabled="animaisEscolhidos.length === 0 || enviando || bloqueada"
              @click="pedirCodigo"
            >
              {{ enviando ? 'Enviando o código…' : 'Continuar para a confirmação' }}
            </button>
          </template>
        </div>

        <!-- Passo 3 — a confirmação ---------------------------------------- -->
        <div v-else-if="passo === 3" class="cartao__corpo">
          <!-- RF37b — endereço não confirmado interrompe o fluxo aqui, com o
               que falta fazer e a promessa de guardar a escolha. -->
          <template v-if="situacao === 'email_nao_verificado'">
            <div>
              <p class="sobrelinha sobrelinha--atencao">Falta um passo antes</p>
              <h1 class="titulo">Confirme seu e-mail para continuar</h1>
            </div>

            <div class="aviso aviso--atencao">
              <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
              <div>
                <p class="aviso__titulo">
                  O código de confirmação vai para <strong>{{ email }}</strong>, e esse endereço
                  ainda não foi confirmado.
                </p>
                <p class="aviso__texto">
                  Enquanto ele não estiver confirmado, ninguém consegue conceder autorização em seu
                  nome — nem você a partir daqui.
                </p>
              </div>
            </div>

            <div class="resumo">
              <p>
                Você está autorizando <strong>{{ prestador?.nome }}</strong> a ver o histórico
                completo de <strong>{{ nomesEscolhidos }}</strong> por
                <strong>{{ prazoEmDias }} dias</strong>.
              </p>
              <p class="resumo__apoio">
                Guardamos esta escolha. Quando você confirmar o e-mail, volta direto para cá.
              </p>
            </div>

            <p v-if="confirmacaoReenviada" class="sucesso" role="status">
              Enviamos a mensagem de confirmação para {{ email }}.
            </p>

            <button
              type="button"
              class="botao botao--consentimento"
              :disabled="enviando"
              @click="reenviarConfirmacaoDeEmail"
            >
              Enviar e-mail de confirmação
            </button>
            <RouterLink to="/conta" class="botao botao--secundario">
              Corrigir meu endereço de e-mail
            </RouterLink>
          </template>

          <!-- Pausa por tentativas: o passo perde o campo, porque não há o que
               digitar enquanto ela corre. -->
          <template v-else-if="bloqueada">
            <div class="bloqueio">
              <div class="bloqueio__cabecalho">
                <Lock :size="20" :stroke-width="1.75" class="bloqueio__icone" />
                <div>
                  <p class="bloqueio__titulo">Bloqueamos esta autorização por 30 minutos</p>
                  <p class="bloqueio__texto">
                    Foram cinco códigos errados seguidos. A pausa protege o histórico
                    <template v-if="nomesEmJogo">de {{ nomesEmJogo }} </template>
                    caso alguém esteja tentando autorizar sem ser você.
                  </p>
                  <p class="bloqueio__apoio">
                    Nada foi autorizado. Enviamos um aviso para o seu e-mail com a data e a hora das
                    tentativas.
                  </p>
                  <p v-if="segundosDeBloqueio > 0" class="bloqueio__contador">
                    Você pode tentar de novo em {{ contadorDoBloqueio }}.
                  </p>
                </div>
              </div>
              <RouterLink to="/acessos" class="botao botao--secundario">
                Ver quem acessou meus dados
              </RouterLink>
            </div>
          </template>

          <template v-else-if="situacao === 'muitos_pedidos'">
            <div class="aviso aviso--atencao" role="alert">
              <ClockAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
              <div>
                <p class="aviso__titulo">{{ mensagem }}</p>
                <p v-if="segundosDeBloqueio > 0" class="aviso__texto">
                  Tente de novo em {{ contadorDoBloqueio }}.
                </p>
              </div>
            </div>
            <button type="button" class="botao botao--secundario" @click="passo = 2">
              Voltar ao passo anterior
            </button>
          </template>

          <template v-else>
            <div>
              <p class="sobrelinha">Confirmar</p>
              <h1 class="titulo">Confirme com o código</h1>
            </div>

            <!-- O resumo em texto corrido, antes do campo: é a última chance de
                 o tutor ler exatamente a que está consentindo. -->
            <div class="resumo">
              <p>
                Você está autorizando <strong>{{ confirmacao.prestador.nome }}</strong> a ver o
                histórico completo de <strong>{{ nomesDaConfirmacao }}</strong> por
                <strong>{{ confirmacao.prazo_em_dias }} dias</strong>, até
                {{ emNumeros(confirmacao.expira_em) }}. Você pode revogar a qualquer momento.
              </p>
            </div>

            <ConsentNotice :icone="KeyRound">
              <p>
                {{ confirmacao.prestador.nome }} passa a ver as vacinas, os atendimentos e os
                anexos de {{ nomesDaConfirmacao }}, inclusive o que foi registrado por outros
                prestadores.
              </p>
              <p>
                Cada consulta que ela fizer fica registrada e aparece para você em "Quem acessou
                meus dados".
              </p>
              <p>Você pode revogar quando quiser, sem justificar.</p>
            </ConsentNotice>

            <div class="codigo" :class="{ 'codigo--invalido': incorreto }">
              <label for="codigo-autorizacao" class="codigo__rotulo">Código de confirmação</label>
              <p class="codigo__texto">
                Enviamos um código de seis números para <strong>{{ email }}</strong>. Abra o seu
                e-mail e digite o código aqui.
              </p>

              <OtpInput
                id="codigo-autorizacao"
                v-model="codigo"
                rotulo="Código de confirmação de seis números"
                :invalido="incorreto"
                :desabilitado="expirado || enviando"
                @completo="conceder"
              />

              <p v-if="incorreto" class="codigo__erro" role="alert">
                <TriangleAlert :size="20" :stroke-width="1.75" />
                <span>
                  Este código não confere. Confira o e-mail mais recente e digite de novo.
                  <template v-if="tentativasRestantes > 0">
                    Você tem {{ tentativasRestantes }}
                    {{ tentativasRestantes === 1 ? 'tentativa' : 'tentativas' }}.
                  </template>
                </span>
              </p>

              <p v-if="expirado" class="codigo__expirado" role="alert">
                <ClockAlert :size="20" :stroke-width="1.75" />
                <span>
                  O prazo deste código terminou. Peça um novo para concluir a autorização
                  <template v-if="nomesEmJogo">de {{ nomesEmJogo }}</template>.
                </span>
              </p>

              <button
                v-if="expirado"
                type="button"
                class="botao botao--consentimento codigo__reenviar"
                :disabled="enviando"
                @click="reenviarCodigo"
              >
                Enviar novo código
              </button>

              <div v-else class="codigo__prazo">
                <span class="codigo__contador">Válido por {{ contador }}</span>
                <!-- O reenvio fica fechado enquanto o código atual vale: emitir
                     outro mataria o que já está na caixa de entrada do tutor. -->
                <span class="codigo__reenvio-fechado">Reenviar código</span>
              </div>
              <p v-if="!expirado" class="codigo__nota">O reenvio libera quando o tempo terminar.</p>
            </div>

            <button
              type="button"
              class="botao botao--consentimento"
              :disabled="!podeConceder"
              @click="conceder"
            >
              <KeyRound :size="20" :stroke-width="1.75" />
              {{ enviando ? 'Concedendo…' : 'Conceder autorização' }}
            </button>
            <button type="button" class="botao botao--secundario" @click="voltar">
              Voltar ao passo anterior
            </button>

            <!-- RF37c dito em voz alta: é a frase que protege o tutor do
                 atendimento que conduz a concessão do balcão. -->
            <p class="apoio">
              O código chega apenas no seu e-mail. Ninguém do balcão da clínica pode vê-lo, nem o
              Imunia mostra em tela.
            </p>
          </template>
        </div>

        <!-- Êxito — o fecho do fluxo, com o caminho para T12, onde a
             autorização recém-concedida chega destacada ------------------- -->
        <div v-else class="cartao__corpo">
          <div class="concedida">
            <span class="concedida__selo">
              <KeyRound :size="24" :stroke-width="1.75" />
            </span>
            <p class="sobrelinha">Autorização concedida</p>
            <h1 class="titulo">Pronto. {{ concessao.prestador.nome }} já pode ver o histórico.</h1>
          </div>

          <div class="resumo">
            <p>
              <strong>{{ concessao.prestador.nome }}</strong> vê o histórico completo de
              <strong>{{ enumerarNomes(concessao.animais.map((animal) => animal.nome)) }}</strong>
              até {{ emNumeros(concessao.expira_em) }}.
            </p>
            <p class="resumo__apoio">
              Você pode revogar quando quiser, sem justificar, e ver em "Quem acessou meus dados"
              cada consulta que a clínica fizer.
            </p>
          </div>

          <RouterLink
            :to="{ path: '/autorizacoes', query: { prestador: concessao.prestador.id } }"
            class="botao botao--consentimento"
          >
            Ver esta autorização
          </RouterLink>
          <RouterLink to="/prestadores" class="botao botao--secundario">
            Autorizar outro estabelecimento
          </RouterLink>
        </div>
      </div>
    </div>
  </TutorShell>
</template>

<style scoped>
/* Coluna de 560 px (§6.1). O fluxo inteiro cabe numa leitura só, e a largura
   curta é parte do desenho: esta não é tela de percorrer, é de ler. */
.concessao {
  width: 100%;
  max-width: 560px;
  margin: 0 auto;
}

.cartao {
  display: flex;
  flex-direction: column;
  background: var(--surface-card);
  border: 1px solid var(--consent);
  border-radius: var(--radius-md);
  overflow: hidden;
}

.cartao__topo {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  height: 56px;
  padding: 0 var(--space-4);
  background: var(--consent);
  color: var(--surface-card);
}

.cartao__voltar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  margin-left: calc(var(--space-3) * -1);
  background: none;
  border: none;
  color: inherit;
  cursor: pointer;
}

.cartao__titulo {
  flex: 1;
  min-width: 0;
  font-size: 16px;
  line-height: 24px;
  font-weight: 600;
}

.cartao__corpo {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  padding: var(--space-4);
}

.sobrelinha {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--consent);
}

.sobrelinha--atencao {
  color: var(--status-due-text);
}

.titulo {
  margin: var(--space-1) 0 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  color: var(--ink);
}

.apoio {
  margin: var(--space-1) 0 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
}

/* Opções — prestador e animal usam a mesma forma, porque são a mesma decisão
   em dois objetos: o cartão inteiro é o alvo, e a marcação pinta a borda de
   índigo. */

.opcao {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-md);
  cursor: pointer;
}

.opcao--marcada {
  background: var(--consent-wash);
  border-color: var(--consent);
}

.opcao--fora {
  flex-wrap: wrap;
  background: var(--surface-sunken);
  border-style: dashed;
  cursor: default;
}

.opcao__controle {
  flex: none;
  width: 24px;
  height: 24px;
  margin: 0;
  accent-color: var(--consent);
  cursor: pointer;
}

.opcao__avatar {
  display: flex;
  align-items: center;
  justify-content: center;
  flex: none;
  width: 56px;
  height: 56px;
  overflow: hidden;
  border-radius: var(--radius-pill);
  background: var(--surface-sunken);
  color: var(--ink-muted);
}

.opcao__foto {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.opcao__texto {
  flex: 1;
  min-width: 0;
}

.opcao__nome {
  display: block;
  font-size: 18px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.opcao__meta {
  display: block;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
}

.opcao__icone {
  flex: none;
  color: var(--consent);
}

.etiqueta {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  min-height: 22px;
  padding: 3px 10px;
  border-radius: var(--radius-pill);
  background: var(--consent-wash);
  color: var(--consent);
  font-size: 12px;
  line-height: 16px;
  font-weight: 600;
}

/* Prazo, resumo e consentimento ------------------------------------------- */

.prazo {
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

.prazo__rotulo {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

.prazo__valor {
  margin: var(--space-2) 0 0;
  font-size: 18px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.prazo__texto {
  margin: var(--space-2) 0 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
}

.resumo {
  padding: var(--space-4);
  background: var(--consent-wash);
  border: 1px solid var(--consent);
  border-radius: var(--radius-md);
}

.resumo p {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);
}

.resumo__apoio {
  margin: var(--space-2) 0 0 !important;
  color: var(--ink-muted) !important;
}

.resumo strong {
  font-weight: 600;
}

/* Campo do código ---------------------------------------------------------- */

.codigo {
  padding: var(--space-4);
  background: var(--consent-wash);
  border: 1px solid var(--consent);
  border-radius: var(--radius-md);
}

.codigo--invalido {
  border-color: var(--status-late);
}

.codigo__rotulo {
  display: block;
  margin: 0 0 var(--space-1);
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--consent);
}

.codigo__texto {
  margin: 0 0 var(--space-3);
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);
}

.codigo__texto strong {
  font-weight: 600;
}

.codigo__erro,
.codigo__expirado {
  display: flex;
  align-items: flex-start;
  gap: var(--space-2);
  margin: var(--space-3) 0 0;
  font-size: 16px;
  line-height: 24px;
}

.codigo__erro {
  color: var(--status-late);
}

.codigo__expirado {
  color: var(--ink);
}

.codigo__expirado svg {
  flex: none;
  color: var(--status-due-text);
}

.codigo__erro svg {
  flex: none;
}

.codigo__reenviar {
  width: 100%;
  margin: var(--space-3) 0 0;
}

.codigo__prazo {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
  margin: var(--space-3) 0 0;
}

.codigo__contador {
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);
  font-variant-numeric: tabular-nums;
}

.codigo__reenvio-fechado {
  font-size: 16px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink-faint);
}

.codigo__nota {
  margin: var(--space-2) 0 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
}

/* Bloqueio ---------------------------------------------------------------- */

.bloqueio {
  padding: var(--space-4);
  background: var(--status-late-wash);
  border: 1px solid var(--status-late);
  border-radius: var(--radius-md);
}

.bloqueio__cabecalho {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
}

.bloqueio__icone {
  flex: none;
  margin-top: 2px;
  color: var(--status-late);
}

.bloqueio__titulo {
  margin: 0;
  font-size: 18px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.bloqueio__texto,
.bloqueio__apoio,
.bloqueio__contador {
  margin: var(--space-2) 0 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);
}

.bloqueio__apoio {
  color: var(--ink-muted);
}

.bloqueio__contador {
  font-variant-numeric: tabular-nums;
}

.bloqueio .botao {
  width: 100%;
  margin: var(--space-3) 0 0;
}

/* Êxito ------------------------------------------------------------------- */

.concedida {
  text-align: center;
}

.concedida__selo {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 56px;
  height: 56px;
  margin: 0 0 var(--space-3);
  border-radius: var(--radius-pill);
  background: var(--consent-wash);
  color: var(--consent);
}

/* Avisos e botões — mesmo vocabulário de T02 e T10. ------------------------ */

.aviso {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  padding: var(--space-4);
  border-radius: var(--radius-md);
}

.aviso__icone {
  flex: none;
  margin-top: 2px;
}

.aviso--erro {
  background: var(--status-late-wash);
  border: 1px solid var(--status-late);
}

.aviso--erro .aviso__icone {
  color: var(--status-late);
}

.aviso--atencao {
  background: var(--surface-card);
  border: 1px solid var(--status-due);
}

.aviso--atencao .aviso__icone {
  color: var(--status-due-text);
}

.aviso__titulo {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.aviso__texto {
  margin: var(--space-1) 0 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
}

.aviso--erro .botao {
  width: auto;
  margin: var(--space-4) 0 0;
}

.erro {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--status-late);
}

.sucesso {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--brand);
}

.botao {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-2);
  height: 48px;
  padding: 0 var(--space-6);
  border: 1px solid transparent;
  border-radius: var(--radius-sm);
  font-family: var(--font-body);
  font-size: 16px;
  font-weight: 600;
  cursor: pointer;
}

.botao--consentimento {
  background: var(--consent);
  border-color: var(--consent);
  color: var(--surface-card);
}

.botao--consentimento:hover:not(:disabled) {
  background: #32447C;
  border-color: #32447C;
  color: var(--surface-card);
}

.botao--secundario {
  background: var(--surface-card);
  border-color: var(--border-strong);
  color: var(--ink);
}

.botao--secundario:hover {
  background: var(--surface-sunken);
}

.botao:disabled {
  opacity: .55;
  cursor: not-allowed;
}

/* Esqueleto de carregamento ------------------------------------------------ */

.esqueleto {
  border-radius: var(--radius-xs);
  background: var(--surface-sunken);
  animation: pulsar 1.2s ease-in-out infinite;
}

.esqueleto--titulo {
  height: 34px;
  width: 70%;
}

.esqueleto--cartao {
  height: 88px;
  border-radius: var(--radius-md);
}

@keyframes pulsar {
  0% { opacity: .55; }
  50% { opacity: 1; }
  100% { opacity: .55; }
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
  .titulo {
    font-size: 36px;
    line-height: 40px;
    letter-spacing: -.02em;
  }

  .cartao__corpo {
    padding: var(--space-6);
  }
}
</style>
