<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import {
  Cat,
  ClipboardPlus,
  Dog,
  Eye,
  Hourglass,
  KeyRound,
  PawPrint,
  QrCode,
  Syringe,
  TriangleAlert,
} from '@lucide/vue'
import VetShell from '@/components/vet/VetShell.vue'
import StatusPill from '@/components/base/StatusPill.vue'
import EmptyState from '@/components/base/EmptyState.vue'
import SolicitarAutorizacaoModal from '@/components/vet/SolicitarAutorizacaoModal.vue'
import { apiGet } from '@/lib/api.js'
import { descreverAnimal, descreverEspecie } from '@/lib/animais.js'
import { emNumeros } from '@/lib/datas.js'
import { CPF, classificarTermo, termoConsultavel, termoParaExibicao } from '@/lib/busca.js'

/**
 * V07a e V08a — escolher o animal antes de registrar.
 *
 * Acionado pela ficha (V06), o registro já sabe de quem é. Acionado pelo botão
 * "Registrar" do cabeçalho, falta o animal — e é esta tela que resolve a falta,
 * antes de qualquer campo de prontuário. A ordem importa: um seletor de animal
 * dentro do formulário de V07 deixaria o profissional redigir o registro inteiro
 * para descobrir, na confirmação, que não pode gravá-lo ali.
 *
 * Por isso a tela oferece dois caminhos e nenhum terceiro: o atalho dos últimos
 * atendidos, que responde à maioria dos casos do balcão, e a busca — a mesma de
 * V03, com o mesmo âmbito e a mesma parcimônia. Encontrado um animal fora da
 * autorização, o que aparece é o pedido de autorização, nunca o início do
 * registro.
 */
const route = useRoute()

/**
 * As duas ações do botão "Registrar". O que muda entre elas é a pergunta, o
 * ícone e a rota de destino; o âmbito, o atalho e a recusa são os mesmos, e é
 * por isso que são uma tela só.
 */
const ACOES = {
  vacinacao: {
    rotulo: 'Registrar vacinação',
    pergunta: 'Qual animal você vai vacinar?',
    icone: Syringe,
    caminho: 'vacinar',
  },
  atendimento: {
    rotulo: 'Registrar atendimento',
    pergunta: 'Qual animal você vai atender?',
    icone: ClipboardPlus,
    caminho: 'atender',
  },
}

const acao = computed(() => ACOES[route.params.acao] ?? ACOES.vacinacao)

const termo = ref('')
const consulta = ref(null)
const carregando = ref(true)
const buscando = ref(false)
const erro = ref('')
/** Erro do próprio termo (CPF com dígito inválido), que nem chega a consultar. */
const erroDoTermo = ref('')

const campo = ref(null)
const resultados = ref(null)

const inicial = computed(() => (consulta.value?.estado ?? 'inicial') === 'inicial')
const semResultado = computed(() => consulta.value?.estado === 'sem_resultado')
const autorizados = computed(() => consulta.value?.autorizados ?? [])
const recentes = computed(() => consulta.value?.recentes ?? [])
const existencia = computed(() => consulta.value?.existencia ?? null)
const prestador = computed(() => consulta.value?.prestador?.nome ?? 'este prestador')

const termoRespondido = computed(() =>
  consulta.value?.termo ? termoParaExibicao(consulta.value.termo) : '',
)

/**
 * Resultado único é escolha feita: em vez de uma lista de um item, a tela
 * apresenta o animal com o prazo do acesso e o botão que abre o registro. É o
 * caminho da busca por código ditado pelo tutor, que é como o balcão trabalha
 * quando o animal não está entre os últimos atendidos.
 */
const escolhido = computed(() => (autorizados.value.length === 1 ? autorizados.value[0] : null))

const CONSENTIMENTO = {
  tutor: {
    titulo: 'Existe um cadastro com este CPF.',
    // RF13a e RF13b — a existência, e nada além dela. Nome, contato e relação
    // de animais permanecem ocultos até a concessão.
    detalhe: (nome) =>
      `Nome, contato e animais aparecem somente depois que o tutor autorizar ${nome} — e é também o que falta para registrar qualquer coisa.`,
  },
  animal: {
    titulo: 'Existe histórico disponível mediante autorização do tutor.',
    detalhe: () =>
      'Enquanto não houver autorização, não é possível abrir a ficha nem iniciar registro para este animal — nem vacinação, nem atendimento.',
  },
  outro: {
    titulo: 'Existe outro cadastro que corresponde a esta busca.',
    detalhe: () => 'Sem autorização do tutor, não mostramos de quem é nem quantos animais tem.',
  },
}

