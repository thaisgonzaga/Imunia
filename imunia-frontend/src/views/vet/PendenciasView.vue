<script setup>
import { computed, onMounted, ref } from 'vue'
import {
  Cat,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  CircleCheck,
  ClockAlert,
  Dog,
  FileCheck,
  KeyRound,
  TriangleAlert,
  X,
} from '@lucide/vue'
import VetShell from '@/components/vet/VetShell.vue'
import StatusPill from '@/components/base/StatusPill.vue'
import EmptyState from '@/components/base/EmptyState.vue'
import { apiGet } from '@/lib/api.js'
import { emNumeros } from '@/lib/datas.js'

/**
 * V02 — pendências vacinais (RF49). A tela que converte a rechamada por
 * amostragem em rechamada por critério: quem está com dose vencida ou prestes a
 * vencer, e quem já foi avisado.
 *
 * A coluna "Última notificação" é a razão de ser do desenho. Sem ela a lista
 * diria o que fazer sem dizer o que já foi feito, e a equipe ligaria duas vezes
 * para o mesmo tutor — ou nenhuma, por supor que alguém já ligou.
 *
 * O âmbito é o mesmo de V01 e não é escolha desta tela: prestador ativo e
 * autorização vigente (RN48). Quando não há autorização alguma, a tela explica
 * a regra em vez de exibir uma lista vazia que pareceria defeito.
 */
const consulta = ref(null)
const carregando = ref(true)
const atualizando = ref(false)
const erro = ref('')

const filtros = ref({ dias: 30, especie: null, imunobiologico: null, situacao: null })
const pagina = ref(1)

const itens = computed(() => consulta.value?.itens ?? [])
const opcoes = computed(() => consulta.value?.filtros.opcoes ?? null)
const paginacao = computed(() => consulta.value?.paginacao ?? null)

/** Quais filtros — fora o período, que está sempre aplicado — estreitam a lista. */
const filtrosAtivos = computed(() =>
  ['especie', 'imunobiologico', 'situacao'].filter((chave) => filtros.value[chave] !== null),
)

const temFiltroAtivo = computed(() => filtrosAtivos.value.length > 0)

/**
 * Os dois vazios da tela são estados distintos e precisam de textos distintos:
 * não há pendência nenhuma, ou há e os filtros a escondem (§6.1 do briefing).
 */
const vazioPositivo = computed(
  () => consulta.value?.total === 0 && consulta.value?.total_sem_filtros === 0,
)

const filtradoSemResultado = computed(
  () => consulta.value?.total === 0 && consulta.value?.total_sem_filtros > 0,
)

const rotuloDoPeriodo = (dias) => `Vencidas e a vencer em ${dias} dias`

function rotuloDaOpcao(chave, valor) {
  const lista = { especie: 'especies', imunobiologico: 'imunobiologicos', situacao: 'situacoes' }[chave]

  return opcoes.value?.[lista].find((opcao) => opcao.valor === valor)?.rotulo ?? valor
}

/**
 * O recorte da consulta. A exportação usa os mesmos filtros e dispensa a
 * página: o arquivo traz o resultado inteiro (RF49b), e mandar `pagina` nele
 * anunciaria um recorte que ele não faz.
 */
function parametros({ paginado = true } = {}) {
  const busca = new URLSearchParams({ dias: filtros.value.dias })

  if (paginado) busca.set('pagina', pagina.value)
  for (const chave of filtrosAtivos.value) busca.set(chave, filtros.value[chave])
  if (consulta.value?.prestador) busca.set('prestador', consulta.value.prestador.id)

  return busca
}

/**
 * RNF03 — a consulta responde em até 2 s no 95º percentil; acima de 1 s a tela
 * mostra esqueleto sem travar os filtros. Daí os dois estados de carregamento: o
 * primeiro, que ainda não tem tela, e o de atualização, que tem — e por isso
 * conserva a barra de filtros operável enquanto a lista se refaz.
 */
