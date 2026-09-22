<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { CircleCheck, Eye, History, KeyRound, TriangleAlert, X } from '@lucide/vue'
import TutorShell from '@/components/tutor/TutorShell.vue'
import AccessLogRow from '@/components/tutor/AccessLogRow.vue'
import ConfirmDialog from '@/components/base/ConfirmDialog.vue'
import EmptyState from '@/components/base/EmptyState.vue'
import { apiDelete, apiGet } from '@/lib/api.js'
import { porDiaDaSemana } from '@/lib/datas.js'
import { enumerarNomes } from '@/lib/animais.js'
import { REVOGACAO_EM_REPOUSO, textoDaRevogacao } from '@/lib/revogacao.js'

/**
 * T14 — quem acessou meus dados (RF53).
 *
 * É a tela que materializa a auditoria da LGPD, e o desenho dela é sobre uma
 * coisa só: tornar concreto o que, sem ela, seria promessa. O bloco do topo
 * anuncia que o registro existe e que ninguém pode apagá-lo — inclusive o
 * prestador que figura nele —, e o resto da tela é a prova disso.
 *
 * O agrupamento por dia vem pronto do servidor. Reagrupar aqui obrigaria duas
 * partes do sistema a concordar sobre onde termina um dia, concordância que o
 * fuso desfaz na primeira madrugada.
 *
 * O recorte viaja na URL, como em T10: é o que faz o "Ver acessos" de cada
 * cartão de T12 chegar aqui já filtrado, e o que faz o botão de voltar do
 * navegador desfazer um filtro em vez de sair da tela.
 */
const route = useRoute()
const router = useRouter()

const dados = ref(null)
const carregando = ref(true)
const erro = ref('')

const emRevogacao = ref(null)
const executando = ref(false)
const feito = ref('')
const erroDaAcao = ref('')

const filtro = computed(() => ({
  animal: typeof route.query.animal === 'string' ? route.query.animal : '',
  periodo: typeof route.query.periodo === 'string' ? route.query.periodo : '',
  prestador: typeof route.query.prestador === 'string' ? route.query.prestador : '',
}))

async function carregar() {
  carregando.value = true
  erro.value = ''

  const parametros = new URLSearchParams(
    Object.entries(filtro.value).filter(([, valor]) => valor !== ''),
  )
  const consulta = parametros.toString()

  try {
    dados.value = await apiGet(`/api/acessos${consulta === '' ? '' : `?${consulta}`}`)
  } catch (excecao) {
    erro.value = excecao.message
  } finally {
    carregando.value = false
  }
}

// A URL é a fonte do recorte, e não uma cópia dele: mudar o filtro é navegar,
// e é a navegação que recarrega. Assim o histórico do navegador guarda os
// recortes, e voltar desfaz o último em vez de abandonar a tela.
watch(() => route.query, carregar, { immediate: true })

/**
 * `replace`, e não `push`: trocar de recorte não é um passo do percurso do
 * tutor, e empilhá-lo faria o botão de voltar percorrer cada combinação de
 * filtro antes de devolver a pessoa a T12.
 */
function recortar(mudancas) {
  const query = { ...route.query, ...mudancas }

  for (const [chave, valor] of Object.entries(query)) {
    if (valor === '' || valor === null || valor === undefined) delete query[chave]
  }

  router.replace({ path: '/acessos', query })
}

const animais = computed(() => dados.value?.animais ?? [])
const periodos = computed(() => dados.value?.periodos ?? [])
const total = computed(() => dados.value?.total ?? 0)
const dias = computed(() => dados.value?.dias ?? [])

const nunca = computed(() => dados.value !== null && dados.value.algum_acesso === false)
const semResultado = computed(
  () => dados.value !== null && dados.value.algum_acesso === true && total.value === 0,
)

const animalEscolhido = computed(
  () => animais.value.find((animal) => animal.codigo === dados.value?.filtro.animal) ?? null,
)

