<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ChevronDown, ChevronLeft, FileCheck, History, TriangleAlert, X } from '@lucide/vue'
import TutorShell from '@/components/tutor/TutorShell.vue'
import TimelineEntry from '@/components/tutor/TimelineEntry.vue'
import EmptyState from '@/components/base/EmptyState.vue'
import ExportarDocumentoModal from '@/components/tutor/ExportarDocumentoModal.vue'
import { apiGet } from '@/lib/api.js'
import { apenasAno, dataDeRegistro, emMesEAno } from '@/lib/datas.js'
import { procedenciaDe } from '@/lib/procedencia.js'

/**
 * T07 — histórico consolidado (RF35). Uma linha do tempo só, em ordem
 * cronológica, com tudo o que aconteceu com o animal, seja qual for o prestador
 * de origem: é a resposta ao problema P4, a fragmentação do histórico.
 *
 * Os filtros são do cliente. O servidor manda a linha do tempo inteira em uma
 * requisição e aqui ela é recortada sem ida à rede, porque filtrar é olhar de
 * novo para o que já se tem, não pedir outra coisa.
 */
const route = useRoute()

const historico = ref(null)
const carregando = ref(true)
const erro = ref('')

const busca = ref('')
const tiposEscolhidos = ref([])
const prestadoresEscolhidos = ref([])
const painelAberto = ref(false)

async function carregar() {
  carregando.value = true
  erro.value = ''

  try {
    historico.value = await apiGet(`/api/animais/${route.params.codigo}/historico`)
  } catch (excecao) {
    erro.value = excecao.message
  } finally {
    carregando.value = false
  }
}

onMounted(carregar)

watch(() => route.params.codigo, () => {
  limparFiltros()
  carregar()
})

const animal = computed(() => historico.value?.animal ?? null)

// T15 — exportação em PDF (RF46), modal sobre esta tela.
const exportarAberto = ref(false)
const resumo = computed(() => historico.value?.resumo ?? null)
const filtros = computed(() => historico.value?.filtros ?? { tipos: [], prestadores: [] })
const entradas = computed(() => historico.value?.entradas ?? [])

/** "5 registros · 2 prestadores · desde 2024" — a régua da tela, em uma linha. */
const linhaDeResumo = computed(() => {
  if (!resumo.value) return ''

  const partes = [`${resumo.value.total} ${resumo.value.total === 1 ? 'registro' : 'registros'}`]

  if (resumo.value.prestadores > 0) {
    partes.push(`${resumo.value.prestadores} ${resumo.value.prestadores === 1 ? 'prestador' : 'prestadores'}`)
  }

  if (resumo.value.desde) {
    partes.push(`desde ${dataDeRegistro(resumo.value.desde, resumo.value.desde_aproximada)}`)
  }

  return partes.join(' · ')
})

const termoBuscado = computed(() => busca.value.trim().toLowerCase())

const filtrosAtivos = computed(
  () => termoBuscado.value !== '' || tiposEscolhidos.value.length > 0 || prestadoresEscolhidos.value.length > 0
)

const entradasFiltradas = computed(() => entradas.value.filter((entrada) => {
  if (tiposEscolhidos.value.length && !tiposEscolhidos.value.includes(entrada.tipo)) return false
  if (prestadoresEscolhidos.value.length && !prestadoresEscolhidos.value.includes(entrada.prestador.chave)) return false
  if (!termoBuscado.value) return true

  // A procedência entra na busca: procurar pelo nome do veterinário ou da
  // clínica é a forma mais natural de reencontrar um registro.
  return [entrada.titulo, entrada.resumo, procedenciaDe(entrada).texto]
    .join(' ')
    .toLowerCase()
    .includes(termoBuscado.value)
}))

/**
 * A linha do tempo em itens: cada atendimento carrega consigo as retificações
 * que o corrigem (RF33), em vez de deixá-las soltas na cronologia. Uma correção
 * longe do registro corrigido pareceria um segundo atendimento — que é
 * exatamente o que ela não é.
 *
 * A retificação cujo registro original não está à vista — porque um filtro o
 * escondeu — volta a ser item próprio, na posição cronológica dela: sumir seria
 * pior do que aparecer sozinha.
 */
