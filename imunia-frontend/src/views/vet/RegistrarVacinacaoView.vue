<script setup>
/**
 * V07 — registrar vacinação (RNF15, RF25b, RF25c, RF26, RF27).
 *
 * A tela onde o produto se ganha ou se perde: RNF15 mede noventa segundos do
 * início ao registro gravado, incluindo lote e validade. Quatro decisões de
 * desenho decorrem disso, e nenhuma é de conveniência:
 *
 * 1. **O painel de cálculo mostra a consequência antes da confirmação.** Ele
 *    não calcula aqui: pergunta ao servidor, que responde pelo mesmo código que
 *    monta a carteira do tutor. A data que aparece à direita é a que a Helena
 *    verá depois — não uma estimativa da tela.
 *
 * 2. **As sugestões nunca pisam no que já foi digitado.** Trocar de vacina
 *    repreenche fabricante, lote, validade e via com os da última aplicação
 *    daquele item nesta clínica; mas um campo em que o profissional encostou é
 *    dele. Sem essa regra, uma resposta atrasada apagaria o lote recém-digitado,
 *    que é exatamente onde RNF15 promete economizar tempo.
 *
 * 3. **Nenhum alerta desabilita o botão** (RF27c, RN36). Atraso acima do limite,
 *    validade expirada e dose adicional pedem confirmação ou justificativa; a
 *    decisão clínica é de quem assina.
 *
 * 4. **O sucesso não troca de página.** O painel vira o resumo do que foi
 *    gravado, com o selo do registro, e oferece os dois caminhos prováveis —
 *    abrir a carteira ou registrar outra. Navegar sozinho seria decidir pelo
 *    profissional qual dos dois ele queria.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  CalendarClock,
  CircleCheck,
  ClockAlert,
  History,
  Lock,
  Syringe,
  TriangleAlert,
  UserRoundCheck,
} from '@lucide/vue'
import VetShell from '@/components/vet/VetShell.vue'
import AppCombobox from '@/components/base/AppCombobox.vue'
import AppSelect from '@/components/base/AppSelect.vue'
import AppTextarea from '@/components/base/AppTextarea.vue'
import ConfirmDialog from '@/components/base/ConfirmDialog.vue'
import ImmutableNotice from '@/components/base/ImmutableNotice.vue'
import BatchSeal from '@/components/tutor/BatchSeal.vue'
import { ApiError, apiGet, apiPost } from '@/lib/api.js'
import { descreverAnimal } from '@/lib/animais.js'
import { emNumeros } from '@/lib/datas.js'
import { formatarMesAno } from '@/lib/masks.js'

const route = useRoute()
const router = useRouter()

/** Campos que a sugestão do servidor pode preencher — e só enquanto intocados. */
const SUGERIVEIS = ['fabricante', 'lote', 'validade', 'via_administracao']

const ICONES_DE_ALERTA = {
  atraso: ClockAlert,
  dose_adicional: Syringe,
  ordem_alterada: ClockAlert,
}

const tela = ref(null)
const previa = ref(null)
const carregando = ref(true)
const erro = ref('')

const formulario = ref(formularioVazio())
const tocados = ref(new Set())

const enviando = ref(false)
const errosDeCampo = ref({})
const erroDeEnvio = ref('')
const registrado = ref(null)

const dialogoAberto = ref(false)
const dossie = ref(null)

/**
 * Contador monotônico das prévias. `apiGet` não aceita `AbortSignal`, e a
 * convenção do projeto não admite alargar os exports de `api.js`; sem descartar
 * a resposta fora de ordem, uma consulta lenta de "V10" sobrescreveria o painel
 * já atualizado para "antirrábica".
 */
let sequencia = 0
let temporizador = null

const prestadorAtivo = ref(null)

function formularioVazio() {
  return {
    imunobiologico: '',
    fabricante: '',
    lote: '',
    validade: '',
    via_administracao: '',
    sitio_anatomico: '',
    aplicado_em: agoraLocal(),
    ordem_dose: '',
    justificativa_conduta: '',
    observacao: '',
    validade_expirada_confirmada: false,
  }
}

/** O momento atual no formato que `datetime-local` entende. */
function agoraLocal() {
  const agora = new Date()
  agora.setMinutes(agora.getMinutes() - agora.getTimezoneOffset())

  return agora.toISOString().slice(0, 16)
}

/* Derivações --------------------------------------------------------------- */

const animal = computed(() => tela.value?.animal ?? null)
const aplicador = computed(() => tela.value?.aplicador ?? null)
const catalogo = computed(() => tela.value?.catalogo ?? [])
const alertas = computed(() => previa.value?.alertas ?? [])
const calculo = computed(() => previa.value?.calculo ?? null)
const sugestoes = computed(() => previa.value?.sugestoes ?? null)

