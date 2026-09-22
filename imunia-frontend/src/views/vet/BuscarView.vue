<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import {
  Cat,
  CircleHelp,
  Dog,
  Eye,
  Hourglass,
  KeyRound,
  PawPrint,
  QrCode,
  TriangleAlert,
} from '@lucide/vue'
import VetShell from '@/components/vet/VetShell.vue'
import StatusPill from '@/components/base/StatusPill.vue'
import EmptyState from '@/components/base/EmptyState.vue'
import SolicitarAutorizacaoModal from '@/components/vet/SolicitarAutorizacaoModal.vue'
import { apiGet } from '@/lib/api.js'
import { descreverAnimal, descreverEspecie } from '@/lib/animais.js'
import { emNumeros } from '@/lib/datas.js'
import {
  CODIGO,
  CPF,
  MICROCHIP,
  classificarTermo,
  termoConsultavel,
  termoParaExibicao,
} from '@/lib/busca.js'

/**
 * V03 — buscar animal ou tutor (RF51, RF18, RF13).
 *
 * A tela de maior risco do sistema, e a única cujo desenho existe para *não*
 * mostrar. O resultado sem autorização é estado normal e frequente, desenhado
 * para parecer acabado e intencional: nada de nome, contato, iniciais, máscara
 * parcial ou contagem de animais (RN12). Só a existência do cadastro e um
 * caminho — pedir a autorização a quem pode dá-la.
 *
 * Daí as duas seções terem desenhos deliberadamente diferentes: a primeira traz
 * cartões completos sobre fundo claro; a segunda, um cartão em `--surface-sunken`
 * com borda tracejada em `--consent`, a cor que neste sistema significa sempre a
 * mesma coisa — quem pode ver o quê. O profissional aprende a diferença sem que
 * ninguém lhe explique.
 */
const termo = ref('')
const consulta = ref(null)
const carregando = ref(true)
const buscando = ref(false)
const erro = ref('')
/** Erro do próprio termo (CPF com dígito inválido), que nem chega a consultar. */
const erroDoTermo = ref('')

const campoDoTopo = ref(null)
const campoDoConteudo = ref(null)
const resultados = ref(null)

const estado = computed(() => consulta.value?.estado ?? 'inicial')
const inicial = computed(() => estado.value === 'inicial')
const autorizados = computed(() => consulta.value?.autorizados ?? [])
const existencia = computed(() => consulta.value?.existencia ?? null)
const prestador = computed(() => consulta.value?.prestador?.nome ?? 'este prestador')

/** O tipo detectado do que está sendo digitado — o rótulo ao lado do campo. */
const tipoDigitado = computed(() => classificarTermo(termo.value))

/** O tipo do que o servidor respondeu, que é o que o resultado descreve. */
const tipoRespondido = computed(() => consulta.value?.tipo ?? null)

const termoRespondido = computed(() =>
  consulta.value?.termo ? termoParaExibicao(consulta.value.termo) : '',
)

/**
 * Só a busca por CPF exibe a seção "sob sua autorização" vazia: ali o vazio é
 * uma afirmação sobre um tutor determinado — "nenhum animal *deste tutor*" —,
 * e é informação. Para as demais chaves, seção vazia não diria nada que a
 * segunda seção já não diga.
 */
const mostrarSecaoAutorizada = computed(
  () => autorizados.value.length > 0 || tipoRespondido.value === CPF,
)

const CABECALHOS = {
  [CPF]: 'Resultados para o CPF',
  [CODIGO]: 'Resultados para',
  [MICROCHIP]: 'Resultados para o micro-chip',
}

const VAZIOS = {
  [CPF]: {
    titulo: 'Nenhum cadastro corresponde a este CPF',
    descricao:
      'Confira o número com o tutor. Se ele ainda não está no Imunia, cadastre-o agora — o animal vem em seguida.',
  },
  [CODIGO]: {
    titulo: 'Nenhum cadastro corresponde a este código',
    descricao:
      'Confira os caracteres com o tutor — o código tem o formato IM-0000-0000. Se o animal ainda não está no Imunia, cadastre-o agora.',
  },
  [MICROCHIP]: {
    titulo: 'Nenhum cadastro corresponde a este micro-chip',
    descricao:
      'Confira o número no leitor. Se o animal ainda não está no Imunia, cadastre-o agora — o micro-chip entra na caracterização.',
  },
}

const vazio = computed(
  () =>
    VAZIOS[tipoRespondido.value] ?? {
      titulo: `Nenhum cadastro corresponde a “${termoRespondido.value}”`,
      descricao:
        'Confira a grafia, ou procure pelo CPF do tutor, pelo código do animal ou pelo micro-chip.',
    },
)

