<script setup>
import { computed, onMounted, ref } from 'vue'
import {
  Cat,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  Dog,
  KeyRound,
  PawPrint,
  TriangleAlert,
  X,
} from '@lucide/vue'
import VetShell from '@/components/vet/VetShell.vue'
import AvisoOutroContexto from '@/components/vet/AvisoOutroContexto.vue'
import StatusPill from '@/components/base/StatusPill.vue'
import EmptyState from '@/components/base/EmptyState.vue'
import { apiGet } from '@/lib/api.js'
import { descreverIdade } from '@/lib/animais.js'
import { emNumeros } from '@/lib/datas.js'

/**
 * Relação de animais da clínica — o destino "Animais" da barra lateral (§5.3).
 * A lista de navegação do plantel: quem está sob os cuidados do prestador
 * ativo, em que situação vacinal, quando passou por aqui e até quando a
 * autorização vale.
 *
 * Não é busca — achar um animal determinado é papel de V03, sempre à mão no
 * cabeçalho da moldura. Daí a ordem alfabética e a ausência de campo de texto:
 * lista de navegação se percorre como catálogo, e a urgência já tem tela
 * própria em V02.
 *
 * O âmbito é o de V01 e V02, e não é escolha desta tela: prestador ativo e
 * autorização vigente (RN48). Quando não há autorização alguma, a tela explica
 * a regra em vez de exibir uma lista vazia que pareceria defeito.
 */
const consulta = ref(null)
const carregando = ref(true)
const atualizando = ref(false)
const erro = ref('')

const filtros = ref({ especie: null, situacao: null })
const pagina = ref(1)

const itens = computed(() => consulta.value?.itens ?? [])
const opcoes = computed(() => consulta.value?.filtros.opcoes ?? null)
const paginacao = computed(() => consulta.value?.paginacao ?? null)

const filtrosAtivos = computed(() =>
  ['especie', 'situacao'].filter((chave) => filtros.value[chave] !== null),
)

const temFiltroAtivo = computed(() => filtrosAtivos.value.length > 0)

/**
 * O único vazio possível com autorização vigente é o dos filtros: sem filtro,
 * o plantel é a própria lista — e o plantel vazio é o estado
 * `sem_autorizacoes`, que tem tela própria.
 */
const filtradoSemResultado = computed(
  () => consulta.value?.total === 0 && consulta.value?.total_sem_filtros > 0,
)

function rotuloDaOpcao(chave, valor) {
  const lista = { especie: 'especies', situacao: 'situacoes' }[chave]

  return opcoes.value?.[lista].find((opcao) => opcao.valor === valor)?.rotulo ?? valor
}

function parametros() {
  const busca = new URLSearchParams({ pagina: pagina.value })

  for (const chave of filtrosAtivos.value) busca.set(chave, filtros.value[chave])
  if (consulta.value?.prestador) busca.set('prestador', consulta.value.prestador.id)

  return busca
}

/**
 * RNF03 — os mesmos dois estados de carregamento de V02: o primeiro, que ainda
 * não tem tela, e o de atualização, que conserva a barra de filtros operável
 * enquanto a lista se refaz.
 */
async function carregar({ primeiraVez = false } = {}) {
  if (primeiraVez) carregando.value = true
  else atualizando.value = true

  erro.value = ''

  try {
    consulta.value = await apiGet(`/api/clinica/animais?${parametros()}`)
  } catch (excecao) {
    erro.value = excecao.message
  } finally {
    carregando.value = false
    atualizando.value = false
  }
}

onMounted(() => carregar({ primeiraVez: true }))

/** Trocar qualquer filtro recomeça a paginação: outra pergunta, outra lista. */
function aplicar(chave, valor) {
  filtros.value[chave] = valor === '' ? null : valor
  pagina.value = 1
  carregar()
}

function limparFiltros() {
  filtros.value.especie = null
  filtros.value.situacao = null
  pagina.value = 1
  carregar()
}

function irParaPagina(destino) {
  pagina.value = destino
  carregar()
}

function trocarPrestador(id) {
  consulta.value = { ...consulta.value, prestador: { ...consulta.value.prestador, id } }
  pagina.value = 1
  filtros.value.especie = null
  filtros.value.situacao = null
  carregar({ primeiraVez: true })
}

function iconeDaEspecie(especie) {
  return especie === 'gato' ? Cat : Dog
}

