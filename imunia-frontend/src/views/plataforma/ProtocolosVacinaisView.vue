<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { CalendarClock, CircleCheck, FilePenLine, Lock, TriangleAlert, X } from '@lucide/vue'
import PlataformaShell from '@/components/plataforma/PlataformaShell.vue'
import AppInput from '@/components/base/AppInput.vue'
import ConfirmDialog from '@/components/base/ConfirmDialog.vue'
import EmptyState from '@/components/base/EmptyState.vue'
import { apiDelete, apiGet, apiPost } from '@/lib/api.js'
import { emHoras, emNumeros } from '@/lib/datas.js'

/**
 * X02 — versões do cálculo de calendário (RF24). A tela que responde à pergunta
 * previsível da banca: quando a WSAVA revisa as diretrizes, publica-se uma
 * versão nova, e nenhuma linha de código muda.
 *
 * Três atos, e só três: editar o rascunho, conferir o resultado no simulador,
 * publicar. Versão publicada não se edita — cria-se outra a partir dela —, e é
 * por isso que o editor troca inteiro de estado conforme a versão selecionada,
 * em vez de esconder um botão aqui e ali.
 */
const ESPECIES = [
  { value: 'cao', label: 'Cão' },
  { value: 'gato', label: 'Gato' },
]

const CONDUTAS = [
  { value: 'prosseguir', label: 'Prosseguir com dose única de reforço' },
  { value: 'reiniciar', label: 'Reiniciar a série primária' },
]

const FORMULARIO_VAZIO = {
  idade_minima_primeira_dose_semanas: '',
  intervalo_minimo_dias: '',
  intervalo_maximo_dias: '',
  idade_minima_dose_final_semanas: '',
  reforco_inicial_meses: '',
  periodicidade_revacinacao_meses: '',
  limite_atraso_dias: '',
  numero_doses_serie_primaria: '',
  doses_adulto_sem_historico: '',
  conduta_apos_limite: 'prosseguir',
}

const versoes = ref([])
const imunobiologicos = ref([])
const casos = ref([])
const carregando = ref(true)
const erro = ref('')

const versaoId = ref(null)
const especie = ref('cao')
const imunobiologicoId = ref(null)

const formulario = reactive({ ...FORMULARIO_VAZIO })
const errosCampo = reactive({})
const salvando = ref(false)
const salvoEm = ref(null)
const erroSalvar = ref('')

const criacaoAberta = ref(false)
const novaVersao = reactive({ rotulo: '', base: '' })
const errosNovaVersao = reactive({})
const criando = ref(false)
const erroCriacao = ref('')

const confirmandoPublicacao = ref(false)
const publicando = ref(false)
const confirmandoDescarte = ref(false)
const descartando = ref(false)

const casoAtivo = ref(null)
const entradas = reactive({ nascimento: '', doses: '' })
const simulacao = ref(null)
const comparacao = ref(null)
const simulando = ref(false)
const erroSimulacao = ref('')

const versaoSelecionada = computed(() => versoes.value.find((versao) => versao.id === versaoId.value) ?? null)
const versaoVigente = computed(() => versoes.value.find((versao) => versao.situacao === 'vigente') ?? null)
const rascunhoExistente = computed(() => versoes.value.find((versao) => versao.situacao === 'rascunho') ?? null)
const editavel = computed(() => versaoSelecionada.value?.situacao === 'rascunho')

const opcoesDeImunobiologico = computed(() =>
  imunobiologicos.value.filter(
    (item) => item.especie_destino === especie.value || item.especie_destino === 'ambas',
  ),
)

const imunobiologicoSelecionado = computed(
  () => imunobiologicos.value.find((item) => item.id === imunobiologicoId.value) ?? null,
)

const descricaoDoParametro = computed(() => {
  const rotulo = ESPECIES.find((opcao) => opcao.value === especie.value)?.label ?? ''
  const nome = imunobiologicoSelecionado.value?.nome_comercial

  return nome ? `${rotulo} · ${nome}` : rotulo
})

const parametros = computed(
  () => versaoSelecionada.value?.parametros.find((item) => item.imunobiologico_id === imunobiologicoId.value) ?? null,
)

/**
 * As contradições da versão inteira — e não só as do imunobiológico aberto:
 * é a versão que se publica, e um parâmetro incoerente em qualquer um dos
 * imunobiológicos impede a publicação de todos.
 */
const incoerencias = computed(() => versaoSelecionada.value?.incoerencias ?? [])

const camposIncoerentes = computed(() => {
  const doImunobiologico = incoerencias.value.filter(
    (item) => item.protocolo_id === parametros.value?.id,
  )

  return new Set(doImunobiologico.flatMap((item) => item.campos))
})

const podePublicar = computed(() => editavel.value && incoerencias.value.length === 0 && parametros.value !== null)

const situacaoDaVersaoSimulada = computed(() => simulacao.value?.versao?.rotulo ?? null)

/**
 * Se a versão vigente produz o mesmo calendário. Zero diferença é resposta, e
 * não ausência de resposta: duas versões podem diferir num imunobiológico e
 * coincidir noutro, e silêncio depois de um clique se lê como defeito.
 */
const comparacaoIdentica = computed(() => {
  if (!comparacao.value || !simulacao.value) return null

  const assinatura = (passos) => passos.map((passo) => `${passo.rotulo}|${passo.data}|${passo.valor_texto}`).join('§')

  return assinatura(comparacao.value.passos) === assinatura(simulacao.value.passos)
})

/**
 * Os quadros exibidos: o da versão aberta e, quando pedida, o da vigente
 * inteiro — e não anotações linha a linha. Versões com parâmetros diferentes
 * produzem quantidades diferentes de passos (uma acusa atraso onde a outra o
 * absorve no limite), e emparelhar linha com linha alinharia a data de um passo
 * à explicação de outro.
 */
const quadros = computed(() => {
  if (!simulacao.value) return []

  const lista = [{ chave: 'atual', titulo: null, passos: simulacao.value.passos }]

  if (comparacao.value) {
    lista.push({
      chave: 'comparada',
      titulo: `O mesmo cálculo sob a ${comparacao.value.versao.rotulo}, ${comparacao.value.versao.situacao}`,
      passos: comparacao.value.passos,
    })
  }

  return lista
})