/**
 * O que o cartão da segunda seção diz, por tipo de correspondência.
 *
 * O nome do prestador entra sem artigo — "autorizar Clínica Vet Amigo" —, como
 * já faz V02. O desenho o traz com artigo porque supõe um nome feminino, e o
 * mesmo profissional tem vínculo com "Hospital Veterinário Central", onde a
 * frase sairia errada.
 */
const CONSENTIMENTO = {
  tutor: {
    titulo: 'Existe um cadastro com este CPF.',
    // RF13a e RF13b — é tudo. Nome, contato e relação de animais permanecem
    // ocultos até a concessão, e a frase precisa dizer isso sem rodeios, para
    // que a ausência não seja lida como falha da tela.
    detalhe: (nome) =>
      `É tudo o que podemos mostrar sem autorização do tutor. Nome, contato e animais aparecem somente depois que ele autorizar ${nome}.`,
    registro: (nome) =>
      `Esta consulta ficou registrada. O tutor verá que ${nome} pesquisou por este CPF, com data e hora.`,
  },
  animal: {
    titulo: 'Existe histórico disponível mediante autorização do tutor.',
    detalhe: (nome) =>
      `Vacinas, atendimentos, anexos e os dados do tutor aparecem somente depois que ele autorizar ${nome}.`,
    registro: (nome) =>
      `Esta consulta ficou registrada. O tutor verá que ${nome} pesquisou por este código, com data e hora.`,
  },
  outro: {
    titulo: 'Existe outro cadastro que corresponde a esta busca.',
    detalhe: () => 'Sem autorização do tutor, não mostramos de quem é nem quantos animais tem.',
    registro: () => 'Esta consulta ficou registrada.',
  },
}

const consentimento = computed(() => CONSENTIMENTO[existencia.value?.tipo] ?? null)

/** V10 — o modal do pedido, aberto sobre esta tela. */
const solicitando = ref(false)

/** O desfecho do pedido feito nesta visita, que vira a etiqueta de espera. */
const solicitacao = ref(null)

/**
 * O que o modal vai pedir. Pelo cartão coletivo ("outro") não há a quem pedir
 * — a correspondência é um conjunto indeterminado de titulares —, e é por isso
 * que ele não devolve alvo: o caminho ali é refinar a busca para uma chave
 * exata.
 */
const alvoDaSolicitacao = computed(() => {
  const termo = consulta.value?.termo ?? ''

  if (existencia.value?.tipo === 'tutor') return { tipo: 'cpf', termo }

  if (existencia.value?.tipo === 'animal') {
    return {
      tipo: 'animal',
      termo,
      nome: existencia.value.nome,
      especie: existencia.value.especie,
    }
  }

  return null
})

/**
 * A pendência que troca o botão pela etiqueta: a que a busca já trouxe, ou a
 * que acabou de nascer no modal.
 */
const solicitacaoPendente = computed(
  () => solicitacao.value ?? existencia.value?.solicitacao_pendente ?? null,
)

function contar(quantidade) {
  return `${quantidade} ${quantidade === 1 ? 'resultado' : 'resultados'}`
}

function iconeDaEspecie(especie) {
  return especie === 'gato' ? Cat : Dog
}

function parametros(termoDaVez) {
  const busca = new URLSearchParams()

  if (termoDaVez) busca.set('termo', termoDaVez)
  if (consulta.value?.prestador) busca.set('prestador', consulta.value.prestador.id)

  return busca
}

/**
 * Sem termo, a rota devolve só o contexto clínico — prestador ativo e vínculos.
 * É o que a moldura precisa para desenhar a faixa de contexto antes da primeira
 * consulta, e nada nela é consultado nem registrado.
 */
async function carregar(termoDaVez = '') {
  if (termoDaVez) buscando.value = true
  else carregando.value = true

  erro.value = ''

  try {
    consulta.value = await apiGet(`/api/clinica/buscar?${parametros(termoDaVez)}`)
    // O pedido feito era sobre o resultado anterior; o novo traz a própria
    // pendência, quando houver.
    solicitacao.value = null
  } catch (excecao) {
    erro.value = excecao.message
  } finally {
    carregando.value = false
    buscando.value = false
  }
}

function buscar() {
  erroDoTermo.value = ''

  const { tipo, valor } = tipoDigitado.value
  if (valor === '') return

  // RF13 — a conferência do dígito acontece antes da consulta, e por isso a
  // requisição sequer sai. É o que sustenta a frase que a tela exibe nesse
  // estado: nada foi consultado, nada foi registrado.
  if (!termoConsultavel(termo.value)) {
    erroDoTermo.value = tipo === CPF
      ? 'Este CPF não é válido: o dígito verificador não confere. Confira o número com o tutor.'
      : 'Não foi possível reconhecer este termo.'

    return
  }

  carregar(termo.value)
}

function trocarPrestador(id) {
  consulta.value = { ...consulta.value, prestador: { ...consulta.value.prestador, id } }
  carregar(consulta.value.termo ?? '')
}