const itens = computed(() => {
  const lista = entradasFiltradas.value.map((entrada) => ({ entrada, vinculadas: [] }))

  const porAtendimento = new Map(
    lista
      .filter(({ entrada }) => entrada.tipo === 'atendimento')
      .map((item) => [item.entrada.id, item])
  )

  return lista
    .filter((item) => {
      const original = item.entrada.vinculada_a ? porAtendimento.get(item.entrada.vinculada_a) : null

      if (!original) return true

      original.vinculadas.push(item.entrada)

      return false
    })
    .map((item) => ({
      entrada: item.entrada,
      destino: destinoDe(item.entrada),
      vinculadas: item.vinculadas.map((entrada) => ({ entrada, destino: destinoDe(entrada) })),
    }))
})

/**
 * Agrupamento por mês, com cabeçalho fixo durante a rolagem. A entrada de data
 * aproximada é agrupada pelo ano, não pelo mês: o mês seria uma precisão que o
 * registro não tem (RN25) — e ela continua no lugar certo da cronologia, que é
 * o que RF35 pede.
 *
 * O histórico pregresso pode não ter data alguma (RF29). Essas entradas formam
 * o último período, com cabeçalho próprio: não há onde encaixá-las na linha do
 * tempo, e escondê-las seria pior — aconteceram.
 */
const periodos = computed(() => {
  const grupos = new Map()

  for (const item of itens.value) {
    const { entrada } = item
    const semData = !entrada.data

    const chave = semData
      ? 'sem-data'
      : (entrada.data_aproximada ? apenasAno(entrada.data) : entrada.data.slice(0, 7))

    if (!grupos.has(chave)) {
      grupos.set(chave, {
        chave,
        rotulo: semData
          ? 'Sem data informada'
          : (entrada.data_aproximada ? apenasAno(entrada.data) : emMesEAno(entrada.data)),
        itens: [],
      })
    }

    grupos.get(chave).itens.push(item)
  }

  return [...grupos.values()]
})

const contador = computed(
  () => `${entradasFiltradas.value.length} de ${entradas.value.length} ${entradas.value.length === 1 ? 'registro' : 'registros'}`
)

/** A explicação do vazio por filtro diz o que foi procurado, e onde. */
const descricaoDoVazioFiltrado = computed(() => {
  const total = `${animal.value.nome} tem ${entradas.value.length} ${entradas.value.length === 1 ? 'registro' : 'registros'} no histórico, mas nenhum`
  const temEscolhas = tiposEscolhidos.value.length > 0 || prestadoresEscolhidos.value.length > 0

  if (termoBuscado.value && temEscolhas) {
    return `${total} corresponde à busca "${busca.value.trim()}" com os filtros aplicados.`
  }

  if (termoBuscado.value) {
    return `${total} corresponde à busca "${busca.value.trim()}".`
  }

  return `${total} corresponde aos filtros aplicados.`
})

/** Os filtros escolhidos, para poderem ser desfeitos um a um no celular. */
const escolhasVisiveis = computed(() => [
  ...filtros.value.tipos
    .filter((tipo) => tiposEscolhidos.value.includes(tipo.chave))
    .map((tipo) => ({ alvo: 'tipo', chave: tipo.chave, rotulo: tipo.rotulo })),
  ...filtros.value.prestadores
    .filter((prestador) => prestadoresEscolhidos.value.includes(prestador.chave))
    .map((prestador) => ({ alvo: 'prestador', chave: prestador.chave, rotulo: prestador.rotulo })),
])

function alternado(lista, chave) {
  return lista.includes(chave) ? lista.filter((item) => item !== chave) : [...lista, chave]
}

function alternarTipo(chave) {
  tiposEscolhidos.value = alternado(tiposEscolhidos.value, chave)
}

function alternarPrestador(chave) {
  prestadoresEscolhidos.value = alternado(prestadoresEscolhidos.value, chave)
}

function removerEscolha(escolha) {
  if (escolha.alvo === 'tipo') {
    alternarTipo(escolha.chave)

    return
  }

  alternarPrestador(escolha.chave)
}

function limparFiltros() {
  busca.value = ''
  tiposEscolhidos.value = []
  prestadoresEscolhidos.value = []
  painelAberto.value = false
}

// Cada registro abre a tela dele — vacinação em T06, atendimento em T08. O
// destino sai de `registro`, e não de `tipo`, porque a retificação de um
// atendimento é um atendimento: aparece na linha do tempo como correção, mas
// abre onde o registro que ela corrige abre (RF33). Os caminhos dos registros
// que ainda não têm tela chegam com a fatia que os criar.
const CAMINHO_POR_REGISTRO = {
  vacinacao: 'vacinas',
  atendimento: 'atendimentos',
  exame: 'exames',
  obito: 'obitos',
}

function destinoDe(entrada) {
  return `/animais/${route.params.codigo}/${CAMINHO_POR_REGISTRO[entrada.registro]}/${entrada.id}`
}
</script>