async function carregar() {
  carregando.value = true
  erro.value = ''

  try {
    const resposta = await apiGet('/api/plataforma/protocolos')
    versoes.value = resposta.versoes
    imunobiologicos.value = resposta.imunobiologicos
    casos.value = resposta.casos

    const inicial = rascunhoExistente.value ?? versaoVigente.value ?? versoes.value[0] ?? null
    if (inicial) selecionarVersao(inicial.id, { manterSimulacao: false })
  } catch (excecao) {
    erro.value = excecao.message
  } finally {
    carregando.value = false
  }
}

onMounted(carregar)

function selecionarVersao(id, { manterSimulacao = false } = {}) {
  versaoId.value = id

  const daVersao = versaoSelecionada.value?.parametros ?? []
  const aindaExiste = daVersao.some((item) => item.imunobiologico_id === imunobiologicoId.value)

  if (!aindaExiste) {
    const primeiro = daVersao[0]
      ? imunobiologicos.value.find((item) => item.id === daVersao[0].imunobiologico_id)
      : opcoesDeImunobiologico.value[0]

    if (primeiro) {
      especie.value = primeiro.especie_destino === 'gato' ? 'gato' : 'cao'
      imunobiologicoId.value = primeiro.id
    }
  }

  sincronizarFormulario()

  if (!manterSimulacao) {
    simulacao.value = null
    comparacao.value = null
    casoAtivo.value = null
  }
}

function selecionarEspecie(valor) {
  especie.value = valor

  if (!opcoesDeImunobiologico.value.some((item) => item.id === imunobiologicoId.value)) {
    imunobiologicoId.value = opcoesDeImunobiologico.value[0]?.id ?? null
  }

  sincronizarFormulario()
}

function selecionarImunobiologico(valor) {
  imunobiologicoId.value = Number(valor)
  sincronizarFormulario()
}

function sincronizarFormulario() {
  Object.keys(errosCampo).forEach((chave) => delete errosCampo[chave])
  erroSalvar.value = ''
  salvoEm.value = null
  simulacao.value = null
  comparacao.value = null

  const atual = parametros.value

  if (!atual) {
    Object.assign(formulario, FORMULARIO_VAZIO)
    return
  }

  Object.keys(FORMULARIO_VAZIO).forEach((campo) => {
    formulario[campo] = atual[campo] ?? ''
  })
}

/**
 * O salvamento é automático, a cada campo deixado: o rascunho é rascunho, e
 * pedir "Salvar" a cada número digitado transformaria a edição de parâmetro em
 * formulário. O que não é automático é publicar.
 */
async function salvar() {
  if (!editavel.value || !imunobiologicoId.value) return

  salvando.value = true
  erroSalvar.value = ''
  Object.keys(errosCampo).forEach((chave) => delete errosCampo[chave])

  try {
    const resposta = await apiPost(`/api/plataforma/protocolos/versoes/${versaoId.value}/parametros`, {
      imunobiologico_id: imunobiologicoId.value,
      ...numeros(),
    })

    aplicarParametros(resposta.parametros, resposta.incoerencias)
    salvoEm.value = new Date()
  } catch (excecao) {
    if (excecao.status === 422 && Object.keys(excecao.errors).length > 0) {
      Object.entries(excecao.errors).forEach(([campo, mensagens]) => {
        errosCampo[campo] = mensagens[0]
      })
    } else {
      erroSalvar.value = excecao.message
    }
  } finally {
    salvando.value = false
  }
}

function numeros() {
  const opcionais = ['idade_minima_primeira_dose_semanas', 'idade_minima_dose_final_semanas', 'reforco_inicial_meses']

  return Object.fromEntries(
    Object.keys(FORMULARIO_VAZIO).map((campo) => {
      if (campo === 'conduta_apos_limite') return [campo, formulario[campo]]

      const bruto = String(formulario[campo]).trim()
      if (bruto === '') return [campo, opcionais.includes(campo) ? null : bruto]

      return [campo, Number(bruto)]
    }),
  )
}

function aplicarParametros(salvos, incoerenciasDaVersao) {
  const versao = versaoSelecionada.value
  if (!versao) return

  const indice = versao.parametros.findIndex((item) => item.id === salvos.id)

  if (indice === -1) versao.parametros.push(salvos)
  else versao.parametros[indice] = salvos

  versao.incoerencias = incoerenciasDaVersao
}

function abrirCriacao() {
  novaVersao.rotulo = ''
  novaVersao.base = ''
  Object.keys(errosNovaVersao).forEach((chave) => delete errosNovaVersao[chave])
  erroCriacao.value = ''
  criacaoAberta.value = true
}

async function criarVersao() {
  criando.value = true
  erroCriacao.value = ''
  Object.keys(errosNovaVersao).forEach((chave) => delete errosNovaVersao[chave])

  try {
    const resposta = await apiPost('/api/plataforma/protocolos/versoes', {
      rotulo: novaVersao.rotulo,
      base: novaVersao.base || null,
    })

    versoes.value = [resposta.versao, ...versoes.value]
    criacaoAberta.value = false
    selecionarVersao(resposta.versao.id)
  } catch (excecao) {
    if (excecao.status === 422 && Object.keys(excecao.errors).length > 0) {
      Object.entries(excecao.errors).forEach(([campo, mensagens]) => {
        errosNovaVersao[campo] = mensagens[0]
      })
    } else {
      erroCriacao.value = excecao.message
    }
  } finally {
    criando.value = false
  }
}

async function publicar() {
  publicando.value = true

  try {
    await apiPost(`/api/plataforma/protocolos/versoes/${versaoId.value}/publicar`)
    confirmandoPublicacao.value = false
    await carregar()
  } catch (excecao) {
    erroSalvar.value = excecao.message
    confirmandoPublicacao.value = false
  } finally {
    publicando.value = false
  }
}

async function descartar() {
  descartando.value = true

  try {
    await apiDelete(`/api/plataforma/protocolos/versoes/${versaoId.value}`)
    confirmandoDescarte.value = false
    await carregar()
  } catch (excecao) {
    erroSalvar.value = excecao.message
    confirmandoDescarte.value = false
  } finally {
    descartando.value = false
  }
}

async function simularCaso(chave) {
  casoAtivo.value = chave
  await simular({ caso: chave })
}

async function simularEntradas() {
  casoAtivo.value = null
  await simular({
    nascimento_em: paraIso(entradas.nascimento),
    doses: entradas.doses
      .split(',')
      .map((parte) => paraIso(parte))
      .filter(Boolean),
  })
}