/**
 * Há dois campos no documento — o do conteúdo e o da faixa do cabeçalho —, e
 * qual deles está à vista depende da largura. Focar pelo primeiro que existe
 * não serve: o oculto continua no DOM, e `focus()` sobre elemento com
 * `display: none` não faz nada, silenciosamente.
 */
async function focarNoCampo() {
  await nextTick()

  const campo = [campoDoConteudo.value, campoDoTopo.value].find((alvo) => alvo?.offsetParent)
  campo?.focus()
  campo?.select()
}

/**
 * O atalho anunciado no rodapé do estado inicial. A moldura já leva `/` até
 * esta rota; estando nela, navegar de novo não faria nada — quem devolve o
 * cursor ao campo é a própria tela.
 */
function aoTeclar(evento) {
  if (evento.key !== '/' || evento.metaKey || evento.ctrlKey || evento.altKey) return

  const alvo = evento.target
  if (alvo instanceof Element && alvo.closest('input, textarea, select, [contenteditable="true"]')) return

  evento.preventDefault()
  focarNoCampo()
}

/**
 * `↑ ↓` percorre os resultados, como o rodapé promete. O percurso começa no
 * campo e segue pelos cartões, que são ligações e por isso já focalizáveis —
 * o que falta é a ordem, e é ela que se dá aqui.
 */
function percorrer(evento) {
  if (evento.key !== 'ArrowDown' && evento.key !== 'ArrowUp') return

  const focalizaveis = Array.from(resultados.value?.querySelectorAll('[data-resultado]') ?? [])
  if (focalizaveis.length === 0) return

  const atual = focalizaveis.indexOf(document.activeElement)
  const proximo = evento.key === 'ArrowDown' ? atual + 1 : atual - 1

  if (proximo < 0) {
    evento.preventDefault()
    focarNoCampo()

    return
  }

  if (proximo >= focalizaveis.length) return

  evento.preventDefault()
  focalizaveis[proximo].focus()
}

onMounted(() => {
  carregar()
  document.addEventListener('keydown', aoTeclar)
})

onBeforeUnmount(() => document.removeEventListener('keydown', aoTeclar))
</script>