async function carregar({ primeiraVez = false } = {}) {
  if (primeiraVez) carregando.value = true
  else atualizando.value = true

  erro.value = ''

  try {
    consulta.value = await apiGet(`/api/clinica/pendencias?${parametros()}`)
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

function ampliarPeriodo(dias) {
  filtros.value.dias = dias
  pagina.value = 1
  carregar()
}

function limparFiltros() {
  filtros.value.especie = null
  filtros.value.imunobiologico = null
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
  carregar({ primeiraVez: true })
}

/**
 * RF49b — o arquivo que acompanha a rechamada. Sai pela mesma rota da consulta,
 * com os mesmos filtros: a equipe risca a lista conforme telefona, e um arquivo
 * que não confere com a tela não serviria para isso.
 */
function exportar() {
  const ligacao = document.createElement('a')
  ligacao.href = `/api/clinica/pendencias/exportar?${parametros({ paginado: false })}`
  document.body.appendChild(ligacao)
  ligacao.click()
  ligacao.remove()
}

function iconeDaEspecie(especie) {
  return especie === 'gato' ? Cat : Dog
}
</script>

<template>
  <VetShell
    titulo="Pendências"
    :prestador="consulta?.prestador"
    :vinculos="consulta?.vinculos ?? []"
    :pendencias="consulta?.total_sem_filtros ?? 0"
    @trocar-prestador="trocarPrestador"
  >
    <!-- Carregando pela primeira vez: esqueleto na forma do conteúdo (§5.1). -->
    <div v-if="carregando" class="pendencias" aria-busy="true" aria-live="polite">
      <span class="visually-hidden">Carregando as pendências vacinais.</span>
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

    <div v-else-if="erro" class="pendencias">
      <div class="aviso aviso--erro" role="alert">
        <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
        <div>
          <p class="aviso__titulo">Não conseguimos carregar as pendências.</p>
          <p class="aviso__texto">{{ erro }}</p>
          <button type="button" class="botao botao--secundario" @click="carregar({ primeiraVez: true })">
            Tentar novamente
          </button>
        </div>
      </div>
    </div>

    <!-- RN48 — a lista vazia por falta de autorização precisa dizer que está
         funcionando. Sem a explicação, a regra parece defeito. -->
    <div v-else-if="consulta.estado === 'sem_autorizacoes'" class="pendencias pendencias--estreito">
      <div class="consentimento">
        <div class="consentimento__topo">
          <KeyRound :size="24" :stroke-width="1.75" class="consentimento__icone" />
          <h1 class="consentimento__titulo">Nenhuma autorização vigente neste prestador</h1>
        </div>
        <p class="consentimento__texto">
          A rechamada abrange somente animais sob autorização vigente para
          {{ consulta.prestador.nome }}. Sem nenhuma, não há pendência a apurar — e isso
          não é uma falha do sistema.
        </p>
        <div class="consentimento__acoes">
          <RouterLink to="/clinica/buscar" class="botao botao--consentimento">
            <KeyRound :size="16" :stroke-width="1.75" />
            Buscar e solicitar autorização
          </RouterLink>
        </div>
      </div>
    </div>

    <div v-else class="pendencias">
      <div class="pendencias__cabecalho">
        <div>
          <p class="pendencias__sobrelinha">Rechamada</p>
          <h1 class="pendencias__titulo">Pendências vacinais</h1>
        </div>

        <button type="button" class="botao botao--secundario" @click="exportar">
          <FileCheck :size="16" :stroke-width="1.75" class="botao__icone" />
          Exportar resultado
        </button>
      </div>

      <!-- Filtros combináveis, refletidos em pílulas (§8.3). Continuam operáveis
           durante a atualização: travá-los transformaria uma espera de um
           segundo em uma tela que não responde. -->
      <div class="filtros">
        <label class="filtro filtro--ativo">
          <span class="visually-hidden">Período</span>
          <select
            class="filtro__campo"
            :value="filtros.dias"
            @change="ampliarPeriodo(Number($event.target.value))"
          >
            <option v-for="dias in opcoes.periodos" :key="dias" :value="dias">
              {{ rotuloDoPeriodo(dias) }}
            </option>
          </select>
          <ChevronDown :size="16" :stroke-width="1.75" class="filtro__seta" />
        </label>

        <div
          v-for="filtro in [
            { chave: 'especie', rotulo: 'Espécie', lista: 'especies' },
            { chave: 'imunobiologico', rotulo: 'Imunobiológico', lista: 'imunobiologicos' },
            { chave: 'situacao', rotulo: 'Situação', lista: 'situacoes' },
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

        <!-- §4.6 — contador de pendência é região dinâmica e se anuncia. -->
        <p class="filtros__contagem" aria-live="polite">
          <span v-if="atualizando">Atualizando a lista…</span>
          <span v-else-if="temFiltroAtivo">
            {{ consulta.total }} de {{ consulta.total_sem_filtros }} resultados
          </span>
          <span v-else>{{ consulta.total }} resultados</span>
        </p>

        <button v-if="temFiltroAtivo" type="button" class="filtros__limpar" @click="limparFiltros">
          Limpar filtros
        </button>
      </div>

      <!-- Vazio positivo: a confirmação de que não há o que fazer, com o
           denominador que a torna uma afirmação e não uma frase vaga. -->
      <EmptyState
        v-if="vazioPositivo"
        class="vazio-positivo"
        :icone="CircleCheck"
        titulo="Nenhuma dose vencida ou próxima do vencimento no período"
        :descricao="`Os ${consulta.animais_no_ambito} animais sob autorização vigente em ${consulta.prestador.nome} estão com o calendário em dia para os próximos ${filtros.dias} dias.`"
      >
        <button
          v-if="filtros.dias < 90"
          type="button"
          class="botao botao--secundario"
          @click="ampliarPeriodo(90)"
        >
          Ampliar para 90 dias
        </button>
      </EmptyState>

      <EmptyState
        v-else-if="filtradoSemResultado"
        :icone="ClockAlert"
        titulo="Nenhuma pendência com esses filtros"
        descricao="Há pendências no período, mas nenhuma corresponde à combinação escolhida. Remova um dos filtros para voltar a vê-las."
      >
        <button type="button" class="botao botao--primario" @click="limparFiltros">
          Limpar filtros
        </button>
      </EmptyState>

      <div v-else class="cartao cartao--liso" :aria-busy="atualizando">
        <table class="tabela">
          <thead>
            <tr>
              <th scope="col">Animal</th>
              <th scope="col">Tutor</th>
              <th scope="col">Imunobiológico</th>
              <th scope="col">Dose</th>
              <th scope="col">Prevista</th>
              <th scope="col" aria-sort="descending">Atraso ↓</th>
              <th scope="col">Última notificação</th>
              <th scope="col" class="tabela__acao">Ação</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="item in itens"
              :key="`${item.animal.codigo}-${item.imunobiologico_chave}-${item.prevista_para}`"
              :class="{ 'tabela__linha--atrasada': item.atraso.vencida }"
            >
              <td data-rotulo="Animal">
                <span class="tabela__animal">
                  <component
                    :is="iconeDaEspecie(item.animal.especie)"
                    :size="16"
                    :stroke-width="1.75"
                    class="tabela__icone"
                  />
                  {{ item.animal.nome }} · {{ item.animal.especie === 'gato' ? 'gato' : 'cão' }}
                </span>
              </td>
              <td data-rotulo="Tutor" class="tabela__discreto">{{ item.tutor }}</td>
              <td data-rotulo="Imunobiológico">{{ item.imunobiologico }}</td>
              <td data-rotulo="Dose" class="tabela__discreto">{{ item.dose }}</td>
              <td data-rotulo="Prevista" class="tabela__numeros">
                {{ emNumeros(item.prevista_para) }}
              </td>
              <td data-rotulo="Atraso">
                <!-- Em tela estreita a linha vira cartão, e a etiqueta assume o
                     papel que a cor da linha tinha: ícone e texto juntos, nunca
                     a cor sozinha (§4.6). -->
                <StatusPill
                  class="tabela__pilula"
                  :tipo="item.situacao.tipo"
                  :texto="item.atraso.texto"
                />
                <span
                  class="tabela__atraso"
                  :class="item.atraso.vencida ? 'tabela__atraso--vencida' : 'tabela__atraso--a-vencer'"
                >
                  {{ item.atraso.texto }}
                </span>
              </td>
              <td data-rotulo="Última notificação" class="tabela__numeros">
                <span
                  v-if="item.ultima_notificacao"
                  :class="{
                    'tabela__sem-confirmacao': item.ultima_notificacao.situacao !== 'entregue',
                  }"
                >
                  {{ item.ultima_notificacao.texto }}
                </span>
                <!-- O silêncio do sistema não pode parecer silêncio do tutor. -->
                <span v-else class="tabela__nunca">nunca notificado</span>
              </td>
              <td data-rotulo="Ação" class="tabela__acao">
                <!-- Em cartão, o alvo é a área inteira e o rótulo sai: repetir
                     "Abrir ficha" em cada cartão gastaria uma linha para dizer o
                     que o cartão todo já faz. O nome acessível vem do conteúdo. -->
                <RouterLink :to="`/clinica/animais/${item.animal.codigo}`" class="tabela__ligacao">
                  <span class="tabela__ligacao-rotulo">Abrir ficha</span>
                  <span class="visually-hidden">
                    Abrir a ficha de {{ item.animal.nome }}
                  </span>
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

          <p v-else class="rodape__nota">Ordenado por dias de atraso, do maior para o menor</p>
        </div>
      </div>
    </div>
  </VetShell>
</template>

<style scoped>
.pendencias {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  width: 100%;
  max-width: 1280px;
  margin: 0 auto;
}

.pendencias--estreito {
  max-width: 880px;
}

.pendencias__cabecalho {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: var(--space-6);
  flex-wrap: wrap;
}

.pendencias__sobrelinha {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--brand);
}

