<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  Cat,
  Dog,
  Eye,
  PawPrint,
  QrCode,
  TriangleAlert,
} from '@lucide/vue'
import VetShell from '@/components/vet/VetShell.vue'
import StatusPill from '@/components/base/StatusPill.vue'
import EmptyState from '@/components/base/EmptyState.vue'
import { apiGet } from '@/lib/api.js'
import { descreverAnimal } from '@/lib/animais.js'
import {
  CODIGO,
  CPF,
  MICROCHIP,
  classificarTermo,
  termoConsultavel,
  termoParaExibicao,
} from '@/lib/busca.js'

/**
 * V03 — buscar animal ou tutor (RF51, RF18, RF13).
 *
 * O atendimento não depende do tutor: a busca por chave exata (CPF, código ou
 * micro-chip) traz o cadastro inteiro, de qualquer clínica, e a busca por nome
 * percorre os animais que esta clínica já acompanha. O cartão diz quando o
 * animal ainda não é acompanhado aqui — abrir a ficha dele fica registrado e
 * visível ao tutor (RF18b), e o animal passa a ser acompanhado.
 */
const route = useRoute()
const router = useRouter()

const termo = ref('')
const consulta = ref(null)
const carregando = ref(true)
const buscando = ref(false)
const erro = ref('')
/** Erro do próprio termo (CPF com dígito inválido), que nem chega a consultar. */
const erroDoTermo = ref('')

const campoDoTopo = ref(null)
const campoDoConteudo = ref(null)
const resultados = ref(null)

const estado = computed(() => consulta.value?.estado ?? 'inicial')
const inicial = computed(() => estado.value === 'inicial')
// A chave ainda se chama `autorizados` na resposta; o conteúdo é o de todos os
// animais encontrados, acompanhados ou não por esta clínica.
const encontrados = computed(() => consulta.value?.autorizados ?? [])
/** Pelo CPF, o titular encontrado — com ou sem animal. */
const tutor = computed(() => consulta.value?.tutor ?? null)
const prestador = computed(() => consulta.value?.prestador?.nome ?? 'este prestador')

/** O tipo detectado do que está sendo digitado — o rótulo ao lado do campo. */
const tipoDigitado = computed(() => classificarTermo(termo.value))

/** O tipo do que o servidor respondeu, que é o que o resultado descreve. */
const tipoRespondido = computed(() => consulta.value?.tipo ?? null)

const termoRespondido = computed(() =>
  consulta.value?.termo ? termoParaExibicao(consulta.value.termo) : '',
)

/**
 * O CPF da resposta, só dígitos, para seguir ao cadastro do animal. Viaja no
 * estado da navegação, como em V04, e nunca no endereço.
 */
const cpfRespondido = computed(() => (consulta.value?.termo ?? '').replace(/\D/g, ''))

const CABECALHOS = {
  [CPF]: 'Resultados para o CPF',
  [CODIGO]: 'Resultados para',
  [MICROCHIP]: 'Resultados para o micro-chip',
}

const VAZIOS = {
  [CPF]: {
    titulo: 'Nenhum cadastro corresponde a este CPF',
    descricao:
      'Confira o número com o tutor. Se ele ainda não está no Imunia, cadastre-o agora — o animal vem em seguida.',
  },
  [CODIGO]: {
    titulo: 'Nenhum cadastro corresponde a este código',
    descricao:
      'Confira os caracteres com o tutor — o código tem o formato IM-0000-0000. Se o animal ainda não está no Imunia, cadastre-o agora.',
  },
  [MICROCHIP]: {
    titulo: 'Nenhum cadastro corresponde a este micro-chip',
    descricao:
      'Confira o número no leitor. Se o animal ainda não está no Imunia, cadastre-o agora — o micro-chip entra na caracterização.',
  },
}