const consentimento = computed(() => CONSENTIMENTO[existencia.value?.tipo] ?? null)

/** V10 — o modal do pedido, aberto sobre esta tela. */
const solicitando = ref(false)

/** O desfecho do pedido feito nesta visita, que vira a etiqueta de espera. */
const solicitacao = ref(null)

/** O que o modal vai pedir — mesmo desenho de V03: só chave exata tem alvo. */
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

const solicitacaoPendente = computed(
  () => solicitacao.value ?? existencia.value?.solicitacao_pendente ?? null,
)

function iconeDaEspecie(especie) {
  return especie === 'gato' ? Cat : Dog
}

function destino(animal) {
  return `/clinica/animais/${animal.codigo}/${acao.value.caminho}`
}

/**
 * O resto da linha depois do nome. Vem como uma cadeia só, e não como texto
 * solto ao lado do `<strong>`, porque o compilador do Vue condensa o espaço
 * entre dois elementos e a linha sairia "Théo· cão".
 */
function descricaoDaLinha(animal) {
  return ` · ${descreverAnimal(animal)} · ${animal.tutor}`
}

/**
 * A etiqueta da linha do atalho: a vacina pendente quando há uma, porque é ela
 * que decide a escolha de quem veio vacinar. Sem pendência, a situação da
 * carteira; sem vacinação alguma, "sem dados" — RF50 manda dizer que o sistema
 * ainda não sabe, nunca "em dia" por omissão.
 */
function etiqueta(animal) {
  if (animal.pendencia) {
    return {
      tipo: animal.pendencia.situacao.tipo,
      texto: `${animal.pendencia.imunobiologico} ${animal.pendencia.situacao.texto_curto}`,
    }
  }

  return animal.situacao
    ? { tipo: animal.situacao.tipo, texto: animal.situacao.texto_curto }
    : { tipo: 'nao-verificada', texto: 'sem dados' }
}

function parametros(termoDaVez) {
  const busca = new URLSearchParams()

  if (termoDaVez) busca.set('termo', termoDaVez)
  if (consulta.value?.prestador) busca.set('prestador', consulta.value.prestador.id)

  return busca
}