<template>
  <!--
    `amplo` mais a coluna centrada abaixo, como em T01: o limite de 880 px da
    moldura, alinhado à esquerda, deixaria o conteúdo fora do centro da área de
    leitura em telas largas.
  -->
  <TutorShell amplo>
    <div class="historico">
      <RouterLink :to="`/animais/${route.params.codigo}`" class="historico__voltar">
        <ChevronLeft :size="20" :stroke-width="1.75" />
        Histórico de {{ animal?.nome ?? '' }}
      </RouterLink>

      <!-- Carregando: esqueleto na forma exata do conteúdo que substitui. -->
      <div v-if="carregando" class="historico__conteudo" aria-busy="true" aria-live="polite">
        <span class="visually-hidden">Carregando o histórico do animal.</span>
        <div class="esqueleto esqueleto--titulo" />
        <div class="esqueleto esqueleto--linha" />
        <div class="esqueleto esqueleto--tarja" />
        <div class="esqueleto esqueleto--bloco" />
        <div class="esqueleto esqueleto--bloco" />
      </div>

      <div v-else-if="erro" class="aviso aviso--erro" role="alert">
        <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
        <div>
          <p class="aviso__titulo">Não conseguimos carregar este histórico.</p>
          <p class="aviso__texto">{{ erro }}</p>
          <button type="button" class="botao botao--secundario" @click="carregar">
            Tentar novamente
          </button>
        </div>
      </div>

      <div v-else class="historico__conteudo">
        <div class="historico__cabecalho">
          <div>
            <p class="historico__sobrelinha">Histórico consolidado</p>
            <h1 class="historico__nome">{{ animal.nome }}</h1>
            <p class="historico__resumo">{{ linhaDeResumo }}</p>
          </div>
          <button type="button" class="botao botao--secundario" @click="exportarAberto = true">
            <FileCheck :size="20" :stroke-width="1.75" class="historico__icone-consent" />
            Exportar PDF do histórico
          </button>
        </div>

        <EmptyState
          v-if="entradas.length === 0"
          :icone="History"
          titulo="Nenhum registro no histórico ainda"
          descricao="Vacinas e atendimentos aparecem aqui na ordem em que aconteceram, com o nome de quem os registrou."
        >
          <div class="historico__acoes-vazio">
            <RouterLink :to="`/animais/${animal.codigo}/pregresso/novo`" class="botao botao--primario">
              Lançar histórico pregresso
            </RouterLink>
            <RouterLink to="/prestadores" class="botao botao--secundario">
              Encontrar uma clínica
            </RouterLink>
          </div>
        </EmptyState>

        <template v-else>
          <div class="filtros">
            <label class="filtros__busca">
              <History :size="20" :stroke-width="1.75" class="filtros__busca-icone" />
              <span class="visually-hidden">Buscar no histórico</span>
              <input v-model="busca" type="search" placeholder="Buscar no histórico" class="filtros__campo" />
            </label>

            <button
              type="button"
              class="filtros__gatilho"
              :aria-expanded="painelAberto"
              aria-controls="filtros-painel"
              @click="painelAberto = !painelAberto"
            >
              Filtrar por tipo e prestador
              <ChevronDown :size="20" :stroke-width="1.75" class="filtros__gatilho-icone" />
            </button>

            <!-- No celular o painel é folha inferior; a partir de md as pílulas
                 ficam à vista, e o gatilho desaparece. -->
            <div
              v-if="painelAberto"
              class="filtros__cortina"
              aria-hidden="true"
              @click="painelAberto = false"
            />
            <div
              id="filtros-painel"
              class="filtros__painel"
              :class="{ 'filtros__painel--aberto': painelAberto }"
              role="group"
              aria-label="Filtros do histórico"
            >
              <div class="filtros__folha-cabecalho">
                <span class="filtros__folha-titulo">Filtrar</span>
                <button type="button" class="filtros__fechar" @click="painelAberto = false">
                  <X :size="20" :stroke-width="1.75" />
                  <span class="visually-hidden">Fechar os filtros</span>
                </button>
              </div>

              <fieldset v-if="filtros.tipos.length" class="filtros__grupo">
                <legend class="filtros__legenda">Tipo</legend>
                <div class="filtros__pilulas">
                  <button
                    v-for="tipo in filtros.tipos"
                    :key="tipo.chave"
                    type="button"
                    class="pilula"
                    :class="{ 'pilula--ativa': tiposEscolhidos.includes(tipo.chave) }"
                    :aria-pressed="tiposEscolhidos.includes(tipo.chave)"
                    @click="alternarTipo(tipo.chave)"
                  >
                    {{ tipo.rotulo }} ({{ tipo.total }})
                  </button>
                </div>
              </fieldset>

              <fieldset v-if="filtros.prestadores.length" class="filtros__grupo">
                <legend class="filtros__legenda">Prestador</legend>
                <div class="filtros__pilulas">
                  <button
                    v-for="prestador in filtros.prestadores"
                    :key="prestador.chave"
                    type="button"
                    class="pilula"
                    :class="{ 'pilula--ativa': prestadoresEscolhidos.includes(prestador.chave) }"
                    :aria-pressed="prestadoresEscolhidos.includes(prestador.chave)"
                    @click="alternarPrestador(prestador.chave)"
                  >
                    {{ prestador.rotulo }} ({{ prestador.total }})
                  </button>
                </div>
              </fieldset>
            </div>

            <div v-if="escolhasVisiveis.length" class="filtros__escolhas">
              <button
                v-for="escolha in escolhasVisiveis"
                :key="escolha.chave"
                type="button"
                class="pilula pilula--ativa"
                @click="removerEscolha(escolha)"
              >
                {{ escolha.rotulo }}
                <X :size="14" :stroke-width="2" />
                <span class="visually-hidden">Remover este filtro</span>
              </button>
            </div>

            <p class="filtros__contador" aria-live="polite">{{ contador }}</p>
          </div>

          <EmptyState
            v-if="entradasFiltradas.length === 0"
            :icone="History"
            titulo="Nenhum registro com esses filtros"
            :descricao="descricaoDoVazioFiltrado"
          >
            <button type="button" class="botao botao--primario" @click="limparFiltros">
              Limpar filtros
            </button>
          </EmptyState>

          <template v-else>
            <section v-for="periodo in periodos" :key="periodo.chave" class="periodo">
              <h2 class="periodo__rotulo">{{ periodo.rotulo }}</h2>
              <ol class="trilho">
                <TimelineEntry
                  v-for="item in periodo.itens"
                  :key="`${item.entrada.tipo}-${item.entrada.id}`"
                  :entrada="item.entrada"
                  :destino="item.destino"
                  :vinculadas="item.vinculadas"
                />
              </ol>
            </section>
          </template>
        </template>
      </div>
    </div>
    <!-- T15 — exportar em PDF verificável (RF46). -->
    <ExportarDocumentoModal
      v-if="exportarAberto && animal"
      :animal="animal"
      @fechar="exportarAberto = false"
    />
  </TutorShell>