const opcoesDeVacina = computed(() => catalogo.value.map((item) => ({
  valor: item.chave,
  rotulo: item.nome,
  detalhe: item.nome_tecnico,
})))

const opcoesDeOrdem = computed(() => (previa.value?.ordem_dose?.opcoes ?? []).map((opcao) => ({
  value: String(opcao.valor),
  label: opcao.rotulo,
})))

const descricao = computed(() => (animal.value ? descreverAnimal(animal.value) : ''))

/**
 * RF25c — a validade vencida na data da aplicação. A conta é feita aqui porque
 * depende de dois campos que ainda estão sendo digitados; o servidor a refaz na
 * gravação, e é a dele que vale.
 */
const validadeExpirada = computed(() => {
  const partes = /^(0[1-9]|1[0-2])\/(\d{4})$/.exec(formulario.value.validade ?? '')
  if (!partes || !formulario.value.aplicado_em) return false

  const fimDaValidade = new Date(Number(partes[2]), Number(partes[1]), 0)
  const aplicacao = new Date(formulario.value.aplicado_em)

  return fimDaValidade < new Date(aplicacao.getFullYear(), aplicacao.getMonth(), aplicacao.getDate())
})

const podeConfirmar = computed(
  () => formulario.value.imunobiologico !== '' && !enviando.value && registrado.value === null,
)

/* Carga e cálculo ao vivo --------------------------------------------------- */

function parametros(extras = {}) {
  const busca = new URLSearchParams()

  if (prestadorAtivo.value) busca.set('prestador', prestadorAtivo.value)

  Object.entries(extras).forEach(([chave, valor]) => {
    if (valor !== null && valor !== undefined && valor !== '') busca.set(chave, valor)
  })

  return busca
}

async function carregar() {
  carregando.value = true
  erro.value = ''

  try {
    const vacina = route.query.imunobiologico ?? ''

    tela.value = await apiGet(
      `/api/clinica/animais/${route.params.codigo}/vacinar?${parametros({ imunobiologico: vacina })}`,
    )

    prestadorAtivo.value = tela.value.prestador.id
    previa.value = tela.value.previa

    if (vacina) {
      formulario.value.imunobiologico = vacina
      aplicarPrevia(tela.value.previa)
    }
  } catch (excecao) {
    erro.value = excecao.message
  } finally {
    carregando.value = false
  }
}

/**
 * A prévia, com o descarte de resposta fora de ordem. O imunobiológico dispara
 * na hora — é escolha, não digitação —; a data e a ordem esperam, porque quem
 * digita "05/08/2026" passa por cinco datas inválidas até chegar à sua.
 */
async function recalcular({ imediato = false } = {}) {
  if (registrado.value !== null) return

  clearTimeout(temporizador)

  if (!imediato) {
    temporizador = setTimeout(() => recalcular({ imediato: true }), 250)

    return
  }

  if (formulario.value.imunobiologico === '') {
    previa.value = null

    return
  }

  const pedido = ++sequencia

  try {
    const resposta = await apiGet(
      `/api/clinica/animais/${route.params.codigo}/vacinar/previa?${parametros({
        imunobiologico: formulario.value.imunobiologico,
        aplicado_em: formulario.value.aplicado_em,
        ordem_dose: formulario.value.ordem_dose,
      })}`,
    )

    // Chegou tarde: outra prévia já respondeu depois desta ter partido.
    if (pedido !== sequencia) return

    previa.value = resposta
    aplicarPrevia(resposta)
  } catch (excecao) {
    if (pedido === sequencia) erro.value = excecao.message
  }
}

/**
 * RNF15 — os valores repetidos do último registro. Só entram onde ninguém
 * encostou: um campo tocado é do profissional, e sobrescrevê-lo apagaria o que
 * ele acabou de ler no frasco.
 */
function aplicarPrevia(resposta) {
  const vacina = catalogo.value.find((item) => item.chave === formulario.value.imunobiologico)

  SUGERIVEIS.forEach((campo) => {
    if (tocados.value.has(campo)) return

    const daSugestao = resposta.sugestoes?.[campo] ?? null

    if (campo === 'validade') {
      formulario.value.validade = daSugestao ? emNumeros(daSugestao).slice(3) : ''

      return
    }

    // Sem aplicação anterior deste item na clínica, o catálogo ainda tem o
    // fabricante e a via usual — melhor ponto de partida do que o campo vazio.
    formulario.value[campo] = daSugestao
      ?? (campo === 'fabricante' ? vacina?.fabricante : vacina?.via_administracao_usual)
      ?? ''
  })

  if (!tocados.value.has('ordem_dose')) {
    formulario.value.ordem_dose = String(resposta.ordem_dose?.sugerida ?? '')
  }
}

function marcarTocado(campo) {
  tocados.value.add(campo)
}