async function simular(corpo) {
  if (!imunobiologicoId.value || !versaoId.value) return

  simulando.value = true
  erroSimulacao.value = ''
  comparacao.value = null

  try {
    const resposta = await apiPost('/api/plataforma/protocolos/simular', {
      versao_id: versaoId.value,
      imunobiologico_id: imunobiologicoId.value,
      ...corpo,
    })

    simulacao.value = resposta.simulacao
    entradas.nascimento = emNumeros(resposta.simulacao.entradas.nascimento_em) ?? ''
    entradas.doses = resposta.simulacao.entradas.doses.map(emNumeros).join(', ')
  } catch (excecao) {
    simulacao.value = null
    erroSimulacao.value = excecao.message
  } finally {
    simulando.value = false
  }
}

/**
 * A comparação é o argumento inteiro de RF24 numa tela só: as mesmas entradas,
 * sob a versão vigente, produzindo datas diferentes — e as já emitidas
 * permanecendo onde estavam, porque nenhuma delas foi tocada aqui.
 */
async function compararComAVigente() {
  if (!versaoVigente.value || !simulacao.value) return

  simulando.value = true
  erroSimulacao.value = ''

  try {
    const resposta = await apiPost('/api/plataforma/protocolos/simular', {
      versao_id: versaoVigente.value.id,
      imunobiologico_id: imunobiologicoId.value,
      nascimento_em: simulacao.value.entradas.nascimento_em,
      doses: simulacao.value.entradas.doses,
    })

    comparacao.value = resposta.simulacao
  } catch (excecao) {
    erroSimulacao.value = excecao.message
  } finally {
    simulando.value = false
  }
}

/**
 * O equivalente deste passo na versão vigente, quando ele existe e difere.
 *
 * A correspondência é pelo rótulo do passo, e não pela posição: versões com
 * parâmetros diferentes produzem quantidades diferentes de passos — uma agenda
 * dose adicional, a outra não; uma acusa atraso, a outra o absorve no limite —,
 * e comparar por índice alinharia a data de um passo com a explicação de outro.
 * Passo sem equivalente simplesmente não se compara.
 */
function paraIso(texto) {
  const encontrado = String(texto).trim().match(/^(\d{2})\/(\d{2})\/(\d{4})$/)

  return encontrado ? `${encontrado[3]}-${encontrado[2]}-${encontrado[1]}` : null
}

function horaDoSalvamento() {
  if (!salvoEm.value) return null

  const hora = new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit' }).format(salvoEm.value)

  return emHoras(hora)
}

function textoDaVersao(versao) {
  if (versao.situacao === 'rascunho') {
    const desde = emNumeros(versao.aberta_em)

    return versao.calculos === 0
      ? `Em edição desde ${desde} · nenhum cálculo realizado`
      : `Em edição desde ${desde}`
  }

  if (versao.situacao === 'vigente') return `Publicada em ${emNumeros(versao.publicado_em)}`

  return `Vigente de ${emNumeros(versao.publicado_em)} a ${emNumeros(versao.encerrado_em)}`
}
</script>

