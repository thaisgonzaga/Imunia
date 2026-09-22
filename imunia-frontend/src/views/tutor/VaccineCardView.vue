<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import {
  Cat,
  CircleCheck,
  CircleHelp,
  Dog,
  ChevronLeft,
  FileCheck,
  Syringe,
  TriangleAlert,
} from '@lucide/vue'
import TutorShell from '@/components/tutor/TutorShell.vue'
import EmptyState from '@/components/base/EmptyState.vue'
import StatusPill from '@/components/base/StatusPill.vue'
import VaccineRail from '@/components/tutor/VaccineRail.vue'
import BatchSeal from '@/components/tutor/BatchSeal.vue'
import ExportarDocumentoModal from '@/components/tutor/ExportarDocumentoModal.vue'
import { apiGet } from '@/lib/api.js'
import { descreverEspecie } from '@/lib/animais.js'

/**
 * T05 — carteira de vacinação digital (RF28, RF26). Agrupada por
 * imunobiológico, não por data: é como o tutor pensa ("a antirrábica está em
 * dia?"). O grupo em atraso sobe ao topo — o serviço já devolve os grupos
 * nessa ordem — e recebe fundo de alerta.
 */
const route = useRoute()

const carteira = ref(null)
const carregando = ref(true)
const erro = ref('')

async function carregar() {
  carregando.value = true
  erro.value = ''

  try {
    carteira.value = await apiGet(`/api/animais/${route.params.codigo}/carteira`)
    await revelarNovoRegistro()
  } catch (excecao) {
    erro.value = excecao.message
  } finally {
    carregando.value = false
  }
}

onMounted(carregar)
watch(() => route.params.codigo, carregar)

/**
 * T09 chega aqui com `?novo=<id>`: o registro recém-lançado precisa ser
 * encontrado sem procura. Numa carteira com vários imunobiológicos ele pode
 * estar em qualquer grupo, e é a carteira que sabe onde ele caiu — não a tela
 * que o criou.
 */
const novoRegistro = computed(() => Number(route.query.novo) || null)

async function revelarNovoRegistro() {
  if (novoRegistro.value === null) return

  await nextTick()

  const alvo = document.getElementById(`aplicacao-${novoRegistro.value}`)
  alvo?.scrollIntoView({ behavior: 'smooth', block: 'center' })
}

const animal = computed(() => carteira.value?.animal ?? null)

// T15 — exportação em PDF (RF46), modal sobre esta tela.
const exportarAberto = ref(false)
const icone = computed(() => (animal.value?.especie === 'gato' ? Cat : Dog))
const grupos = computed(() => carteira.value?.grupos ?? [])
const resumo = computed(() => carteira.value?.resumo ?? null)
const proximasDoses = computed(() => carteira.value?.proximas_doses ?? [])

const RESUMO_ROTULOS = [
  { chave: 'em_dia', tipo: 'em-dia', singular: 'em dia', plural: 'em dia' },
  { chave: 'proxima', tipo: 'proxima', singular: 'próxima', plural: 'próximas' },
  { chave: 'atrasada', tipo: 'atrasada', singular: 'atrasada', plural: 'atrasadas' },
]

const badgesDeResumo = computed(() => {
  if (!resumo.value) return []

  const badges = RESUMO_ROTULOS
    .filter((item) => resumo.value[item.chave] > 0)
    .map((item) => ({
      tipo: item.tipo,
      texto: `${resumo.value[item.chave]} ${resumo.value[item.chave] === 1 ? item.singular : item.plural}`,
    }))

  if (resumo.value.nao_verificadas > 0) {
    badges.push({
      tipo: 'nao-verificada',
      texto: `${resumo.value.nao_verificadas} não verificada${resumo.value.nao_verificadas === 1 ? '' : 's'}`,
    })
  }

  return badges
})