const contagem = computed(() => (total.value === 1 ? '1 acesso' : `${total.value} acessos`))

/** "nos últimos 30 dias", "em todo o período" — o período dentro da frase. */
const periodoNaFrase = computed(() => {
  const chave = dados.value?.filtro.periodo

  if (chave === 'tudo') return 'em todo o período'

  return `nos ${(dados.value?.filtro.rotulo_periodo ?? '').toLowerCase()}`
})

/**
 * O vazio filtrado nomeia exatamente o recorte que não encontrou nada. "Nenhum
 * acesso" sozinho deixaria o tutor sem saber se o silêncio é do registro ou do
 * filtro que ele mesmo pôs.
 */
const tituloDoVazioFiltrado = computed(() => {
  const alvo = animalEscolhido.value === null
    ? 'aos seus animais'
    : `a ${animalEscolhido.value.nome}`
  const clinica = dados.value?.prestador === null || dados.value?.prestador === undefined
    ? ''
    : ` por ${dados.value.prestador.nome}`

  return `Nenhum acesso ${alvo}${clinica} ${periodoNaFrase.value}`
})

/**
 * E explica o silêncio: há quem pudesse ter acessado e não acessou. Sem isso,
 * a ausência de linhas pareceria falha de registro — leitura que destruiria a
 * confiança que esta tela existe para construir.
 */
const apoioDoVazioFiltrado = computed(() => {
  const vigentes = dados.value?.vigentes ?? []
  const sobre = animalEscolhido.value === null
    ? 'os seus animais'
    : animalEscolhido.value.nome

  if (vigentes.length === 0) {
    return `Nenhuma clínica tem autorização vigente sobre ${sobre} no momento.`
  }

  const nomes = enumerarNomes(vigentes.map((prestador) => prestador.nome))
  const verbo = vigentes.length === 1 ? 'está vigente, mas ela não abriu' : 'estão vigentes, mas elas não abriram'

  return `A autorização de ${nomes} ${verbo} o histórico de ${sobre} neste período.`
})

const temRecorte = computed(
  () => filtro.value.animal !== '' || filtro.value.prestador !== '' || filtro.value.periodo === '30d',
)

// Revogação (RF39, acionável desta tela por RF53b) ---------------------------

function pedirRevogacao(acesso) {
  erroDaAcao.value = ''
  feito.value = ''
  emRevogacao.value = acesso
}

const revogando = computed(() => emRevogacao.value !== null)

/**
 * O mesmo diálogo de T12, e não um parecido: o texto do que a revogação
 * alcança e do que não alcança é requisito (RF39d), e mantê-lo num lugar só é
 * o que impede que as duas telas passem a dizer coisas diferentes sobre o
 * mesmo ato.
 */
const dialogo = computed(() => {
  if (emRevogacao.value === null) return REVOGACAO_EM_REPOUSO

  const acesso = emRevogacao.value

  return textoDaRevogacao(acesso.prestador.nome, acesso.animal?.nome ?? 'seus animais')
})

async function revogar() {
  const acesso = emRevogacao.value
  executando.value = true
  erroDaAcao.value = ''

  try {
    const resposta = await apiDelete(`/api/autorizacoes/${acesso.revogavel}`)
    emRevogacao.value = null
    feito.value = resposta.message
    await carregar()
  } catch (excecao) {
    emRevogacao.value = null
    erroDaAcao.value = excecao.message
  } finally {
    executando.value = false
  }
}
</script>