function aoEscolherVacina(chave) {
  formulario.value.imunobiologico = chave

  // Trocar de vacina recomeça o preenchimento assistido: as sugestões do item
  // anterior não dizem nada sobre este, e manter "tocado" o que foi preenchido
  // pela sugestão anterior travaria o campo com o lote de outra vacina.
  SUGERIVEIS.forEach((campo) => tocados.value.delete(campo))
  tocados.value.delete('ordem_dose')
  formulario.value.validade_expirada_confirmada = false

  recalcular({ imediato: true })
}

function aoDigitarValidade(valor) {
  marcarTocado('validade')
  formulario.value.validade = formatarMesAno(valor)
}

/* Confirmação -------------------------------------------------------------- */

/**
 * O dossiê do diálogo é congelado na abertura. `acontece` e `naoAcontece` são
 * props reativas: uma prévia em voo mudaria o texto sob os olhos de quem está
 * lendo para decidir, e o `ConfirmDialog` refocaria o primeiro botão no meio da
 * leitura.
 */
function abrirConfirmacao() {
  if (!podeConfirmar.value) return

  clearTimeout(temporizador)

  const nomeDaVacina = catalogo.value.find(
    (item) => item.chave === formulario.value.imunobiologico,
  )?.nome ?? 'a vacina'

  const acontece = [
    `O registro passa a integrar o prontuário de ${animal.value.nome} com o seu nome e o seu CRMV `
    + `(${aplicador.value.nome} · ${aplicador.value.crmv}), pela ${tela.value.prestador.nome}.`,
  ]

  if (calculo.value) {
    acontece.push(
      `${animal.value.tutor} vê a aplicação na carteira, com a próxima dose prevista para `
      + `${emNumeros(calculo.value.prevista_para)}, e receberá o lembrete dessa dose.`,
    )
  }

  if (validadeExpirada.value) {
    acontece.push(
      'O registro fica marcado como aplicado com validade expirada, e essa marca aparece ao tutor na carteira.',
    )
  }

  dossie.value = {
    titulo: `Confirmar a aplicação de ${nomeDaVacina} em ${animal.value.nome}`,
    acontece,
    naoAcontece: [
      'Depois de confirmado, o registro não pode ser alterado nem excluído.',
      'Correções entram como retificação vinculada, e as duas versões ficam visíveis.',
    ],
  }

  dialogoAberto.value = true
}

async function confirmar() {
  if (enviando.value) return

  enviando.value = true
  errosDeCampo.value = {}
  erroDeEnvio.value = ''

  try {
    registrado.value = await apiPost(
      `/api/clinica/animais/${route.params.codigo}/vacinar?${parametros()}`,
      { ...formulario.value },
    )

    dialogoAberto.value = false
  } catch (excecao) {
    dialogoAberto.value = false

    if (excecao instanceof ApiError && excecao.status === 422) {
      errosDeCampo.value = excecao.errors
    }

    erroDeEnvio.value = excecao.message
  } finally {
    enviando.value = false
  }
}

function primeiroErro(campo) {
  return errosDeCampo.value[campo]?.[0] ?? ''
}

function registrarOutra() {
  registrado.value = null
  previa.value = null
  erroDeEnvio.value = ''
  errosDeCampo.value = {}
  tocados.value = new Set()
  formulario.value = formularioVazio()
}

function trocarPrestador(id) {
  prestadorAtivo.value = id
  carregar()
}

/* Atalhos ------------------------------------------------------------------ */

function aoTeclar(evento) {
  if (evento.key !== 'Enter' || !(evento.metaKey || evento.ctrlKey)) return

  // Com o diálogo aberto, a tecla é dele: o atalho global e o clique do botão
  // "Confirmar" dispararia o envio duas vezes.
  if (dialogoAberto.value || enviando.value) return

  evento.preventDefault()
  abrirConfirmacao()
}

onMounted(() => {
  carregar()
  document.addEventListener('keydown', aoTeclar)
})

onBeforeUnmount(() => {
  clearTimeout(temporizador)
  document.removeEventListener('keydown', aoTeclar)
})

watch(() => route.params.codigo, carregar)
</script>