const vazio = computed(
  () =>
    VAZIOS[tipoRespondido.value] ?? {
      titulo: `Nenhum cadastro corresponde a “${termoRespondido.value}”`,
      descricao:
        'Confira a grafia, ou procure pelo CPF do tutor, pelo código do animal ou pelo micro-chip.',
    },
)

function contar(quantidade) {
  return `${quantidade} ${quantidade === 1 ? 'resultado' : 'resultados'}`
}

function iconeDaEspecie(especie) {
  return especie === 'gato' ? Cat : Dog
}

function parametros(termoDaVez) {
  const busca = new URLSearchParams()

  if (termoDaVez) busca.set('termo', termoDaVez)
  if (consulta.value?.prestador) busca.set('prestador', consulta.value.prestador.id)

  return busca
}

/**
 * Sem termo, a rota devolve só o contexto clínico — prestador ativo e vínculos.
 * É o que a moldura precisa para desenhar a faixa de contexto antes da primeira
 * consulta, e nada nela é consultado nem registrado.
 */
async function carregar(termoDaVez = '', { abrindo = false } = {}) {
  // Com termo, é uma busca sobre a tela já aberta. Abrindo a tela com um termo
  // — o código que o leitor de QR Code entregou —, é a própria abertura.
  if (termoDaVez && !abrindo) buscando.value = true
  else carregando.value = true

  erro.value = ''

  try {
    consulta.value = await apiGet(`/api/clinica/buscar?${parametros(termoDaVez)}`)
  } catch (excecao) {
    erro.value = excecao.message
  } finally {
    carregando.value = false
    buscando.value = false
  }
}

/**
 * RF13 — a conferência do dígito acontece antes da consulta, e por isso a
 * requisição sequer sai. É o que sustenta a frase que a tela exibe nesse
 * estado: nada foi consultado, nada foi registrado.
 */