<template>
  <PlataformaShell titulo="Administração da plataforma">
    <div class="protocolos">
      <div class="protocolos__topo">
        <div>
          <p class="protocolos__rotulo">Protocolos vacinais</p>
          <h1 class="protocolos__titulo">Versões do cálculo de calendário</h1>
        </div>
        <button
          type="button"
          class="botao botao--secundario"
          :disabled="carregando || rascunhoExistente !== null"
          :title="rascunhoExistente ? 'Já há um rascunho em edição. Publique-o ou descarte-o antes de começar outro.' : null"
          @click="abrirCriacao"
        >
          Nova versão a partir da vigente
        </button>
      </div>

      <div v-if="carregando" class="cartao" aria-busy="true" aria-live="polite">
        <span class="visually-hidden">Carregando as versões de protocolo.</span>
        <div v-for="linha in 3" :key="linha" class="esqueleto-linha">
          <div class="esqueleto esqueleto--celula" />
          <div class="esqueleto esqueleto--pilula" />
        </div>
      </div>

      <div v-else-if="erro" class="aviso aviso--erro" role="alert">
        <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
        <div>
          <p class="aviso__titulo">Não conseguimos carregar as versões.</p>
          <p class="aviso__texto">{{ erro }}</p>
          <button type="button" class="botao botao--secundario" @click="carregar">Tentar novamente</button>
        </div>
      </div>

      <EmptyState
        v-else-if="versoes.length === 0"
        :icone="CalendarClock"
        titulo="Nenhuma versão de protocolo"
        descricao="Sem uma versão vigente, o sistema não tem como calcular a data da próxima dose de animal algum: são estes parâmetros que dizem quantas doses formam a série, com que intervalo e a partir de que idade."
      >
        <button type="button" class="botao botao--primario" @click="abrirCriacao">Criar a primeira versão</button>
      </EmptyState>

      <div v-else class="protocolos__grade">
        <!-- Coluna das versões. A cor da borda é o que carrega a situação, e a
             etiqueta a repete em palavra: cor sozinha nunca porta significado. -->
        <div class="versoes">
          <button
            v-for="versao in versoes"
            :key="versao.id"
            type="button"
            class="versao"
            :class="[`versao--${versao.situacao}`, { 'versao--ativa': versao.id === versaoId }]"
            :aria-pressed="versao.id === versaoId"
            @click="selecionarVersao(versao.id)"
          >
            <span class="versao__topo">
              <span class="versao__rotulo">{{ versao.rotulo }}</span>
              <span class="etiqueta" :class="`etiqueta--${versao.situacao}`">
                <FilePenLine v-if="versao.situacao === 'rascunho'" :size="14" :stroke-width="1.75" />
                <CircleCheck v-else-if="versao.situacao === 'vigente'" :size="14" :stroke-width="1.75" />
                {{ versao.situacao }}
              </span>
            </span>
            <span class="versao__linha">{{ textoDaVersao(versao) }}</span>
            <span v-if="versao.base" class="versao__base">Base: {{ versao.base }}</span>
            <span v-if="versao.calculos > 0" class="versao__calculos">
              <strong>{{ versao.calculos.toLocaleString('pt-BR') }}</strong>
              cálculos realizados sob esta versão
            </span>
          </button>

          <div class="nota">
            <Lock :size="20" :stroke-width="1.75" class="nota__icone" />
            <p>
              Versão encerrada não é apagada: os registros calculados sob ela guardam a referência, e é assim
              que uma data antiga continua explicável anos depois.
            </p>
          </div>
        </div>

        <div class="protocolos__editor">
          <!-- Painel de parâmetros -->
          <section class="painel">
            <header class="painel__cabecalho">
              <div>
                <h2 class="painel__titulo">Parâmetros · {{ versaoSelecionada.situacao }} {{ versaoSelecionada.rotulo }}</h2>
                <p class="painel__subtitulo">{{ descricaoDoParametro }}</p>
              </div>
              <div class="painel__seletores">
                <select
                  aria-label="Espécie"
                  class="seletor"
                  :value="especie"
                  @change="selecionarEspecie($event.target.value)"
                >
                  <option v-for="opcao in ESPECIES" :key="opcao.value" :value="opcao.value">{{ opcao.label }}</option>
                </select>
                <select
                  aria-label="Imunobiológico"
                  class="seletor"
                  :value="imunobiologicoId"
                  @change="selecionarImunobiologico($event.target.value)"
                >
                  <option v-for="opcao in opcoesDeImunobiologico" :key="opcao.id" :value="opcao.id">
                    {{ opcao.nome_comercial }}
                  </option>
                </select>
              </div>
            </header>

            <!-- Parâmetros incoerentes: o rascunho continua salvo, e é a
                 publicação que fica indisponível. -->
            <div v-if="incoerencias.length > 0" class="incoerencia" role="alert">
              <p class="incoerencia__titulo">
                <TriangleAlert :size="16" :stroke-width="1.75" />
                Parâmetros incoerentes
              </p>
              <p v-for="item in incoerencias" :key="`${item.protocolo_id}-${item.campos.join()}`" class="incoerencia__texto">
                <strong>{{ item.imunobiologico }}:</strong> {{ item.mensagem }}
              </p>
              <p class="incoerencia__rodape">
                O rascunho continua salvo e editável. A publicação fica indisponível enquanto os valores se
                contradisserem, porque uma versão publicada é usada em cálculo real.
              </p>
            </div>

            <div v-if="!parametros && !editavel" class="painel__vazio">
              <p>Esta versão não define parâmetros para {{ imunobiologicoSelecionado?.nome_comercial }}.</p>
            </div>

            <div v-else class="campos">
              <div class="campo">
                <label for="x02-idade1" class="campo__rotulo">Idade mínima da 1ª dose</label>
                <div class="medida" :class="{ 'medida--invalida': camposIncoerentes.has('idade_minima_primeira_dose_semanas') }">
                  <input
                    id="x02-idade1"
                    v-model="formulario.idade_minima_primeira_dose_semanas"
                    type="text"
                    inputmode="numeric"
                    class="medida__campo"
                    :readonly="!editavel"
                    @change="salvar"
                  />
                  <span class="medida__unidade">semanas</span>
                </div>
                <p v-if="errosCampo.idade_minima_primeira_dose_semanas" class="campo__erro">
                  {{ errosCampo.idade_minima_primeira_dose_semanas }}
                </p>
              </div>

              <!-- RN34 — o intervalo da série é uma janela de duas a quatro
                   semanas, e não um número único; o cálculo usa o ponto médio,
                   que a dica exibe para que ninguém precise fazer a conta. -->
              <div class="campo">
                <label for="x02-int-min" class="campo__rotulo">Intervalo entre doses da série</label>
                <div class="medida" :class="{ 'medida--invalida': camposIncoerentes.has('intervalo_minimo_dias') || camposIncoerentes.has('intervalo_maximo_dias') }">
                  <input
                    id="x02-int-min"
                    v-model="formulario.intervalo_minimo_dias"
                    type="text"
                    inputmode="numeric"
                    class="medida__campo medida__campo--estreito"
                    aria-label="Intervalo mínimo entre doses, em dias"
                    :readonly="!editavel"
                    @change="salvar"
                  />
                  <span class="medida__unidade">a</span>
                  <input
                    v-model="formulario.intervalo_maximo_dias"
                    type="text"
                    inputmode="numeric"
                    class="medida__campo medida__campo--estreito"
                    aria-label="Intervalo máximo entre doses, em dias"
                    :readonly="!editavel"
                    @change="salvar"
                  />
                  <span class="medida__unidade">dias</span>
                </div>
                <p v-if="parametros" class="campo__dica">Previsto: {{ parametros.intervalo_previsto_dias }} dias, o ponto médio da janela.</p>
              </div>

              <div class="campo">
                <label for="x02-idadef" class="campo__rotulo">Idade mínima da dose final</label>
                <div class="medida" :class="{ 'medida--invalida': camposIncoerentes.has('idade_minima_dose_final_semanas') }">
                  <input
                    id="x02-idadef"
                    v-model="formulario.idade_minima_dose_final_semanas"
                    type="text"
                    inputmode="numeric"
                    class="medida__campo"
                    :readonly="!editavel"
                    @change="salvar"
                  />
                  <span class="medida__unidade">semanas</span>
                </div>
                <p class="campo__dica">Abaixo disso, o sistema agenda dose adicional.</p>
              </div>

              <div class="campo">
                <label for="x02-ref1" class="campo__rotulo">Prazo do primeiro reforço</label>
                <div class="medida" :class="{ 'medida--invalida': camposIncoerentes.has('reforco_inicial_meses') }">
                  <input
                    id="x02-ref1"
                    v-model="formulario.reforco_inicial_meses"
                    type="text"
                    inputmode="numeric"
                    class="medida__campo"
                    :readonly="!editavel"
                    @change="salvar"
                  />
                  <span class="medida__unidade">meses</span>
                </div>
                <p class="campo__dica">Em branco, vale a periodicidade da revacinação.</p>
              </div>

              <div class="campo">
                <label for="x02-reva" class="campo__rotulo">Periodicidade da revacinação</label>
                <div class="medida">
                  <input
                    id="x02-reva"
                    v-model="formulario.periodicidade_revacinacao_meses"
                    type="text"
                    inputmode="numeric"
                    class="medida__campo"
                    :readonly="!editavel"
                    @change="salvar"
                  />
                  <span class="medida__unidade">meses</span>
                </div>
                <p v-if="errosCampo.periodicidade_revacinacao_meses" class="campo__erro">
                  {{ errosCampo.periodicidade_revacinacao_meses }}
                </p>
              </div>

              <div class="campo">
                <label for="x02-atraso" class="campo__rotulo">Limite de atraso</label>
                <div class="medida">
                  <input
                    id="x02-atraso"
                    v-model="formulario.limite_atraso_dias"
                    type="text"
                    inputmode="numeric"
                    class="medida__campo"
                    :readonly="!editavel"
                    @change="salvar"
                  />
                  <span class="medida__unidade">dias</span>
                </div>
              </div>

              <div class="campo">
                <label for="x02-doses" class="campo__rotulo">Doses da série primária</label>
                <div class="medida">
                  <input
                    id="x02-doses"
                    v-model="formulario.numero_doses_serie_primaria"
                    type="text"
                    inputmode="numeric"
                    class="medida__campo"
                    :readonly="!editavel"
                    @change="salvar"
                  />
                  <span class="medida__unidade">doses</span>
                </div>
                <p v-if="errosCampo.numero_doses_serie_primaria" class="campo__erro">
                  {{ errosCampo.numero_doses_serie_primaria }}
                </p>
              </div>

              <!-- O caso do adulto sem histórico conhecido — a gata Nina do
                   cenário — tem série própria, e ela é parâmetro: RF24a proíbe
                   absorver diretriz nova por alteração de código. -->
              <div class="campo">
                <label for="x02-adulto" class="campo__rotulo">Doses no adulto sem histórico</label>
                <div class="medida">
                  <input
                    id="x02-adulto"
                    v-model="formulario.doses_adulto_sem_historico"
                    type="text"
                    inputmode="numeric"
                    class="medida__campo"
                    :readonly="!editavel"
                    @change="salvar"
                  />
                  <span class="medida__unidade">doses</span>
                </div>
              </div>

              <div class="campo campo--largo">
                <label for="x02-conduta" class="campo__rotulo">Conduta sugerida acima do limite</label>
                <select
                  id="x02-conduta"
                  v-model="formulario.conduta_apos_limite"
                  class="seletor seletor--largo"
                  :disabled="!editavel"
                  @change="salvar"
                >
                  <option v-for="opcao in CONDUTAS" :key="opcao.value" :value="opcao.value">{{ opcao.label }}</option>
                </select>
                <p class="campo__dica">A conduta é sugestão exibida ao profissional; nunca impede o registro.</p>
              </div>
            </div>

            <p v-if="erroSalvar" class="campo__erro campo__erro--painel" role="alert">{{ erroSalvar }}</p>

            <footer class="painel__rodape">
              <span v-if="editavel" class="painel__estado">
                <template v-if="salvando">Salvando…</template>
                <template v-else-if="salvoEm">Alterações salvas no rascunho às {{ horaDoSalvamento() }}</template>
                <template v-else>Rascunho em edição</template>
              </span>
              <span v-else class="painel__estado painel__estado--travado">
                <Lock :size="16" :stroke-width="1.75" />
                Versão publicada não é editada. Para mudar um parâmetro, crie uma versão nova a partir dela.
              </span>

              <div v-if="editavel" class="painel__acoes">
                <button type="button" class="botao botao--secundario" @click="confirmandoDescarte = true">
                  Descartar rascunho
                </button>
                <button
                  type="button"
                  class="botao botao--primario"
                  :disabled="!podePublicar || publicando"
                  @click="confirmandoPublicacao = true"
                >
                  <span v-if="publicando" class="botao__girando" aria-hidden="true" />
                  {{ publicando ? `Publicando a ${versaoSelecionada.rotulo}…` : `Publicar versão ${versaoSelecionada.rotulo}` }}
                </button>
              </div>
            </footer>
          </section>

          <!-- Simulador. A cor própria não é enfeite: separa, à distância de um
               olhar, o que é cálculo hipotético do que é parâmetro gravado. -->
          <section class="simulador">
            <header class="simulador__cabecalho">
              <CalendarClock :size="20" :stroke-width="1.75" class="simulador__icone" />
              <div>
                <h2 class="simulador__titulo">Simulador do cálculo</h2>
                <p class="simulador__subtitulo">Confere o resultado sem tocar em dado real. Nada aqui é gravado.</p>
              </div>
            </header>

            <div class="simulador__corpo">
              <p class="simulador__secao">Casos de teste</p>
              <div class="simulador__casos">
                <button
                  v-for="(caso, indice) in casos"
                  :key="caso.chave"
                  type="button"
                  class="chip"
                  :class="{ 'chip--ativo': casoAtivo === caso.chave }"
                  :disabled="simulando || !parametros"
                  @click="simularCaso(caso.chave)"
                >
                  {{ indice + 1 }} · {{ caso.rotulo }}
                </button>
              </div>

              <div class="simulador__entradas">
                <div class="campo">
                  <label for="x02-sim-esp" class="campo__rotulo">Espécie</label>
                  <select id="x02-sim-esp" class="seletor seletor--largo" disabled>
                    <option>{{ ESPECIES.find((opcao) => opcao.value === especie)?.label }}</option>
                  </select>
                </div>
                <div class="campo">
                  <label for="x02-sim-nasc" class="campo__rotulo">Nascimento</label>
                  <div class="medida">
                    <input
                      id="x02-sim-nasc"
                      v-model="entradas.nascimento"
                      type="text"
                      inputmode="numeric"
                      placeholder="dd/mm/aaaa"
                      class="medida__campo"
                    />
                    <span class="medida__unidade">hipotético</span>
                  </div>
                </div>
                <div class="campo">
                  <label for="x02-sim-doses" class="campo__rotulo">Doses aplicadas</label>
                  <input
                    id="x02-sim-doses"
                    v-model="entradas.doses"
                    type="text"
                    placeholder="dd/mm/aaaa, dd/mm/aaaa"
                    class="medida medida--simples"
                  />
                </div>
              </div>

              <div class="simulador__acao">
                <button type="button" class="botao botao--secundario" :disabled="simulando || !parametros" @click="simularEntradas">
                  <span v-if="simulando" class="botao__girando botao__girando--escuro" aria-hidden="true" />
                  {{ simulando ? 'Simulando…' : 'Simular com estas entradas' }}
                </button>
              </div>

              <p v-if="erroSimulacao" class="campo__erro" role="alert">{{ erroSimulacao }}</p>

              <template v-for="quadro in quadros" :key="quadro.chave">
                <p v-if="quadro.titulo" class="trilho__titulo">{{ quadro.titulo }}</p>
                <div class="trilho" aria-live="polite">
                  <div class="trilho__cabecalho">
                    <span>Passo</span>
                    <span>Data calculada</span>
                    <span>Regra aplicada</span>
                  </div>
                  <div
                    v-for="(passo, indice) in quadro.passos"
                    :key="`${passo.rotulo}-${indice}`"
                    class="trilho__linha"
                    :class="`trilho__linha--${passo.tom}`"
                  >
                    <span class="trilho__passo">{{ passo.rotulo }}</span>
                    <span class="trilho__valor">{{ passo.valor_texto ?? emNumeros(passo.data) }}</span>
                    <span class="trilho__regra">{{ passo.regra }}</span>
                  </div>
                </div>
              </template>

              <div v-if="simulacao" class="simulador__rodape">
                <span class="simulador__fonte">
                  Simulado sob os parâmetros da versão {{ situacaoDaVersaoSimulada }}
                  <template v-if="comparacaoIdentica === true">
                    · a {{ versaoVigente?.rotulo }} produz exatamente o mesmo calendário para este imunobiológico
                  </template>
                  <template v-else-if="comparacaoIdentica === false">
                    · a {{ versaoVigente?.rotulo }} produz um calendário diferente
                  </template>
                </span>
                <button
                  v-if="versaoVigente && versaoVigente.id !== versaoId"
                  type="button"
                  class="botao botao--secundario botao--pequeno"
                  :disabled="simulando"
                  @click="comparacao ? (comparacao = null) : compararComAVigente()"
                >
                  {{ comparacao ? 'Ocultar a comparação' : `Comparar com a ${versaoVigente.rotulo}` }}
                </button>
              </div>
            </div>
          </section>
        </div>
      </div>
    </div>

    <!-- Abertura de versão nova -->
    <Teleport to="body">
      <div v-if="criacaoAberta" class="modal__fundo" @click.self="criacaoAberta = false">
        <div class="modal" role="dialog" aria-modal="true" aria-label="Nova versão de protocolo">
          <div class="modal__cabecalho">
            <span class="modal__titulo">Nova versão a partir da vigente</span>
            <button type="button" class="modal__fechar" aria-label="Fechar" @click="criacaoAberta = false">
              <X :size="20" :stroke-width="1.75" />
            </button>
          </div>
          <form class="modal__corpo" @submit.prevent="criarVersao">
            <p class="modal__texto">
              A versão nova nasce como cópia da vigente, em rascunho: os parâmetros já vêm preenchidos e ficam
              editáveis até a publicação. Nada muda nos cálculos enquanto ela não for publicada.
            </p>
            <AppInput
              id="x02-nova-rotulo"
              v-model="novaVersao.rotulo"
              label="Identificação da versão"
              hint="Como ela aparecerá nos registros calculados sob ela — por exemplo, 2026.1."
              :error="errosNovaVersao.rotulo"
              mono
            />
            <AppInput
              id="x02-nova-base"
              v-model="novaVersao.base"
              label="Diretriz de origem"
              hint="Opcional. De onde vieram os parâmetros desta revisão."
              :error="errosNovaVersao.base"
            />
            <p v-if="erroCriacao" class="campo__erro">{{ erroCriacao }}</p>
          </form>
          <div class="modal__rodape">
            <button type="button" class="botao botao--secundario" :disabled="criando" @click="criacaoAberta = false">
              Cancelar
            </button>
            <button type="button" class="botao botao--primario" :disabled="criando" @click="criarVersao">
              <span v-if="criando" class="botao__girando" aria-hidden="true" />
              {{ criando ? 'Criando…' : 'Criar rascunho' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <ConfirmDialog
      :aberto="confirmandoPublicacao"
      :titulo="`Publicar a versão ${versaoSelecionada?.rotulo ?? ''}`"
      :acontece="[
        'Novos cálculos passam a usar esta versão, em todos os prestadores.',
        versaoVigente ? `A ${versaoVigente.rotulo} é encerrada e permanece consultável.` : 'Esta passa a ser a versão vigente.',
      ]"
      :nao-acontece="[
        'Datas já emitidas não são recalculadas.',
        'Cada registro conserva a versão aplicada à época, e continua explicável por ela.',
      ]"
      rotulo-confirmar="Publicar"
      rotulo-cancelar="Revisar"
      :carregando="publicando"
      @confirmar="publicar"
      @cancelar="confirmandoPublicacao = false"
    />

    <ConfirmDialog
      :aberto="confirmandoDescarte"
      :titulo="`Descartar o rascunho ${versaoSelecionada?.rotulo ?? ''}`"
      :acontece="['O rascunho e os parâmetros editados nele são apagados.']"
      :nao-acontece="[
        'A versão vigente continua como está, e nenhum cálculo muda.',
        'Nenhum registro de vacinação é afetado.',
      ]"
      rotulo-confirmar="Descartar"
      variante-confirmar="destrutiva"
      :carregando="descartando"
      @confirmar="descartar"
      @cancelar="confirmandoDescarte = false"
    />
  </PlataformaShell>
