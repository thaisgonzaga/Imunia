<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { Cat, CircleCheck, Dog, KeyRound, TriangleAlert } from '@lucide/vue'
import TutorShell from '@/components/tutor/TutorShell.vue'
import AuthorizationCard from '@/components/tutor/AuthorizationCard.vue'
import ConfirmDialog from '@/components/base/ConfirmDialog.vue'
import EmptyState from '@/components/base/EmptyState.vue'
import { apiDelete, apiGet, apiPost } from '@/lib/api.js'
import { emNumeros } from '@/lib/datas.js'
import { REVOGACAO_EM_REPOUSO, textoDaRevogacao } from '@/lib/revogacao.js'

/**
 * T12 — minhas autorizações (RF41), com revogação (RF39) e renovação (RF40c).
 *
 * O agrupamento por animal é a tese da tela, e não uma escolha de arranjo: a
 * pergunta do tutor é "quem vê o Théo", não "quais autorizações existem". Uma
 * relação plana responderia à segunda e deixaria a primeira por conta dele.
 *
 * As abas filtram aqui, sem nova requisição: a relação de um tutor é da ordem
 * da dezena, e trocar de recorte não é motivo para a tela piscar.
 */
const route = useRoute()

const ABAS = [
  { chave: 'vigentes', rotulo: 'Vigentes', situacoes: ['vigente', 'a_expirar'] },
  { chave: 'a_expirar', rotulo: 'A expirar', situacoes: ['a_expirar'] },
  { chave: 'encerradas', rotulo: 'Encerradas', situacoes: ['expirada', 'revogada'] },
]

const dados = ref(null)
const carregando = ref(true)
const erro = ref('')
const aba = ref('vigentes')

const emRevogacao = ref(null)
const executando = ref(false)
const feito = ref('')
const erroDaAcao = ref('')

/**
 * O prestador que a tela destaca ao chegar de T11 ou de T10 — a autorização
 * recém-concedida e aquela que o tutor veio conferir. Sem o destaque, voltar do
 * fluxo de concessão deixaria o tutor procurando na lista o que ele acabou de
 * fazer.
 */
const prestadorEmDestaque = computed(() => {
  const valor = Number(route.query.prestador)

  return Number.isInteger(valor) && valor > 0 ? valor : null
})

async function carregar() {
  carregando.value = true
  erro.value = ''

  try {
    dados.value = await apiGet('/api/autorizacoes')
  } catch (excecao) {
    erro.value = excecao.message
  } finally {
    carregando.value = false
  }
}

onMounted(carregar)

const situacoesDaAba = computed(
  () => ABAS.find((opcao) => opcao.chave === aba.value).situacoes,
)

/**
 * Os grupos do recorte em vigor. Grupo sem cartão algum some: o cabeçalho de um
 * animal sozinho anunciaria uma seção vazia dentro de uma tela que já tem
 * estado vazio próprio.
 */
const grupos = computed(() => {
  if (dados.value === null) return []

  return dados.value.grupos
    .map((grupo) => ({
      ...grupo,
      autorizacoes: grupo.autorizacoes.filter((autorizacao) =>
        situacoesDaAba.value.includes(autorizacao.situacao),
      ),
    }))
    .filter((grupo) => grupo.autorizacoes.length > 0)
})

const abas = computed(() => dados.value?.abas ?? { vigentes: 0, a_expirar: 0, encerradas: 0 })

const semAutorizacaoAlguma = computed(
  () => dados.value !== null && dados.value.grupos.length === 0,
)

/**
 * O cabeçalho do grupo responde à pergunta da aba, e por isso muda com ela:
 * "quem vê" nas vigentes, "o que vence" nas que expiram, "o que já terminou"
 * nas encerradas.
 */