<template>
  <VetShell
    titulo="Buscar"
    :prestador="consulta?.prestador"
    :vinculos="consulta?.vinculos ?? []"
    @trocar-prestador="trocarPrestador"
  >
    <!-- §8.3 — o campo é proeminente e centrado no primeiro uso, e desloca-se
         para o topo depois da primeira consulta. Antes dela, a faixa do
         cabeçalho conduz ao campo grande em vez de duplicá-lo. -->
    <template #busca>
      <button v-if="inicial" type="button" class="atalho-do-topo" @click="focarNoCampo">
        <PawPrint :size="16" :stroke-width="1.75" class="atalho-do-topo__icone" />
        <span>Buscar animal, tutor ou código</span>
        <kbd class="atalho-do-topo__tecla">/</kbd>
      </button>

      <form
        v-else
        class="campo-do-topo"
        :class="{ 'campo-do-topo--invalido': erroDoTermo }"
        role="search"
        @submit.prevent="buscar"
      >
        <PawPrint :size="16" :stroke-width="1.75" class="campo-do-topo__icone" />
        <input
          ref="campoDoTopo"
          v-model="termo"
          type="search"
          class="campo-do-topo__entrada"
          :class="{ 'campo-do-topo__entrada--codigo': tipoDigitado.tipo !== 'nome' }"
          aria-label="Buscar animal ou tutor"
          :aria-invalid="Boolean(erroDoTermo)"
          @keydown.down="percorrer"
        >
        <span class="campo-do-topo__tipo">{{ tipoDigitado.rotulo }}</span>
      </form>
    </template>

    <div v-if="carregando" class="busca" aria-busy="true" aria-live="polite">
      <span class="visually-hidden">Abrindo a busca.</span>
      <div class="heroi">
        <div class="esqueleto esqueleto--titulo" />
        <div class="esqueleto esqueleto--campo" />
        <div class="exemplos">
          <div v-for="linha in 4" :key="linha" class="esqueleto esqueleto--exemplo" />
        </div>
      </div>
    </div>

    <div v-else-if="erro" class="busca">
      <div class="aviso aviso--erro" role="alert">
        <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
        <div>
          <p class="aviso__titulo">Não conseguimos concluir a busca.</p>
          <p class="aviso__texto">{{ erro }}</p>
          <button type="button" class="botao botao--secundario" @click="carregar(consulta?.termo ?? '')">
            Tentar novamente
          </button>
        </div>
      </div>
    </div>

    <div v-else class="busca" @keydown="percorrer">
      <!-- Estado inicial: o campo grande, os formatos aceitos e o aviso do que
           acontece quando se procura fora da própria carteira. -->
      <div v-if="inicial" class="heroi">
        <h1 class="heroi__titulo">Buscar animal ou tutor</h1>

        <form class="heroi__campo-linha" role="search" @submit.prevent="buscar">
          <div class="campo" :class="{ 'campo--invalido': erroDoTermo }">
            <PawPrint :size="20" :stroke-width="1.75" class="campo__icone" />
            <input
              ref="campoDoConteudo"
              v-model="termo"
              type="search"
              class="campo__entrada"
              placeholder="Nome, CPF, código do animal ou micro-chip"
              aria-label="Buscar animal ou tutor"
              :aria-invalid="Boolean(erroDoTermo)"
              :aria-describedby="erroDoTermo ? 'erro-do-termo' : undefined"
            >
            <span v-if="termo.trim()" class="campo__tipo">{{ tipoDigitado.rotulo }}</span>
          </div>

          <!-- O leitor de QR é da moldura do celular (§8.3); a leitura em si é
               fatia própria, e até lá a ligação responde por ela. -->
          <RouterLink to="/clinica/buscar/qr" class="leitor-qr" aria-label="Ler QR Code do animal">
            <QrCode :size="24" :stroke-width="1.75" />
          </RouterLink>
        </form>

        <p v-if="erroDoTermo" id="erro-do-termo" class="erro-do-termo" role="alert">
          <TriangleAlert :size="16" :stroke-width="1.75" class="erro-do-termo__icone" />
          {{ erroDoTermo }}
        </p>

        <div v-if="erroDoTermo" class="nada-consultado">
          <p class="nada-consultado__texto">
            Nenhuma consulta foi feita e nada foi registrado: a validação acontece antes de
            qualquer busca, para não gerar registro de acesso a partir de um número digitado
            errado.
          </p>
        </div>

        <dl class="exemplos">
          <div class="exemplo">
            <dt class="exemplo__rotulo">CPF do tutor</dt>
            <dd class="exemplo__forma exemplo__forma--numero">000.000.000-00</dd>
          </div>
          <div class="exemplo">
            <dt class="exemplo__rotulo">Código do animal</dt>
            <dd class="exemplo__forma">IM-0000-0000</dd>
          </div>
          <div class="exemplo">
            <dt class="exemplo__rotulo">Micro-chip</dt>
            <dd class="exemplo__forma exemplo__forma--numero">000000000000000</dd>
          </div>
          <div class="exemplo">
            <dt class="exemplo__rotulo">Nome do animal ou do tutor</dt>
            <dd class="exemplo__forma exemplo__forma--texto">Théo, Helena Ramos</dd>
          </div>
        </dl>

        <!-- RF18b anunciado antes de acontecer: o profissional decide procurar
             sabendo que a procura fora da própria carteira fica registrada. -->
        <div class="aviso-de-registro">
          <Eye :size="20" :stroke-width="1.75" class="aviso-de-registro__icone" />
          <p class="aviso-de-registro__texto">
            Buscas por CPF ou código de animal fora da sua carteira de autorizações ficam
            registradas e visíveis ao tutor. O resultado, nesses casos, confirma apenas que o
            cadastro existe.
          </p>
        </div>

        <p class="atalhos">
          Atalho: pressione <kbd>/</kbd> em qualquer tela para voltar a este campo ·
          <kbd>Enter</kbd> busca · <kbd>↑ ↓</kbd> percorre os resultados
        </p>
      </div>

      <!-- Resultado. Em celular o campo continua aqui, porque a faixa do
           cabeçalho não cabe naquela largura. -->
      <template v-else>
        <form class="campo-linha-estreita" role="search" @submit.prevent="buscar">
          <div class="campo" :class="{ 'campo--invalido': erroDoTermo }">
            <PawPrint :size="20" :stroke-width="1.75" class="campo__icone" />
            <input
              ref="campoDoConteudo"
              v-model="termo"
              type="search"
              class="campo__entrada"
              aria-label="Buscar animal ou tutor"
              :aria-invalid="Boolean(erroDoTermo)"
            >
          </div>
          <RouterLink to="/clinica/buscar/qr" class="leitor-qr" aria-label="Ler QR Code do animal">
            <QrCode :size="24" :stroke-width="1.75" />
          </RouterLink>
        </form>

        <!-- O erro do termo acompanha o campo, e o campo muda de lugar com a
             largura: embaixo dele no celular, logo abaixo da faixa do
             cabeçalho no desktop, que é onde ele está ali. -->
        <p v-if="erroDoTermo" class="erro-do-termo erro-do-termo--bloco" role="alert">
          <TriangleAlert :size="16" :stroke-width="1.75" class="erro-do-termo__icone" />
          {{ erroDoTermo }}
        </p>

        <div v-if="erroDoTermo" class="nada-consultado">
          <p class="nada-consultado__texto">
            Nenhuma consulta foi feita e nada foi registrado: a validação acontece antes de
            qualquer busca, para não gerar registro de acesso a partir de um número digitado
            errado.
          </p>
        </div>

        <div class="busca__cabecalho">
          <div>
            <p class="busca__sobrelinha">Busca</p>
            <h1 class="busca__titulo">
              {{ CABECALHOS[tipoRespondido] ?? 'Resultados para' }}
              <span v-if="CABECALHOS[tipoRespondido]" class="busca__termo">{{ termoRespondido }}</span>
              <span v-else>“{{ termoRespondido }}”</span>
            </h1>
          </div>
          <span v-if="tipoRespondido === CPF" class="busca__deteccao">
            CPF reconhecido automaticamente
          </span>
        </div>

        <div v-if="buscando" class="secao" aria-busy="true" aria-live="polite">
          <span class="visually-hidden">Buscando.</span>
          <div class="cartoes">
            <div v-for="linha in 3" :key="linha" class="esqueleto esqueleto--cartao" />
          </div>
        </div>

        <div v-else ref="resultados" aria-live="polite">
          <div v-if="estado === 'sem_resultado'" class="sem-resultado">
            <EmptyState :icone="PawPrint" :titulo="vazio.titulo" :descricao="vazio.descricao">
              <RouterLink to="/clinica/animais/novo" class="botao botao--primario">
                Cadastrar animal
              </RouterLink>
              <RouterLink to="/clinica/tutores/novo" class="botao botao--secundario">
                Cadastrar tutor
              </RouterLink>
            </EmptyState>
          </div>

          <template v-else>
            <!-- Primeira seção: o que o prestador pode ver (RN48). -->
            <section v-if="mostrarSecaoAutorizada" class="secao">
              <div class="secao__cabecalho">
                <h2 class="secao__rotulo">Sob sua autorização</h2>
                <span class="secao__contagem">{{ contar(autorizados.length) }}</span>
              </div>

              <div v-if="autorizados.length" class="cartoes">
                <RouterLink
                  v-for="animal in autorizados"
                  :key="animal.codigo"
                  :to="`/clinica/animais/${animal.codigo}`"
                  class="cartao-animal"
                  data-resultado
                >
                  <span class="cartao-animal__icone">
                    <component :is="iconeDaEspecie(animal.especie)" :size="20" :stroke-width="1.75" />
                  </span>
                  <span class="cartao-animal__texto">
                    <span class="cartao-animal__nome">{{ animal.nome }}</span>
                    <span class="cartao-animal__meta">
                      {{ descreverAnimal(animal) }} · {{ animal.tutor }}
                    </span>
                    <span class="cartao-animal__codigo">{{ animal.codigo }}</span>
                  </span>
                  <StatusPill
                    v-if="animal.situacao"
                    :tipo="animal.situacao.tipo"
                    :texto="animal.situacao.texto_curto"
                  />
                  <!-- RF50 — sem vacinação registrada o sistema diz que ainda
                       não sabe, e nunca "em dia" por omissão. -->
                  <StatusPill v-else tipo="nao-verificada" texto="sem dados" />
                </RouterLink>
              </div>

              <p v-else class="secao__vazio">
                Nenhum animal deste tutor está sob autorização vigente para {{ prestador }}.
              </p>
            </section>

            <!-- Segunda seção: o que ele não pode ver, e por quê (RN12). -->
            <section v-if="existencia" class="secao">
              <div class="secao__cabecalho">
                <h2 class="secao__rotulo secao__rotulo--consentimento">
                  Existe cadastro na plataforma
                </h2>
                <span class="secao__contagem">{{ contar(1) }}</span>
              </div>

              <div class="consentimento">
                <div v-if="existencia.tipo === 'animal'" class="consentimento__animal">
                  <component
                    :is="iconeDaEspecie(existencia.especie)"
                    :size="24"
                    :stroke-width="1.75"
                    class="consentimento__animal-icone"
                  />
                  <div>
                    <p class="consentimento__animal-nome">{{ existencia.nome }}</p>
                    <p class="consentimento__animal-especie">{{ descreverEspecie(existencia.especie) }}</p>
                  </div>
                </div>

                <div class="consentimento__topo">
                  <KeyRound
                    :size="existencia.tipo === 'animal' ? 20 : 24"
                    :stroke-width="1.75"
                    class="consentimento__icone"
                  />
                  <p class="consentimento__titulo" :class="{ 'consentimento__titulo--miudo': existencia.tipo === 'animal' }">
                    {{ consentimento.titulo }}
                  </p>
                </div>

                <p class="consentimento__detalhe">{{ consentimento.detalhe(prestador) }}</p>

                <!-- V10 — o pedido é modal sobre esta tela: o contexto que o
                     abriu permanece atrás dele. Pendente, o botão vira
                     etiqueta — informação, não ação, porque pedir de novo não
                     apressaria ninguém. -->
                <p v-if="solicitacaoPendente" class="consentimento__etiqueta">
                  <Hourglass :size="16" :stroke-width="1.75" />
                  Solicitação enviada · aguardando o tutor até
                  {{ emNumeros(solicitacaoPendente.expira_em) }}
                </p>
                <button
                  v-else-if="alvoDaSolicitacao"
                  type="button"
                  class="botao botao--consentimento"
                  data-resultado
                  @click="solicitando = true"
                >
                  <KeyRound :size="16" :stroke-width="1.75" />
                  Solicitar autorização ao tutor
                </button>
                <p v-else class="consentimento__detalhe">
                  Para solicitar autorização, busque pelo CPF do tutor, pelo código do animal ou
                  pelo micro-chip.
                </p>

                <p class="consentimento__registro">
                  <Eye :size="16" :stroke-width="1.75" class="consentimento__registro-icone" />
                  {{ consentimento.registro(prestador) }}
                </p>
              </div>
            </section>

            <div v-if="existencia?.tipo === 'tutor'" class="ajuda">
              <CircleHelp :size="20" :stroke-width="1.75" class="ajuda__icone" />
              <p class="ajuda__texto">
                Se o tutor está no balcão, ele pode autorizar em quatro toques pelo aplicativo. A
                confirmação vai para o e-mail dele e o código não passa pelo nosso sistema aqui.
              </p>
            </div>
          </template>
        </div>
      </template>
    </div>

    <SolicitarAutorizacaoModal
      :aberto="solicitando"
      :prestador="prestador"
      :prestador-id="consulta?.prestador?.id"
      :alvo="alvoDaSolicitacao"
      @fechar="solicitando = false"
      @enviada="solicitacao = $event"
    />
  </VetShell>
