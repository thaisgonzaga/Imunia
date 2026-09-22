<script setup>
import { computed, onMounted, ref } from 'vue'
import {
  Cat,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  ClipboardPlus,
  Dog,
  History,
  Syringe,
  TriangleAlert,
  X,
} from '@lucide/vue'
import VetShell from '@/components/vet/VetShell.vue'
import EmptyState from '@/components/base/EmptyState.vue'
import { apiGet } from '@/lib/api.js'
import { emNumeros } from '@/lib/datas.js'

/**
 * Relação de registros da clínica — o destino "Registros" da barra lateral
 * (§5.3). O livro de produção do prestador ativo: cada atendimento prestado e
 * cada vacina aplicada aqui, do mais recente ao mais antigo, com a autoria que
 * assinou cada um.
 *
 * O âmbito é o inverso do da relação de animais, e a inversão é a tela: lá
 * decide a autorização vigente (RN48 — quem a clínica pode acompanhar); aqui
 * decide a autoria (RN40 — o que a clínica produziu e guarda). A linha do
 * animal cuja autorização venceu ou foi revogada continua no livro — some o
 * endereço dela, não ela, e é a nota sob a tabela que explica a diferença.
 */
const consulta = ref(null)
const carregando = ref(true)
const atualizando = ref(false)
const erro = ref('')

const filtros = ref({ tipo: null, profissional: null })
const pagina = ref(1)

const itens = computed(() => consulta.value?.itens ?? [])
const opcoes = computed(() => consulta.value?.filtros.opcoes ?? null)
const paginacao = computed(() => consulta.value?.paginacao ?? null)

const filtrosAtivos = computed(() =>
  ['tipo', 'profissional'].filter((chave) => filtros.value[chave] !== null),
)

const temFiltroAtivo = computed(() => filtrosAtivos.value.length > 0)

const filtradoSemResultado = computed(
  () => consulta.value?.total === 0 && consulta.value?.total_sem_filtros > 0,
)

/** A nota da guarda só aparece quando há, na página, linha que precise dela. */
const temLinhaSobGuarda = computed(() => itens.value.some((item) => !item.sob_autorizacao))

function rotuloDaOpcao(chave, valor) {
  const lista = { tipo: 'tipos', profissional: 'profissionais' }[chave]

  return (
    opcoes.value?.[lista].find((opcao) => String(opcao.valor) === String(valor))?.rotulo ?? valor
  )
}

function parametros() {
  const busca = new URLSearchParams({ pagina: pagina.value })

  for (const chave of filtrosAtivos.value) busca.set(chave, filtros.value[chave])
  if (consulta.value?.prestador) busca.set('prestador', consulta.value.prestador.id)

  return busca
}

/**
 * RNF03 — os mesmos dois estados de carregamento das listas irmãs: o
 * primeiro, que ainda não tem tela, e o de atualização, que conserva a barra
 * de filtros operável enquanto a lista se refaz.
 */