function resumoDoGrupo(grupo) {
  const quantas = grupo.autorizacoes.length

  if (aba.value === 'vigentes') {
    return quantas === 1 ? '1 clínica vê o histórico' : `${quantas} clínicas veem o histórico`
  }

  if (aba.value === 'a_expirar') {
    return quantas === 1 ? '1 autorização a expirar' : `${quantas} autorizações a expirar`
  }

  return quantas === 1 ? '1 autorização encerrada' : `${quantas} autorizações encerradas`
}

const vazioDaAba = computed(() => ({
  vigentes: 'Nenhuma clínica vê os seus animais no momento.',
  a_expirar: `Nenhuma autorização vence nos próximos ${dados.value?.dias_para_avisar ?? 15} dias.`,
  encerradas: 'Nenhuma autorização sua terminou até agora.',
}[aba.value]))

function iconeDaEspecie(especie) {
  return especie === 'gato' ? Cat : Dog
}

// Revogação (RF39) -----------------------------------------------------------

function pedirRevogacao(autorizacao, animal) {
  erroDaAcao.value = ''
  feito.value = ''
  emRevogacao.value = { autorizacao, animal }
}

/**
 * RF39d — a estrutura do diálogo é requisito, não cortesia. O bloco do que
 * *não* acontece é o que impede o tutor de supor que revogar apaga o prontuário
 * da clínica: a guarda do registro é obrigação dela perante o Conselho Federal
 * de Medicina Veterinária, e não faculdade que o titular possa extinguir (RN40).
 */
const revogando = computed(() => emRevogacao.value !== null)

/**
 * O diálogo fica montado o tempo todo e apenas alterna `aberto`: é a abertura
 * que leva o foco para dentro dele e o devolve ao botão de origem depois. Montá-lo
 * já aberto pularia essa transição, e quem navega por teclado ficaria com o foco
 * na página de trás enquanto a decisão está pendente.
 */
const dialogo = computed(() => {
  if (emRevogacao.value === null) {
    return REVOGACAO_EM_REPOUSO
  }

  const { autorizacao, animal } = emRevogacao.value

  return textoDaRevogacao(autorizacao.prestador.nome, animal.nome)
})