</template>

<style scoped>
.historico {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  width: 100%;
  max-width: 880px;
  margin: 0 auto;
}

/* Alvo de 44 px sem deslocar o texto: o briefing fixa esse mínimo abaixo de
   1024 px, e um link de voltar de 24 px de altura é justamente onde o dedo
   erra. O par padding/margin negativo amplia a área tocável e mantém o
   espaçamento visual das demais telas do tutor. */
.historico__voltar {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  padding: 10px 0;
  margin: -10px 0;
  color: var(--ink-muted);
  font-size: 14px;
  font-weight: 600;
}

.historico__voltar:hover {
  color: var(--ink);
}

.historico__conteudo {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.historico__cabecalho {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: var(--space-4);
  flex-wrap: wrap;
}

.historico__sobrelinha {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--brand-bright);
}

.historico__nome {
  margin: var(--space-1) 0 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  color: var(--ink);
}

.historico__resumo {
  margin: var(--space-1) 0 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
  font-variant-numeric: tabular-nums;
}

.historico__icone-consent {
  color: var(--consent);
}

.historico__acoes-vazio {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

/* Filtros ------------------------------------------------------------------ */

.filtros {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  padding: var(--space-3);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

.filtros__busca {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  height: 48px;
  padding: 0 var(--space-3);
  background: var(--surface-sunken);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
}

.filtros__busca:focus-within {
  border-color: var(--brand-bright);
}

.filtros__busca-icone {
  flex: none;
  color: var(--ink-muted);
}

.filtros__campo {
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

.filtros__gatilho {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-2);
  height: 48px;
  padding: 0 var(--space-3);
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  font-family: var(--font-body);
  font-size: 16px;
  font-weight: 600;
  color: var(--ink);
  cursor: pointer;
}

.filtros__gatilho-icone {
  flex: none;
  color: var(--ink-muted);
}

.filtros__cortina {
  position: fixed;
  inset: 0;
  z-index: 20;
  background: rgb(20 35 31 / .32);
}

/* Abaixo de md, o painel é folha inferior: sobe sobre a barra de abas, que é
   fixa, e por isso precisa vencê-la no empilhamento. */
.filtros__painel {
  position: fixed;
  inset: auto 0 0;
  z-index: 21;
  display: none;
  flex-direction: column;
  gap: var(--space-4);
  padding: var(--space-4);
  background: var(--surface-card);
  border-top: 1px solid var(--border-hairline);
  border-radius: var(--radius-lg) var(--radius-lg) 0 0;
  box-shadow: var(--shadow-modal);
}

.filtros__painel--aberto {
  display: flex;
}

.filtros__folha-cabecalho {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
}

.filtros__folha-titulo {
  font-family: var(--font-display);
  font-size: 22px;
  line-height: 28px;
  font-weight: 600;
  color: var(--ink);
}

.filtros__fechar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  margin-right: calc(var(--space-3) * -1);
  background: none;
  border: none;
  color: var(--ink-muted);
  cursor: pointer;
}

.filtros__grupo {
  margin: 0;
  padding: 0;
  border: none;
}

.filtros__legenda {
  padding: 0;
  margin: 0 0 var(--space-2);
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

.filtros__pilulas,
.filtros__escolhas {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
}

.filtros__contador {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
  font-variant-numeric: tabular-nums;
}

.pilula {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  height: 48px;
  padding: 0 var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-pill);
  font-family: var(--font-body);
  font-size: 16px;
  font-weight: 600;
  color: var(--ink-muted);
  cursor: pointer;
}

.pilula:hover {
  color: var(--ink);
}

.pilula--ativa {
  background: var(--brand-wash);
  border-color: var(--brand);
  color: var(--brand);
}

/* Linha do tempo ----------------------------------------------------------- */

.periodo {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}

.periodo__rotulo {
  position: sticky;
  top: 0;
  z-index: 1;
  margin: 0;
  padding: var(--space-2) 0;
  background: var(--surface-page);
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

.trilho {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
  margin: 0;
  padding: 0 0 0 4px;
  list-style: none;
}

/* A linha corre por trás dos marcadores, que a recortam com o próprio halo. */
.trilho::before {
  content: '';
  position: absolute;
  left: 15px;
  top: 12px;
  bottom: 12px;
  width: 2px;
  background: var(--border-hairline);
}

/* Botões e avisos — mesmo vocabulário das demais telas do tutor. ---------- */

.botao {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-2);
  height: 48px;
  padding: 0 var(--space-6);
  border-radius: var(--radius-sm);
  font-family: var(--font-body);
  font-size: 16px;
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
}

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
  margin: var(--space-4) 0 0;
  width: auto;
}