async function carregar({ primeiraVez = false } = {}) {
  if (primeiraVez) carregando.value = true
  else atualizando.value = true

  erro.value = ''

  try {
    consulta.value = await apiGet(`/api/clinica/registros?${parametros()}`)
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
  filtros.value.tipo = null
  filtros.value.profissional = null
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
  filtros.value.tipo = null
  filtros.value.profissional = null
  carregar({ primeiraVez: true })
}

function iconeDoTipo(tipo) {
  return tipo === 'vacinacao' ? Syringe : ClipboardPlus
}

function iconeDaEspecie(especie) {
  return especie === 'gato' ? Cat : Dog
}
</script>

<template>
  <VetShell
    titulo="Registros"
    :prestador="consulta?.prestador"
    :vinculos="consulta?.vinculos ?? []"
    @trocar-prestador="trocarPrestador"
  >
    <!-- Carregando pela primeira vez: esqueleto na forma do conteúdo (§5.1). -->
    <div v-if="carregando" class="registros" aria-busy="true" aria-live="polite">
      <span class="visually-hidden">Carregando a relação de registros.</span>
      <div class="esqueleto esqueleto--titulo" />
      <div class="cartao cartao--liso">
        <div class="esqueleto-cabecalho"><div class="esqueleto esqueleto--rotulo" /></div>
        <div v-for="linha in 6" :key="linha" class="esqueleto-linha">
          <div class="esqueleto esqueleto--celula esqueleto--curta" />
          <div class="esqueleto esqueleto--celula" />
          <div class="esqueleto esqueleto--celula esqueleto--curta" />
          <div class="esqueleto esqueleto--pilula" />
        </div>
      </div>
    </div>

    <div v-else-if="erro" class="registros">
      <div class="aviso aviso--erro" role="alert">
        <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
        <div>
          <p class="aviso__titulo">Não conseguimos carregar a relação de registros.</p>
          <p class="aviso__texto">{{ erro }}</p>
          <button type="button" class="botao botao--secundario" @click="carregar({ primeiraVez: true })">
            Tentar novamente
          </button>
        </div>
      </div>
    </div>

    <!-- O livro vazio não é defeito nem falta de autorização: registro clínico
         nasce do atendimento e da aplicação, e é para lá que o vazio aponta. -->
    <div v-else-if="consulta.estado === 'sem_registros'" class="registros registros--estreito">
      <EmptyState
        :icone="History"
        titulo="Nenhum registro neste prestador ainda"
        descricao="O livro relaciona o que a clínica produziu: cada atendimento prestado e cada vacina aplicada aqui. O primeiro registro nasce do primeiro ato clínico."
      >
        <RouterLink to="/clinica/registrar/vacinacao" class="botao botao--primario">
          <Syringe :size="16" :stroke-width="1.75" />
          Registrar vacinação
        </RouterLink>
        <RouterLink to="/clinica/registrar/atendimento" class="botao botao--secundario">
          <ClipboardPlus :size="16" :stroke-width="1.75" />
          Registrar atendimento
        </RouterLink>
      </EmptyState>
    </div>

    <div v-else class="registros">
      <div class="registros__cabecalho">
        <div>
          <p class="registros__sobrelinha">Livro da clínica</p>
          <h1 class="registros__titulo">Registros</h1>
        </div>
      </div>

      <!-- Filtros combináveis, refletidos em pílulas (§8.3), operáveis durante
           a atualização — o mesmo desenho das listas irmãs. -->
      <div class="filtros">
        <div
          v-for="filtro in [
            { chave: 'tipo', rotulo: 'Tipo de registro', lista: 'tipos' },
            { chave: 'profissional', rotulo: 'Profissional', lista: 'profissionais' },
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
            {{ consulta.total }} de {{ consulta.total_sem_filtros }} registros
          </span>
          <span v-else>
            {{ consulta.total }} {{ consulta.total === 1 ? 'registro' : 'registros' }}
          </span>
        </p>

        <button v-if="temFiltroAtivo" type="button" class="filtros__limpar" @click="limparFiltros">
          Limpar filtros
        </button>
      </div>

      <EmptyState
        v-if="filtradoSemResultado"
        :icone="History"
        titulo="Nenhum registro com esses filtros"
        descricao="Há registros no livro, mas nenhum corresponde à combinação escolhida. Remova um dos filtros para voltar a vê-los."
      >
        <button type="button" class="botao botao--primario" @click="limparFiltros">
          Limpar filtros
        </button>
      </EmptyState>

      <div v-else class="cartao cartao--liso" :aria-busy="atualizando">
        <table class="tabela">
          <thead>
            <tr>
              <th scope="col" aria-sort="descending">Data ↓</th>
              <th scope="col">Registro</th>
              <th scope="col">Animal</th>
              <th scope="col">Tutor</th>
              <th scope="col">Responsável</th>
              <th scope="col" class="tabela__acao">Ação</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in itens" :key="`${item.tipo}-${item.id}`">
              <td data-rotulo="Data" class="tabela__numeros">{{ emNumeros(item.em) }}</td>
              <td data-rotulo="Registro">
                <span class="tabela__registro">
                  <component
                    :is="iconeDoTipo(item.tipo)"
                    :size="16"
                    :stroke-width="1.75"
                    class="tabela__icone"
                  />
                  {{ item.titulo }}
                  <span v-if="item.dose" class="tabela__dose">{{ item.dose }}ª dose</span>

                  <!-- RF33b — as duas pontas do encadeamento, cada uma na sua
                       linha. Nada sai do livro por ter sido corrigido (RN26):
                       a marca diz qual das duas linhas responde pelo ato. -->
                  <span v-if="item.retifica" class="chip">retificação</span>
                  <span v-if="item.retificado" class="chip">retificado</span>

                  <!-- RN40 — a linha sem autorização vigente fica; o que ela
                       perde é o endereço. A nota sob a tabela explica. -->
                  <span v-if="!item.sob_autorizacao" class="chip chip--guarda">sob guarda</span>
                </span>
              </td>
              <td data-rotulo="Animal">
                <span class="tabela__animal">
                  <component
                    :is="iconeDaEspecie(item.animal.especie)"
                    :size="16"
                    :stroke-width="1.75"
                    class="tabela__icone"
                  />
                  {{ item.animal.nome }}
                </span>
              </td>
              <td data-rotulo="Tutor" class="tabela__discreto">{{ item.tutor }}</td>
              <td data-rotulo="Responsável" class="tabela__discreto">
                {{ item.responsavel.nome }} · {{ item.responsavel.crmv }}
              </td>
              <td data-rotulo="Ação" class="tabela__acao">
                <RouterLink v-if="item.url" :to="item.url" class="tabela__ligacao">
                  <span class="tabela__ligacao-rotulo">Abrir registro</span>
                  <span class="visually-hidden">
                    Abrir o registro de {{ item.titulo }} de {{ item.animal.nome }}
                  </span>
                </RouterLink>
                <span v-else class="tabela__nunca" aria-hidden="true">—</span>
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

          <p v-else class="rodape__nota">Do mais recente para o mais antigo</p>
        </div>
      </div>

      <!-- RN40 — a explicação é parte do desenho, como o bloco fixo de A01:
           sem ela, a linha sem endereço parece defeito. -->
      <p v-if="temLinhaSobGuarda && !filtradoSemResultado" class="registros__guarda">
        Linhas marcadas com <strong>sob guarda</strong> são de animais sem autorização vigente do
        tutor: o registro permanece no livro, porque a revogação não alcança o que a própria
        clínica produziu — e a leitura completa volta a abrir com nova autorização.
      </p>
    </div>
  </VetShell>
</template>

<style scoped>
.registros {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  width: 100%;
  max-width: 1280px;
  margin: 0 auto;
}

.registros--estreito {
  max-width: 880px;
}

.registros__cabecalho {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: var(--space-6);
  flex-wrap: wrap;
}

.registros__sobrelinha {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--brand);
}