.pendencias__titulo {
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

/* A pílula é o invólucro; o <select> dentro dela é o que responde ao teclado.
   Desenhar um menu próprio custaria a navegação que o campo nativo já tem. */
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

/* O único vazio do sistema que é boa notícia, e o contorno diz isso. O ícone
   acompanha: o `circle-check` em cinza de vazio pareceria "nada aqui", quando o
   que a tela afirma é que o plantel inteiro está em dia. */
.vazio-positivo {
  border-color: var(--brand);
}

.vazio-positivo :deep(.empty-state__icone) {
  color: var(--brand);
}

/* Tabela — vira lista de cartões abaixo de 768 px (§5.1). ------------------- */

.tabela {
  width: 100%;
  border-collapse: collapse;
}

.tabela thead {
  display: none;
}

/* Abaixo de 768 px a linha é um cartão, e o cartão promove o que decide a
   rechamada: o animal, a etiqueta de atraso, e a linha que diz de que vacina se
   trata e para quem ligar. Os rótulos de coluna somem — em cartão eles ocupam
   metade da largura para nomear o que o próprio conteúdo já nomeia. */
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
  padding-right: 96px;
  font-size: 16px;
  line-height: 24px;
  font-weight: 600;
}

.tabela td[data-rotulo='Atraso'] {
  order: 2;
  position: absolute;
  top: var(--space-4);
  right: var(--space-4);
}