/**
 * RN39 — a coluna anuncia o vencimento com a mesma antecedência da ficha e de
 * T12. O texto sai daqui, e não do servidor, porque é apresentação da mesma
 * contagem que `dias_restantes` já traz.
 */
function vencimento(autorizacao) {
  if (!autorizacao.a_expirar) return `até ${emNumeros(autorizacao.expira_em)}`
  if (autorizacao.dias_restantes === 0) return 'expira hoje'
  if (autorizacao.dias_restantes === 1) return 'expira amanhã'

  return `expira em ${autorizacao.dias_restantes} dias`
}
</script>

<template>
  <VetShell
    titulo="Animais"
    :prestador="consulta?.prestador"
    :vinculos="consulta?.vinculos ?? []"
    @trocar-prestador="trocarPrestador"
  >
    <!-- Carregando pela primeira vez: esqueleto na forma do conteúdo (§5.1). -->
    <div v-if="carregando" class="animais" aria-busy="true" aria-live="polite">
      <span class="visually-hidden">Carregando a relação de animais.</span>
      <div class="esqueleto esqueleto--titulo" />
      <div class="cartao cartao--liso">
        <div class="esqueleto-cabecalho"><div class="esqueleto esqueleto--rotulo" /></div>
        <div v-for="linha in 6" :key="linha" class="esqueleto-linha">
          <div class="esqueleto esqueleto--celula" />
          <div class="esqueleto esqueleto--celula esqueleto--curta" />
          <div class="esqueleto esqueleto--celula esqueleto--curta" />
          <div class="esqueleto esqueleto--pilula" />
        </div>
      </div>
    </div>

    <div v-else-if="erro" class="animais">
      <div class="aviso aviso--erro" role="alert">
        <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
        <div>
          <p class="aviso__titulo">Não conseguimos carregar a relação de animais.</p>
          <p class="aviso__texto">{{ erro }}</p>
          <button type="button" class="botao botao--secundario" @click="carregar({ primeiraVez: true })">
            Tentar novamente
          </button>
        </div>
      </div>
    </div>

    <!-- RN48 — a lista vazia por falta de autorização precisa dizer que está
         funcionando. Sem a explicação, a regra parece defeito. -->
    <div v-else-if="consulta.estado === 'sem_autorizacoes'" class="animais animais--estreito">
      <div class="consentimento">
        <div class="consentimento__topo">
          <KeyRound :size="24" :stroke-width="1.75" class="consentimento__icone" />
          <h1 class="consentimento__titulo">Nenhuma autorização vigente neste prestador</h1>
        </div>
        <p class="consentimento__texto">
          A relação abrange somente animais sob autorização vigente para
          {{ consulta.prestador.nome }}. Sem nenhuma, não há animal a listar — e isso
          não é uma falha do sistema.
        </p>
        <div class="consentimento__acoes">
          <RouterLink to="/clinica/buscar" class="botao botao--consentimento">
            <KeyRound :size="16" :stroke-width="1.75" />
            Buscar e solicitar autorização
          </RouterLink>
        </div>
      </div>

      <AvisoOutroContexto
        :prestador="consulta.prestador"
        :vinculos="consulta.vinculos ?? []"
        @trocar="trocarPrestador"
      />
    </div>

    <div v-else class="animais">
      <div class="animais__cabecalho">
        <div>
          <p class="animais__sobrelinha">Plantel</p>
          <h1 class="animais__titulo">Animais</h1>
        </div>
      </div>

      <!-- Filtros combináveis, refletidos em pílulas (§8.3), operáveis durante
           a atualização — o mesmo desenho de V02. -->
      <div class="filtros">
        <div
          v-for="filtro in [
            { chave: 'especie', rotulo: 'Espécie', lista: 'especies' },
            { chave: 'situacao', rotulo: 'Situação vacinal', lista: 'situacoes' },
          ]"
          :key="filtro.chave"
          class="filtro"
          :class="{ 'filtro--ativo': filtros[filtro.chave] !== null }"
        >
          <label class="filtro__rotulo">
            <span class="visually-hidden">{{ filtro.rotulo }}</span>
            <select
              class="filtro__campo"
              :value="filtros[filtro.chave] ?? ''"
              @change="aplicar(filtro.chave, $event.target.value)"
            >
              <option value="">{{ filtro.rotulo }}</option>
              <option v-for="opcao in opcoes[filtro.lista]" :key="opcao.valor" :value="opcao.valor">
                {{ opcao.rotulo }}
              </option>
            </select>
          </label>

          <button
            v-if="filtros[filtro.chave] !== null"
            type="button"
            class="filtro__limpar"
            :aria-label="`Remover o filtro ${rotuloDaOpcao(filtro.chave, filtros[filtro.chave])}`"
            @click="aplicar(filtro.chave, '')"
          >
            <X :size="14" :stroke-width="1.75" />
          </button>
          <ChevronDown v-else :size="16" :stroke-width="1.75" class="filtro__seta" />
        </div>

        <!-- §4.6 — contador é região dinâmica e se anuncia. -->
        <p class="filtros__contagem" aria-live="polite">
          <span v-if="atualizando">Atualizando a lista…</span>
          <span v-else-if="temFiltroAtivo">
            {{ consulta.total }} de {{ consulta.total_sem_filtros }} animais
          </span>
          <span v-else>
            {{ consulta.total }} {{ consulta.total === 1 ? 'animal' : 'animais' }}
          </span>
        </p>

        <button v-if="temFiltroAtivo" type="button" class="filtros__limpar" @click="limparFiltros">
          Limpar filtros
        </button>
      </div>

      <EmptyState
        v-if="filtradoSemResultado"
        :icone="PawPrint"
        titulo="Nenhum animal com esses filtros"
        descricao="Há animais sob autorização vigente, mas nenhum corresponde à combinação escolhida. Remova um dos filtros para voltar a vê-los."
      >
        <button type="button" class="botao botao--primario" @click="limparFiltros">
          Limpar filtros
        </button>
      </EmptyState>

      <div v-else class="cartao cartao--liso" :aria-busy="atualizando">
        <table class="tabela">
          <thead>
            <tr>
              <th scope="col" aria-sort="ascending">Animal ↑</th>
              <th scope="col">Tutor</th>
              <th scope="col">Idade</th>
              <th scope="col">Situação vacinal</th>
              <th scope="col">Última passagem</th>
              <th scope="col">Autorização</th>
              <th scope="col" class="tabela__acao">Ação</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in itens" :key="item.codigo">
              <td data-rotulo="Animal">
                <span class="tabela__animal">
                  <component
                    :is="iconeDaEspecie(item.especie)"
                    :size="16"
                    :stroke-width="1.75"
                    class="tabela__icone"
                  />
                  {{ item.nome }}
                  <!-- RF16d — a condição preliminar é visível em toda tela. -->
                  <span v-if="item.preliminar" class="marcador">preliminar</span>
                </span>
              </td>
              <td data-rotulo="Tutor" class="tabela__discreto">{{ item.tutor }}</td>
              <td data-rotulo="Idade" class="tabela__discreto">
                {{ descreverIdade(item.idade_em_meses, item.nascimento_exato) ?? 'não informada' }}
              </td>
              <td data-rotulo="Situação vacinal">
                <StatusPill
                  v-if="item.situacao"
                  :tipo="item.situacao.tipo"
                  :texto="item.situacao.texto_curto"
                />
                <!-- RF22a — registrado o óbito, cessa o calendário; RF50 — a
                     carteira vazia é "o sistema ainda não sabe", nunca "em
                     dia" por omissão. -->
                <span v-else-if="item.obito" class="tabela__nunca">óbito registrado</span>
                <span v-else class="tabela__nunca">sem registro</span>
              </td>
              <td data-rotulo="Última passagem" class="tabela__numeros">
                <span v-if="item.ultima_passagem">{{ emNumeros(item.ultima_passagem) }}</span>
                <span v-else class="tabela__nunca">nenhum registro aqui</span>
              </td>
              <td data-rotulo="Autorização" class="tabela__numeros">
                <span :class="{ 'tabela__a-expirar': item.autorizacao.a_expirar }">
                  {{ vencimento(item.autorizacao) }}
                </span>
              </td>
              <td data-rotulo="Ação" class="tabela__acao">
                <RouterLink :to="`/clinica/animais/${item.codigo}`" class="tabela__ligacao">
                  <span class="tabela__ligacao-rotulo">Abrir ficha</span>
                  <span class="visually-hidden">Abrir a ficha de {{ item.nome }}</span>
                </RouterLink>
              </td>
            </tr>
          </tbody>
        </table>

        <div class="rodape">
          <p class="rodape__contagem">
            Exibindo {{ paginacao.de }}–{{ paginacao.ate }} de {{ paginacao.total }}
          </p>

          <div v-if="paginacao.paginas > 1" class="rodape__controles">
            <button
              type="button"
              class="rodape__botao"
              aria-label="Página anterior"
              :disabled="paginacao.pagina === 1"
              @click="irParaPagina(paginacao.pagina - 1)"
            >
              <ChevronLeft :size="16" :stroke-width="1.75" />
            </button>
            <span class="rodape__posicao">{{ paginacao.pagina }} / {{ paginacao.paginas }}</span>
            <button
              type="button"
              class="rodape__botao"
              aria-label="Próxima página"
              :disabled="paginacao.pagina === paginacao.paginas"
              @click="irParaPagina(paginacao.pagina + 1)"
            >
              <ChevronRight :size="16" :stroke-width="1.75" />
            </button>
          </div>

          <p v-else class="rodape__nota">Ordenado por nome</p>
        </div>
      </div>
    </div>
  </VetShell>