<template>
  <VetShell
    titulo="Registrar vacinação"
    :prestador="tela?.prestador"
    :vinculos="tela?.vinculos ?? []"
    @trocar-prestador="trocarPrestador"
  >
    <div v-if="carregando" class="registro" aria-busy="true" aria-live="polite">
      <span class="visually-hidden">Abrindo o registro de vacinação.</span>
      <div class="esqueleto esqueleto--titulo" />
      <div class="esqueleto esqueleto--linha" />
      <div class="registro__grade">
        <div class="esqueleto esqueleto--bloco" />
        <div class="esqueleto esqueleto--bloco" />
      </div>
    </div>

    <div v-else-if="erro" class="registro">
      <div class="aviso aviso--erro" role="alert">
        <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
        <div>
          <p class="aviso__titulo">Não conseguimos abrir esta tela.</p>
          <p class="aviso__texto">{{ erro }}</p>
          <button type="button" class="botao botao--secundario" @click="carregar">
            Tentar novamente
          </button>
        </div>
      </div>
    </div>

    <div v-else class="registro">
      <nav class="trilha" aria-label="Você está em">
        <RouterLink to="/clinica/painel">Animais</RouterLink>
        <span aria-hidden="true">/</span>
        <RouterLink :to="`/clinica/animais/${animal.codigo}?prestador=${prestadorAtivo}`">
          {{ animal.nome }}
        </RouterLink>
        <span aria-hidden="true">/</span>
        <span class="trilha__atual">Registrar vacinação</span>
      </nav>

      <div class="cabecalho">
        <div>
          <p class="cabecalho__sobrenome">Registrar vacinação</p>
          <h1 class="cabecalho__nome">{{ animal.nome }}</h1>
          <p class="cabecalho__descricao">
            {{ descricao }} ·
            <span class="cabecalho__codigo">{{ animal.codigo }}</span>
            · tutor {{ animal.tutor }}
          </p>
        </div>
        <p class="cabecalho__atalhos">
          <span class="tecla">Tab</span> avança ·
          <span class="tecla">Enter</span> escolhe no seletor<br>
          <span class="tecla">Ctrl/Cmd + Enter</span> confirma a aplicação
        </p>
      </div>

      <ImmutableNotice variante="formulario" class="registro__imutavel" />

      <div class="registro__grade">
        <!-- Coluna do formulário -->
        <div class="coluna">
          <section class="bloco" aria-labelledby="bloco-imunobiologico">
            <h2 id="bloco-imunobiologico" class="bloco__titulo">Imunobiológico</h2>

            <AppCombobox
              id="v07-imuno"
              label="Vacina do catálogo"
              :model-value="formulario.imunobiologico"
              :opcoes="opcoesDeVacina"
              :error="primeiroErro('imunobiologico')"
              hint="Digite para filtrar o catálogo e confirme com Enter."
              autofoco
              :readonly="registrado !== null"
              @update:model-value="aoEscolherVacina"
            >
              <template #icone>
                <Syringe :size="16" class="combo-icone" />
              </template>
            </AppCombobox>

            <p v-if="sugestoes" class="sugestao-faixa">
              <History :size="16" class="sugestao-faixa__icone" />
              <span class="sugestao-faixa__texto">
                Preenchemos fabricante, lote, validade e via com os da última aplicação desta vacina
                nesta clínica, em {{ emNumeros(sugestoes.aplicada_em) }}.
              </span>
            </p>

            <div class="campos">
              <div class="campo" :class="{ 'campo--sugerido': !tocados.has('fabricante') && sugestoes }">
                <label for="v07-fab" class="campo__rotulo">Fabricante</label>
                <div class="campo__moldura">
                  <input
                    id="v07-fab"
                    v-model="formulario.fabricante"
                    class="campo__entrada"
                    type="text"
                    :readonly="registrado !== null"
                    @input="marcarTocado('fabricante')"
                  >
                  <span v-if="!tocados.has('fabricante') && sugestoes" class="campo__selo">sugestão</span>
                </div>
                <p v-if="primeiroErro('fabricante')" class="campo__erro">{{ primeiroErro('fabricante') }}</p>
              </div>

              <div class="campo" :class="{ 'campo--sugerido': !tocados.has('lote') && sugestoes }">
                <label for="v07-lote" class="campo__rotulo">Lote</label>
                <div class="campo__moldura">
                  <input
                    id="v07-lote"
                    v-model="formulario.lote"
                    class="campo__entrada campo__entrada--mono"
                    type="text"
                    inputmode="numeric"
                    :readonly="registrado !== null"
                    @input="marcarTocado('lote')"
                  >
                  <span v-if="!tocados.has('lote') && sugestoes" class="campo__selo">sugestão</span>
                </div>
                <p v-if="primeiroErro('lote')" class="campo__erro">{{ primeiroErro('lote') }}</p>
                <p v-else class="campo__dica">Confira no frasco antes de confirmar. Obrigatório.</p>
              </div>

              <div class="campo" :class="{ 'campo--sugerido': !tocados.has('validade') && sugestoes }">
                <label for="v07-val" class="campo__rotulo">Validade</label>
                <div class="campo__moldura">
                  <input
                    id="v07-val"
                    class="campo__entrada campo__entrada--mono"
                    type="text"
                    inputmode="numeric"
                    placeholder="04/2027"
                    :value="formulario.validade"
                    :readonly="registrado !== null"
                    @input="aoDigitarValidade($event.target.value)"
                  >
                  <span v-if="!tocados.has('validade') && sugestoes" class="campo__selo">sugestão</span>
                </div>
                <p v-if="primeiroErro('validade')" class="campo__erro">{{ primeiroErro('validade') }}</p>
                <p v-else class="campo__dica">Mês e ano, como no frasco. Obrigatório.</p>
              </div>

              <div class="campo" :class="{ 'campo--sugerido': !tocados.has('via_administracao') && sugestoes }">
                <label for="v07-via" class="campo__rotulo">Via de administração</label>
                <div class="campo__moldura">
                  <input
                    id="v07-via"
                    v-model="formulario.via_administracao"
                    class="campo__entrada"
                    type="text"
                    :readonly="registrado !== null"
                    @input="marcarTocado('via_administracao')"
                  >
                  <span v-if="!tocados.has('via_administracao') && sugestoes" class="campo__selo">sugestão</span>
                </div>
                <p v-if="primeiroErro('via_administracao')" class="campo__erro">
                  {{ primeiroErro('via_administracao') }}
                </p>
              </div>
            </div>
          </section>

          <section class="bloco" aria-labelledby="bloco-aplicacao">
            <h2 id="bloco-aplicacao" class="bloco__titulo">Aplicação</h2>

            <div class="campos">
              <div class="campo">
                <label for="v07-data" class="campo__rotulo">Data e hora</label>
                <div class="campo__moldura">
                  <input
                    id="v07-data"
                    v-model="formulario.aplicado_em"
                    class="campo__entrada campo__entrada--mono"
                    type="datetime-local"
                    :readonly="registrado !== null"
                    @input="marcarTocado('aplicado_em'); recalcular()"
                  >
                  <CalendarClock :size="16" class="campo__icone" />
                </div>
                <p v-if="primeiroErro('aplicado_em')" class="campo__erro">{{ primeiroErro('aplicado_em') }}</p>
                <p v-else class="campo__dica">Preenchida com o momento atual; editável.</p>
              </div>

              <div class="campo">
                <AppSelect
                  id="v07-dose"
                  label="Ordem da dose"
                  :model-value="formulario.ordem_dose"
                  :options="opcoesDeOrdem"
                  placeholder="Escolha a vacina primeiro"
                  :error="primeiroErro('ordem_dose')"
                  @update:model-value="(v) => {
                    formulario.ordem_dose = v
                    marcarTocado('ordem_dose')
                    recalcular({ imediato: true })
                  }"
                />
                <p class="campo__dica">Calculada pelo histórico. Se você mudar a ordem, pedimos uma justificativa.</p>
              </div>

              <div class="campo">
                <span class="campo__rotulo">Aplicador</span>
                <div class="campo__moldura campo__moldura--travada">
                  <UserRoundCheck :size="16" class="campo__icone campo__icone--marca" />
                  <span class="campo__texto">
                    {{ aplicador.nome }} · <span class="mono">{{ aplicador.crmv }}</span>
                  </span>
                  <Lock :size="16" class="campo__icone" />
                </div>
                <p class="campo__dica">
                  Vem do seu acesso e não é editável: é a sua responsabilidade técnica.
                </p>
              </div>

              <div class="campo">
                <label for="v07-local" class="campo__rotulo">Local anatômico</label>
                <div class="campo__moldura">
                  <input
                    id="v07-local"
                    v-model="formulario.sitio_anatomico"
                    class="campo__entrada"
                    type="text"
                    placeholder="opcional"
                    :readonly="registrado !== null"
                  >
                </div>
              </div>
            </div>

            <AppTextarea
              id="v07-obs"
              v-model="formulario.observacao"
              class="bloco__observacao"
              label="Observação"
              placeholder="opcional"
              :readonly="registrado !== null"
              :error="primeiroErro('observacao')"
            />
          </section>
        </div>

        <!-- Coluna do painel -->
        <div class="coluna coluna--painel">
          <!-- Sucesso: o painel vira o resumo, sem troca de página -->
          <section v-if="registrado" class="painel painel--sucesso" role="status">
            <p class="painel__titulo painel__titulo--sucesso">
              <CircleCheck :size="16" />Aplicação registrada
            </p>

            <BatchSeal :aplicacao="registrado.aplicacao" class="painel__selo" />

            <dl class="resumo">
              <div v-if="registrado.proxima_dose" class="resumo__linha">
                <dt>Próxima dose</dt>
                <dd class="mono">{{ emNumeros(registrado.proxima_dose.prevista_para) }}</dd>
              </div>
              <div v-if="registrado.situacao_animal" class="resumo__linha">
                <dt>Situação de {{ animal.nome }}</dt>
                <dd class="resumo__forte">{{ registrado.situacao_animal.texto }}</dd>
              </div>
            </dl>

            <div class="painel__acoes">
              <RouterLink :to="registrado.destino_carteira" class="botao botao--secundario">
                Abrir a carteira
              </RouterLink>
              <button type="button" class="botao botao--primario" @click="registrarOutra">
                Registrar outra
              </button>
            </div>
          </section>

          <template v-else>
            <!-- RF25c — não bloqueia; exige confirmação explícita -->
            <section v-if="validadeExpirada" class="painel painel--erro">
              <p class="painel__titulo painel__titulo--erro">
                <TriangleAlert :size="16" />Validade expirada
              </p>
              <p class="painel__texto">
                O lote <span class="mono">{{ formulario.lote || '—' }}</span> venceu em
                {{ formulario.validade }}, antes da data desta aplicação.
              </p>
              <label for="v07-exp" class="confirmacao">
                <input
                  id="v07-exp"
                  v-model="formulario.validade_expirada_confirmada"
                  type="checkbox"
                  class="confirmacao__caixa"
                >
                <span>
                  Confirmo que apliquei este lote com a validade expirada e assumo a responsabilidade
                  técnica pela decisão.
                </span>
              </label>
              <p class="painel__nota">
                O registro será marcado como aplicado com validade expirada, e essa marca aparece ao
                tutor na carteira. O registro não é impedido.
              </p>
              <p v-if="primeiroErro('validade_expirada_confirmada')" class="campo__erro">
                {{ primeiroErro('validade_expirada_confirmada') }}
              </p>
            </section>

            <section
              v-for="alerta in alertas"
              :key="alerta.tipo"
              class="painel painel--atencao"
            >
              <p class="painel__titulo painel__titulo--atencao">
                <component :is="ICONES_DE_ALERTA[alerta.tipo]" :size="16" />{{ alerta.titulo }}
              </p>
              <p class="painel__texto">{{ alerta.texto }}</p>

              <div v-if="alerta.conduta_sugerida" class="conduta">
                <p class="conduta__rotulo">Conduta sugerida</p>
                <p class="conduta__texto">{{ alerta.conduta_sugerida }}</p>
              </div>

              <p v-if="alerta.agendada_para" class="conduta">
                Dose adicional agendada para
                <span class="mono">{{ emNumeros(alerta.agendada_para) }}</span>, com lembrete ao tutor.
              </p>

              <AppTextarea
                v-if="alerta.pede_justificativa"
                id="v07-just"
                v-model="formulario.justificativa_conduta"
                class="painel__justificativa"
                label="Justificativa, se a conduta divergir"
                placeholder="Descreva a conduta adotada"
                hint="Opcional. O alerta não impede o registro: a decisão é sua."
                :error="primeiroErro('justificativa_conduta')"
              />
            </section>

            <section class="painel">
              <p class="painel__titulo painel__titulo--marca">
                <CalendarClock :size="16" />Cálculo ao vivo
              </p>

              <div v-if="calculo" class="calculo">
                <p class="calculo__rotulo">Próxima dose prevista</p>
                <p class="calculo__data">{{ emNumeros(calculo.prevista_para) }}</p>
                <p class="calculo__regra">{{ calculo.regra_texto }}</p>
              </div>
              <p v-else class="painel__texto painel__texto--vazio">
                {{
                  formulario.imunobiologico
                    ? 'Não há protocolo vigente para esta vacina, então não há próxima data a calcular.'
                    : 'Escolha a vacina do catálogo para ver a próxima data prevista.'
                }}
              </p>

              <dl v-if="calculo" class="resumo">
                <div class="resumo__linha">
                  <dt>Regra aplicada</dt>
                  <dd>{{ calculo.rotulo }}</dd>
                </div>
                <div v-if="calculo.protocolo_versao" class="resumo__linha">
                  <dt>Protocolo</dt>
                  <dd>{{ calculo.protocolo_versao }}</dd>
                </div>
                <div v-if="calculo.situacao_apos" class="resumo__linha">
                  <dt>Situação após registrar</dt>
                  <dd class="resumo__forte">{{ calculo.situacao_apos.texto }}</dd>
                </div>
                <div class="resumo__linha resumo__linha--separada">
                  <dt>Lembrete ao tutor</dt>
                  <dd>enviado antes da data prevista</dd>
                </div>
              </dl>
            </section>

            <div v-if="erroDeEnvio" class="painel painel--erro" role="alert">
              <p class="painel__titulo painel__titulo--erro">
                <TriangleAlert :size="16" />Não conseguimos gravar agora
              </p>
              <p class="painel__texto">
                A aplicação não foi registrada e nada foi perdido: o lote, a validade e a via
                continuam preenchidos no formulário.
              </p>
              <p class="painel__nota">{{ erroDeEnvio }}</p>
            </div>

            <div class="barra">
              <button
                type="button"
                class="botao botao--primario botao--confirmar"
                :disabled="!podeConfirmar"
                @click="abrirConfirmacao"
              >
                <Syringe :size="16" />Confirmar aplicação
              </button>
              <p class="barra__atalho">Ou <span class="tecla">Ctrl/Cmd + Enter</span></p>
            </div>
          </template>
        </div>
      </div>
    </div>

    <ConfirmDialog
      :aberto="dialogoAberto"
      :titulo="dossie?.titulo ?? ''"
      :acontece="dossie?.acontece ?? []"
      :nao-acontece="dossie?.naoAcontece ?? []"
      rotulo-confirmar="Confirmar aplicação"
      rotulo-cancelar="Revisar os dados"
      :carregando="enviando"
      @confirmar="confirmar"
      @cancelar="dialogoAberto = false"
    />
  </VetShell>