</template>

<style scoped>
.protocolos {
  width: 100%;
  max-width: 1280px;
  margin: 0 auto;
}

.protocolos__topo {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: var(--space-6);
  flex-wrap: wrap;
}

.protocolos__rotulo {
  margin: 0;
  font-size: 13px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--brand);
}

.protocolos__titulo {
  margin: 4px 0 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  color: var(--ink);
}

/* §8.5 — duas colunas em `lg`, coluna única abaixo. Esta é tela de desktop; o
   simulador é a última seção quando a largura não comporta as duas colunas. */
.protocolos__grade {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--space-6);
  margin: var(--space-6) 0 0;
  align-items: flex-start;
}

.protocolos__editor {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  min-width: 0;
}

/* Versões ----------------------------------------------------------------- */

.versoes { display: flex; flex-direction: column; gap: var(--space-3); }

.versao {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  padding: var(--space-4);
  text-align: left;
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
  font-family: inherit;
  cursor: pointer;
}

.versao--rascunho { border-color: var(--status-due); }
.versao--vigente { border-color: var(--brand); }
.versao--encerrada { opacity: .55; }
.versao--ativa { box-shadow: 0 0 0 2px var(--brand-wash); }
.versao--encerrada.versao--ativa { opacity: 1; }

.versao__topo { display: flex; align-items: center; justify-content: space-between; gap: var(--space-3); }