</template>

<style scoped>
.animais {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  width: 100%;
  max-width: 1280px;
  margin: 0 auto;
}

.animais--estreito {
  max-width: 880px;
}

.animais__cabecalho {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: var(--space-6);
  flex-wrap: wrap;
}

.animais__sobrelinha {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--brand);
}

.animais__titulo {
  margin: var(--space-1) 0 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  color: var(--ink);
}

/* Barra de filtros --------------------------------------------------------- */

.filtros {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  flex-wrap: wrap;
  padding: var(--space-3);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-sm);
}

/* A pílula é o invólucro; o <select> dentro dela é o que responde ao teclado. */
.filtro {
  position: relative;
  display: inline-flex;
  align-items: center;
  height: 32px;
  padding: 0 var(--space-3);
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-pill);
  font-size: 14px;
  font-weight: 600;
  color: var(--ink-muted);
}

.filtro:hover {
  border-color: var(--brand-bright);
  color: var(--ink);
}

.filtro--ativo {
  background: var(--brand-wash);
  border-color: var(--brand);
  color: var(--brand);
}

.filtro__rotulo {
  display: inline-flex;
  align-items: center;
}

.filtro__campo {
  appearance: none;
  max-width: 220px;
  padding: 0 var(--space-1) 0 0;
  background: none;
  border: 0;
  font-family: inherit;
  font-size: inherit;
  font-weight: inherit;
  color: inherit;
  cursor: pointer;
}