</template>

<style scoped>
.busca {
  max-width: 1280px;
  margin: 0 auto;
}

/* Campo do cabeçalho ------------------------------------------------------- */

.atalho-do-topo,
.campo-do-topo {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  width: 100%;
  height: 36px;
  padding: 0 var(--space-3);
  background: var(--surface-sunken);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  font-size: 14px;
  text-align: left;
  color: var(--ink-faint);
  cursor: pointer;
}

.atalho-do-topo:hover {
  border-color: var(--brand-bright);
  color: var(--ink-muted);
}

.atalho-do-topo__icone,
.campo-do-topo__icone {
  flex: none;
  color: var(--ink-muted);
}

.atalho-do-topo__tecla {
  margin-left: auto;
  font-family: var(--font-mono);
  font-size: 12px;
  color: var(--ink-faint);
}

.campo-do-topo {
  cursor: text;
}

.campo-do-topo__entrada {
  flex: 1;
  min-width: 0;
  border: 0;
  outline: none;
  background: none;
  font-family: inherit;
  font-size: 14px;
  color: var(--ink);
}

/* Chave exata é número ditado e transcrito: monoespaçada, para conferir
   caractere a caractere contra o papel. */
.campo-do-topo__entrada--codigo {
  font-family: var(--font-mono);
  font-size: 13px;
  font-variant-numeric: tabular-nums;
}