/* Esqueleto de carregamento ------------------------------------------------ */

.esqueleto {
  border-radius: var(--radius-xs);
  background: var(--surface-sunken);
  animation: pulsar 1.2s ease-in-out infinite;
}

.esqueleto--titulo {
  height: 34px;
  width: 55%;
}

.esqueleto--linha {
  height: 20px;
  width: 70%;
}

.esqueleto--tarja {
  height: 46px;
  border-radius: var(--radius-md);
}

.esqueleto--bloco {
  height: 120px;
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

/* Larguras derivadas -------------------------------------------------------- */

@media (min-width: 768px) {
  .historico__cabecalho .botao {
    width: auto;
  }

  .historico__acoes-vazio {
    flex-direction: row;
    justify-content: center;
  }

  .historico__acoes-vazio .botao {
    width: auto;
  }

  .filtros {
    flex-direction: row;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--space-3);
  }

  .filtros__busca {
    flex: 1;
    min-width: 200px;
  }

  /* O gatilho e a folha existem só no celular: aqui as pílulas ficam à vista,
     e o que as escondia sairia do caminho. */
  .filtros__gatilho,
  .filtros__cortina,
  .filtros__folha-cabecalho,
  .filtros__escolhas {
    display: none;
  }

  .filtros__painel {
    position: static;
    z-index: auto;
    display: flex;
    flex-direction: row;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--space-4);
    padding: 0;
    border: none;
    border-radius: 0;
    background: none;
    box-shadow: none;
  }

  .filtros__legenda {
    margin: 0 0 var(--space-1);
  }

  .pilula {
    height: 40px;
    padding: 0 var(--space-3);
    font-size: 14px;
  }
}

@media (min-width: 1024px) {
  .historico__nome {
    font-size: 36px;
    line-height: 40px;
    letter-spacing: -.02em;
  }
}
</style>