<template>
  <!--
    `amplo` mais a coluna centrada abaixo, como em T01: o limite de 880 px da
    moldura, alinhado à esquerda, deixaria o conteúdo fora do centro da área de
    leitura em telas largas.
  -->
  <TutorShell amplo>
    <div class="acessos">
      <div>
        <p class="acessos__sobrelinha">Auditoria</p>
        <h1 class="acessos__titulo">Quem acessou meus dados</h1>
      </div>

      <!-- RF53 traduzido em uma frase: o registro existe, é do tutor, e nenhum
           prestador pode apagá-lo (RF52a). -->
      <div class="explicacao">
        <Eye :size="20" :stroke-width="1.75" class="explicacao__icone" />
        <p class="explicacao__texto">
          Toda vez que uma clínica autorizada abre o histórico de um animal seu, o Imunia
          registra quem foi, quando e o que foi consultado. Esta lista é sua e não pode ser
          apagada por nenhum prestador.
        </p>
      </div>

      <div v-if="!carregando && !erro && !nunca" class="filtros">
        <!-- Celular: dois seletores de 48 px, como no desenho de 360 px. -->
        <div class="filtros__seletores">
          <label class="filtros__campo">
            <span class="visually-hidden">Animal</span>
            <select
              class="filtros__select"
              :value="filtro.animal"
              @change="recortar({ animal: $event.target.value })"
            >
              <option value="">Todos os animais</option>
              <option v-for="animal in animais" :key="animal.codigo" :value="animal.codigo">
                {{ animal.nome }}
              </option>
            </select>
          </label>

          <label class="filtros__campo">
            <span class="visually-hidden">Período</span>
            <select
              class="filtros__select"
              :value="dados?.filtro.periodo ?? ''"
              @change="recortar({ periodo: $event.target.value })"
            >
              <option v-for="periodo in periodos" :key="periodo.chave" :value="periodo.chave">
                {{ periodo.rotulo }}
              </option>
            </select>
          </label>
        </div>

        <!-- A partir de md o animal vira etiqueta, como no desenho de 1440 px:
             são poucos, e vê-los todos de uma vez poupa a abertura do seletor. -->
        <div class="filtros__etiquetas">
          <button
            type="button"
            class="etiqueta"
            :class="{ 'etiqueta--ativa': filtro.animal === '' }"
            @click="recortar({ animal: '' })"
          >
            Todos
          </button>
          <button
            v-for="animal in animais"
            :key="animal.codigo"
            type="button"
            class="etiqueta"
            :class="{ 'etiqueta--ativa': filtro.animal === animal.codigo }"
            @click="recortar({ animal: filtro.animal === animal.codigo ? '' : animal.codigo })"
          >
            {{ animal.nome }}
            <X v-if="filtro.animal === animal.codigo" :size="16" :stroke-width="1.75" />
          </button>
        </div>

        <!-- Chegou de T12 pelo "Ver acessos" de um cartão: a etiqueta diz de
             quem a tela está falando, e sai com um toque. -->
        <button
          v-if="dados?.prestador"
          type="button"
          class="etiqueta etiqueta--ativa filtros__prestador"
          @click="recortar({ prestador: '' })"
        >
          {{ dados.prestador.nome }}
          <X :size="16" :stroke-width="1.75" />
        </button>

        <p class="filtros__contagem" aria-live="polite">{{ contagem }}</p>
      </div>

      <p v-if="feito" class="aviso aviso--feito" role="status">
        <CircleCheck :size="20" :stroke-width="1.75" class="aviso__icone" />
        {{ feito }}
      </p>

      <div v-if="erroDaAcao" class="aviso aviso--erro" role="alert">
        <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
        <div>
          <p class="aviso__titulo">Não conseguimos concluir a ação.</p>
          <p class="aviso__texto">{{ erroDaAcao }}</p>
        </div>
      </div>

      <div v-if="carregando" class="acessos__lista" aria-busy="true" aria-live="polite">
        <span class="visually-hidden">Carregando a sua auditoria de acessos.</span>
        <div v-for="linha in 2" :key="linha" class="esqueleto-grupo">
          <div class="esqueleto esqueleto--dia" />
          <div class="esqueleto esqueleto--linha" />
        </div>
      </div>

      <div v-else-if="erro" class="aviso aviso--erro" role="alert">
        <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
        <div>
          <p class="aviso__titulo">Não conseguimos carregar a sua auditoria.</p>
          <p class="aviso__texto">{{ erro }}</p>
          <button type="button" class="botao botao--secundario" @click="carregar">
            Tentar novamente
          </button>
        </div>
      </div>

      <!-- Vazio positivo: aqui a ausência é boa notícia, e o desenho a trata
           como tal — ícone de confirmação e moldura de consentimento, não o
           cinza de "nada encontrado". -->
      <EmptyState
        v-else-if="nunca"
        :icone="CircleCheck"
        titulo="Ninguém acessou o histórico dos seus animais ainda"
        descricao="Está tudo em ordem. Assim que uma clínica autorizada abrir a carteira ou o histórico de um animal seu, o acesso aparece aqui com data, hora e o nome do profissional."
        class="acessos__vazio"
      >
        <RouterLink to="/autorizacoes" class="botao botao--secundario">
          <KeyRound :size="20" :stroke-width="1.75" />
          Ver minhas autorizações
        </RouterLink>
      </EmptyState>

      <EmptyState
        v-else-if="semResultado"
        :icone="History"
        :titulo="tituloDoVazioFiltrado"
        :descricao="apoioDoVazioFiltrado"
      >
        <button
          v-if="dados.filtro.periodo !== 'tudo'"
          type="button"
          class="botao botao--secundario"
          @click="recortar({ periodo: 'tudo' })"
        >
          Ver todo o período
        </button>
        <button
          v-else-if="temRecorte"
          type="button"
          class="botao botao--secundario"
          @click="recortar({ animal: '', prestador: '' })"
        >
          Ver todos os animais
        </button>
      </EmptyState>

      <div v-else class="acessos__lista">
        <section v-for="dia in dias" :key="dia.data" class="dia">
          <h2 class="dia__cabecalho">{{ porDiaDaSemana(dia.data) }}</h2>

          <div class="dia__linhas">
            <AccessLogRow
              v-for="acesso in dia.acessos"
              :key="acesso.id"
              :acesso="acesso"
              @revogar="pedirRevogacao"
            />
          </div>
        </section>
      </div>
    </div>

    <ConfirmDialog
      :aberto="revogando"
      :titulo="dialogo.titulo"
      :acontece="dialogo.acontece"
      :nao-acontece="dialogo.naoAcontece"
      rotulo-confirmar="Revogar acesso"
      rotulo-cancelar="Manter acesso"
      variante-confirmar="destrutiva"
      :carregando="executando"
      @confirmar="revogar"
      @cancelar="emRevogacao = null"
    />
  </TutorShell>