/* Linha 2: imunobiológico · dose · tutor, separados por ponto médio. */
.tabela td[data-rotulo='Imunobiológico'] {
  order: 3;
}

.tabela td[data-rotulo='Dose'] {
  order: 4;
}

.tabela td[data-rotulo='Tutor'] {
  order: 5;
}

.tabela td[data-rotulo='Imunobiológico'],
.tabela td[data-rotulo='Dose'],
.tabela td[data-rotulo='Tutor'] {
  flex: 0 0 auto;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
}

.tabela td[data-rotulo='Imunobiológico']::after,
.tabela td[data-rotulo='Dose']::after {
  content: '·';
  margin: 0 6px;
  color: var(--ink-muted);
}

/* Linhas 3 e 4: as duas datas que a rechamada confere. A prevista fica, ainda
   que o desenho de 360 px a dispense: sem ela o cartão diz há quantos dias a
   dose venceu, mas não quando — e é a data que a equipe lê ao telefone. */
.tabela td[data-rotulo='Prevista'] {
  order: 6;
  flex: 1 1 100%;
  color: var(--ink-muted);
}

.tabela td[data-rotulo='Última notificação'] {
  order: 7;
  flex: 1 1 100%;
  color: var(--ink-muted);
}

.tabela td::before {
  content: attr(data-rotulo) ': ';
  color: var(--ink-muted);
}