async function revogar() {
  const { autorizacao } = emRevogacao.value
  executando.value = true
  erroDaAcao.value = ''

  try {
    const resposta = await apiDelete(`/api/autorizacoes/${autorizacao.id}`)
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

// Renovação (RF40c) ----------------------------------------------------------

/**
 * Em ato único, sem código: o tutor já confirmou esta autorização uma vez, e
 * exigir a confirmação de novo transformaria a renovação em concessão — o
 * oposto do que o requisito pede.
 */
async function renovar(autorizacao) {
  executando.value = true
  erroDaAcao.value = ''
  feito.value = ''

  try {
    const resposta = await apiPost(`/api/autorizacoes/${autorizacao.id}/renovar`)
    feito.value = `${resposta.message} Agora vale até ${emNumeros(resposta.renovacao.expira_em)}.`
    await carregar()
  } catch (excecao) {
    erroDaAcao.value = excecao.message
  } finally {
    executando.value = false
  }
}
</script>

<template>
  <!--
    `amplo` mais a coluna centrada abaixo, como em T01. A moldura passa a
    largura inteira e a centragem é feita aqui: assim o estado vazio — que
    antes era o único a pedir `amplo`, para não ficar encostado à esquerda —
    fica centrado pelo mesmo caminho que a lista, e não por uma exceção.
  -->
  <TutorShell amplo>
    <div class="autorizacoes">
      <div class="autorizacoes__cabecalho">
        <div>
          <p class="autorizacoes__sobrelinha">Quem vê os seus animais</p>
          <h1 class="autorizacoes__titulo">Minhas autorizações</h1>
        </div>

        <RouterLink to="/prestadores" class="botao botao--consentimento autorizacoes__conceder">
          <KeyRound :size="20" :stroke-width="1.75" />
          Autorizar uma clínica
        </RouterLink>
      </div>

      <div v-if="!carregando && !erro && !semAutorizacaoAlguma" class="abas" role="tablist">
        <button
          v-for="opcao in ABAS"
          :key="opcao.chave"
          type="button"
          role="tab"
          class="abas__item"
          :class="{ 'abas__item--ativa': aba === opcao.chave }"
          :aria-selected="aba === opcao.chave"
          @click="aba = opcao.chave"
        >
          {{ opcao.rotulo }}
          <span class="abas__contagem">{{ abas[opcao.chave] }}</span>
        </button>
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

      <!-- Carregando: esqueleto na forma dos cartões que substitui. -->
      <div v-if="carregando" class="autorizacoes__lista" aria-busy="true" aria-live="polite">
        <span class="visually-hidden">Carregando as suas autorizações.</span>
        <div v-for="linha in 2" :key="linha" class="esqueleto-grupo">
          <div class="esqueleto esqueleto--animal" />
          <div class="esqueleto esqueleto--cartao" />
        </div>
      </div>

      <div v-else-if="erro" class="aviso aviso--erro" role="alert">
        <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
        <div>
          <p class="aviso__titulo">Não conseguimos carregar as suas autorizações.</p>
          <p class="aviso__texto">{{ erro }}</p>
          <button type="button" class="botao botao--secundario" @click="carregar">
            Tentar novamente
          </button>
        </div>
      </div>

      <!-- Vazio: explica o que é uma autorização antes de oferecer a ação. Quem
           nunca concedeu nenhuma não sabe o que está deixando de fazer. -->
      <EmptyState
        v-else-if="semAutorizacaoAlguma"
        :icone="KeyRound"
        titulo="Nenhuma clínica vê os seus animais"
        descricao="Uma autorização é a permissão que você dá a uma clínica para ver o histórico de um animal seu: vacinas, atendimentos e anexos. Ela vale por um prazo determinado e pode ser revogada quando você quiser."
        class="autorizacoes__vazio"
      >
        <p class="autorizacoes__vazio-apoio">
          Sem autorização, uma clínica só vê o que ela mesma registrou.
        </p>
        <RouterLink to="/prestadores" class="botao botao--consentimento">
          <KeyRound :size="20" :stroke-width="1.75" />
          Autorizar uma clínica
        </RouterLink>
      </EmptyState>

      <p v-else-if="grupos.length === 0" class="autorizacoes__vazio-aba">{{ vazioDaAba }}</p>

      <div v-else class="autorizacoes__lista">
        <section v-for="grupo in grupos" :key="grupo.animal.codigo" class="grupo">
          <RouterLink :to="`/animais/${grupo.animal.codigo}`" class="grupo__animal">
            <span class="grupo__foto">
              <img
                v-if="grupo.animal.foto_url"
                :src="grupo.animal.foto_url"
                :alt="`Foto de ${grupo.animal.nome}`"
              />
              <component
                :is="iconeDaEspecie(grupo.animal.especie)"
                v-else
                :size="24"
                :stroke-width="1.75"
              />
            </span>
            <span class="grupo__dados">
              <span class="grupo__nome">{{ grupo.animal.nome }}</span>
              <span class="grupo__resumo">{{ resumoDoGrupo(grupo) }}</span>
            </span>
          </RouterLink>

          <div class="grupo__cartoes">
            <AuthorizationCard
              v-for="autorizacao in grupo.autorizacoes"
              :key="autorizacao.id"
              :autorizacao="autorizacao"
              :animal="grupo.animal"
              :class="{ 'grupo__destaque': autorizacao.prestador.id === prestadorEmDestaque }"
              @revogar="pedirRevogacao($event, grupo.animal)"
              @renovar="renovar"
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
.autorizacoes {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  width: 100%;
  max-width: 880px;
  margin: 0 auto;
}

.autorizacoes__sobrelinha {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--consent);
}

.autorizacoes__titulo {
  margin: var(--space-1) 0 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  color: var(--ink);
}

.autorizacoes__conceder {
  width: 100%;
}

/* Abas ---------------------------------------------------------------------
   No celular ocupam a largura inteira em três alvos de 48 px; a partir de md
   viram pílulas, como no desenho de 1440 px. */

.abas {
  display: flex;
  gap: var(--space-2);
}

.abas__item {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-2);
  flex: 1;
  height: 48px;
  padding: 0 var(--space-2);
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  font-family: var(--font-body);
  font-size: 16px;
  font-weight: 600;
  color: var(--ink-muted);
  cursor: pointer;
}