</template>

<style scoped>
.acessos {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  width: 100%;
  max-width: 880px;
  margin: 0 auto;
}

.acessos__sobrelinha {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--consent);
}

.acessos__titulo {
  margin: var(--space-1) 0 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  color: var(--ink);
}

/* Explicação do topo ------------------------------------------------------- */

.explicacao {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  padding: var(--space-4);
  background: var(--consent-wash);
  border: 1px solid var(--consent);
  border-radius: var(--radius-sm);
}

.explicacao__icone {
  flex: none;
  margin-top: 2px;
  color: var(--consent);
}

.explicacao__texto {
  margin: 0;
  max-width: 75ch;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);
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

.filtros__seletores {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.filtros__campo {
  display: block;
}

.filtros__select {
  width: 100%;
  height: 48px;
  padding: 0 var(--space-3);
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  font-family: var(--font-body);
  font-size: 16px;
  font-weight: 600;
  color: var(--ink);
}

/* Abaixo de md o animal é seletor, e as etiquetas não existem. */
.filtros__etiquetas {
  display: none;
}

.etiqueta {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  height: 44px;
  padding: 0 var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-pill);
  font-family: var(--font-body);
  font-size: 14px;
  font-weight: 600;
  color: var(--ink-muted);
  cursor: pointer;
}

