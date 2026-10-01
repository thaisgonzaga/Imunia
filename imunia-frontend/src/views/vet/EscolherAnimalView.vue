<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  Cat,
  ClipboardPlus,
  Dog,
  PawPrint,
  QrCode,
  Syringe,
  TriangleAlert,
} from '@lucide/vue'
import VetShell from '@/components/vet/VetShell.vue'
import StatusPill from '@/components/base/StatusPill.vue'
import EmptyState from '@/components/base/EmptyState.vue'
import { apiGet } from '@/lib/api.js'
import { descreverAnimal } from '@/lib/animais.js'
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
 * V03, com o mesmo âmbito. O registro não depende do tutor: qualquer animal
 * encontrado pode ser escolhido, e registrar nele o põe entre os que a clínica
 * acompanha.
 */
const route = useRoute()
const router = useRouter()

/**
 * As duas ações do botão "Registrar". O que muda entre elas é a pergunta, o
 * ícone e a rota de destino; o âmbito e o atalho são os mesmos, e é por isso
 * que são uma tela só.
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
const encontrados = computed(() => consulta.value?.animais ?? [])
const recentes = computed(() => consulta.value?.recentes ?? [])
const prestador = computed(() => consulta.value?.prestador?.nome ?? 'este prestador')

const termoRespondido = computed(() =>
  consulta.value?.termo ? termoParaExibicao(consulta.value.termo) : '',
)

/**
 * Resultado único é escolha feita: em vez de uma lista de um item, a tela
 * apresenta o animal e o botão que abre o registro. É o
 * caminho da busca por código ditado pelo tutor, que é como o balcão trabalha
 * quando o animal não está entre os últimos atendidos.
 */
const escolhido = computed(() => (encontrados.value.length === 1 ? encontrados.value[0] : null))

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

async function carregar(termoDaVez = '', { abrindo = false } = {}) {
  // Com termo, é uma busca sobre a tela já aberta. Abrindo a tela com um termo
  // — o código que o leitor de QR Code entregou —, é a própria abertura.
  if (termoDaVez && !abrindo) buscando.value = true
  else carregando.value = true

  erro.value = ''

  try {
    consulta.value = await apiGet(`/api/clinica/registrar?${parametros(termoDaVez)}`)
  } catch (excecao) {
    erro.value = excecao.message
  } finally {
    carregando.value = false
    buscando.value = false
  }
}

/**
 * RF13 — a conferência do dígito acontece antes da consulta, e por isso a
 * requisição sequer sai: um número digitado errado é o CPF de outra pessoa, e
 * não pode gerar registro de acesso no nome dela.
 */
function termoValido() {
  erroDoTermo.value = ''

  const { tipo, valor } = classificarTermo(termo.value)
  if (valor === '') return false

  if (!termoConsultavel(termo.value)) {
    erroDoTermo.value = tipo === CPF
      ? 'Este CPF não é válido: o dígito verificador não confere. Confira o número com o tutor.'
      : 'Não foi possível reconhecer este termo.'

    return false
  }

  return true
}

function buscar() {
  if (termoValido()) carregar(termo.value)
}

/**
 * O termo que chega pelo endereço é o código que o leitor de QR Code entregou.
 * É consumido na montagem e retirado do endereço em seguida: a busca fora do
 * âmbito fica registrada (RF18b), e recarregar a página não pode repeti-la
 * sem que ninguém tenha pedido.
 */
function termoDoEndereco() {
  const lido = route.query.termo

  return typeof lido === 'string' ? lido.trim() : ''
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
  const lido = termoDoEndereco()

  if (lido) {
    termo.value = lido
    router.replace({ path: route.path, query: {} })
  }

  // Com o código lido pelo QR Code, a tela abre já no resultado — e o cursor
  // não vai ao campo, que já está preenchido com o que se procurava.
  if (lido && termoValido()) {
    await carregar(lido, { abrindo: true })

    return
  }

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

        <!-- O leitor de QR é da moldura do celular (§8.3). Leva a ação no
             endereço para que o código lido volte para esta tela, e não para a
             busca. -->
        <RouterLink
          :to="{ path: '/clinica/buscar/qr', query: { acao: route.params.acao } }"
          class="leitor-qr"
          aria-label="Ler QR Code do animal"
        >
          <QrCode :size="24" :stroke-width="1.75" />
        </RouterLink>
      </form>

      <!-- O âmbito dito antes de o profissional procurar: a busca por nome
           curta não é falha, é a carteira da clínica. -->
      <p id="ambito-da-escolha" class="escolha__ambito">
        Pelo nome, aparecem os animais que {{ prestador }} acompanha; pelo código, micro-chip
        ou CPF do tutor, qualquer animal do Imunia.
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
            :descricao="'Procure pelo nome, pelo código ou pelo micro-chip.'"
          />
        </template>

        <!-- Nada corresponde ao que se procurou. -->
        <EmptyState
          v-else-if="semResultado"
          :icone="PawPrint"
          titulo="Nenhum animal encontrado"
          :descricao="`Nenhum animal corresponde a “${termoRespondido}”. Pelo nome, a busca percorre só os animais que ${prestador} acompanha; pelo código ou pelo CPF do tutor, alcança qualquer cadastro do Imunia.`"
        >
          <RouterLink to="/clinica/animais/novo" class="botao botao--primario">
            Cadastrar animal
          </RouterLink>
          <RouterLink to="/clinica/buscar" class="botao botao--secundario">
            Buscar por CPF do tutor
          </RouterLink>
        </EmptyState>

        <template v-else>
          <!-- Resultado único: a escolha está feita, e a tela a confirma antes
               de abrir o formulário. -->
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
                <span v-if="!escolhido.vinculado" class="escolhido__vinculo">
                  ainda não acompanhado por {{ prestador }}
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

          <section v-else-if="encontrados.length" class="secao">
            <div class="secao__cabecalho">
              <h2 class="secao__rotulo">Encontrados</h2>
              <span class="secao__nota">{{ encontrados.length }} animais</span>
            </div>

            <div class="lista">
              <RouterLink
                v-for="animal in encontrados"
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

/* Discreto de propósito: não impede nada, só avisa que o registro põe o
   animal na carteira da clínica. */
.escolhido__vinculo {
  flex: none;
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-muted);
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