.filtro__seta {
  flex: none;
  pointer-events: none;
}

.filtro__limpar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: none;
  width: 18px;
  height: 18px;
  margin-left: var(--space-1);
  padding: 0;
  background: none;
  border: 0;
  border-radius: var(--radius-pill);
  color: inherit;
  cursor: pointer;
}

.filtro__limpar:hover {
  background: var(--surface-card);
}

.filtros__contagem {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
  font-variant-numeric: tabular-nums;
}

.filtros__limpar {
  padding: 0;
  background: none;
  border: 0;
  font-family: inherit;
  font-size: 14px;
  line-height: 20px;
  font-weight: 600;
  color: var(--brand-bright);
  cursor: pointer;
}

.filtros__limpar:hover {
  color: var(--brand-hover);
}

/* Cartões ------------------------------------------------------------------ */

.cartao {
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

.cartao--liso {
  padding: 0;
  overflow: hidden;
  border-radius: var(--radius-sm);
}

/* Tabela — vira lista de cartões abaixo de 768 px (§5.1). ------------------- */

.tabela {
  width: 100%;
  border-collapse: collapse;
}

.tabela thead {
  display: none;
}

/* Abaixo de 768 px a linha é um cartão que promove o que decide a leitura: o
   animal, a etiqueta de situação, e a linha de tutor e idade. */
.tabela tr {
  position: relative;
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  row-gap: var(--space-1);
  padding: var(--space-4);
  border-top: 1px solid var(--border-hairline);
}

.tabela tbody tr:first-child {
  border-top: 0;
}

.tabela td {
  display: block;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

/* Linha 1: o nome, com espaço reservado à etiqueta que flutua à direita. */
.tabela td[data-rotulo='Animal'] {
  order: 1;
  flex: 1 1 100%;
  padding-right: 120px;
  font-size: 16px;
  line-height: 24px;
  font-weight: 600;
}

.tabela td[data-rotulo='Situação vacinal'] {
  order: 2;
  position: absolute;
  top: var(--space-4);
  right: var(--space-4);
}

/* Linha 2: tutor · idade, separados por ponto médio. */
.tabela td[data-rotulo='Tutor'] {
  order: 3;
}

.tabela td[data-rotulo='Idade'] {
  order: 4;
}

.tabela td[data-rotulo='Tutor'],
.tabela td[data-rotulo='Idade'] {
  flex: 0 0 auto;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
}

.tabela td[data-rotulo='Tutor']::after {
  content: '·';
  margin: 0 6px;
  color: var(--ink-muted);
}

/* Linhas 3 e 4: a última passagem e o vencimento, com os rótulos que o
   conteúdo sozinho não daria — "12/08/2026" não diz de que data se trata. */
.tabela td[data-rotulo='Última passagem'] {
  order: 5;
  flex: 1 1 100%;
  color: var(--ink-muted);
}

.tabela td[data-rotulo='Autorização'] {
  order: 6;
  flex: 1 1 100%;
  color: var(--ink-muted);
}

.tabela td::before {
  content: attr(data-rotulo) ': ';
  color: var(--ink-muted);
}

.tabela td[data-rotulo='Animal']::before,
.tabela td[data-rotulo='Tutor']::before,
.tabela td[data-rotulo='Idade']::before,
.tabela td[data-rotulo='Situação vacinal']::before,
.tabela td[data-rotulo='Ação']::before {
  display: none;
}

/* O cartão inteiro é o alvo, e o rótulo da ação não gasta uma linha para isso. */
.tabela td[data-rotulo='Ação'] {
  position: absolute;
  inset: 0;
  padding: 0;
}

.tabela__ligacao-rotulo {
  display: none;
}

.tabela__animal {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
}

.tabela__icone {
  flex: none;
  color: var(--ink-muted);
}

/* RF16d — o cadastro preliminar se anuncia com o mesmo tracejado da
   informação não verificada: é o vocabulário do sistema para "isto ainda
   depende de um veterinário". */
.marcador {
  display: inline-flex;
  align-items: center;
  height: 20px;
  padding: 0 8px;
  border: 1px dashed var(--unverified);
  border-radius: var(--radius-pill);
  font-size: 12px;
  line-height: 16px;
  font-weight: 600;
  color: var(--ink-muted);
  white-space: nowrap;
}

.tabela__discreto {
  color: var(--ink-muted);
}

.tabela__numeros {
  font-variant-numeric: tabular-nums;
}

/* Ausência é informação, não decoração: `--ink-muted`, que passa no contraste
   (§4.1), nunca `--ink-faint` (decisoes.md §9.1). */
.tabela__nunca {
  color: var(--ink-muted);
}

/* RN39 — o âmbar do aviso de expiração, na variante de texto que passa em
   contraste, como na coluna de atraso de V02. */
.tabela__a-expirar {
  font-weight: 600;
  color: var(--status-due-text);
}

.tabela__ligacao {
  font-weight: 600;
}

/* A linha inteira é o alvo, sem que a semântica da tabela se perca. */
.tabela__ligacao::after {
  content: '';
  position: absolute;
  inset: 0;
}

.tabela tbody tr:hover {
  background: var(--surface-sunken);
}

/* Rodapé ------------------------------------------------------------------- */

.rodape {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-4);
  flex-wrap: wrap;
  padding: var(--space-3) var(--space-4);
  border-top: 1px solid var(--border-hairline);
}

.rodape__contagem {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
  font-variant-numeric: tabular-nums;
}

.rodape__nota {
  margin: 0;
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-muted);
}