.etiqueta:hover {
  background: var(--surface-sunken);
}

.etiqueta--ativa {
  background: var(--consent-wash);
  border-color: var(--consent);
  color: var(--consent);
}

.filtros__prestador {
  align-self: flex-start;
}

.filtros__contagem {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
  font-variant-numeric: tabular-nums;
}

/* Dias --------------------------------------------------------------------- */

.acessos__lista {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.dia__cabecalho {
  position: sticky;
  top: 0;
  z-index: 1;
  margin: 0;
  padding: var(--space-2) 0;
  background: var(--surface-page);
  font-family: var(--font-body);
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

.dia__linhas {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}

/* Vazios ------------------------------------------------------------------- */

.acessos__vazio {
  border-color: var(--consent);
}

.acessos__vazio :deep(.empty-state__icone) {
  color: var(--consent);
}

/* Avisos e botões — mesmo vocabulário de T12. ------------------------------ */

.aviso {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  margin: 0;
  padding: var(--space-4);
  border-radius: var(--radius-md);
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);
}

.aviso__icone {
  flex: none;
  margin-top: 2px;
}

.aviso--feito {
  background: var(--brand-wash);
  border: 1px solid var(--brand);
}

.aviso--feito .aviso__icone {
  color: var(--brand);
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
  font-weight: 600;
}

.aviso__texto {
  margin: var(--space-1) 0 0;
  color: var(--ink-muted);
}

.aviso--erro .botao {
  margin: var(--space-4) 0 0;
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

.botao--secundario {
  background: var(--surface-card);
  border-color: var(--border-strong);
  color: var(--ink);
}

.botao--secundario:hover {
  background: var(--surface-sunken);
}

/* Esqueleto de carregamento ------------------------------------------------ */

.esqueleto-grupo + .esqueleto-grupo {
  margin: var(--space-4) 0 0;
}

.esqueleto {
  border-radius: var(--radius-xs);
  background: var(--surface-sunken);
  animation: pulsar 1.2s ease-in-out infinite;
}

.esqueleto--dia {
  height: 16px;
  width: 45%;
}

.esqueleto--linha {
  height: 152px;
  margin: var(--space-3) 0 0;
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

/* Larguras derivadas ------------------------------------------------------- */

@media (min-width: 768px) {
  .filtros {
    flex-direction: row;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--space-3);
  }

  /* O animal passa a etiqueta; o período continua seletor, porque três opções
     mutuamente exclusivas de texto longo cabem melhor numa lista do que em
     três pílulas concorrendo com as dos animais. */
  .filtros__seletores {
    flex-direction: row;
  }

  .filtros__campo:first-child {
    display: none;
  }

  /* Em pílula, mas ainda com os 44 px de alvo mínimo: §4.3 do briefing os
     exige em qualquer largura abaixo de 1024 px, e 768 é uma delas. */
  .filtros__select {
    width: auto;
    border-radius: var(--radius-pill);
    font-size: 14px;
  }

  .filtros__etiquetas {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    flex-wrap: wrap;
  }

  /* Em tabela, as linhas se encostam e a moldura é do grupo do dia. */
  .dia {
    background: var(--surface-card);
    border: 1px solid var(--border-hairline);
    border-radius: var(--radius-sm);
    overflow: hidden;
  }

  .dia__cabecalho {
    padding: var(--space-3) var(--space-4);
    background: var(--surface-sunken);
  }

  .dia__linhas {
    gap: 0;
  }
}

/* A partir daqui há ponteiro, o alvo mínimo de toque deixa de reger, e os
   filtros recuam para os 40 px de densidade do desenho de 1440 px. */
@media (min-width: 1024px) {
  .acessos__titulo {
    font-size: 36px;
    line-height: 40px;
    letter-spacing: -.02em;
  }

  .etiqueta,
  .filtros__select {
    height: 40px;
  }
}
</style>