.abas__item:hover {
  background: var(--surface-sunken);
}

.abas__item--ativa {
  background: var(--consent-wash);
  border-color: var(--consent);
  color: var(--consent);
}

.abas__contagem {
  font-variant-numeric: tabular-nums;
  font-weight: 400;
}

/* Grupos por animal --------------------------------------------------------- */

.autorizacoes__lista {
  display: flex;
  flex-direction: column;
  gap: var(--space-6);
}

.grupo__animal {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-2) 0;
  color: var(--ink);
}

.grupo__foto {
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

.grupo__foto img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.grupo__dados {
  flex: 1;
  min-width: 0;
}

.grupo__nome {
  display: block;
  font-family: var(--font-display);
  font-size: 22px;
  line-height: 28px;
  font-weight: 600;
}

/* O cabeçalho do grupo leva ao perfil do animal, como o cartão de T02. O
   sublinhado só no nome mantém a linha de resumo legível. */
.grupo__animal:hover .grupo__nome {
  text-decoration: underline;
}

.grupo__resumo {
  display: block;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
}

.grupo__cartoes {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  margin: var(--space-2) 0 0;
}

/* Chegou de T11 ou de T10 procurando esta: o realce dura enquanto a URL o
   pedir, e some na primeira navegação seguinte. */
.grupo__destaque {
  outline: 2px solid var(--consent);
  outline-offset: 2px;
}

/* Vazios -------------------------------------------------------------------- */

.autorizacoes__vazio {
  border-color: var(--consent);
}

.autorizacoes__vazio :deep(.empty-state__icone) {
  color: var(--consent);
}

/* A área de ação do EmptyState é uma linha flexível: sem tomar a linha
   inteira, esta frase ficaria ao lado do botão em vez de acima dele. */
.autorizacoes__vazio-apoio {
  flex: 0 0 100%;
  margin: 0 0 var(--space-1);
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
}

.autorizacoes__vazio-aba {
  margin: 0;
  padding: var(--space-6);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
  text-align: center;
}

/* Avisos e botões — mesmo vocabulário de T10. ------------------------------- */

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

/* O índigo de consentimento é o eixo visual do bloco e não aparece em nenhuma
   outra parte do sistema. */
.botao--consentimento {
  background: var(--consent);
  border-color: var(--consent);
  color: var(--surface-card);
}

.botao--consentimento:hover {
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

/* Esqueleto de carregamento ------------------------------------------------- */

.esqueleto-grupo + .esqueleto-grupo {
  margin: var(--space-6) 0 0;
}

.esqueleto {
  border-radius: var(--radius-xs);
  background: var(--surface-sunken);
  animation: pulsar 1.2s ease-in-out infinite;
}

.esqueleto--animal {
  height: 56px;
  width: 60%;
  border-radius: var(--radius-pill);
}

.esqueleto--cartao {
  height: 180px;
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
  .autorizacoes__cabecalho {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: var(--space-6);
  }

  .autorizacoes__conceder {
    width: auto;
    height: 40px;
    padding: 0 var(--space-4);
    font-size: 14px;
  }

  .abas__item {
    flex: none;
    height: 32px;
    padding: 0 var(--space-3);
    border-radius: var(--radius-pill);
    font-size: 14px;
  }

  /* Dois cartões por linha na coluna de 880 px, como no desenho. */
  .grupo__cartoes {
    display: grid;
    grid-template-columns: 1fr 1fr;
  }
}

@media (min-width: 1024px) {
  .autorizacoes__titulo {
    font-size: 36px;
    line-height: 40px;
    letter-spacing: -.02em;
  }
}
</style>