.campo-do-topo__tipo {
  flex: none;
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
}

/* Estado inicial ----------------------------------------------------------- */

.heroi {
  width: 640px;
  max-width: 100%;
  margin: 0 auto;
  padding: var(--space-8) 0 var(--space-12);
}

.heroi__titulo {
  margin: 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  letter-spacing: -.02em;
  color: var(--ink);
  text-align: center;
}

.heroi__campo-linha,
.campo-linha-estreita {
  display: flex;
  gap: var(--space-2);
  margin: var(--space-6) 0 0;
}

.campo-linha-estreita {
  margin: 0 0 var(--space-6);
}

/* Com erro logo abaixo, o campo o encosta em vez de o soltar no meio da tela. */
.campo-linha-estreita:has(+ .erro-do-termo) {
  margin-bottom: var(--space-2);
}

.campo {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  flex: 1;
  min-width: 0;
  height: 48px;
  padding: 0 var(--space-3);
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
}

.campo:focus-within {
  border-color: var(--brand-bright);
  outline: 2px solid var(--brand-bright);
  outline-offset: 2px;
}

.campo--invalido {
  border-color: var(--status-late);
}

.campo__icone {
  flex: none;
  color: var(--ink-muted);
}

.campo__entrada {
  flex: 1;
  min-width: 0;
  border: 0;
  outline: none;
  background: none;
  font-family: inherit;
  font-size: 16px;
  color: var(--ink);
}

.campo__tipo {
  flex: none;
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
}

.leitor-qr {
  display: flex;
  align-items: center;
  justify-content: center;
  flex: none;
  width: 48px;
  height: 48px;
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  color: var(--consent);
}

