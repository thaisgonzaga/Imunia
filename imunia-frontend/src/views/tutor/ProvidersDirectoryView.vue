<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Building2, ChevronDown, ChevronLeft, KeyRound, TriangleAlert, X } from '@lucide/vue'
import TutorShell from '@/components/tutor/TutorShell.vue'
import EmptyState from '@/components/base/EmptyState.vue'
import { apiGet } from '@/lib/api.js'
import { emNumeros } from '@/lib/datas.js'

/**
 * T10 — diretório de prestadores (RF11). O ponto de partida da autorização:
 * encontrar o estabelecimento que vai atender o animal.
 *
 * O cartão traz o que RF11a permite e nada além — nome, tipo, município e
 * contato público. Não há contagem de animais, de atendimentos nem de
 * profissionais, e a ausência é o desenho: o diretório serve para encontrar
 * quem vai atender, não para comparar tamanho de clínica.
 */
const route = useRoute()
const router = useRouter()

const ESPERA_DA_BUSCA = 300

const busca = ref(typeof route.query.nome === 'string' ? route.query.nome : '')

/**
 * O município escolhido pelo tutor, distinto do que o servidor sugere. Só o que
 * ele escolheu viaja na requisição: enquanto ele não mexer no filtro, a
 * sugestão continua sendo do servidor, e é ela que traz o rótulo com a ação de
 * limpar. `municipio` presente na URL — mesmo vazio — é a marca de que a
 * escolha foi feita, e é o que faz o botão de limpar surtir efeito.
 */
const escolhaDeMunicipio = ref('municipio' in route.query)
const municipioEscolhido = ref(
  typeof route.query.municipio === 'string' && route.query.municipio !== ''
    ? {
        municipio: route.query.municipio,
        uf: typeof route.query.uf === 'string' ? route.query.uf : null,
      }
    : null,
)

const filtro = ref(null)
const municipios = ref([])
const prestadores = ref([])
const total = ref(0)
const exibidos = ref(0)
const carregando = ref(true)
const erro = ref('')
const folhaAberta = ref(false)

function parametros() {
  const query = {}

  if (busca.value.trim() !== '') query.nome = busca.value.trim()

  if (escolhaDeMunicipio.value) {
    query.municipio = municipioEscolhido.value?.municipio ?? ''
    if (municipioEscolhido.value?.uf) query.uf = municipioEscolhido.value.uf
  }

  return query
}

async function carregar() {
  carregando.value = true
  erro.value = ''

  const query = parametros()

  // A URL acompanha o filtro para que voltar de T11 devolva a mesma lista, e
  // para que o município continue escolhido depois de conceder.
  router.replace({ query })

  try {
    const resposta = await apiGet(`/api/prestadores?${new URLSearchParams(query)}`)

    filtro.value = resposta.filtro
    municipios.value = resposta.municipios
    prestadores.value = resposta.prestadores
    total.value = resposta.total
    exibidos.value = resposta.exibidos
  } catch (excecao) {
    erro.value = excecao.message
  } finally {
    carregando.value = false
  }
}

// §6.1 do briefing — busca com atraso de 300 ms. Sem ele, cada tecla vira uma
// consulta, e a lista pisca no meio da digitação.
let atraso = null

watch(busca, () => {
  clearTimeout(atraso)
  atraso = setTimeout(carregar, ESPERA_DA_BUSCA)
})

onBeforeUnmount(() => clearTimeout(atraso))
onMounted(carregar)

function escolherMunicipio(escolha) {
  escolhaDeMunicipio.value = true
  municipioEscolhido.value = escolha
  folhaAberta.value = false
  carregar()
}

const rotuloDoMunicipio = computed(() => filtro.value?.rotulo_municipio ?? null)

const contagem = computed(() =>
  total.value === 1 ? '1 estabelecimento' : `${total.value} estabelecimentos`,
)

/**
 * O vazio tem duas causas, e a frase muda com ela: não há estabelecimento
 * naquele município, ou o nome digitado não corresponde a nenhum dos que há.
 */
const vazioPorBusca = computed(() => busca.value.trim() !== '')

const tituloDoVazio = computed(() => {
  if (vazioPorBusca.value) return `Nenhum estabelecimento com "${busca.value.trim()}"`

  return rotuloDoMunicipio.value
    ? `Nenhum estabelecimento em ${rotuloDoMunicipio.value}`
    : 'Nenhum estabelecimento cadastrado ainda'
})

const descricaoDoVazio = computed(() => {
  if (vazioPorBusca.value) {
    return 'Confira a grafia do nome ou procure pelo município.'
  }

  return 'Só aparecem no diretório os estabelecimentos já cadastrados no Imunia. Procure em um município vizinho ou peça à clínica que se cadastre.'
})