.rodape__controles {
  display: flex;
  align-items: center;
  gap: var(--space-2);
}

.rodape__botao {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  color: var(--ink-muted);
  cursor: pointer;
}

.rodape__botao:hover:not(:disabled) {
  border-color: var(--brand-bright);
  color: var(--ink);
}

.rodape__botao:disabled {
  opacity: .45;
  cursor: not-allowed;
}

.rodape__posicao {
  font-family: var(--font-mono);
  font-size: 13px;
  color: var(--ink);
  font-variant-numeric: tabular-nums;
}

/* Sem autorização vigente -------------------------------------------------- */

.consentimento {
  padding: var(--space-6);
  background: var(--surface-card);
  border: 1px solid var(--consent);
  border-radius: var(--radius-md);
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
  font-family: var(--font-display);
  font-size: 22px;
  line-height: 28px;
  font-weight: 600;
  color: var(--ink);
}

.consentimento__texto {
  margin: var(--space-3) 0 0;
  max-width: 75ch;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

.consentimento__acoes {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-3);
  margin: var(--space-4) 0 0;
}

/* Botões ------------------------------------------------------------------- */

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
  background: var(--consent);
  border: 1px solid var(--consent);
  color: var(--surface-card);
}

.botao--consentimento:hover {
  background: #32427A;
  color: var(--surface-card);
}