.versao__rotulo {
  font-family: var(--font-mono);
  font-size: 16px;
  line-height: 24px;
  font-weight: 500;
  color: var(--ink);
}

.versao__linha { font-size: 14px; line-height: 20px; color: var(--ink-muted); }
.versao__base { font-size: 14px; line-height: 20px; color: var(--ink); }

.versao__calculos {
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
  font-variant-numeric: tabular-nums;
}

.etiqueta {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 22px;
  flex: none;
  padding: 0 10px;
  border-radius: var(--radius-pill);
  background: var(--surface-card);
  font-size: 12px;
  font-weight: 600;
}

.etiqueta--rascunho { border: 1px solid var(--status-due); color: var(--status-due-text); }
.etiqueta--vigente { border: 1px solid var(--brand); color: var(--brand); }
.etiqueta--encerrada { background: var(--surface-sunken); color: var(--ink-muted); }

.nota {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  padding: var(--space-4);
  background: var(--surface-sunken);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
}

.nota p { margin: 0; font-size: 14px; line-height: 20px; color: var(--ink); }
.nota__icone { flex: none; color: var(--ink-muted); }

/* Painel de parâmetros ---------------------------------------------------- */

.painel {
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
  overflow: hidden;
}

.painel__cabecalho {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-4);
  flex-wrap: wrap;
  padding: var(--space-4) var(--space-6);
  border-bottom: 1px solid var(--border-hairline);
}