function destinoDaAutorizacao(prestador) {
  // T11 com o prestador pré-selecionado: é o que mantém a concessão dentro dos
  // quatro passos contados desde a tela inicial (RNF14).
  return { path: '/autorizacoes/nova', query: { prestador: prestador.id } }
}

/**
 * A autorização é nominal por animal (RF36a). Com mais de um animal e só parte
 * deles alcançada, "autorizado" sem qualificação seria lido como se todos
 * estivessem cobertos — e não estão.
 *
 * "Autorizado para Théo" em vez de "Théo autorizado" porque a etiqueta fala do
 * prestador, e porque assim ela começa igual nos dois casos e escapa da
 * concordância de gênero: o sexo do animal não é dado desta tela.
 */
function rotuloDaEtiqueta(autorizacao) {
  const prazo = `até ${emNumeros(autorizacao.expira_em)}`

  if (autorizacao.pendentes === 0) return `Autorizado ${prazo}`

  const animais = autorizacao.animais
  const nomes =
    animais.length > 1
      ? `${animais.slice(0, -1).join(', ')} e ${animais.at(-1)}`
      : animais[0]

  return `Autorizado para ${nomes} ${prazo}`
}
</script>

<template>
  <!--
    `amplo` mais a coluna centrada abaixo, como em T01: o limite de 880 px da
    moldura, alinhado à esquerda, deixaria o conteúdo fora do centro da área de
    leitura em telas largas.
  -->
  <TutorShell amplo>
    <div class="diretorio">
      <RouterLink to="/autorizacoes" class="diretorio__voltar">
        <ChevronLeft :size="20" :stroke-width="1.75" />
        Minhas autorizações
      </RouterLink>

      <div class="diretorio__cabecalho">
        <p class="diretorio__sobrelinha">Diretório</p>
        <h1 class="diretorio__titulo">Quem vai atender seu animal?</h1>
      </div>

      <div class="filtros">
        <label class="filtros__busca">
          <Building2 :size="20" :stroke-width="1.75" class="filtros__busca-icone" />
          <span class="visually-hidden">Buscar estabelecimento por nome</span>
          <input v-model="busca" type="search" placeholder="Buscar por nome" class="filtros__campo" />
        </label>

        <div class="filtros__linha">
          <!-- O município em vigor, sugerido ou escolhido, com a ação de
               limpar: sem ela, a sugestão viraria uma restrição sem saída. -->
          <span v-if="rotuloDoMunicipio" class="chip">
            {{ rotuloDoMunicipio }}
            <button
              type="button"
              class="chip__limpar"
              :aria-label="`Ver todos os municípios, sem o filtro de ${rotuloDoMunicipio}`"
              @click="escolherMunicipio(null)"
            >
              <X :size="14" :stroke-width="2" />
            </button>
          </span>

          <button
            type="button"
            class="filtros__gatilho"
            :aria-expanded="folhaAberta"
            aria-controls="filtros-municipios"
            @click="folhaAberta = !folhaAberta"
          >
            {{ rotuloDoMunicipio ? 'Trocar o município' : 'Filtrar por município' }}
            <ChevronDown :size="20" :stroke-width="1.75" class="filtros__gatilho-icone" />
          </button>

          <span v-if="!carregando && !erro" class="filtros__contagem">{{ contagem }}</span>
        </div>
      </div>

      <!-- Abaixo de md a lista de municípios é folha inferior, conforme §6.1. -->
      <div v-if="folhaAberta" class="filtros__cortina" aria-hidden="true" @click="folhaAberta = false" />
      <div
        v-if="folhaAberta"
        id="filtros-municipios"
        class="filtros__folha"
        role="dialog"
        aria-label="Escolher o município"
      >
        <div class="filtros__folha-cabecalho">
          <span class="filtros__folha-titulo">Município</span>
          <button type="button" class="filtros__fechar" @click="folhaAberta = false">
            <X :size="20" :stroke-width="1.75" />
            <span class="visually-hidden">Fechar a escolha de município</span>
          </button>
        </div>

        <div class="filtros__opcoes">
          <button
            type="button"
            class="pilula"
            :class="{ 'pilula--ativa': !rotuloDoMunicipio }"
            :aria-pressed="!rotuloDoMunicipio"
            @click="escolherMunicipio(null)"
          >
            Todos os municípios
          </button>
          <button
            v-for="opcao in municipios"
            :key="opcao.rotulo"
            type="button"
            class="pilula"
            :class="{ 'pilula--ativa': opcao.rotulo === rotuloDoMunicipio }"
            :aria-pressed="opcao.rotulo === rotuloDoMunicipio"
            @click="escolherMunicipio(opcao)"
          >
            {{ opcao.rotulo }}
          </button>
        </div>
      </div>

      <!-- Carregando: esqueleto na forma exata dos cartões que substitui. -->
      <div v-if="carregando" class="diretorio__lista" aria-busy="true" aria-live="polite">
        <span class="visually-hidden">Carregando o diretório de prestadores.</span>
        <div v-for="linha in 3" :key="linha" class="cartao">
          <div class="esqueleto esqueleto--nome" />
          <div class="esqueleto esqueleto--meta" />
          <div class="esqueleto esqueleto--acao" />
        </div>
      </div>

      <div v-else-if="erro" class="aviso aviso--erro" role="alert">
        <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
        <div>
          <p class="aviso__titulo">Não conseguimos carregar o diretório.</p>
          <p class="aviso__texto">{{ erro }}</p>
          <button type="button" class="botao botao--secundario" @click="carregar">
            Tentar novamente
          </button>
        </div>
      </div>

      <EmptyState
        v-else-if="prestadores.length === 0"
        :icone="Building2"
        :titulo="tituloDoVazio"
        :descricao="descricaoDoVazio"
      >
        <button type="button" class="botao botao--consentimento" @click="folhaAberta = true">
          Trocar o município
        </button>
      </EmptyState>

      <template v-else>
        <div class="diretorio__lista">
          <article v-for="prestador in prestadores" :key="prestador.id" class="cartao">
            <h2 class="cartao__nome">{{ prestador.nome }}</h2>
            <p class="cartao__meta">
              {{ prestador.tipo_rotulo }} · {{ prestador.municipio }}, {{ prestador.uf }}
            </p>
            <p class="cartao__contato">{{ prestador.telefone }}</p>

            <template v-if="prestador.autorizacao">
              <span class="etiqueta">
                <KeyRound :size="14" :stroke-width="1.75" />
                {{ rotuloDaEtiqueta(prestador.autorizacao) }}
              </span>

              <RouterLink
                :to="{ path: '/autorizacoes', query: { prestador: prestador.id } }"
                class="botao botao--secundario cartao__acao"
              >
                Ver esta autorização
              </RouterLink>

              <!-- Autorizado para um animal não é autorizado para os outros: o
                   caminho de conceder continua à vista enquanto houver animal
                   de fora. -->
              <RouterLink
                v-if="prestador.autorizacao.pendentes > 0"
                :to="destinoDaAutorizacao(prestador)"
                class="cartao__secundaria"
              >
                Autorizar outro animal
              </RouterLink>
            </template>

            <RouterLink
              v-else
              :to="destinoDaAutorizacao(prestador)"
              class="botao botao--consentimento cartao__acao"
            >
              <KeyRound :size="20" :stroke-width="1.75" />
              Autorizar acesso
            </RouterLink>
          </article>
        </div>

        <p v-if="exibidos < total" class="diretorio__resto">
          Mostrando {{ exibidos }} de {{ total }} estabelecimentos. Filtre por município ou busque
          pelo nome para encontrar o que procura.
        </p>
      </template>
    </div>
  </TutorShell>