async function carregar(termoDaVez = '') {
  if (termoDaVez) buscando.value = true
  else carregando.value = true

  erro.value = ''

  try {
    consulta.value = await apiGet(`/api/clinica/registrar?${parametros(termoDaVez)}`)
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

  const { tipo, valor } = classificarTermo(termo.value)
  if (valor === '') return

  // RF13 — a conferência do dígito acontece antes da consulta, e por isso a
  // requisição sequer sai: um número digitado errado é o CPF de outra pessoa, e
  // não pode gerar registro de acesso no nome dela.
  if (!termoConsultavel(termo.value)) {
    erroDoTermo.value = tipo === CPF
      ? 'Este CPF não é válido: o dígito verificador não confere. Confira o número com o tutor.'
      : 'Não foi possível reconhecer este termo.'

    return
  }

  carregar(termo.value)
}

/**
 * "Escolher outro animal": desfaz a consulta e devolve o cursor ao campo. Sem
 * ir ao servidor — o atalho dos últimos atendidos já está na mão, e uma segunda
 * requisição só serviria para desmontar o campo enquanto o esqueleto aparece,
 * levando embora o foco que este botão existe para devolver.
 */
function recomecar() {
  termo.value = ''
  erroDoTermo.value = ''
  consulta.value = { ...consulta.value, termo: '', estado: 'inicial', autorizados: [], existencia: null }
  focar()
}

function trocarPrestador(id) {
  consulta.value = { ...consulta.value, prestador: { ...consulta.value.prestador, id } }
  carregar(consulta.value.termo ?? '')
}

async function focar() {
  await nextTick()
  campo.value?.focus()
  campo.value?.select()
}

/**
 * `↑ ↓` percorre, `Enter` escolhe — o que o rodapé do campo promete. Os alvos
 * são ligações, e por isso já focalizáveis; o que falta é a ordem, e é ela que
 * se dá aqui.
 */
function percorrer(evento) {
  if (evento.key !== 'ArrowDown' && evento.key !== 'ArrowUp') return

  const focalizaveis = Array.from(resultados.value?.querySelectorAll('[data-escolha]') ?? [])
  if (focalizaveis.length === 0) return

  const atual = focalizaveis.indexOf(document.activeElement)
  const proximo = evento.key === 'ArrowDown' ? atual + 1 : atual - 1

  if (proximo < 0) {
    evento.preventDefault()
    focar()

    return
  }

  if (proximo >= focalizaveis.length) return

  evento.preventDefault()
  focalizaveis[proximo].focus()
}

// Trocar de ação pelo menu "Registrar" sem sair da tela mantém o que já foi
// procurado: o animal é o mesmo, a pergunta é que mudou.
watch(() => route.params.acao, focar)

onMounted(async () => {
  await carregar()
  // §8.3 — foco automático no campo, que é o que sustenta o registro em noventa
  // segundos (RNF15) quando o caminho começa pelo botão do cabeçalho.
  focar()
})
</script>

<template>
  <VetShell
    titulo="Escolher o animal"
    :prestador="consulta?.prestador"
    :vinculos="consulta?.vinculos ?? []"
    @trocar-prestador="trocarPrestador"
  >
    <div v-if="carregando" class="escolha" aria-busy="true" aria-live="polite">
      <span class="visually-hidden">Abrindo a escolha do animal.</span>
      <div class="esqueleto esqueleto--pilula" />
      <div class="esqueleto esqueleto--titulo" />
      <div class="esqueleto esqueleto--campo" />
      <div class="lista">
        <div v-for="linha in 3" :key="linha" class="esqueleto esqueleto--linha" />
      </div>
    </div>

    <div v-else-if="erro" class="escolha">
      <div class="aviso" role="alert">
        <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
        <div>
          <p class="aviso__titulo">Não conseguimos abrir a escolha do animal.</p>
          <p class="aviso__texto">{{ erro }}</p>
          <button type="button" class="botao botao--secundario" @click="carregar(consulta?.termo ?? '')">
            Tentar novamente
          </button>
        </div>
      </div>
    </div>

    <div v-else class="escolha" @keydown="percorrer">
      <p class="escolha__acao">
        <component :is="acao.icone" :size="14" :stroke-width="1.75" />
        {{ acao.rotulo }}
      </p>
      <h1 class="escolha__titulo">{{ acao.pergunta }}</h1>

      <form class="escolha__campo-linha" role="search" @submit.prevent="buscar">
        <div class="campo" :class="{ 'campo--invalido': erroDoTermo }">
          <PawPrint :size="20" :stroke-width="1.75" class="campo__icone" />
          <input
            ref="campo"
            v-model="termo"
            type="search"
            class="campo__entrada"
            placeholder="Nome, código do animal, micro-chip ou nome do tutor"
            aria-label="Buscar o animal do registro"
            :aria-invalid="Boolean(erroDoTermo)"
            :aria-describedby="erroDoTermo ? 'erro-do-termo' : 'ambito-da-escolha'"
          >
        </div>

        <!-- O leitor de QR é da moldura do celular (§8.3); a leitura em si é
             fatia própria, e até lá a ligação responde por ela. -->
        <RouterLink to="/clinica/buscar/qr" class="leitor-qr" aria-label="Ler QR Code do animal">
          <QrCode :size="24" :stroke-width="1.75" />
        </RouterLink>
      </form>

      <!-- RN48 dito antes de o profissional procurar: a lista curta não é falha
           da busca, é o âmbito do que ele pode registrar. -->
      <p id="ambito-da-escolha" class="escolha__ambito">
        Só aparecem aqui os animais sob autorização vigente para {{ prestador }}.
        <span class="escolha__teclas"><kbd>↑ ↓</kbd> percorre · <kbd>Enter</kbd> escolhe</span>
      </p>

      <p v-if="erroDoTermo" id="erro-do-termo" class="erro-do-termo" role="alert">
        <TriangleAlert :size="16" :stroke-width="1.75" class="erro-do-termo__icone" />
        {{ erroDoTermo }}
      </p>

      <div v-if="buscando" class="lista" aria-busy="true" aria-live="polite">
        <span class="visually-hidden">Buscando.</span>
        <div v-for="linha in 3" :key="linha" class="esqueleto esqueleto--linha" />
      </div>

      <div v-else ref="resultados" aria-live="polite">
        <!-- Estado de abertura: o trabalho do dia como atalho. -->
        <template v-if="inicial">
          <section v-if="recentes.length" class="secao">
            <div class="secao__cabecalho">
              <h2 class="secao__rotulo">Atendidos recentemente</h2>
              <span class="secao__nota">atalho para o trabalho do dia</span>
            </div>

            <div class="lista">
              <RouterLink
                v-for="animal in recentes"
                :key="animal.codigo"
                :to="destino(animal)"
                class="linha"
                data-escolha
              >
                <span class="linha__icone">
                  <component :is="iconeDaEspecie(animal.especie)" :size="16" :stroke-width="1.75" />
                </span>
                <span class="linha__texto">
                  <strong class="linha__nome">{{ animal.nome }}</strong>
                  <span class="linha__meta">{{ descricaoDaLinha(animal) }}</span>
                </span>
                <span class="linha__codigo">{{ animal.codigo }}</span>
                <StatusPill class="linha__etiqueta" v-bind="etiqueta(animal)" />
              </RouterLink>
            </div>
          </section>

          <EmptyState
            v-else
            :icone="PawPrint"
            titulo="Nenhum animal atendido nos últimos 30 dias"
            :descricao="`Procure pelo nome, pelo código ou pelo micro-chip. Só é possível registrar em animal com autorização vigente para ${prestador}.`"
          />
        </template>

        <!-- Nada corresponde ao que se procurou, dentro nem fora do âmbito. -->
        <EmptyState
          v-else-if="semResultado"
          :icone="PawPrint"
          titulo="Nenhum animal encontrado"
          :descricao="`Nenhum animal sob autorização de ${prestador} corresponde a “${termoRespondido}”. Se o tutor está no balcão, ele autoriza pelo aplicativo em quatro toques.`"
        >
          <RouterLink to="/clinica/animais/novo" class="botao botao--primario">
            Cadastrar animal
          </RouterLink>
          <RouterLink to="/clinica/buscar" class="botao botao--secundario">
            Buscar por CPF do tutor
          </RouterLink>
        </EmptyState>

        <template v-else>
          <!-- Resultado único: a escolha está feita, e a tela a confirma com o
               prazo do acesso antes de abrir o formulário. -->
          <section v-if="escolhido" class="secao">
            <div class="escolhido">
              <div class="escolhido__topo">
                <span class="linha__icone">
                  <component :is="iconeDaEspecie(escolhido.especie)" :size="16" :stroke-width="1.75" />
                </span>
                <div class="escolhido__identidade">
                  <p class="escolhido__nome">
                    {{ escolhido.nome }} · {{ descreverAnimal(escolhido) }} · {{ escolhido.tutor }}
                  </p>
                  <p class="escolhido__codigo">{{ escolhido.codigo }}</p>
                </div>
                <span v-if="escolhido.autorizado_ate" class="autorizacao">
                  <KeyRound :size="14" :stroke-width="1.75" />
                  autorizado até {{ emNumeros(escolhido.autorizado_ate) }}
                </span>
              </div>

              <div class="escolhido__rodape">
                <p class="escolhido__texto">
                  Escolhido. O registro abre já no contexto de {{ escolhido.nome }}.
                </p>
                <RouterLink :to="destino(escolhido)" class="botao botao--primario" data-escolha>
                  <component :is="acao.icone" :size="16" :stroke-width="1.75" />
                  {{ acao.rotulo }}
                </RouterLink>
              </div>
            </div>
          </section>

          <section v-else-if="autorizados.length" class="secao">
            <div class="secao__cabecalho">
              <h2 class="secao__rotulo">Sob sua autorização</h2>
              <span class="secao__nota">{{ autorizados.length }} animais</span>
            </div>

            <div class="lista">
              <RouterLink
                v-for="animal in autorizados"
                :key="animal.codigo"
                :to="destino(animal)"
                class="linha"
                data-escolha
              >
                <span class="linha__icone">
                  <component :is="iconeDaEspecie(animal.especie)" :size="16" :stroke-width="1.75" />
                </span>
                <span class="linha__texto">
                  <strong class="linha__nome">{{ animal.nome }}</strong>
                  <span class="linha__meta">{{ descricaoDaLinha(animal) }}</span>
                </span>
                <span class="linha__codigo">{{ animal.codigo }}</span>
                <StatusPill class="linha__etiqueta" v-bind="etiqueta(animal)" />
              </RouterLink>
            </div>
          </section>

          <!-- P2 — o que existe fora do âmbito, e o único caminho que dele sai.
               O desenho é deliberadamente diferente do cartão de resultado: o
               que falta aqui não é dado que não veio, é dado que não pode vir
               sem autorização. -->
          <section v-if="existencia" class="secao">
            <h2 class="secao__rotulo secao__rotulo--consentimento">Existe cadastro na plataforma</h2>

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
                <KeyRound :size="20" :stroke-width="1.75" class="consentimento__icone" />
                <p class="consentimento__titulo">{{ consentimento.titulo }}</p>
              </div>

              <p class="consentimento__detalhe">{{ consentimento.detalhe(prestador) }}</p>

              <!-- V10 — o pedido é modal sobre esta tela. Pendente, o botão
                   vira etiqueta de espera. -->
              <p v-if="solicitacaoPendente" class="consentimento__etiqueta">
                <Hourglass :size="16" :stroke-width="1.75" />
                Solicitação enviada · aguardando o tutor até
                {{ emNumeros(solicitacaoPendente.expira_em) }}
              </p>

              <div class="consentimento__acoes">
                <button
                  v-if="!solicitacaoPendente && alvoDaSolicitacao"
                  type="button"
                  class="botao botao--consentimento"
                  data-escolha
                  @click="solicitando = true"
                >
                  <KeyRound :size="16" :stroke-width="1.75" />
                  Solicitar autorização ao tutor
                </button>
                <button type="button" class="botao botao--secundario" @click="recomecar">
                  Escolher outro animal
                </button>
              </div>

              <!-- RF18b — dito na própria tela em que a consulta aconteceu. -->
              <p class="consentimento__registro">
                <Eye :size="16" :stroke-width="1.75" class="consentimento__registro-icone" />
                Esta consulta ficou registrada e ficará visível ao tutor.
              </p>
            </div>
          </section>
        </template>

        <!-- A saída para quem não encontrou o animal por caminho nenhum. Fica
             fora dos estados vazios porque neles as mesmas ações já estão. -->
        <div v-if="!semResultado" class="escape">
          <p class="escape__pergunta">O animal não está na lista nem na busca?</p>
          <RouterLink to="/clinica/animais/novo" class="botao botao--secundario">
            Cadastrar animal
          </RouterLink>
          <RouterLink to="/clinica/buscar" class="botao botao--secundario">
            Buscar por CPF do tutor
          </RouterLink>
        </div>
      </div>
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
.escolha {
  max-width: 880px;
  margin: 0 auto;
}

.escolha__acao {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 22px;
  margin: 0;
  padding: 0 10px;
  border-radius: var(--radius-pill);
  background: var(--brand-wash);
  font-size: 12px;
  line-height: 16px;
  font-weight: 600;
  color: var(--brand);
}

.escolha__titulo {
  margin: var(--space-3) 0 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  letter-spacing: -.02em;
  color: var(--ink);
}

/* Campo -------------------------------------------------------------------- */

.escolha__campo-linha {
  display: flex;
  gap: var(--space-2);
  margin: var(--space-6) 0 0;
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
  /* O alvo é o campo inteiro, e não a caixa de texto do meio: sem isto, tocar
     na borda de cima do campo em celular não leva o cursor a lugar nenhum. */
  height: 100%;
  border: 0;
  outline: none;
  background: none;
  font-family: inherit;
  font-size: 16px;
  color: var(--ink);
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

.escolha__ambito {
  margin: 6px 0 0;
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
}

.escolha__teclas {
  display: none;
}

.escolha__teclas kbd {
  font-family: var(--font-mono);
  font-size: 12px;
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

.erro-do-termo__icone {
  flex: none;
}

/* Seções e listas ---------------------------------------------------------- */

.secao {
  margin: var(--space-6) 0 0;
}

.secao__cabecalho {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  flex-wrap: wrap;
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

.secao__nota {
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-faint);
}

.lista {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  margin: var(--space-3) 0 0;
}

/* Linha, e não cartão: a escolha é uma lista de alvos percorríveis com o
   teclado, e a densidade é a que faz caber o dia inteiro na dobra.
   Em grade, e não em flex, por causa da etiqueta: ela não quebra linha, e no
   celular espremia a identificação do animal em quatro linhas de duas palavras.
   Aqui ela desce para a linha de baixo, alinhada ao texto. */
.linha {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  grid-template-areas:
    "icone texto"
    ".     etiqueta";
  align-items: center;
  column-gap: var(--space-3);
  row-gap: var(--space-2);
  min-height: 48px;
  padding: var(--space-2) var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-sm);
  color: var(--ink);
}

.linha:hover {
  border-color: var(--brand-bright);
  color: var(--ink);
}

.linha:focus-visible {
  outline: 2px solid var(--brand-bright);
  outline-offset: 2px;
}

.linha__icone {
  display: flex;
  align-items: center;
  justify-content: center;
  flex: none;
  grid-area: icone;
  width: 32px;
  height: 32px;
  border-radius: var(--radius-pill);
  background: var(--surface-sunken);
  color: var(--ink-muted);
}

.linha__texto {
  grid-area: texto;
  min-width: 0;
  font-size: 14px;
  line-height: 20px;
}

.linha__nome {
  font-weight: 600;
}

.linha__meta {
  color: var(--ink-muted);
}

.linha__codigo {
  display: none;
  grid-area: codigo;
  font-family: var(--font-mono);
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
}

.linha__etiqueta {
  grid-area: etiqueta;
  justify-self: start;
}

/* Escolha confirmada ------------------------------------------------------- */

.escolhido {
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--brand);
  border-radius: var(--radius-md);
}

.escolhido__topo {
  display: flex;
  align-items: center;
  gap: var(--space-3);
}

.escolhido__identidade {
  flex: 1;
  min-width: 0;
}

.escolhido__nome {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  font-weight: 600;
  color: var(--ink);
}

.escolhido__codigo {
  margin: 0;
  font-family: var(--font-mono);
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-muted);
}