.painel__titulo { margin: 0; font-size: 18px; line-height: 24px; font-weight: 600; color: var(--ink); }
.painel__subtitulo { margin: 0; font-size: 14px; line-height: 20px; color: var(--ink-muted); }
.painel__seletores { display: flex; gap: var(--space-2); flex-wrap: wrap; }

.painel__vazio { padding: var(--space-6); color: var(--ink-muted); }
.painel__vazio p { margin: 0; }

.campos {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--space-4);
  padding: var(--space-6);
}

.campo { display: flex; flex-direction: column; min-width: 0; }
.campo--largo { grid-column: 1 / -1; }

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

.campo__dica { margin: 6px 0 0; font-size: 12px; line-height: 16px; color: var(--ink-muted); }
.campo__erro { margin: 6px 0 0; font-size: 14px; line-height: 20px; color: var(--status-late); }
.campo__erro--painel { padding: 0 var(--space-6) var(--space-4); }

/* O número e a sua unidade dentro da mesma moldura: a unidade é parte do
   valor, e um rótulo solto ao lado faria a pessoa procurá-la. */
.medida {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  height: 40px;
  padding: 0 var(--space-3);
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
}

.medida--invalida { border-color: var(--status-late); }
.medida:focus-within { border-color: var(--brand-bright); }

.medida--simples {
  width: 100%;
  font-family: var(--font-mono);
  font-size: 13px;
  color: var(--ink);
  font-variant-numeric: tabular-nums;
}

.medida__campo {
  flex: 1;
  min-width: 0;
  height: 100%;
  border: none;
  outline: none;
  background: transparent;
  font-family: var(--font-mono);
  font-size: 13px;
  color: var(--ink);
  font-variant-numeric: tabular-nums;
}

.medida__campo--estreito { flex: none; width: 3.5em; }
.medida__campo:read-only { color: var(--ink-muted); }
.medida__unidade { flex: none; font-size: 12px; line-height: 16px; color: var(--ink-muted); }

.seletor {
  height: 32px;
  padding: 0 var(--space-2);
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  font-family: var(--font-body);
  font-size: 14px;
  color: var(--ink);
}

.seletor--largo { width: 100%; height: 40px; padding: 0 var(--space-3); }
.seletor:disabled { color: var(--ink-muted); }

.incoerencia {
  margin: var(--space-6) var(--space-6) 0;
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--status-late);
  border-radius: var(--radius-md);
}

.incoerencia__titulo {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--status-late);
}

.incoerencia__texto { margin: var(--space-3) 0 0; font-size: 14px; line-height: 20px; color: var(--ink); }
.incoerencia__rodape { margin: var(--space-4) 0 0; font-size: 14px; line-height: 20px; color: var(--ink-muted); }

.painel__rodape {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-4);
  flex-wrap: wrap;
  padding: var(--space-4) var(--space-6);
  border-top: 1px solid var(--border-hairline);
}

.painel__estado { font-size: 14px; line-height: 20px; color: var(--ink-muted); }

.painel__estado--travado {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  max-width: 62ch;
}

.painel__acoes { display: flex; gap: var(--space-2); flex-wrap: wrap; }

/* Simulador --------------------------------------------------------------- */

.simulador {
  background: var(--surface-card);
  border: 1px solid var(--consent);
  border-radius: var(--radius-md);
  overflow: hidden;
}

.simulador__cabecalho {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-4) var(--space-6);
  background: var(--consent-wash);
  border-bottom: 1px solid var(--consent);
}

.simulador__icone { flex: none; color: var(--consent); }
.simulador__titulo { margin: 0; font-size: 18px; line-height: 24px; font-weight: 600; color: var(--ink); }
.simulador__subtitulo { margin: 0; font-size: 14px; line-height: 20px; color: var(--ink-muted); }
.simulador__corpo { padding: var(--space-6); }

.simulador__secao {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

.simulador__casos { display: flex; flex-wrap: wrap; gap: var(--space-2); margin: var(--space-3) 0 0; }

.chip {
  height: 44px;
  padding: 0 var(--space-3);
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-pill);
  font-family: var(--font-body);
  font-size: 14px;
  font-weight: 600;
  color: var(--ink-muted);
  cursor: pointer;
}

.chip--ativo { background: var(--consent-wash); border-color: var(--consent); color: var(--consent); }
.chip:disabled { opacity: .45; cursor: not-allowed; }

.simulador__entradas {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--space-4);
  margin: var(--space-6) 0 0;
}

.simulador__acao { margin: var(--space-4) 0 0; }

.trilho {
  margin: var(--space-6) 0 0;
  background: var(--surface-page);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-sm);
  overflow: hidden;
}

.trilho__cabecalho,
.trilho__linha {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--space-2);
  padding: var(--space-3) var(--space-4);
}