</template>

<style scoped>
.diretorio {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  width: 100%;
  max-width: 880px;
  margin: 0 auto;
}

.diretorio__voltar {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  align-self: flex-start;
  height: 44px;
  color: var(--ink-muted);
  font-size: 16px;
  line-height: 24px;
}

.diretorio__voltar:hover {
  color: var(--ink);
}

.diretorio__sobrelinha {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--consent);
}

.diretorio__titulo {
  margin: var(--space-1) 0 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  color: var(--ink);
}

/* Barra de filtros ---------------------------------------------------------
   Um bloco só, em cartão, porque busca, município e contagem respondem juntos
   à mesma pergunta: o que está sendo listado agora. */

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
  border-color: var(--consent);
}

.filtros__busca-icone {
  flex: none;
  color: var(--ink-muted);
}

.filtros__campo {
  flex: 1;
  min-width: 0;
  border: none;
  outline: none;
  background: transparent;
  font-family: var(--font-body);
  font-size: 16px;
  color: var(--ink);
}

.filtros__campo::placeholder {
  color: var(--ink-faint);
}

.filtros__linha {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: var(--space-2);
}

.filtros__gatilho {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  height: 44px;
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

.filtros__gatilho:hover {
  background: var(--surface-sunken);
}

.filtros__gatilho-icone {
  flex: none;
  color: var(--ink-muted);
}

.filtros__contagem {
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
  font-variant-numeric: tabular-nums;
}

.chip {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  height: 44px;
  padding: 0 var(--space-2) 0 var(--space-3);
  border-radius: var(--radius-pill);
  background: var(--consent-wash);
  color: var(--consent);
  font-size: 14px;
  font-weight: 600;
}

.chip__limpar {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  background: none;
  border: none;
  border-radius: var(--radius-pill);
  color: inherit;
  cursor: pointer;
}

/* O desenho pede um ✕ discreto dentro da etiqueta, e o briefing pede 44 px de
   alvo abaixo de 1024 px (§4.3). O botão fica com o tamanho do desenho e o
   alvo cresce por fora dele: é a única ação do chip, então a área estendida
   não disputa toque com nada. */
.chip__limpar::after {
  content: '';
  position: absolute;
  inset: -8px;
}

.chip__limpar:hover {
  background: rgb(59 78 140 / .14);
}

/* Folha inferior de municípios --------------------------------------------- */

.filtros__cortina {
  position: fixed;
  inset: 0;
  z-index: 20;
  background: rgb(20 35 31 / .32);
}

.filtros__folha {
  position: fixed;
  inset: auto 0 0;
  z-index: 21;
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  max-height: 70vh;
  overflow-y: auto;
  padding: var(--space-4);
  background: var(--surface-card);
  border-top: 1px solid var(--border-hairline);
  border-radius: var(--radius-lg) var(--radius-lg) 0 0;
  box-shadow: var(--shadow-modal);
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

.filtros__opcoes {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
}

.pilula {
  display: inline-flex;
  align-items: center;
  height: 36px;
  padding: 0 var(--space-3);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-pill);
  font-family: var(--font-body);
  font-size: 14px;
  font-weight: 600;
  color: var(--ink-muted);
  cursor: pointer;
}

.pilula--ativa {
  background: var(--consent-wash);
  border-color: var(--consent);
  color: var(--consent);
}

/* Cartões ------------------------------------------------------------------ */

.diretorio__lista {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.cartao {
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

.cartao__nome {
  margin: 0;
  font-size: 18px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.cartao__meta,
.cartao__contato {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
}

.cartao__contato {
  font-variant-numeric: tabular-nums;
}

.cartao__acao {
  width: 100%;
  margin: var(--space-3) 0 0;
}

.cartao__secundaria {
  display: inline-flex;
  align-items: center;
  height: 44px;
  margin: var(--space-1) 0 0;
  color: var(--consent);
  font-size: 16px;
  font-weight: 600;
}

.etiqueta {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  /* 22 px é a altura do desenho, e é a que vale enquanto o texto couber numa
     linha; nomeando dois ou três animais, a etiqueta cresce em vez de cortar. */
  min-height: 22px;
  margin: var(--space-3) 0 0;
  padding: 3px 10px;
  border-radius: var(--radius-pill);
  background: var(--consent-wash);
  color: var(--consent);
  font-size: 12px;
  line-height: 16px;
  font-weight: 600;
}

.diretorio__resto {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
}

/* Avisos e botões — mesmo vocabulário de T02. ------------------------------ */

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
   outra parte do sistema: a ação que leva à concessão o carrega desde aqui. */
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

/* Esqueleto de carregamento ------------------------------------------------ */

.esqueleto {
  border-radius: var(--radius-xs);
  background: var(--surface-sunken);
  animation: pulsar 1.2s ease-in-out infinite;
}

.esqueleto--nome {
  height: 24px;
  width: 60%;
}

.esqueleto--meta {
  height: 20px;
  width: 80%;
  margin: var(--space-2) 0 0;
}

.esqueleto--acao {
  height: 48px;
  margin: var(--space-3) 0 0;
  border-radius: var(--radius-sm);
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
    gap: var(--space-3);
  }

  .filtros__busca {
    flex: 1;
    min-width: 220px;
  }

  /* A folha some com o celular: a partir de md a escolha do município cabe na
     própria barra, e a cortina só atrapalharia. */
  .filtros__cortina {
    display: none;
  }

  .filtros__folha {
    position: static;
    max-height: none;
    padding: 0;
    border: none;
    border-radius: 0;
    box-shadow: none;
  }

  .filtros__folha-cabecalho {
    display: none;
  }

  .diretorio__lista {
    display: grid;
    grid-template-columns: 1fr 1fr;
  }
}

@media (min-width: 1024px) {
  .diretorio__titulo {
    font-size: 36px;
    line-height: 40px;
    letter-spacing: -.02em;
  }
}
</style>