.registros__titulo {
  margin: var(--space-1) 0 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  color: var(--ink);
}

/* Ausência é informação, e a nota que a explica também é (--ink-muted passa no
   contraste; decisoes.md §9.1). */
.registros__guarda {
  margin: 0;
  max-width: 75ch;
  font-size: 13px;
  line-height: 18px;
  color: var(--ink-muted);
}

.registros__guarda strong {
  font-weight: 600;
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
   registro, a data no canto, e a linha de animal e tutor. */
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

/* Linha 1: o registro, com espaço reservado à data que flutua à direita. */
.tabela td[data-rotulo='Registro'] {
  order: 1;
  flex: 1 1 100%;
  padding-right: 96px;
  font-size: 16px;
  line-height: 24px;
  font-weight: 600;
}

.tabela td[data-rotulo='Data'] {
  order: 2;
  position: absolute;
  top: var(--space-4);
  right: var(--space-4);
  color: var(--ink-muted);
}

/* Linha 2: animal · tutor, separados por ponto médio. */
.tabela td[data-rotulo='Animal'] {
  order: 3;
}

.tabela td[data-rotulo='Tutor'] {
  order: 4;
}

.tabela td[data-rotulo='Animal'],
.tabela td[data-rotulo='Tutor'] {
  flex: 0 0 auto;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
}

.tabela td[data-rotulo='Animal']::after {
  content: '·';
  margin: 0 6px;
  color: var(--ink-muted);
}

/* Linha 3: o responsável, com o rótulo que o conteúdo sozinho não daria. */
.tabela td[data-rotulo='Responsável'] {
  order: 5;
  flex: 1 1 100%;
  color: var(--ink-muted);
}

.tabela td::before {
  content: attr(data-rotulo) ': ';
  color: var(--ink-muted);
}

.tabela td[data-rotulo='Data']::before,
.tabela td[data-rotulo='Registro']::before,
.tabela td[data-rotulo='Animal']::before,
.tabela td[data-rotulo='Tutor']::before,
.tabela td[data-rotulo='Ação']::before {
  display: none;
}

/* O cartão inteiro é o alvo, e o rótulo da ação não gasta uma linha para isso.
   Na linha sob guarda não há ligação — e o cartão, portanto, não é alvo. */
.tabela td[data-rotulo='Ação'] {
  position: absolute;
  inset: 0;
  padding: 0;
}

.tabela__ligacao-rotulo {
  display: none;
}

/* O travessão da linha sem endereço é ruído no cartão do celular: lá quem
   explica a ausência é o chip "sob guarda", que já está na primeira linha. */
.tabela td[data-rotulo='Ação'] .tabela__nunca {
  display: none;
}

.tabela__registro,
.tabela__animal {
  display: inline-flex;
  align-items: center;
  flex-wrap: wrap;
  gap: var(--space-2);
}

.tabela__icone {
  flex: none;
  color: var(--ink-muted);
}

.tabela__dose {
  font-size: 13px;
  font-weight: 400;
  color: var(--ink-muted);
  white-space: nowrap;
}

/* RF33b e RN40 — as marcas do livro: informação de estado, não decoração.
   Borda cheia, e não tracejada: o tracejado é o vocabulário do não verificado
   e do preliminar, e nada aqui depende de verificação. */
.chip {
  display: inline-flex;
  align-items: center;
  height: 20px;
  padding: 0 8px;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-pill);
  font-size: 12px;
  line-height: 16px;
  font-weight: 600;
  color: var(--ink-muted);
  white-space: nowrap;
}