/* Avisos ------------------------------------------------------------------- */

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
  width: 40%;
}

.esqueleto-cabecalho {
  padding: var(--space-3) var(--space-4);
  background: var(--surface-sunken);
}

.esqueleto--rotulo {
  height: 16px;
  width: 36%;
  background: var(--surface-card);
}

.esqueleto-linha {
  display: flex;
  align-items: center;
  gap: var(--space-4);
  height: 48px;
  padding: 0 var(--space-4);
  border-top: 1px solid var(--border-hairline);
}

.esqueleto--celula {
  height: 16px;
  width: 24%;
}

.esqueleto--curta {
  width: 16%;
}

.esqueleto--pilula {
  height: 22px;
  width: 12%;
  margin-left: auto;
  border-radius: var(--radius-pill);
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

/* Larguras derivadas ------------------------------------------------------- */

@media (min-width: 768px) {
  .animais {
    gap: var(--space-6);
  }

  .tabela thead {
    display: table-header-group;
  }

  .tabela th {
    padding: var(--space-3) 0;
    font-size: 13px;
    line-height: 16px;
    font-weight: 600;
    letter-spacing: .06em;
    text-transform: uppercase;
    text-align: left;
    color: var(--ink-muted);
  }

  /* Cabeçalho e linhas repetem a mesma grade e o mesmo recuo lateral — e cada
     faixa é `minmax(0, …fr)`, não `…fr` seco: cabeçalho e corpo são grades
     independentes, e o piso de min-content de cada uma resolveria larguras
     diferentes para a mesma coluna ("SITUAÇÃO VACINAL" não comprime como
     "em dia"), desalinhando a coluna do próprio título. */
  .tabela thead tr,
  .tabela tbody tr {
    display: grid;
    grid-template-columns:
      minmax(0, 1.4fr) minmax(0, 1.1fr) minmax(0, .9fr) minmax(0, 1.2fr)
      minmax(0, 1fr) minmax(0, 1fr) 110px;
    gap: var(--space-4);
    padding: 0 20px;
  }

  .tabela thead tr {
    background: var(--surface-sunken);
    border-top: 0;
  }

  .tabela tbody tr {
    align-items: center;
    min-height: 48px;
    padding-top: var(--space-2);
    padding-bottom: var(--space-2);
    border-top: 1px solid var(--border-hairline);
  }

  .tabela td::before,
  .tabela td[data-rotulo='Tutor']::after {
    display: none;
  }

  /* `order` também vale em grid: sem zerá-lo, a ordem de empilhamento do
     cartão sobrevive à volta da tabela e cada célula cai sob o cabeçalho da
     vizinha (a lição registrada em V02). */
  .tabela td[data-rotulo='Animal'],
  .tabela td[data-rotulo='Tutor'],
  .tabela td[data-rotulo='Idade'],
  .tabela td[data-rotulo='Situação vacinal'],
  .tabela td[data-rotulo='Última passagem'],
  .tabela td[data-rotulo='Autorização'],
  .tabela td[data-rotulo='Ação'] {
    order: 0;
    font-size: 14px;
    line-height: 20px;
    font-weight: 400;
    padding-right: 0;
  }

  .tabela td[data-rotulo='Animal'] {
    font-weight: 600;
  }

  .tabela td[data-rotulo='Situação vacinal'],
  .tabela td[data-rotulo='Ação'] {
    position: static;
    inset: auto;
  }

  .tabela__ligacao-rotulo {
    display: inline;
  }

  .tabela__acao {
    text-align: right;
  }

  .tabela th.tabela__acao {
    display: block;
  }
}

@media (min-width: 1024px) {
  .animais__titulo {
    font-size: 36px;
    line-height: 40px;
    letter-spacing: -.02em;
  }
}
</style>