.tabela td[data-rotulo='Animal']::before,
.tabela td[data-rotulo='Atraso']::before,
.tabela td[data-rotulo='Tutor']::before,
.tabela td[data-rotulo='Dose']::before,
.tabela td[data-rotulo='Imunobiológico']::before,
.tabela td[data-rotulo='Última notificação']::before,
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

.tabela__discreto {
  color: var(--ink-muted);
}

.tabela__numeros {
  font-variant-numeric: tabular-nums;
}

/* RF49 — "sem confirmação" é conduta diferente de "entregue": a rechamada por
   telefone continua valendo a pena, e a cor acompanha o texto que já o diz. */
.tabela__sem-confirmacao {
  color: var(--status-due-text);
}

/* Nunca notificado é informação, não decoração: fica em `--ink-muted`, que
   passa no contraste que o próprio briefing exige (§4.1), e não em
   `--ink-faint`, que reprova em toda superfície (decisoes.md §9.1). */
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

/* Fundo de atraso lavado — o recorte visual que faz a lista se ler de cima
   para baixo sem que ninguém explique a ordenação. */
.tabela__linha--atrasada,
.tabela__linha--atrasada:hover {
  background: var(--status-late-wash);
}

.tabela__atraso {
  display: none;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.tabela__atraso--vencida {
  color: var(--status-late);
}

.tabela__atraso--a-vencer {
  color: var(--status-due-text);
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

.botao__icone {
  flex: none;
  color: var(--brand);
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
  .pendencias {
    gap: var(--space-6);
  }

  /* A tabela volta a ser tabela: cabeçalho de volta, rótulos por célula fora,
     e a etiqueta cede lugar ao número puro — a cor da linha já diz a situação,
     e o cabeçalho da coluna já diz o que o número é. */
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

  /* Cabeçalho e linhas repetem a mesma grade e o mesmo recuo lateral: qualquer
     diferença entre os dois desalinha a coluna do seu próprio título. */
  .tabela thead tr,
  .tabela tbody tr {
    grid-template-columns: 1.2fr 1.1fr 1.1fr .7fr .9fr .9fr 1.2fr 110px;
    gap: var(--space-4);
    padding: 0 20px;
  }

  /* O cartão volta a ser linha: a grade retoma o comando e todas as regras de
     ordem, largura e separador que só faziam sentido empilhado saem de cena. */
  .tabela thead tr,
  .tabela tbody tr {
    display: grid;
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
  .tabela td[data-rotulo='Imunobiológico']::after,
  .tabela td[data-rotulo='Dose']::after,
  .tabela__pilula {
    display: none;
  }

  /* `order` também vale em grid: sem zerá-lo, a ordem de empilhamento do cartão
     sobrevive à volta da tabela e cada célula cai sob o cabeçalho da vizinha —
     "Imunobiológico" aparecendo na coluna "Dose". A ordem das colunas passa a
     ser de novo a do documento, que é a que o cabeçalho anuncia. */
  .tabela td[data-rotulo='Animal'],
  .tabela td[data-rotulo='Tutor'],
  .tabela td[data-rotulo='Imunobiológico'],
  .tabela td[data-rotulo='Dose'],
  .tabela td[data-rotulo='Prevista'],
  .tabela td[data-rotulo='Atraso'],
  .tabela td[data-rotulo='Última notificação'],
  .tabela td[data-rotulo='Ação'] {
    order: 0;
    font-size: 14px;
    line-height: 20px;
    font-weight: 400;
    padding-right: 0;
  }

  .tabela td[data-rotulo='Imunobiológico'],
  .tabela td[data-rotulo='Prevista'] {
    color: var(--ink);
  }

  .tabela td[data-rotulo='Atraso'],
  .tabela td[data-rotulo='Ação'] {
    position: static;
    inset: auto;
  }

  .tabela__atraso {
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
  .pendencias__titulo {
    font-size: 36px;
    line-height: 40px;
    letter-spacing: -.02em;
  }
}
</style>