.chip--guarda {
  border-color: var(--consent);
  color: var(--consent);
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
  .registros {
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
     diferentes para a mesma coluna, desalinhando a coluna do próprio título
     (a lição da relação de animais, decisoes.md §9.15). */
  .tabela thead tr,
  .tabela tbody tr {
    display: grid;
    grid-template-columns:
      minmax(0, .7fr) minmax(0, 1.8fr) minmax(0, 1fr) minmax(0, 1.1fr)
      minmax(0, 1.4fr) 120px;
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
  .tabela td[data-rotulo='Animal']::after {
    display: none;
  }

  /* `order` também vale em grid: sem zerá-lo, a ordem de empilhamento do
     cartão sobrevive à volta da tabela e cada célula cai sob o cabeçalho da
     vizinha (a lição registrada em V02). */
  .tabela td[data-rotulo='Data'],
  .tabela td[data-rotulo='Registro'],
  .tabela td[data-rotulo='Animal'],
  .tabela td[data-rotulo='Tutor'],
  .tabela td[data-rotulo='Responsável'],
  .tabela td[data-rotulo='Ação'] {
    order: 0;
    font-size: 14px;
    line-height: 20px;
    font-weight: 400;
    padding-right: 0;
  }

  .tabela td[data-rotulo='Registro'] {
    font-weight: 600;
  }

  .tabela td[data-rotulo='Data'],
  .tabela td[data-rotulo='Ação'] {
    position: static;
    inset: auto;
  }

  .tabela td[data-rotulo='Data'] {
    color: var(--ink);
  }

  .tabela td[data-rotulo='Ação'] .tabela__nunca {
    display: inline;
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
  .registros__titulo {
    font-size: 36px;
    line-height: 40px;
    letter-spacing: -.02em;
  }
}
</style>