.leitor-qr:hover {
  background: var(--surface-sunken);
  color: var(--consent);
}

.erro-do-termo {
  display: flex;
  align-items: flex-start;
  gap: var(--space-2);
  margin: var(--space-2) 0 0;
  font-size: 12px;
  line-height: 16px;
  color: var(--status-late);
}

.erro-do-termo--bloco {
  margin: 0 0 var(--space-3);
}

.erro-do-termo__icone {
  flex: none;
}

/* O campo perde a borda neutra ao acusar erro, no cabeçalho como no conteúdo. */
.campo-do-topo--invalido {
  border-color: var(--status-late);
}

.nada-consultado {
  max-width: 75ch;
  margin: var(--space-4) 0 var(--space-6);
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

.nada-consultado__texto {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.exemplos {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--space-3);
  margin: var(--space-4) 0 0;
}

.exemplo {
  padding: var(--space-3) var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-sm);
}

.exemplo__rotulo {
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
}

.exemplo__forma {
  margin: 0;
  font-family: var(--font-mono);
  font-size: 13px;
  line-height: 18px;
  font-weight: 500;
  color: var(--ink);
}

.exemplo__forma--numero {
  font-variant-numeric: tabular-nums;
}

.exemplo__forma--texto {
  font-family: var(--font-body);
  font-size: 14px;
  line-height: 20px;
  font-weight: 400;
}

.aviso-de-registro {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  margin: var(--space-4) 0 0;
  padding: var(--space-4);
  background: var(--surface-sunken);
  border: 1px dashed var(--consent);
  border-radius: var(--radius-sm);
}

.aviso-de-registro__icone {
  flex: none;
  color: var(--consent);
}

.aviso-de-registro__texto {
  margin: 0;
  max-width: 75ch;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

.atalhos {
  margin: var(--space-4) 0 0;
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
  text-align: center;
}

.atalhos kbd {
  font-family: var(--font-mono);
  font-size: 12px;
}

/* Cabeçalho do resultado --------------------------------------------------- */

.busca__cabecalho {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: var(--space-4);
  flex-wrap: wrap;
}

.busca__sobrelinha {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--brand);
}

.busca__titulo {
  margin: var(--space-1) 0 0;
  font-family: var(--font-display);
  font-size: 22px;
  line-height: 28px;
  font-weight: 600;
  color: var(--ink);
}

.busca__termo {
  font-family: var(--font-mono);
  font-size: 18px;
  font-weight: 500;
  font-variant-numeric: tabular-nums;
}