function emNumeros(iso) {
  return new Intl.DateTimeFormat('pt-BR').format(new Date(`${iso}T00:00:00`))
}

/**
 * Duas aplicações por grupo; o resto fica em T07. A exceção é o registro
 * recém-lançado, que precisa estar visível mesmo quando a sua data o joga
 * para o fim da lista — foi para vê-lo que o tutor veio parar aqui.
 */
function aplicacoesVisiveis(grupo) {
  const visiveis = grupo.aplicacoes.slice(0, 2)

  if (novoRegistro.value === null || visiveis.some((item) => item.id === novoRegistro.value)) {
    return visiveis
  }

  const novo = grupo.aplicacoes.find((item) => item.id === novoRegistro.value)

  return novo ? [...visiveis, novo] : visiveis
}
</script>

<template>
  <!--
    Como em T01 e T02, `amplo` para que a coluna se centre na área de conteúdo
    inteira: a carteira traz a própria largura de leitura, e o limite de 880 px
    da moldura, alinhado à esquerda, a deixaria fora do centro em telas largas.
  -->
  <TutorShell amplo>
    <div class="carteira">
      <RouterLink :to="`/animais/${route.params.codigo}`" class="carteira__voltar">
        <ChevronLeft :size="20" :stroke-width="1.75" />
        Carteira de {{ animal?.nome ?? '' }}
      </RouterLink>

      <!-- Carregando: esqueleto na forma exata do conteúdo que substitui. -->
      <div v-if="carregando" class="carteira__conteudo" aria-busy="true" aria-live="polite">
        <span class="visually-hidden">Carregando a carteira de vacinação.</span>
        <div class="esqueleto esqueleto--titulo" />
        <div class="esqueleto esqueleto--tarja" />
        <div class="esqueleto esqueleto--bloco" />
        <div class="esqueleto esqueleto--bloco" />
      </div>

      <div v-else-if="erro" class="aviso aviso--erro" role="alert">
        <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
        <div>
          <p class="aviso__titulo">Não conseguimos carregar esta carteira.</p>
          <p class="aviso__texto">{{ erro }}</p>
          <button type="button" class="botao botao--secundario" @click="carregar">
            Tentar novamente
          </button>
        </div>
      </div>

      <div v-else class="carteira__conteudo">
        <div class="carteira__cabecalho">
          <div>
            <p class="carteira__sobrelinha">Carteira de vacinação</p>
            <h1 class="carteira__nome">{{ animal.nome }}</h1>
            <div class="carteira__especie">
              <component :is="icone" :size="20" :stroke-width="1.75" />
              {{ descreverEspecie(animal.especie) }}
            </div>
          </div>
          <button type="button" class="botao botao--secundario" @click="exportarAberto = true">
            <FileCheck :size="20" :stroke-width="1.75" class="carteira__icone-consent" />
            Exportar PDF da carteira
          </button>
        </div>

        <!-- RF29 — o retorno de T09. A confirmação diz o que foi feito e
             repete a consequência, porque é agora que ela passa a valer. -->
        <p v-if="novoRegistro" class="aviso aviso--sucesso" role="status">
          <CircleCheck :size="20" :stroke-width="1.75" class="aviso__icone" />
          <span>
            Registro lançado. Ele fica na carteira marcado como não verificado, com o seu nome
            como quem informou.
          </span>
        </p>

        <div v-if="badgesDeResumo.length" class="carteira__resumo">
          <StatusPill v-for="badge in badgesDeResumo" :key="badge.tipo" :tipo="badge.tipo" :texto="badge.texto" />
        </div>

        <EmptyState
          v-if="grupos.length === 0"
          :icone="Syringe"
          titulo="Nenhuma vacinação registrada ainda"
          :descricao="`${animal.nome} ainda não tem carteira. Você pode lançar o que já sabe, ou levá-lo(a) a uma clínica para começar o registro.`"
        >
          <div class="carteira__acoes-vazio">
            <RouterLink :to="`/animais/${animal.codigo}/pregresso/novo`" class="botao botao--primario">
              Lançar histórico pregresso
            </RouterLink>
            <RouterLink to="/prestadores" class="botao botao--secundario">
              Encontrar uma clínica
            </RouterLink>
          </div>
        </EmptyState>

        <template v-else>
          <section v-if="proximasDoses.length" class="cartao">
            <h2 class="cartao__rotulo">Próximas doses</h2>
            <div class="proximas">
              <div v-for="dose in proximasDoses" :key="dose.imunobiologico" class="proxima-dose">
                <Syringe
                  :size="20"
                  :stroke-width="1.75"
                  class="proxima-dose__icone"
                  :class="`proxima-dose__icone--${dose.situacao.tipo}`"
                />
                <div>
                  <p class="proxima-dose__titulo">{{ dose.imunobiologico }}</p>
                  <StatusPill :tipo="dose.situacao.tipo" :texto="dose.situacao.texto" class="proxima-dose__situacao" />
                  <p class="proxima-dose__data">previsto para {{ emNumeros(dose.prevista_para) }}</p>
                  <p class="proxima-dose__regra">{{ dose.regra_texto }}</p>
                </div>
              </div>
            </div>
          </section>

          <section
            v-for="grupo in grupos"
            :key="grupo.imunobiologico.chave"
            class="cartao grupo"
            :class="{ 'grupo--atrasado': grupo.situacao.tipo === 'atrasada' }"
          >
            <div class="grupo__cabecalho">
              <div>
                <h2 class="grupo__nome">{{ grupo.imunobiologico.nome }}</h2>
                <p class="grupo__classificacao">
                  Vacina {{ grupo.imunobiologico.classificacao === 'essencial' ? 'essencial' : 'não essencial' }}
                </p>
              </div>
              <StatusPill :tipo="grupo.situacao.tipo" :texto="grupo.situacao.texto" />
            </div>

            <VaccineRail
              v-if="grupo.estacoes.length"
              :estacoes="grupo.estacoes"
              :nota-regra="grupo.proxima_dose?.regra_texto ?? ''"
              class="grupo__trilho"
            />
            <div v-else class="grupo__sem-data">
              <CircleHelp :size="20" :stroke-width="1.75" />
              <p>Sem data exata registrada — não é possível calcular a próxima dose.</p>
            </div>

            <div class="grupo__aplicacoes">
              <RouterLink
                v-for="aplicacao in aplicacoesVisiveis(grupo)"
                :id="`aplicacao-${aplicacao.id}`"
                :key="aplicacao.id"
                :to="`/animais/${animal.codigo}/vacinas/${aplicacao.id}`"
                class="grupo__selo-link"
                :class="{ 'grupo__selo-link--novo': aplicacao.id === novoRegistro }"
              >
                <BatchSeal :aplicacao="aplicacao" />
              </RouterLink>
              <RouterLink
                v-if="grupo.aplicacoes.length > 2"
                :to="`/animais/${animal.codigo}/historico`"
                class="botao botao--secundario"
              >
                Ver as {{ grupo.aplicacoes.length }} aplicações
              </RouterLink>
            </div>
          </section>
        </template>
      </div>
    </div>
    <!-- T15 — exportar em PDF verificável (RF46), já no recorte da carteira. -->
    <ExportarDocumentoModal
      v-if="exportarAberto && animal"
      :animal="animal"
      conteudo-inicial="carteira"
      @fechar="exportarAberto = false"
    />
  </TutorShell>