</template>

<style scoped>
.registro {
  display: flex;
  flex-direction: column;
  max-width: 1280px;
  margin: 0 auto;
}

/* Trilha e cabeçalho ------------------------------------------------------- */

.trilha {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-faint);
}

.trilha__atual {
  color: var(--ink-muted);
}

/* §4.3 — abaixo de 1024 px o alvo de toque é de 44 px. A margem negativa
   estende a área tocável sem afastar as migalhas umas das outras: o que cresce
   é o alvo, não a linha. */
@media (max-width: 1023px) {
  .trilha a {
    display: inline-flex;
    align-items: center;
    height: 44px;
    margin: -12px 0;
    padding: 0 var(--space-1);
  }
}

.cabecalho {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: var(--space-6);
  flex-wrap: wrap;
  margin: var(--space-3) 0 0;
}

.cabecalho__sobrenome {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--brand);
}

.cabecalho__nome {
  margin: var(--space-1) 0 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  letter-spacing: -.02em;
  color: var(--ink);
}

.cabecalho__descricao {
  margin: var(--space-1) 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.cabecalho__codigo {
  font-family: var(--font-mono);
  font-size: 13px;
  font-weight: 500;
}

.cabecalho__atalhos {
  margin: 0;
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
}

.tecla {
  font-family: var(--font-mono);
  font-size: 12px;
}

.registro__imutavel {
  margin: var(--space-4) 0 0;
}

/* Grade -------------------------------------------------------------------- */

.registro__grade {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--space-4);
  margin: var(--space-4) 0 0;
  align-items: flex-start;
}