function termoValido() {
  erroDoTermo.value = ''

  const { tipo, valor } = tipoDigitado.value
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

/**
 * Há dois campos no documento — o do conteúdo e o da faixa do cabeçalho —, e
 * qual deles está à vista depende da largura. Focar pelo primeiro que existe
 * não serve: o oculto continua no DOM, e `focus()` sobre elemento com
 * `display: none` não faz nada, silenciosamente.
 */
async function focarNoCampo() {
  await nextTick()

  const campo = [campoDoConteudo.value, campoDoTopo.value].find((alvo) => alvo?.offsetParent)
  campo?.focus()
  campo?.select()
}

/**
 * O atalho anunciado no rodapé do estado inicial. A moldura já leva `/` até
 * esta rota; estando nela, navegar de novo não faria nada — quem devolve o
 * cursor ao campo é a própria tela.
 */
function aoTeclar(evento) {
  if (evento.key !== '/' || evento.metaKey || evento.ctrlKey || evento.altKey) return

  const alvo = evento.target
  if (alvo instanceof Element && alvo.closest('input, textarea, select, [contenteditable="true"]')) return

  evento.preventDefault()
  focarNoCampo()
}

/**
 * `↑ ↓` percorre os resultados, como o rodapé promete. O percurso começa no
 * campo e segue pelos cartões, que são ligações e por isso já focalizáveis —
 * o que falta é a ordem, e é ela que se dá aqui.
 */
function percorrer(evento) {
  if (evento.key !== 'ArrowDown' && evento.key !== 'ArrowUp') return

  const focalizaveis = Array.from(resultados.value?.querySelectorAll('[data-resultado]') ?? [])
  if (focalizaveis.length === 0) return

  const atual = focalizaveis.indexOf(document.activeElement)
  const proximo = evento.key === 'ArrowDown' ? atual + 1 : atual - 1

  if (proximo < 0) {
    evento.preventDefault()
    focarNoCampo()

    return
  }

  if (proximo >= focalizaveis.length) return

  evento.preventDefault()
  focalizaveis[proximo].focus()
}

onMounted(() => {
  const lido = termoDoEndereco()

  if (lido) {
    termo.value = lido
    router.replace({ path: route.path, query: {} })
  }

  if (lido && termoValido()) carregar(lido, { abrindo: true })
  else carregar()

  document.addEventListener('keydown', aoTeclar)
})

onBeforeUnmount(() => document.removeEventListener('keydown', aoTeclar))
</script>

<template>
  <VetShell
    titulo="Buscar"
    :prestador="consulta?.prestador"
    :vinculos="consulta?.vinculos ?? []"
    @trocar-prestador="trocarPrestador"
  >
    <!-- §8.3 — o campo é proeminente e centrado no primeiro uso, e desloca-se
         para o topo depois da primeira consulta. Antes dela, a faixa do
         cabeçalho conduz ao campo grande em vez de duplicá-lo. -->
    <template #busca>
      <button v-if="inicial" type="button" class="atalho-do-topo" @click="focarNoCampo">
        <PawPrint :size="16" :stroke-width="1.75" class="atalho-do-topo__icone" />
        <span>Buscar animal, tutor ou código</span>
        <kbd class="atalho-do-topo__tecla">/</kbd>
      </button>

      <form
        v-else
        class="campo-do-topo"
        :class="{ 'campo-do-topo--invalido': erroDoTermo }"
        role="search"
        @submit.prevent="buscar"
      >
        <PawPrint :size="16" :stroke-width="1.75" class="campo-do-topo__icone" />
        <input
          ref="campoDoTopo"
          v-model="termo"
          type="search"
          class="campo-do-topo__entrada"
          :class="{ 'campo-do-topo__entrada--codigo': tipoDigitado.tipo !== 'nome' }"
          aria-label="Buscar animal ou tutor"
          :aria-invalid="Boolean(erroDoTermo)"
          @keydown.down="percorrer"
        >
        <span class="campo-do-topo__tipo">{{ tipoDigitado.rotulo }}</span>
      </form>
    </template>

    <div v-if="carregando" class="busca" aria-busy="true" aria-live="polite">
      <span class="visually-hidden">Abrindo a busca.</span>
      <div class="heroi">
        <div class="esqueleto esqueleto--titulo" />
        <div class="esqueleto esqueleto--campo" />
        <div class="exemplos">
          <div v-for="linha in 4" :key="linha" class="esqueleto esqueleto--exemplo" />
        </div>
      </div>
    </div>

    <div v-else-if="erro" class="busca">
      <div class="aviso aviso--erro" role="alert">
        <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
        <div>
          <p class="aviso__titulo">Não conseguimos concluir a busca.</p>
          <p class="aviso__texto">{{ erro }}</p>
          <button type="button" class="botao botao--secundario" @click="carregar(consulta?.termo ?? '')">
            Tentar novamente
          </button>
        </div>
      </div>
    </div>

    <div v-else class="busca" @keydown="percorrer">
      <!-- Estado inicial: o campo grande, os formatos aceitos e o aviso do que
           acontece quando se procura fora da própria carteira. -->
      <div v-if="inicial" class="heroi">
        <h1 class="heroi__titulo">Buscar animal ou tutor</h1>

        <form class="heroi__campo-linha" role="search" @submit.prevent="buscar">
          <div class="campo" :class="{ 'campo--invalido': erroDoTermo }">
            <PawPrint :size="20" :stroke-width="1.75" class="campo__icone" />
            <input
              ref="campoDoConteudo"
              v-model="termo"
              type="search"
              class="campo__entrada"
              placeholder="Nome, CPF, código do animal ou micro-chip"
              aria-label="Buscar animal ou tutor"
              :aria-invalid="Boolean(erroDoTermo)"
              :aria-describedby="erroDoTermo ? 'erro-do-termo' : undefined"
            >
            <span v-if="termo.trim()" class="campo__tipo">{{ tipoDigitado.rotulo }}</span>
          </div>

          <!-- O leitor de QR é da moldura do celular (§8.3). O código lido volta
               para cá pelo endereço, em `?termo=`, e a busca segue como se ele
               tivesse sido digitado. -->
          <RouterLink to="/clinica/buscar/qr" class="leitor-qr" aria-label="Ler QR Code do animal">
            <QrCode :size="24" :stroke-width="1.75" />
          </RouterLink>
        </form>

        <p v-if="erroDoTermo" id="erro-do-termo" class="erro-do-termo" role="alert">
          <TriangleAlert :size="16" :stroke-width="1.75" class="erro-do-termo__icone" />
          {{ erroDoTermo }}
        </p>

        <div v-if="erroDoTermo" class="nada-consultado">
          <p class="nada-consultado__texto">
            Nenhuma consulta foi feita e nada foi registrado: a validação acontece antes de
            qualquer busca, para não gerar registro de acesso a partir de um número digitado
            errado.
          </p>
        </div>

        <dl class="exemplos">
          <div class="exemplo">
            <dt class="exemplo__rotulo">CPF do tutor</dt>
            <dd class="exemplo__forma exemplo__forma--numero">000.000.000-00</dd>
          </div>
          <div class="exemplo">
            <dt class="exemplo__rotulo">Código do animal</dt>
            <dd class="exemplo__forma">IM-0000-0000</dd>
          </div>
          <div class="exemplo">
            <dt class="exemplo__rotulo">Micro-chip</dt>
            <dd class="exemplo__forma exemplo__forma--numero">000000000000000</dd>
          </div>
          <div class="exemplo">
            <dt class="exemplo__rotulo">Nome do animal ou do tutor</dt>
            <dd class="exemplo__forma exemplo__forma--texto">Théo, Helena Ramos</dd>
          </div>
        </dl>

        <!-- RF18b anunciado antes de acontecer: o profissional decide procurar
             sabendo que a procura fora da própria carteira fica registrada. -->
        <div class="aviso-de-registro">
          <Eye :size="20" :stroke-width="1.75" class="aviso-de-registro__icone" />
          <p class="aviso-de-registro__texto">
            Buscas por CPF, código ou micro-chip de animal que a clínica ainda não acompanha
            ficam registradas e visíveis ao tutor. A busca por nome percorre só os animais que
            a clínica já acompanha.
          </p>
        </div>

        <p class="atalhos">
          Atalho: pressione <kbd>/</kbd> em qualquer tela para voltar a este campo ·
          <kbd>Enter</kbd> busca · <kbd>↑ ↓</kbd> percorre os resultados
        </p>
      </div>

      <!-- Resultado. Em celular o campo continua aqui, porque a faixa do
           cabeçalho não cabe naquela largura. -->
      <template v-else>
        <form class="campo-linha-estreita" role="search" @submit.prevent="buscar">
          <div class="campo" :class="{ 'campo--invalido': erroDoTermo }">
            <PawPrint :size="20" :stroke-width="1.75" class="campo__icone" />
            <input
              ref="campoDoConteudo"
              v-model="termo"
              type="search"
              class="campo__entrada"
              aria-label="Buscar animal ou tutor"
              :aria-invalid="Boolean(erroDoTermo)"
            >
          </div>
          <RouterLink to="/clinica/buscar/qr" class="leitor-qr" aria-label="Ler QR Code do animal">
            <QrCode :size="24" :stroke-width="1.75" />
          </RouterLink>
        </form>

        <!-- O erro do termo acompanha o campo, e o campo muda de lugar com a
             largura: embaixo dele no celular, logo abaixo da faixa do
             cabeçalho no desktop, que é onde ele está ali. -->
        <p v-if="erroDoTermo" class="erro-do-termo erro-do-termo--bloco" role="alert">
          <TriangleAlert :size="16" :stroke-width="1.75" class="erro-do-termo__icone" />
          {{ erroDoTermo }}
        </p>

        <div v-if="erroDoTermo" class="nada-consultado">
          <p class="nada-consultado__texto">
            Nenhuma consulta foi feita e nada foi registrado: a validação acontece antes de
            qualquer busca, para não gerar registro de acesso a partir de um número digitado
            errado.
          </p>
        </div>

        <div class="busca__cabecalho">
          <div>
            <p class="busca__sobrelinha">Busca</p>
            <h1 class="busca__titulo">
              {{ CABECALHOS[tipoRespondido] ?? 'Resultados para' }}
              <span v-if="CABECALHOS[tipoRespondido]" class="busca__termo">{{ termoRespondido }}</span>
              <span v-else>“{{ termoRespondido }}”</span>
            </h1>
          </div>
          <span v-if="tipoRespondido === CPF" class="busca__deteccao">
            CPF reconhecido automaticamente
          </span>
        </div>

        <div v-if="buscando" class="secao" aria-busy="true" aria-live="polite">
          <span class="visually-hidden">Buscando.</span>
          <div class="cartoes">
            <div v-for="linha in 3" :key="linha" class="esqueleto esqueleto--cartao" />
          </div>
        </div>

        <div v-else ref="resultados" aria-live="polite">
          <div v-if="estado === 'sem_resultado'" class="sem-resultado">
            <EmptyState :icone="PawPrint" :titulo="vazio.titulo" :descricao="vazio.descricao">
              <RouterLink to="/clinica/animais/novo" class="botao botao--primario">
                Cadastrar animal
              </RouterLink>
              <RouterLink to="/clinica/tutores/novo" class="botao botao--secundario">
                Cadastrar tutor
              </RouterLink>
            </EmptyState>
          </div>

          <template v-else>
            <section class="secao">
              <!-- Pelo CPF a resposta traz o titular, com ou sem animal: é o
                   que permite seguir ao cadastro do primeiro. -->
              <p v-if="tutor" class="secao__tutor">
                Tutor: <strong>{{ tutor.nome }}</strong>
              </p>

              <div class="secao__cabecalho">
                <h2 class="secao__rotulo">Animais</h2>
                <span class="secao__contagem">{{ contar(encontrados.length) }}</span>
              </div>

              <div v-if="encontrados.length" class="cartoes">
                <RouterLink
                  v-for="animal in encontrados"
                  :key="animal.codigo"
                  :to="`/clinica/animais/${animal.codigo}`"
                  class="cartao-animal"
                  data-resultado
                >
                  <span class="cartao-animal__icone">
                    <component :is="iconeDaEspecie(animal.especie)" :size="20" :stroke-width="1.75" />
                  </span>
                  <span class="cartao-animal__texto">
                    <span class="cartao-animal__nome">{{ animal.nome }}</span>
                    <span class="cartao-animal__meta">
                      {{ descreverAnimal(animal) }} · {{ animal.tutor }}
                    </span>
                    <span class="cartao-animal__codigo">{{ animal.codigo }}</span>
                    <span v-if="!animal.vinculado" class="cartao-animal__vinculo">
                      ainda não acompanhado por {{ prestador }}
                    </span>
                  </span>
                  <StatusPill
                    v-if="animal.situacao"
                    :tipo="animal.situacao.tipo"
                    :texto="animal.situacao.texto_curto"
                  />
                  <!-- RF50 — sem vacinação registrada o sistema diz que ainda
                       não sabe, e nunca "em dia" por omissão. -->
                  <StatusPill v-else tipo="nao-verificada" texto="sem dados" />
                </RouterLink>
              </div>

              <p v-else-if="tutor" class="secao__vazio">
                {{ tutor.nome }} ainda não tem animal cadastrado no Imunia.
                <RouterLink :to="{ path: '/clinica/animais/novo', state: { cpf: cpfRespondido } }">
                  Cadastrar o animal
                </RouterLink>
              </p>
            </section>
          </template>
        </div>
      </template>
    </div>

  </VetShell>
</template>

<style scoped>
.busca {
  max-width: 1280px;
  margin: 0 auto;
}

/* Campo do cabeçalho ------------------------------------------------------- */

.atalho-do-topo,
.campo-do-topo {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  width: 100%;
  height: 36px;
  padding: 0 var(--space-3);
  background: var(--surface-sunken);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  font-size: 14px;
  text-align: left;
  color: var(--ink-faint);
  cursor: pointer;
}

.atalho-do-topo:hover {
  border-color: var(--brand-bright);
  color: var(--ink-muted);
}

.atalho-do-topo__icone,
.campo-do-topo__icone {
  flex: none;
  color: var(--ink-muted);
}

.atalho-do-topo__tecla {
  margin-left: auto;
  font-family: var(--font-mono);
  font-size: 12px;
  color: var(--ink-faint);
}

.campo-do-topo {
  cursor: text;
}

.campo-do-topo__entrada {
  flex: 1;
  min-width: 0;
  border: 0;
  outline: none;
  background: none;
  font-family: inherit;
  font-size: 14px;
  color: var(--ink);
}

/* Chave exata é número ditado e transcrito: monoespaçada, para conferir
   caractere a caractere contra o papel. */
.campo-do-topo__entrada--codigo {
  font-family: var(--font-mono);
  font-size: 13px;
  font-variant-numeric: tabular-nums;
}

.campo-do-topo__tipo {
  flex: none;
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
}

/* Estado inicial ----------------------------------------------------------- */

.heroi {
  width: 640px;
  max-width: 100%;
  margin: 0 auto;
  padding: var(--space-8) 0 var(--space-12);
}

.heroi__titulo {
  margin: 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  letter-spacing: -.02em;
  color: var(--ink);
  text-align: center;
}

.heroi__campo-linha,
.campo-linha-estreita {
  display: flex;
  gap: var(--space-2);
  margin: var(--space-6) 0 0;
}

.campo-linha-estreita {
  margin: 0 0 var(--space-6);
}

/* Com erro logo abaixo, o campo o encosta em vez de o soltar no meio da tela. */
.campo-linha-estreita:has(+ .erro-do-termo) {
  margin-bottom: var(--space-2);
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
  border: 0;
  outline: none;
  background: none;
  font-family: inherit;
  font-size: 16px;
  color: var(--ink);
}

.campo__tipo {
  flex: none;
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
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

.erro-do-termo {
  display: flex;
  align-items: flex-start;
  gap: var(--space-2);
  margin: var(--space-2) 0 0;
  font-size: 12px;
  line-height: 16px;
  color: var(--status-late);
}

.erro-do-termo--bloco {
  margin: 0 0 var(--space-3);
}

.erro-do-termo__icone {
  flex: none;
}

/* O campo perde a borda neutra ao acusar erro, no cabeçalho como no conteúdo. */
.campo-do-topo--invalido {
  border-color: var(--status-late);
}

.nada-consultado {
  max-width: 75ch;
  margin: var(--space-4) 0 var(--space-6);
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

.nada-consultado__texto {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.exemplos {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--space-3);
  margin: var(--space-4) 0 0;
}

.exemplo {
  padding: var(--space-3) var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-sm);
}

.exemplo__rotulo {
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
}

.exemplo__forma {
  margin: 0;
  font-family: var(--font-mono);
  font-size: 13px;
  line-height: 18px;
  font-weight: 500;
  color: var(--ink);
}

.exemplo__forma--numero {
  font-variant-numeric: tabular-nums;
}

.exemplo__forma--texto {
  font-family: var(--font-body);
  font-size: 14px;
  line-height: 20px;
  font-weight: 400;
}

.aviso-de-registro {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  margin: var(--space-4) 0 0;
  padding: var(--space-4);
  background: var(--surface-sunken);
  border: 1px dashed var(--consent);
  border-radius: var(--radius-sm);
}

.aviso-de-registro__icone {
  flex: none;
  color: var(--consent);
}

.aviso-de-registro__texto {
  margin: 0;
  max-width: 75ch;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

.atalhos {
  margin: var(--space-4) 0 0;
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
  text-align: center;
}

.atalhos kbd {
  font-family: var(--font-mono);
  font-size: 12px;
}

/* Cabeçalho do resultado --------------------------------------------------- */

.busca__cabecalho {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: var(--space-4);
  flex-wrap: wrap;
}

.busca__sobrelinha {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--brand);
}

.busca__titulo {
  margin: var(--space-1) 0 0;
  font-family: var(--font-display);
  font-size: 22px;
  line-height: 28px;
  font-weight: 600;
  color: var(--ink);
}

.busca__termo {
  font-family: var(--font-mono);
  font-size: 18px;
  font-weight: 500;
  font-variant-numeric: tabular-nums;
}

.busca__deteccao {
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

/* Seções ------------------------------------------------------------------- */

.secao {
  margin: var(--space-6) 0 0;
}

.secao__cabecalho {
  display: flex;
  align-items: center;
  gap: var(--space-3);
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

.secao__contagem {
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-faint);
  font-variant-numeric: tabular-nums;
}

.secao__vazio {
  max-width: 75ch;
  margin: var(--space-3) 0 0;
  padding: var(--space-6);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.cartoes {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--space-4);
  margin: var(--space-3) 0 0;
}

.cartao-animal {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
  color: var(--ink);
}

.cartao-animal:hover {
  border-color: var(--brand-bright);
  color: var(--ink);
}

.cartao-animal__icone {
  display: flex;
  align-items: center;
  justify-content: center;
  flex: none;
  width: 40px;
  height: 40px;
  border-radius: var(--radius-pill);
  background: var(--surface-sunken);
  color: var(--ink-muted);
}

.cartao-animal__texto {
  flex: 1;
  min-width: 0;
}

.cartao-animal__nome {
  display: block;
  font-size: 16px;
  line-height: 24px;
  font-weight: 600;
}

.cartao-animal__meta {
  display: block;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.cartao-animal__codigo {
  display: block;
  font-family: var(--font-mono);
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
}

/* Discreto de propósito: não é impedimento, só o aviso de que abrir a ficha
   fica registrado para o tutor. */
.cartao-animal__vinculo {
  display: block;
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-muted);
}

.secao__tutor {
  margin: 0 0 var(--space-4);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.secao__tutor strong {
  font-weight: 600;
  color: var(--ink);
}

/* Como os estados de coluna única do painel, o bloco se centra na área de
   conteúdo em vez de encostar à esquerda. */
.sem-resultado {
  max-width: 708px;
  margin: var(--space-6) auto 0;
}

/* Botões e avisos ---------------------------------------------------------- */

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
  width: 60%;
  margin: 0 auto;
}

.esqueleto--campo {
  height: 48px;
  margin: var(--space-6) 0 0;
}

.esqueleto--exemplo {
  height: 58px;
}

.esqueleto--cartao {
  height: 72px;
  border-radius: var(--radius-md);
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
  /* Acima daqui o campo vive na faixa do cabeçalho, e repeti-lo no conteúdo
     seria oferecer dois campos para a mesma pergunta. A mensagem de erro fica:
     é do termo, e o termo continua na tela. */
  .campo-linha-estreita {
    display: none;
  }

  .heroi {
    padding: var(--space-24) 0;
  }

  .heroi__titulo {
    font-size: 36px;
    line-height: 40px;
  }

  .campo {
    height: 40px;
  }

  .campo__entrada {
    font-size: 14px;
  }

  .leitor-qr {
    display: none;
  }

  .exemplos {
    grid-template-columns: repeat(2, 1fr);
  }

  .busca__titulo {
    font-size: 28px;
    line-height: 34px;
  }

  .busca__termo {
    font-size: 22px;
  }

  .cartoes {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (min-width: 1280px) {
  .cartoes {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}
</style>