</template>

<style scoped>
.carteira {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  width: 100%;
  max-width: 880px;
  margin: 0 auto;
}

.carteira__voltar {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  color: var(--ink-muted);
  font-size: 14px;
  font-weight: 600;
}

.carteira__voltar:hover {
  color: var(--ink);
}

.carteira__conteudo {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.carteira__cabecalho {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: var(--space-4);
  flex-wrap: wrap;
}

.carteira__sobrelinha {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--brand-bright);
}

.carteira__nome {
  margin: var(--space-1) 0 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  color: var(--ink);
}

.carteira__especie {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: var(--space-1) 0 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
}

.carteira__icone-consent {
  color: var(--consent);
}

.carteira__resumo {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  padding: var(--space-3) var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

.carteira__acoes-vazio {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

/* Cartões genéricos --------------------------------------------------- */

.cartao {
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

.cartao__rotulo {
  margin: 0 0 var(--space-3);
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

/* Próximas doses -------------------------------------------------------- */

.proximas {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.proxima-dose {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  padding-top: var(--space-3);
  border-top: 1px solid var(--border-hairline);
}

.proxima-dose:first-child {
  padding-top: 0;
  border-top: none;
}

.proxima-dose__icone {
  flex: none;
  margin-top: 2px;
  color: var(--ink-muted);
}

.proxima-dose__icone--atrasada {
  color: var(--status-late);
}

.proxima-dose__icone--proxima {
  color: var(--status-due-text);
}

.proxima-dose__icone--em-dia {
  color: var(--status-ok);
}

.proxima-dose__titulo {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.proxima-dose__situacao {
  margin: var(--space-1) 0 0;
}

.proxima-dose__data {
  margin: var(--space-1) 0 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
  font-variant-numeric: tabular-nums;
}

.proxima-dose__regra {
  margin: var(--space-1) 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-faint);
}

/* Grupo por imunobiológico ------------------------------------------------ */

.grupo {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.grupo--atrasado {
  background: var(--status-late-wash);
  border-color: var(--status-late);
}

.grupo__cabecalho {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--space-3);
  flex-wrap: wrap;
}

.grupo__nome {
  margin: 0;
  font-family: var(--font-display);
  font-size: 22px;
  line-height: 28px;
  font-weight: 600;
  color: var(--ink);
}

.grupo__classificacao {
  margin: var(--space-1) 0 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
}

.grupo__trilho {
  padding: var(--space-3);
  background: var(--surface-card);
  border-radius: var(--radius-sm);
}

.grupo__sem-data {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  padding: var(--space-3);
  background: var(--surface-card);
  border-radius: var(--radius-sm);
  color: var(--ink-muted);
}

.grupo__sem-data p {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
}

.grupo__aplicacoes {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}

.grupo__selo-link {
  display: block;
  color: inherit;
}

.grupo__selo-link:hover {
  opacity: .92;
}

/* O destaque é um anel, e não uma cor de fundo: o selo do registro pregresso
   já é cinza e hachurado por regra (RN24), e tingi-lo apagaria justamente o
   que ele precisa comunicar. */
.grupo__selo-link--novo {
  border-radius: var(--radius-xs);
  outline: 2px solid var(--brand-bright);
  outline-offset: 3px;
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

.aviso--sucesso {
  align-items: center;
  margin: 0;
  background: var(--brand-wash);
  border: 1px solid var(--brand);
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);
}

.aviso--sucesso .aviso__icone {
  margin-top: 0;
  color: var(--brand);
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

.esqueleto--tarja {
  height: 46px;
  border-radius: var(--radius-md);
}

.esqueleto--bloco {
  height: 160px;
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
  .carteira__cabecalho .botao {
    width: auto;
  }

  .carteira__acoes-vazio {
    flex-direction: row;
    justify-content: center;
  }

  .carteira__acoes-vazio .botao {
    width: auto;
  }

  .grupo__aplicacoes {
    flex-direction: row;
    flex-wrap: wrap;
  }

  .grupo__aplicacoes > * {
    flex: 1;
    min-width: 260px;
  }
}

@media (min-width: 1024px) {
  .carteira__nome {
    font-size: 36px;
    line-height: 40px;
    letter-spacing: -.02em;
  }
}
</style>