.coluna {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  min-width: 0;
}

/* Blocos do formulário ----------------------------------------------------- */

.bloco {
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

.bloco__titulo {
  margin: 0 0 var(--space-3);
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

.bloco__observacao {
  margin: var(--space-4) 0 0;
}

.combo-icone {
  flex: none;
  color: var(--brand);
}

.sugestao-faixa {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: var(--space-4) 0 0;
  padding: var(--space-2) var(--space-3);
  background: var(--brand-wash);
  border-radius: var(--radius-sm);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

.sugestao-faixa__icone {
  flex: none;
  color: var(--brand);
}

.campos {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--space-4);
  margin: var(--space-4) 0 0;
}

.campo {
  min-width: 0;
}

.campo__rotulo {
  display: block;
  margin: 0 0 6px;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink);
}

.campo__moldura {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  height: 48px;
  padding: 0 var(--space-3);
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
}

.campo__moldura:focus-within {
  border-color: var(--brand-bright);
}

/* A moldura tracejada é o que distingue, à distância de um olhar, o valor que
   o sistema propôs do que o profissional digitou. É informação clínica: o lote
   sugerido ainda precisa ser conferido no frasco. */
.campo--sugerido .campo__moldura {
  background: var(--brand-wash);
  border-style: dashed;
  border-color: var(--brand);
}

.campo__moldura--travada {
  background: var(--surface-sunken);
}

.campo__entrada {
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

.campo__entrada--mono {
  font-family: var(--font-mono);
  font-variant-numeric: tabular-nums;
}

.campo__texto {
  flex: 1;
  min-width: 0;
  font-size: 14px;
  color: var(--ink);
}

.campo__icone {
  flex: none;
  color: var(--ink-muted);
}

.campo__icone--marca {
  color: var(--brand);
}

.campo__selo {
  flex: none;
  font-size: 12px;
  line-height: 16px;
  font-weight: 600;
  color: var(--brand);
}

.campo__dica {
  margin: 6px 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.campo__erro {
  margin: 6px 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--status-late);
}

.mono {
  font-family: var(--font-mono);
  font-weight: 500;
}

/* Painel ------------------------------------------------------------------- */

.painel {
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

/* §4.1 — o âmbar é borda, nunca cor de texto: não passa em contraste. */
.painel--atencao {
  border-color: var(--status-due);
}

.painel--erro {
  border-color: var(--status-late);
}

.painel--sucesso {
  border-color: var(--brand);
}

.painel__titulo {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
}

.painel__titulo--atencao {
  color: var(--status-due-text);
}

.painel__titulo--erro {
  color: var(--status-late);
}

.painel__titulo--marca,
.painel__titulo--sucesso {
  color: var(--brand);
}

.painel__texto {
  margin: var(--space-3) 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

.painel__texto--vazio {
  color: var(--ink-muted);
}

.painel__nota {
  margin: var(--space-3) 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.painel__justificativa {
  margin: var(--space-3) 0 0;
}

.painel__selo {
  margin: var(--space-3) 0 0;
}

.conduta {
  margin: var(--space-3) 0 0;
  padding: var(--space-3);
  background: var(--surface-sunken);
  border-radius: var(--radius-sm);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

.conduta__rotulo {
  margin: 0;
  font-size: 12px;
  line-height: 16px;
  font-weight: 600;
  color: var(--ink-muted);
}

.conduta__texto {
  margin: var(--space-1) 0 0;
}

.confirmacao {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  margin: var(--space-3) 0 0;
  padding: var(--space-3);
  background: var(--status-late-wash);
  border-radius: var(--radius-sm);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
  cursor: pointer;
}

.confirmacao__caixa {
  flex: none;
  width: 20px;
  height: 20px;
  margin: 0;
  accent-color: var(--status-late);
}

.calculo {
  margin: var(--space-3) 0 0;
  padding: var(--space-4);
  background: var(--brand-wash);
  border-radius: var(--radius-sm);
}

.calculo__rotulo {
  margin: 0;
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-muted);
}

.calculo__data {
  margin: var(--space-1) 0 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  color: var(--brand);
  font-variant-numeric: tabular-nums;
}

.calculo__regra {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

.resumo {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  margin: var(--space-4) 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.resumo__linha {
  display: flex;
  justify-content: space-between;
  gap: var(--space-4);
}

.resumo__linha dt {
  flex: none;
}

.resumo__linha dd {
  margin: 0;
  text-align: right;
  color: var(--ink);
}

.resumo__linha--separada {
  padding-top: var(--space-2);
  border-top: 1px solid var(--border-hairline);
}

.resumo__forte {
  color: var(--brand);
  font-weight: 600;
}

.painel__acoes {
  display: flex;
  gap: var(--space-2);
  margin: var(--space-4) 0 0;
}

.painel__acoes > * {
  flex: 1;
}

/* Ações -------------------------------------------------------------------- */

.barra {
  position: sticky;
  bottom: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  padding: var(--space-3) 0;
  background: var(--surface-page);
}

.barra__atalho {
  margin: 0;
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
  text-align: center;
}

.botao {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-2);
  height: 48px;
  padding: 0 var(--space-4);
  border-radius: var(--radius-sm);
  font-family: var(--font-body);
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
}

.botao--primario {
  background: var(--brand);
  border: 1px solid var(--brand);
  color: #FFFFFF;
}

.botao--primario:hover:not(:disabled) {
  background: var(--brand-hover);
}

.botao--primario:disabled {
  opacity: .55;
  cursor: not-allowed;
}

.botao--secundario {
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  color: var(--ink);
}

.botao--confirmar {
  width: 100%;
}

/* Erro e esqueleto --------------------------------------------------------- */

.aviso {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--status-late);
  border-radius: var(--radius-md);
}

.aviso__icone {
  flex: none;
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
  margin: var(--space-2) 0 var(--space-3);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.esqueleto {
  border-radius: var(--radius-xs);
  background: var(--surface-sunken);
  animation: pulsar 1.2s ease-in-out infinite;
}

.esqueleto--titulo {
  width: 220px;
  height: 34px;
}

.esqueleto--linha {
  width: 320px;
  height: 20px;
  margin: var(--space-2) 0 0;
}

.esqueleto--bloco {
  height: 320px;
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

/* Larguras ----------------------------------------------------------------- */

@media (min-width: 768px) {
  .campos {
    grid-template-columns: 1fr 1fr;
  }
}

@media (min-width: 1024px) {
  .cabecalho__nome {
    font-size: 36px;
    line-height: 40px;
  }

  .campo__moldura {
    height: 40px;
  }

  .campo__entrada {
    font-size: 14px;
  }

  .campo__entrada--mono {
    font-size: 13px;
  }

  .campo__dica,
  .campo__erro {
    font-size: 12px;
    line-height: 16px;
  }

  .botao {
    height: 40px;
  }

  .barra {
    position: static;
    padding: 0;
  }
}

@media (min-width: 1280px) {
  /* A grade 7fr/5fr do desenho: o formulário à esquerda, e a consequência do
     que se está registrando à direita, visível sem rolar. */
  .registro__grade {
    grid-template-columns: 7fr 5fr;
    gap: var(--space-6);
  }
}
</style>