.trilho__cabecalho {
  background: var(--surface-sunken);
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

.trilho__cabecalho span:not(:first-child) { display: none; }

.trilho__linha {
  align-items: center;
  min-height: 48px;
  border-top: 1px solid var(--border-hairline);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

.trilho__passo { font-weight: 600; }

.trilho__valor {
  display: flex;
  flex-direction: column;
  font-family: var(--font-mono);
  font-size: 13px;
  font-variant-numeric: tabular-nums;
}

.trilho__regra { color: var(--ink-muted); }

.trilho__titulo {
  margin: var(--space-4) 0 var(--space-2);
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--consent);
}

/* A cor da linha diz o que aconteceu, e a coluna "Regra" diz por quê: a cor
   nunca é o único portador do significado (§4.2 do briefing). */
.trilho__linha--previsto { background: var(--surface-card); }
.trilho__linha--atraso { background: var(--status-late-wash); }
.trilho__linha--atraso .trilho__valor { font-weight: 600; color: var(--status-late); }
.trilho__linha--reforco { background: var(--brand-wash); }
.trilho__linha--reforco .trilho__valor { font-weight: 600; color: var(--brand); }
.trilho__linha--ignorado .trilho__valor { color: var(--ink-muted); }

.trilho__linha--extra {
  background: var(--surface-card);
  border-left: 4px solid var(--status-due);
}

.trilho__linha--extra .trilho__valor { font-weight: 600; color: var(--status-due-text); }

.simulador__rodape {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-4);
  flex-wrap: wrap;
  margin: var(--space-3) 0 0;
}

.simulador__fonte { font-size: 12px; line-height: 16px; color: var(--ink-muted); }

/* Botões ------------------------------------------------------------------ */

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

.botao:disabled { opacity: .45; cursor: not-allowed; }
.botao--primario { background: var(--brand); border: 1px solid var(--brand); color: var(--surface-card); }
.botao--primario:hover:not(:disabled) { background: var(--brand-hover); border-color: var(--brand-hover); }
.botao--secundario { background: var(--surface-card); border: 1px solid var(--border-strong); color: var(--ink); }
.botao--secundario:hover:not(:disabled) { background: var(--surface-sunken); }

.botao__girando {
  width: 14px;
  height: 14px;
  flex: none;
  border: 2px solid rgba(255, 255, 255, .5);
  border-top-color: var(--surface-card);
  border-radius: var(--radius-pill);
  animation: protocolos-girar .6s linear infinite;
}

.botao__girando--escuro { border-color: var(--border-strong); border-top-color: var(--brand); }

@keyframes protocolos-girar { to { transform: rotate(360deg); } }

/* Estados de carregamento e erro ------------------------------------------ */

.cartao {
  margin: var(--space-6) 0 0;
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-sm);
}

.esqueleto-linha { display: flex; align-items: center; gap: var(--space-4); height: 48px; }

.esqueleto {
  border-radius: var(--radius-xs);
  background: var(--surface-sunken);
  animation: protocolos-shimmer 1.2s ease-in-out infinite;
}

.esqueleto--celula { width: 40%; height: 16px; }
.esqueleto--pilula { width: 16%; height: 22px; margin-left: auto; border-radius: var(--radius-pill); }

@keyframes protocolos-shimmer { 0%, 100% { opacity: .55; } 50% { opacity: 1; } }
@media (prefers-reduced-motion: reduce) { .esqueleto { animation-duration: .001ms; } }

.aviso { display: flex; gap: var(--space-3); margin: var(--space-6) 0 0; padding: var(--space-4); border-radius: var(--radius-sm); }
.aviso--erro { background: var(--status-late-wash); }
.aviso__icone { flex: none; color: var(--status-late); }
.aviso__titulo { margin: 0; font-weight: 600; color: var(--ink); }
.aviso__texto { margin: var(--space-1) 0 var(--space-3); color: var(--ink-muted); }

.visually-hidden { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0, 0, 0, 0); }

/* Modal de abertura de versão --------------------------------------------- */

.modal__fundo {
  position: fixed;
  inset: 0;
  z-index: 40;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: var(--space-4);
  background: rgba(20, 35, 31, .32);
}

.modal {
  width: 100%;
  max-width: 480px;
  background: var(--surface-card);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-modal);
  overflow: hidden;
}

.modal__cabecalho {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
  padding: var(--space-4) var(--space-6);
  border-bottom: 1px solid var(--border-hairline);
}

.modal__titulo { font-family: var(--font-display); font-size: 22px; line-height: 28px; font-weight: 600; color: var(--ink); }

.modal__fechar {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  background: none;
  border: none;
  border-radius: var(--radius-sm);
  color: var(--ink-muted);
  cursor: pointer;
}

.modal__corpo { display: flex; flex-direction: column; gap: var(--space-4); padding: var(--space-6); }
.modal__texto { margin: 0; font-size: 14px; line-height: 20px; color: var(--ink-muted); }
.modal__rodape { display: flex; justify-content: flex-end; gap: var(--space-2); padding: var(--space-4) var(--space-6); border-top: 1px solid var(--border-hairline); }

.modal__corpo :deep(.app-field__input) { height: 40px; font-size: 14px; }

/* §4.3 — alvo mínimo de 44 px abaixo de 1024 px; a densidade do desenho vale a
   partir de `lg`, onde o cursor substitui o dedo. */
@media (max-width: 1023px) {
  .botao { min-height: 44px; }
  .medida, .seletor--largo { height: 48px; }
  .medida__campo, .medida--simples { font-size: 16px; }

  /* Os seletores de espécie e imunobiológico têm 32 px no desenho de 1440 px,
     largura em que o cursor já substituiu o dedo. Abaixo de `lg` eles voltam
     ao alvo mínimo, como as pílulas de X01. */
  .seletor { height: 44px; }
}

@media (min-width: 768px) {
  .campos { grid-template-columns: 1fr 1fr; }
  .simulador__entradas { grid-template-columns: 1fr 1fr 1fr; }

  .trilho__cabecalho,
  .trilho__linha {
    grid-template-columns: 150px 130px 1fr;
    gap: var(--space-4);
  }

  .trilho__cabecalho span:not(:first-child) { display: inline; }
  .trilho__valor { flex-direction: row; align-items: baseline; gap: var(--space-2); }
}

@media (min-width: 1024px) {
  .protocolos__grade { grid-template-columns: 4fr 8fr; }
  .chip { height: 32px; }
  .botao--pequeno { height: 32px; }
}
</style>