.busca__deteccao {
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

/* Seções ------------------------------------------------------------------- */

.secao {
  margin: var(--space-6) 0 0;
}

.secao__cabecalho {
  display: flex;
  align-items: center;
  gap: var(--space-3);
}

.secao__rotulo {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

/* O índigo aparece só onde o assunto é quem pode ver o quê (§4 do briefing). */
.secao__rotulo--consentimento {
  color: var(--consent);
}

.secao__contagem {
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-faint);
  font-variant-numeric: tabular-nums;
}

.secao__vazio {
  max-width: 75ch;
  margin: var(--space-3) 0 0;
  padding: var(--space-6);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.cartoes {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--space-4);
  margin: var(--space-3) 0 0;
}

.cartao-animal {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
  color: var(--ink);
}

.cartao-animal:hover {
  border-color: var(--brand-bright);
  color: var(--ink);
}

.cartao-animal__icone {
  display: flex;
  align-items: center;
  justify-content: center;
  flex: none;
  width: 40px;
  height: 40px;
  border-radius: var(--radius-pill);
  background: var(--surface-sunken);
  color: var(--ink-muted);
}

.cartao-animal__texto {
  flex: 1;
  min-width: 0;
}

.cartao-animal__nome {
  display: block;
  font-size: 16px;
  line-height: 24px;
  font-weight: 600;
}

.cartao-animal__meta {
  display: block;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.cartao-animal__codigo {
  display: block;
  font-family: var(--font-mono);
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
}

/* Cartão de consentimento — o desenho deliberadamente diferente -------------
   Fundo rebaixado e borda tracejada: o cartão parece propositalmente incompleto
   porque é isso que ele é. O que falta nele não é dado que não veio; é dado que
   não pode vir sem autorização. */

.consentimento {
  max-width: 520px;
  margin: var(--space-3) 0 0;
  padding: var(--space-6);
  background: var(--surface-sunken);
  border: 1px dashed var(--consent);
  border-radius: var(--radius-md);
}

.consentimento__animal {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  margin: 0 0 var(--space-3);
}

.consentimento__animal-icone {
  flex: none;
  color: var(--ink-muted);
}

.consentimento__animal-nome {
  margin: 0;
  font-size: 18px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.consentimento__animal-especie {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.consentimento__topo {
  display: flex;
  align-items: center;
  gap: var(--space-3);
}

.consentimento__icone {
  flex: none;
  color: var(--consent);
}

.consentimento__titulo {
  margin: 0;
  max-width: 70ch;
  font-size: 18px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

/* Quando o nome do animal já é o título do cartão, a frase da autorização vira
   texto de corpo: dois títulos disputariam a mesma pergunta. */
.consentimento__titulo--miudo {
  font-size: 14px;
  line-height: 20px;
  font-weight: 400;
}

.consentimento__detalhe {
  margin: var(--space-2) 0 0;
  max-width: 70ch;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

/* A etiqueta de espera (V10): o mesmo lugar do botão, o oposto do convite —
   o pedido já foi feito e a vez agora é do tutor. */
.consentimento__etiqueta {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  margin: var(--space-4) 0 0;
  padding: var(--space-2) var(--space-3);
  background: var(--consent-wash);
  border: 1px solid var(--consent);
  border-radius: var(--radius-pill);
  font-size: 13px;
  line-height: 18px;
  font-weight: 600;
  color: var(--consent);
}

.consentimento__registro {
  display: flex;
  align-items: flex-start;
  gap: var(--space-2);
  margin: var(--space-4) 0 0;
  padding: var(--space-3) 0 0;
  border-top: 1px solid var(--border-strong);
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-muted);
}

.consentimento__registro-icone {
  flex: none;
  color: var(--consent);
}

.ajuda {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  max-width: 75ch;
  margin: var(--space-6) 0 0;
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-sm);
}

.ajuda__icone {
  flex: none;
  color: var(--ink-muted);
}

.ajuda__texto {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

/* Como os estados de coluna única do painel, o bloco se centra na área de
   conteúdo em vez de encostar à esquerda. */
.sem-resultado {
  max-width: 708px;
  margin: var(--space-6) auto 0;
}

/* Botões e avisos ---------------------------------------------------------- */

.botao {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-2);
  height: 40px;
  padding: 0 var(--space-4);
  border-radius: var(--radius-sm);
  font-family: inherit;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
}

.botao--primario {
  background: var(--brand);
  border: 1px solid var(--brand);
  color: var(--surface-card);
}

.botao--primario:hover {
  background: var(--brand-hover);
  border-color: var(--brand-hover);
  color: var(--surface-card);
}

.botao--secundario {
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  color: var(--ink);
}

.botao--secundario:hover {
  background: var(--surface-sunken);
  color: var(--ink);
}

.botao--consentimento {
  margin: var(--space-4) 0 0;
  background: var(--consent);
  border: 1px solid var(--consent);
  color: var(--surface-card);
}

.botao--consentimento:hover {
  background: #32427A;
  color: var(--surface-card);
}

.aviso {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  padding: var(--space-4);
  border-radius: var(--radius-md);
}

.aviso--erro {
  background: var(--status-late-wash);
  border: 1px solid var(--status-late);
}

.aviso__icone {
  flex: none;
  margin-top: 2px;
  color: var(--status-late);
}

.aviso__titulo {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.aviso__texto {
  margin: var(--space-1) 0 var(--space-4);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

/* Esqueleto ---------------------------------------------------------------- */

.esqueleto {
  border-radius: var(--radius-xs);
  background: var(--surface-sunken);
  animation: pulsar 1.2s ease-in-out infinite;
}

.esqueleto--titulo {
  height: 34px;
  width: 60%;
  margin: 0 auto;
}

.esqueleto--campo {
  height: 48px;
  margin: var(--space-6) 0 0;
}

.esqueleto--exemplo {
  height: 58px;
}

.esqueleto--cartao {
  height: 72px;
  border-radius: var(--radius-md);
}

@keyframes pulsar {
  0%, 100% { opacity: .55; }
  50% { opacity: 1; }
}

.visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip-path: inset(50%);
  white-space: nowrap;
}

/* Larguras derivadas ------------------------------------------------------- */

@media (min-width: 768px) {
  /* Acima daqui o campo vive na faixa do cabeçalho, e repeti-lo no conteúdo
     seria oferecer dois campos para a mesma pergunta. A mensagem de erro fica:
     é do termo, e o termo continua na tela. */
  .campo-linha-estreita {
    display: none;
  }

  .heroi {
    padding: var(--space-24) 0;
  }

  .heroi__titulo {
    font-size: 36px;
    line-height: 40px;
  }

  .campo {
    height: 40px;
  }

  .campo__entrada {
    font-size: 14px;
  }

  .leitor-qr {
    display: none;
  }

  .exemplos {
    grid-template-columns: repeat(2, 1fr);
  }

  .busca__titulo {
    font-size: 28px;
    line-height: 34px;
  }

  .busca__termo {
    font-size: 22px;
  }

  .cartoes {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (min-width: 1280px) {
  .cartoes {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}
</style>