/* O prazo do acesso é assunto de autorização, e por isso índigo — a cor que
   neste sistema significa sempre quem pode ver o quê. */
.autorizacao {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  flex: none;
  height: 22px;
  padding: 0 10px;
  border-radius: var(--radius-pill);
  background: var(--consent-wash);
  font-size: 12px;
  line-height: 16px;
  font-weight: 600;
  color: var(--consent);
}

.escolhido__rodape {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  flex-wrap: wrap;
  margin: var(--space-4) 0 0;
}

.escolhido__texto {
  flex: 1;
  min-width: 0;
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

/* Cartão de consentimento -------------------------------------------------- */

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
  align-items: flex-start;
  gap: var(--space-2);
}

.consentimento__icone {
  flex: none;
  color: var(--consent);
}

.consentimento__titulo {
  margin: 0;
  max-width: 70ch;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
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

.consentimento__acoes {
  display: flex;
  gap: var(--space-3);
  flex-wrap: wrap;
  margin: var(--space-4) 0 0;
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

/* Saída -------------------------------------------------------------------- */

.escape {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  flex-wrap: wrap;
  margin: var(--space-6) 0 0;
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-sm);
}

.escape__pergunta {
  flex: 1;
  min-width: 0;
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

/* Botões e avisos ---------------------------------------------------------- */

/* §4.3 — 44 px de alvo mínimo abaixo de 1024 px, onde a tela é tocada com o
   dedo. A densidade de 40 px do desenho de 1440 px vale só do desktop para
   cima, e é lá que ela volta. */
.botao {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-2);
  height: 44px;
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
  background: var(--status-late-wash);
  border: 1px solid var(--status-late);
  border-radius: var(--radius-md);
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

.esqueleto--pilula {
  width: 160px;
  height: 22px;
  border-radius: var(--radius-pill);
}

.esqueleto--titulo {
  width: 60%;
  height: 34px;
  margin: var(--space-3) 0 0;
}

.esqueleto--campo {
  height: 48px;
  margin: var(--space-6) 0 0;
}

.esqueleto--linha {
  height: 48px;
  border-radius: var(--radius-sm);
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
  .escolha__titulo {
    font-size: 36px;
    line-height: 40px;
  }

  /* O leitor de QR é do celular: no desktop o código é digitado, e o botão
     ocuparia lugar de alvo sem função. */
  .leitor-qr {
    display: none;
  }

  .escolha__teclas {
    display: inline;
  }

  /* Com espaço, tudo cabe numa linha só — e o código volta, que é por onde o
     profissional confere com o que o tutor ditou. */
  .linha {
    grid-template-columns: auto minmax(0, 1fr) auto auto;
    grid-template-areas: "icone texto codigo etiqueta";
  }

  .linha__codigo {
    display: block;
  }
}

/* A densidade do desenho de 1440 px começa aqui, e não em 768: entre uma
   largura e outra a tela ainda é tocada com o dedo, e §4.3 pede 44 px de alvo.
   O campo e os botões só encolhem onde há mouse. */
@media (min-width: 1024px) {
  .campo {
    height: 40px;
  }

  .campo__entrada {
    font-size: 14px;
  }

  .botao {
    height: 40px;
  }
}
</style>
