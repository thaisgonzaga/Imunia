<script setup>
/**
 * V08 — registrar atendimento (RF31, RF32, RF34).
 *
 * A tela irmã de V07, com o problema invertido: lá o registro se ganha em
 * noventa segundos de campos curtos; aqui ele é **escrito**. Quatro decisões de
 * desenho decorrem disso:
 *
 * 1. **Coluna de leitura de 720 px.** Largura de linha confortável não é
 *    estética em texto clínico: a anamnese é lida por outro profissional, meses
 *    depois, para decidir conduta.
 *
 * 2. **O rascunho é conveniência, e a tela diz isso onde o profissional olha.**
 *    Ele mora neste computador, não no servidor: o tutor não o vê, ele não entra
 *    no prontuário e não conta como documentação. Um rascunho que se parecesse
 *    com registro seria pior do que não ter rascunho nenhum.
 *
 * 3. **O anexo sobe antes da confirmação, um por vez.** É o que dá progresso por
 *    arquivo e recusa imediata por formato ou tamanho (RN28) — e é o que permite
 *    ao erro de gravação prometer, com verdade, que o arquivo já enviado não
 *    precisa subir de novo.
 *
 * 4. **A confirmação é a única porta.** Depois dela não há edição nesta tela nem
 *    em nenhuma outra (RN26): a correção é a retificação vinculada de V09.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  Bell,
  CalendarClock,
  CircleCheck,
  ClockAlert,
  FilePenLine,
  Paperclip,
  Stethoscope,
  TriangleAlert,
  UserRoundCheck,
  X,
} from '@lucide/vue'
import VetShell from '@/components/vet/VetShell.vue'
import AppTextarea from '@/components/base/AppTextarea.vue'
import ConfirmDialog from '@/components/base/ConfirmDialog.vue'
import ImmutableNotice from '@/components/base/ImmutableNotice.vue'
import { ApiError, apiDelete, apiGet, apiPost, apiUpload } from '@/lib/api.js'
import { descreverAnimal } from '@/lib/animais.js'
import { emNumeros } from '@/lib/datas.js'

const route = useRoute()
const router = useRouter()

/** RN28 — a mesma lista que o servidor confere, para recusar antes de subir. */
const TIPOS_ACEITOS = ['application/pdf', 'image/jpeg', 'image/png']

const tela = ref(null)
const carregando = ref(true)
const erro = ref('')

const formulario = ref(formularioVazio())
const anexos = ref([])
const enviosEmCurso = ref([])
const recusa = ref(null)
const arrastando = ref(false)

const enviando = ref(false)
const errosDeCampo = ref({})
const erroDeEnvio = ref('')
const sessaoExpirada = ref(false)
const copiado = ref(false)

const dialogoAberto = ref(false)
const dossie = ref(null)

const rascunhoSalvoEm = ref(null)
const prestadorAtivo = ref(null)

let temporizadorDoRascunho = null
let sequenciaDeEnvio = 0

function formularioVazio() {
  return {
    motivo: '',
    anamnese: '',
    exame_fisico: '',
    peso_kg: '',
    hipoteses_diagnosticas: '',
    diagnostico: '',
    conduta: '',
    retorno_em: '',
    retorno_finalidade: '',
  }
}

/* Derivações --------------------------------------------------------------- */

const animal = computed(() => tela.value?.animal ?? null)
const profissional = computed(() => tela.value?.profissional ?? null)
const limites = computed(() => tela.value?.limites_anexo ?? { tamanho_maximo_mb: 10, quantidade_maxima: 10 })
const pesoAnterior = computed(() => tela.value?.peso_anterior ?? null)
const retornoEmAberto = computed(() => tela.value?.retorno_em_aberto ?? null)

const descricao = computed(() => (animal.value ? descreverAnimal(animal.value) : ''))

/**
 * O peso como a balança o mostra: a coluna guarda duas casas por precisão de
 * armazenamento, e "11,90 kg" na frase soa a exatidão que a medição não tem.
 */
function emQuilos(valor) {
  return new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 2 }).format(Number(valor))
}

/** RF31b — o carimbo do sistema, exibido e não editável. */
const momento = computed(() => {
  if (!tela.value?.momento) return ''

  return new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
  }).format(new Date(tela.value.momento)).replace(', ', ', ').replace(':', 'h')
})

const hoje = computed(() => new Date(Date.now() - new Date().getTimezoneOffset() * 60000)
  .toISOString()
  .slice(0, 10))

const podeConfirmar = computed(() => !enviando.value && enviosEmCurso.value.length === 0)

const horaDoRascunho = computed(() => (rascunhoSalvoEm.value === null
  ? ''
  : new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit' }).format(rascunhoSalvoEm.value)))

/* Carga -------------------------------------------------------------------- */

function parametros() {
  const busca = new URLSearchParams()

  if (prestadorAtivo.value) busca.set('prestador', prestadorAtivo.value)

  return busca
}

async function carregar() {
  carregando.value = true
  erro.value = ''

  try {
    tela.value = await apiGet(`/api/clinica/animais/${route.params.codigo}/atender?${parametros()}`)
    prestadorAtivo.value = tela.value.prestador.id

    restaurarRascunho()
  } catch (excecao) {
    erro.value = excecao.message
  } finally {
    carregando.value = false
  }
}

/* Rascunho ----------------------------------------------------------------- */

/**
 * O rascunho é do animal, e não da sessão: o profissional que foi chamado ao
 * balcão no meio da redação volta pela mesma rota e reencontra o texto.
 */
const chaveDoRascunho = computed(() => `imunia:v08:${route.params.codigo}`)

function agendarRascunho() {
  clearTimeout(temporizadorDoRascunho)
  temporizadorDoRascunho = setTimeout(salvarRascunho, 800)
}

function salvarRascunho() {
  // Formulário em branco não vira rascunho: um registro vazio guardado ao abrir
  // a tela faria a etiqueta prometer texto que ninguém escreveu.
  const escreveu = Object.values(formulario.value).some((valor) => String(valor).trim() !== '')

  if (!escreveu && anexos.value.length === 0) return

  try {
    localStorage.setItem(chaveDoRascunho.value, JSON.stringify({
      formulario: formulario.value,
      anexos: anexos.value,
      salvo_em: new Date().toISOString(),
    }))

    rascunhoSalvoEm.value = new Date()
  } catch {
    // Armazenamento cheio ou indisponível (navegação privativa): o texto
    // continua na tela, que é o que importa. Sem etiqueta, sem promessa.
    rascunhoSalvoEm.value = null
  }
}

function restaurarRascunho() {
  try {
    const guardado = JSON.parse(localStorage.getItem(chaveDoRascunho.value) ?? 'null')

    if (guardado === null) return

    formulario.value = { ...formularioVazio(), ...guardado.formulario }
    anexos.value = guardado.anexos ?? []
    rascunhoSalvoEm.value = guardado.salvo_em ? new Date(guardado.salvo_em) : null
  } catch {
    // Rascunho corrompido é rascunho que não existe.
  }
}

function descartarRascunho() {
  try {
    localStorage.removeItem(chaveDoRascunho.value)
  } catch {
    // idem
  }

  rascunhoSalvoEm.value = null
}

/* Anexos ------------------------------------------------------------------- */

function formatarTamanho(bytes) {
  const mega = bytes / (1024 * 1024)

  return mega >= 1
    ? `${mega.toFixed(1).replace('.', ',')} MB`
    : `${Math.max(1, Math.round(bytes / 1024))} KB`
}

function aoSoltar(evento) {
  arrastando.value = false
  escolherArquivos(evento.dataTransfer?.files)
}

function aoEscolherPeloBotao(evento) {
  escolherArquivos(evento.target.files)
  evento.target.value = ''
}

function escolherArquivos(lista) {
  recusa.value = null

  for (const arquivo of Array.from(lista ?? [])) {
    if (anexos.value.length + enviosEmCurso.value.length >= limites.value.quantidade_maxima) {
      recusa.value = {
        titulo: `${arquivo.name} não foi anexado`,
        texto: `Este atendimento já tem ${limites.value.quantidade_maxima} anexos, que é o limite por registro.`,
        conselho: 'Se faltar documento, ele pode entrar em uma retificação depois.',
      }

      return
    }

    if (!recusarLocalmente(arquivo)) enviar(arquivo)
  }
}

/**
 * RN28 conferida duas vezes, de propósito. Esta é a conferência que evita subir
 * dez megabytes para ouvir "não"; a que vale é a do servidor, sobre o conteúdo
 * do arquivo, e é ela que recusa o `.pdf` que é outra coisa por dentro.
 */
function recusarLocalmente(arquivo) {
  if (!TIPOS_ACEITOS.includes(arquivo.type)) {
    recusa.value = {
      titulo: `${arquivo.name} não foi anexado`,
      texto: 'O Imunia aceita PDF, JPG e PNG. Documentos de texto ficam de fora porque o anexo precisa ser '
        + 'um documento fechado, que não muda depois de enviado.',
      conselho: 'Exporte o arquivo como PDF e anexe novamente.',
    }

    return true
  }

  if (arquivo.size > limites.value.tamanho_maximo_mb * 1024 * 1024) {
    recusa.value = {
      titulo: `${arquivo.name} não foi anexado`,
      texto: `O limite é de ${limites.value.tamanho_maximo_mb} MB por arquivo, e este tem `
        + `${formatarTamanho(arquivo.size)}.`,
      conselho: 'Reduza a resolução da imagem ou divida o documento antes de enviar.',
    }

    return true
  }

  return false
}

function enviar(arquivo) {
  const local = ++sequenciaDeEnvio
  const { promessa, cancelar } = apiUpload(
    `/api/clinica/animais/${route.params.codigo}/atender/anexos?${parametros()}`,
    arquivo,
    { aoProgresso: (enviados, total) => atualizarProgresso(local, enviados, total) },
  )

  enviosEmCurso.value.push({
    local,
    nome: arquivo.name,
    enviados: 0,
    total: arquivo.size,
    cancelar,
  })

  promessa
    .then((recebido) => {
      anexos.value.push({
        token: recebido.token,
        nome: recebido.nome,
        tamanho_bytes: recebido.tamanho_bytes,
        tipo: recebido.tipo,
        descricao: '',
        exame_em: '',
      })

      agendarRascunho()
    })
    .catch((excecao) => {
      // Cancelamento é decisão do profissional, e não falha a relatar.
      if (excecao instanceof ApiError && excecao.status === 0) return

      recusa.value = recusaDoServidor(arquivo, excecao)
    })
    .finally(() => {
      enviosEmCurso.value = enviosEmCurso.value.filter((envio) => envio.local !== local)
    })
}

/**
 * A recusa que veio do servidor já nomeia o arquivo na primeira frase — é a
 * resposta da API, e uma que dissesse "o formato não é aceito" sem dizer de
 * qual arquivo seria inútil a quem enviou três. A tela reaproveita essa frase
 * como título em vez de escrever o nome de novo acima dela.
 */
function recusaDoServidor(arquivo, excecao) {
  const mensagem = excecao.errors?.arquivo?.[0] ?? excecao.message
  const corte = mensagem.indexOf('. ')

  if (corte === -1) {
    return { titulo: `${arquivo.name} não foi anexado`, texto: mensagem, conselho: '' }
  }

  return {
    titulo: mensagem.slice(0, corte),
    texto: mensagem.slice(corte + 2),
    conselho: '',
  }
}

function atualizarProgresso(local, enviados, total) {
  const envio = enviosEmCurso.value.find((item) => item.local === local)

  if (envio) {
    envio.enviados = enviados
    envio.total = total
  }
}

function percentual(envio) {
  return envio.total === 0 ? 0 : Math.round((envio.enviados / envio.total) * 100)
}

/**
 * Descartar antes de confirmar é operação legítima: o arquivo ainda não é anexo
 * de registro algum, e é por isso que ela existe aqui e não existirá depois.
 */
async function removerAnexo(anexo) {
  anexos.value = anexos.value.filter((item) => item.token !== anexo.token)
  agendarRascunho()

  try {
    await apiDelete(
      `/api/clinica/animais/${route.params.codigo}/atender/anexos/${anexo.token}?${parametros()}`,
    )
  } catch {
    // O arquivo já não estava lá, ou a rede falhou. Em qualquer dos casos ele
    // não entra no prontuário, que é o que o profissional pediu.
  }
}

/* Confirmação -------------------------------------------------------------- */

function abrirConfirmacao() {
  if (!podeConfirmar.value) return

  clearTimeout(temporizadorDoRascunho)
  salvarRascunho()

  const acontece = [
    `O registro passa a integrar o prontuário de ${animal.value.nome} com o seu nome e o seu CRMV `
    + `(${profissional.value.nome} · ${profissional.value.crmv}), pela ${tela.value.prestador.nome}.`,
  ]

  if (formulario.value.retorno_em && formulario.value.retorno_finalidade) {
    acontece.push(
      `O retorno de ${emNumeros(formulario.value.retorno_em)} gera lembrete a ${animal.value.tutor} `
      + 'e entra no painel de pendências da clínica.',
    )
  }

  if (anexos.value.length) {
    acontece.push(
      `${anexos.value.length === 1 ? 'O arquivo anexado passa' : `Os ${anexos.value.length} arquivos anexados passam`} `
      + `a fazer parte do prontuário, visível a ${animal.value.tutor} e a quem tiver autorização.`,
    )
  }

  if (retornoEmAberto.value?.encerrado_por_este) {
    acontece.push(
      `O retorno previsto para ${emNumeros(retornoEmAberto.value.em)} é encerrado por este atendimento.`,
    )
  }

  dossie.value = {
    titulo: `Confirmar o atendimento de ${animal.value.nome}`,
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
  sessaoExpirada.value = false

  try {
    const criado = await apiPost(
      `/api/clinica/animais/${route.params.codigo}/atender?${parametros()}`,
      {
        ...formulario.value,
        peso_kg: formulario.value.peso_kg === '' ? null : formulario.value.peso_kg,
        retorno_em: formulario.value.retorno_em || null,
        retorno_finalidade: formulario.value.retorno_finalidade || null,
        anexos: anexos.value.map((anexo) => ({
          token: anexo.token,
          descricao: anexo.descricao,
          exame_em: anexo.exame_em || null,
        })),
      },
    )

    dialogoAberto.value = false
    descartarRascunho()

    // Gravado o prontuário, o rascunho perdeu o assunto — e sair da tela com
    // ele guardado faria a próxima abertura oferecer de volta um texto que já
    // é registro.
    router.push(criado.destino)
  } catch (excecao) {
    dialogoAberto.value = false
    salvarRascunho()

    // Campo por corrigir não é falha de gravação: o erro já está escrito sob o
    // campo, e repetir "não conseguimos gravar" ao lado mandaria procurar na
    // rede um problema que está no formulário.
    if (excecao instanceof ApiError && excecao.status === 422) {
      errosDeCampo.value = excecao.errors
      focarPrimeiroErro()
    } else if (excecao instanceof ApiError && [401, 419].includes(excecao.status)) {
      // O acesso caiu no meio da redação. O texto não se perde, e é isso que a
      // tela precisa dizer antes de qualquer outra coisa.
      sessaoExpirada.value = true
    } else {
      erroDeEnvio.value = excecao.message
    }
  } finally {
    enviando.value = false
  }
}

function primeiroErro(campo) {
  return errosDeCampo.value[campo]?.[0] ?? ''
}

/** O campo de cada erro que o servidor pode devolver, na ordem do prontuário. */
const CAMPOS = {
  motivo: 'v08-motivo',
  anamnese: 'v08-anamnese',
  exame_fisico: 'v08-exame',
  peso_kg: 'v08-peso',
  hipoteses_diagnosticas: 'v08-hipoteses',
  diagnostico: 'v08-diagnostico',
  conduta: 'v08-conduta',
  retorno_em: 'v08-retorno',
  retorno_finalidade: 'v08-retorno-motivo',
}

/**
 * O formulário tem seis campos de texto longo e passa da altura da tela: o erro
 * de um campo fora da dobra ficaria invisível, e o botão pareceria não responder.
 */
async function focarPrimeiroErro() {
  await nextTick()

  const chave = Object.keys(CAMPOS).find((campo) => errosDeCampo.value[campo])
  const alvo = chave ? document.getElementById(CAMPOS[chave]) : document.querySelector('.anexo')

  alvo?.scrollIntoView({ behavior: 'smooth', block: 'center' })
  alvo?.focus?.()
}

function erroDoAnexo(indice) {
  return errosDeCampo.value[`anexos.${indice}.token`]?.[0]
    ?? errosDeCampo.value[`anexos.${indice}.descricao`]?.[0]
    ?? ''
}

/**
 * A saída de emergência do estado de erro: o prontuário inteiro na área de
 * transferência, para colar onde for preciso enquanto a gravação não vai.
 */
async function copiarTexto() {
  const partes = [
    ['Motivo da consulta', formulario.value.motivo],
    ['Anamnese', formulario.value.anamnese],
    ['Exame físico', formulario.value.exame_fisico],
    ['Peso aferido', formulario.value.peso_kg ? `${formulario.value.peso_kg} kg` : ''],
    ['Hipóteses diagnósticas', formulario.value.hipoteses_diagnosticas],
    ['Diagnóstico', formulario.value.diagnostico],
    ['Conduta terapêutica', formulario.value.conduta],
  ]

  const texto = partes
    .filter(([, valor]) => String(valor).trim() !== '')
    .map(([rotulo, valor]) => `${rotulo}\n${valor}`)
    .join('\n\n')

  try {
    await navigator.clipboard.writeText(texto)
    copiado.value = true
    setTimeout(() => { copiado.value = false }, 2000)
  } catch {
    // Sem permissão de área de transferência o texto continua no formulário,
    // que é de onde ele nunca saiu.
    copiado.value = false
  }
}

/**
 * Sair para renovar o acesso só é seguro porque o rascunho está no navegador, e
 * não na sessão que acabou de cair. O login leva à rota inicial do papel — não
 * há retorno automático a inventar aqui —, e o texto reaparece quando o
 * profissional voltar a esta tela.
 */
function renovarAcesso() {
  salvarRascunho()
  router.push('/entrar')
}

function trocarPrestador(id) {
  prestadorAtivo.value = id
  carregar()
}

function aoTeclar(evento) {
  if (evento.key !== 'Enter' || !(evento.metaKey || evento.ctrlKey)) return
  if (dialogoAberto.value || enviando.value) return

  evento.preventDefault()
  abrirConfirmacao()
}

onMounted(() => {
  carregar()
  document.addEventListener('keydown', aoTeclar)
})

onBeforeUnmount(() => {
  clearTimeout(temporizadorDoRascunho)
  document.removeEventListener('keydown', aoTeclar)
})

watch(() => route.params.codigo, carregar)
watch(formulario, agendarRascunho, { deep: true })
</script>

<template>
  <VetShell
    titulo="Registrar atendimento"
    :prestador="tela?.prestador"
    :vinculos="tela?.vinculos ?? []"
    @trocar-prestador="trocarPrestador"
  >
    <div v-if="carregando" class="registro" aria-busy="true" aria-live="polite">
      <span class="visually-hidden">Abrindo o registro de atendimento.</span>
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
        <span class="trilha__atual">Registrar atendimento</span>
      </nav>

      <div class="cabecalho">
        <div>
          <p class="cabecalho__sobrenome">Registrar atendimento</p>
          <h1 class="cabecalho__nome">{{ animal.nome }}</h1>
          <p class="cabecalho__descricao">
            {{ descricao }} ·
            <span class="cabecalho__codigo">{{ animal.codigo }}</span>
            · {{ momento }}
          </p>
        </div>

        <div class="cabecalho__acoes">
          <span v-if="rascunhoSalvoEm" class="selo-rascunho">
            <FilePenLine :size="14" />Rascunho salvo às {{ horaDoRascunho }}
          </span>
          <button
            type="button"
            class="botao botao--primario cabecalho__confirmar"
            :disabled="!podeConfirmar"
            title="Ctrl/Cmd + Enter"
            aria-keyshortcuts="Control+Enter"
            @click="abrirConfirmacao"
          >
            <Stethoscope :size="16" />Confirmar atendimento
          </button>
        </div>
      </div>

      <ImmutableNotice variante="formulario" class="registro__imutavel" />
      <p class="registro__rascunho-aviso">
        O rascunho fica salvo neste computador e <strong>não é registro</strong>:
        {{ animal.tutor }} não o vê, ele não entra no prontuário e não conta como documentação do
        atendimento.
      </p>

      <div v-if="sessaoExpirada" class="painel painel--erro" role="alert">
        <p class="painel__titulo painel__titulo--erro">
          <ClockAlert :size="16" />Seu acesso expirou
        </p>
        <p class="painel__texto">
          Entre de novo para continuar. O texto que você digitou está salvo neste computador e
          reaparece assim que você voltar a esta tela.
        </p>
        <p class="painel__nota">
          Lembre-se: rascunho não é registro. Enquanto você não confirmar, o atendimento não integra
          o prontuário de {{ animal.nome }}.
        </p>
        <div class="painel__acoes">
          <button type="button" class="botao botao--primario" @click="renovarAcesso">
            Renovar acesso
          </button>
        </div>
      </div>

      <div class="registro__grade">
        <!-- Coluna de leitura: 720 px de largura de linha para o texto clínico -->
        <div class="coluna">
          <section class="bloco" aria-labelledby="bloco-consulta">
            <h2 id="bloco-consulta" class="visually-hidden">Consulta</h2>

            <AppTextarea
              id="v08-motivo"
              v-model="formulario.motivo"
              label="Motivo da consulta"
              :linhas="2"
              auto-expansivel
              class="campo-clinico"
              :error="primeiroErro('motivo')"
            />

            <AppTextarea
              id="v08-anamnese"
              v-model="formulario.anamnese"
              label="Anamnese"
              :linhas="4"
              auto-expansivel
              class="campo-clinico"
              :error="primeiroErro('anamnese')"
            />

            <AppTextarea
              id="v08-exame"
              v-model="formulario.exame_fisico"
              label="Exame físico"
              :linhas="3"
              auto-expansivel
              class="campo-clinico"
              :error="primeiroErro('exame_fisico')"
            />

            <div class="peso">
              <div class="campo">
                <label for="v08-peso" class="campo__rotulo">Peso aferido hoje</label>
                <div class="campo__moldura">
                  <input
                    id="v08-peso"
                    v-model="formulario.peso_kg"
                    class="campo__entrada campo__entrada--mono"
                    type="text"
                    inputmode="decimal"
                    placeholder="12,4"
                  >
                  <span class="campo__sufixo">kg</span>
                </div>
                <p v-if="primeiroErro('peso_kg')" class="campo__erro">{{ primeiroErro('peso_kg') }}</p>
              </div>

              <p class="peso__nota">
                Cada peso é uma medição datada, não um campo que se sobrescreve:
                <template v-if="pesoAnterior">
                  o anterior era {{ emQuilos(pesoAnterior.valor) }} kg em
                  {{ emNumeros(pesoAnterior.em) }} e continua no histórico.
                </template>
                <template v-else>
                  esta é a primeira aferição registrada, e as próximas não a apagarão.
                </template>
              </p>
            </div>
          </section>

          <section class="bloco" aria-labelledby="bloco-conclusao">
            <h2 id="bloco-conclusao" class="visually-hidden">Diagnóstico e conduta</h2>

            <AppTextarea
              id="v08-hipoteses"
              v-model="formulario.hipoteses_diagnosticas"
              label="Hipóteses diagnósticas"
              :linhas="2"
              auto-expansivel
              class="campo-clinico"
              :error="primeiroErro('hipoteses_diagnosticas')"
            />

            <AppTextarea
              id="v08-diagnostico"
              v-model="formulario.diagnostico"
              label="Diagnóstico"
              :linhas="2"
              auto-expansivel
              class="campo-clinico"
              hint="Pode ficar em branco quando depender de exame — a pendência se descreve na conduta."
              :error="primeiroErro('diagnostico')"
            />

            <AppTextarea
              id="v08-conduta"
              v-model="formulario.conduta"
              label="Conduta terapêutica"
              :linhas="3"
              auto-expansivel
              class="campo-clinico"
              :error="primeiroErro('conduta')"
            />
          </section>

          <div v-if="erroDeEnvio" class="painel painel--erro" role="alert">
            <p class="painel__titulo painel__titulo--erro">
              <TriangleAlert :size="16" />Não conseguimos gravar agora
            </p>
            <p class="painel__texto">
              O atendimento não foi registrado. Todo o texto continua no formulário
              <template v-if="rascunhoSalvoEm">
                e o rascunho local foi atualizado às {{ horaDoRascunho }}.
              </template>
              <template v-else>.</template>
            </p>
            <p v-if="anexos.length" class="painel__nota">
              O anexo já enviado permanece disponível e não precisa ser enviado outra vez.
            </p>
            <p class="painel__nota">{{ erroDeEnvio }}</p>
            <div class="painel__acoes">
              <button type="button" class="botao botao--primario" @click="abrirConfirmacao">
                Tentar de novo
              </button>
              <button type="button" class="botao botao--secundario" @click="copiarTexto">
                <CircleCheck v-if="copiado" :size="16" />{{ copiado ? 'Texto copiado' : 'Copiar o texto' }}
              </button>
            </div>
          </div>
        </div>

        <!-- Cartões laterais: anexos, retorno e responsável -->
        <div class="coluna coluna--lateral">
          <section class="painel" aria-labelledby="bloco-anexos">
            <p id="bloco-anexos" class="painel__titulo">Anexos</p>

            <div
              class="solta"
              :class="{ 'solta--ativa': arrastando }"
              @dragover.prevent="arrastando = true"
              @dragleave.prevent="arrastando = false"
              @drop.prevent="aoSoltar"
            >
              <Paperclip :size="20" class="solta__icone" />
              <p class="solta__titulo">Arraste um arquivo ou escolha</p>
              <p class="solta__limites">
                PDF, JPG ou PNG · até {{ limites.tamanho_maximo_mb }} MB por arquivo
              </p>
              <label class="botao botao--secundario botao--pequeno">
                Escolher arquivo
                <input
                  class="visually-hidden"
                  type="file"
                  accept="application/pdf,image/jpeg,image/png"
                  multiple
                  @change="aoEscolherPeloBotao"
                >
              </label>
            </div>

            <div v-if="recusa" class="recusa" role="alert">
              <TriangleAlert :size="16" class="recusa__icone" />
              <div>
                <p class="recusa__titulo">{{ recusa.titulo }}</p>
                <p class="recusa__texto">{{ recusa.texto }}</p>
                <p v-if="recusa.conselho" class="recusa__texto">{{ recusa.conselho }}</p>
              </div>
            </div>

            <!-- Envio em curso: progresso por arquivo, com saída -->
            <div v-for="envio in enviosEmCurso" :key="envio.local" class="envio">
              <div class="envio__linha">
                <Paperclip :size="16" class="envio__icone" />
                <span class="envio__nome">{{ envio.nome }}</span>
                <span class="envio__percentual">{{ percentual(envio) }}%</span>
              </div>
              <div
                class="barra-progresso"
                role="progressbar"
                :aria-valuenow="percentual(envio)"
                aria-valuemin="0"
                aria-valuemax="100"
              >
                <div class="barra-progresso__preenchida" :style="{ width: `${percentual(envio)}%` }" />
              </div>
              <div class="envio__rodape">
                <span class="envio__tamanho">
                  {{ formatarTamanho(envio.enviados) }} de {{ formatarTamanho(envio.total) }}
                </span>
                <button type="button" class="ligacao" @click="envio.cancelar()">Cancelar envio</button>
              </div>
            </div>

            <div v-for="(anexo, indice) in anexos" :key="anexo.token" class="anexo">
              <div class="anexo__linha">
                <Paperclip :size="16" class="anexo__icone" />
                <span class="anexo__nome">{{ anexo.nome }}</span>
                <span class="anexo__tamanho">{{ formatarTamanho(anexo.tamanho_bytes) }}</span>
                <button
                  type="button"
                  class="anexo__remover"
                  :aria-label="`Remover ${anexo.nome}`"
                  @click="removerAnexo(anexo)"
                >
                  <X :size="16" />
                </button>
              </div>

              <div class="anexo__campos">
                <input
                  v-model="anexo.descricao"
                  class="entrada-compacta"
                  type="text"
                  placeholder="Descrição"
                  :aria-label="`Descrição de ${anexo.nome}`"
                  @input="agendarRascunho"
                >
                <input
                  v-model="anexo.exame_em"
                  class="entrada-compacta entrada-compacta--mono"
                  type="date"
                  :max="hoje"
                  :aria-label="`Data do exame de ${anexo.nome}`"
                  @input="agendarRascunho"
                >
              </div>

              <p v-if="erroDoAnexo(indice)" class="campo__erro">{{ erroDoAnexo(indice) }}</p>
              <p v-else class="anexo__dica">A descrição é exigida por arquivo; a data do exame, quando houver.</p>
            </div>
          </section>

          <section class="painel" aria-labelledby="bloco-retorno">
            <p id="bloco-retorno" class="painel__titulo">Retorno programado</p>

            <div v-if="retornoEmAberto" class="retorno-aberto">
              <CalendarClock :size="16" class="retorno-aberto__icone" />
              <p>
                Há retorno em aberto para {{ emNumeros(retornoEmAberto.em) }}
                <template v-if="retornoEmAberto.finalidade">· {{ retornoEmAberto.finalidade }}</template>.
                <template v-if="retornoEmAberto.encerrado_por_este">
                  Este atendimento o encerra.
                </template>
              </p>
            </div>

            <div class="campo campo--compacto">
              <label for="v08-retorno" class="campo__rotulo campo__rotulo--leve">Data</label>
              <div class="campo__moldura">
                <input
                  id="v08-retorno"
                  v-model="formulario.retorno_em"
                  class="campo__entrada campo__entrada--mono"
                  type="date"
                  :min="hoje"
                >
                <CalendarClock :size="16" class="campo__icone" />
              </div>
              <p v-if="primeiroErro('retorno_em')" class="campo__erro">{{ primeiroErro('retorno_em') }}</p>
            </div>

            <div class="campo campo--compacto">
              <label for="v08-retorno-motivo" class="campo__rotulo campo__rotulo--leve">Motivo</label>
              <div class="campo__moldura">
                <input
                  id="v08-retorno-motivo"
                  v-model="formulario.retorno_finalidade"
                  class="campo__entrada"
                  type="text"
                  placeholder="Reavaliação com resultado do exame"
                >
              </div>
              <p v-if="primeiroErro('retorno_finalidade')" class="campo__erro">
                {{ primeiroErro('retorno_finalidade') }}
              </p>
            </div>

            <p class="nota-lembrete">
              <Bell :size="16" class="nota-lembrete__icone" />
              <span>
                {{ animal.tutor }} recebe um lembrete deste retorno, e ele aparece no painel de
                pendências da clínica.
              </span>
            </p>
          </section>

          <section class="painel" aria-labelledby="bloco-responsavel">
            <p id="bloco-responsavel" class="painel__titulo">Responsável</p>
            <p class="responsavel">
              <UserRoundCheck :size="16" class="responsavel__icone" />
              <span>
                {{ tela.prestador.nome }} · {{ profissional.nome }} ·
                <span class="mono">{{ profissional.crmv }}</span>
              </span>
            </p>
            <p class="painel__nota">
              Vem do seu acesso e não é editável: é a sua responsabilidade técnica sobre o que está
              escrito aqui.
            </p>
          </section>
        </div>
      </div>

      <!-- Barra de ação fixa: abaixo de 1024 px o botão do cabeçalho sai de vista -->
      <div class="barra">
        <button
          type="button"
          class="botao botao--primario botao--confirmar"
          :disabled="!podeConfirmar"
          @click="abrirConfirmacao"
        >
          <Stethoscope :size="16" />Confirmar atendimento
        </button>
      </div>
    </div>

    <ConfirmDialog
      :aberto="dialogoAberto"
      :titulo="dossie?.titulo ?? ''"
      :acontece="dossie?.acontece ?? []"
      :nao-acontece="dossie?.naoAcontece ?? []"
      rotulo-confirmar="Confirmar atendimento"
      rotulo-cancelar="Revisar o texto"
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

/* §4.3 — abaixo de 1024 px o alvo de toque é de 44 px. */
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

.cabecalho__acoes {
  display: flex;
  align-items: center;
  gap: var(--space-3);
}

/* Um botão de confirmar por vez: no cabeçalho a partir de `lg`, na barra fixa
   abaixo dela. A regra precisa do seletor composto para vencer `.botao`, que
   declara `display` e vem depois nesta folha. */
.cabecalho__acoes .cabecalho__confirmar {
  display: none;
}

.selo-rascunho {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  height: 22px;
  padding: 0 10px;
  border-radius: 999px;
  background: var(--surface-sunken);
  color: var(--ink-muted);
  font-size: 12px;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.registro__imutavel {
  margin: var(--space-4) 0 0;
}

/* O aviso do rascunho é irmão do `ImmutableNotice`, e não parte dele: a
   imutabilidade é regra do sistema; o rascunho, comportamento desta tela. */
.registro__rascunho-aviso {
  max-width: 75ch;
  margin: var(--space-2) 0 0;
  padding: 0 var(--space-4) 0 calc(var(--space-4) + 18px + var(--space-3));
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

/* Grade -------------------------------------------------------------------- */

/* A coluna de leitura tem 720 px em toda largura em que caiba, e não só onde há
   duas colunas: o limite é da linha de texto, não do layout. Abaixo de `xl` os
   cartões de anexo e retorno descem para baixo do formulário, e continuam
   alinhados a ele. */
.registro__grade {
  display: grid;
  grid-template-columns: minmax(0, 720px);
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

.bloco {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

/* Texto clínico: altura mínima de 120 px, como o briefing pede, e expansão
   automática daí em diante. */
.campo-clinico :deep(.app-textarea__campo) {
  min-height: 120px;
}

/* Peso --------------------------------------------------------------------- */

.peso {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--space-3);
  align-items: flex-start;
}

.peso__nota {
  margin: 0;
  padding: var(--space-3);
  background: var(--surface-sunken);
  border-radius: var(--radius-sm);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

/* Campos ------------------------------------------------------------------- */

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

.campo__rotulo--leve {
  font-size: 12px;
  font-weight: 400;
  letter-spacing: 0;
  text-transform: none;
  color: var(--ink-faint);
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

.campo__icone {
  flex: none;
  color: var(--ink-muted);
}

.campo__sufixo {
  flex: none;
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
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

/* Painéis laterais --------------------------------------------------------- */

.painel {
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

.painel--erro {
  border-color: var(--status-late);
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
  color: var(--ink-muted);
}

.painel__titulo--erro {
  color: var(--status-late);
}

.painel__texto {
  margin: var(--space-3) 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

.painel__nota {
  margin: var(--space-3) 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.painel__acoes {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  margin: var(--space-4) 0 0;
}

/* Anexos ------------------------------------------------------------------- */

.solta {
  margin: var(--space-3) 0 0;
  padding: var(--space-3);
  background: var(--surface-page);
  border: 1px dashed var(--border-strong);
  border-radius: var(--radius-sm);
  text-align: center;
}

.solta--ativa {
  background: var(--brand-wash);
  border-color: var(--brand);
}

.solta__icone {
  color: var(--ink-muted);
}

.solta__titulo {
  margin: var(--space-2) 0 0;
  font-size: 14px;
  line-height: 20px;
  font-weight: 600;
  color: var(--ink);
}

.solta__limites {
  margin: var(--space-1) 0 0;
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-muted);
}

.solta .botao {
  margin: var(--space-3) 0 0;
}

.recusa {
  display: flex;
  align-items: flex-start;
  gap: var(--space-2);
  margin: var(--space-3) 0 0;
  padding: var(--space-3);
  background: var(--status-late-wash);
  border-radius: var(--radius-sm);
}

.recusa__icone {
  flex: none;
  margin-top: 2px;
  color: var(--status-late);
}

.recusa__titulo {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  font-weight: 600;
  color: var(--ink);
}

.recusa__texto {
  margin: var(--space-1) 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.envio,
.anexo {
  margin: var(--space-3) 0 0;
  padding: var(--space-3);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-sm);
}

.envio__linha,
.anexo__linha {
  display: flex;
  align-items: center;
  gap: var(--space-2);
}

.envio__icone,
.anexo__icone {
  flex: none;
  color: var(--ink-muted);
}

.envio__nome,
.anexo__nome {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 14px;
  line-height: 20px;
  font-weight: 600;
  color: var(--ink);
}

.envio__percentual,
.anexo__tamanho {
  flex: none;
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
  font-variant-numeric: tabular-nums;
}

.anexo__remover {
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  margin: -12px -8px -12px 0;
  border: none;
  background: transparent;
  color: var(--ink-muted);
  cursor: pointer;
}

.barra-progresso {
  height: 6px;
  margin: var(--space-2) 0 0;
  background: var(--surface-sunken);
  border-radius: 999px;
  overflow: hidden;
}

.barra-progresso__preenchida {
  height: 100%;
  background: var(--brand);
  transition: width .2s linear;
}

.envio__rodape {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-2);
  margin: var(--space-2) 0 0;
}

.envio__tamanho {
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
  font-variant-numeric: tabular-nums;
}

.ligacao {
  padding: 0;
  border: none;
  background: none;
  font-family: var(--font-body);
  font-size: 12px;
  font-weight: 600;
  color: var(--brand);
  cursor: pointer;
}

.anexo__campos {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--space-2);
  margin: var(--space-2) 0 0;
}

.entrada-compacta {
  height: 44px;
  padding: 0 var(--space-2);
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  font-family: var(--font-body);
  font-size: 14px;
  color: var(--ink);
}

.entrada-compacta--mono {
  font-family: var(--font-mono);
  font-size: 13px;
  font-variant-numeric: tabular-nums;
}

.anexo__dica {
  margin: 6px 0 0;
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
}

/* Retorno e responsável ---------------------------------------------------- */

.campo--compacto {
  margin: var(--space-3) 0 0;
}

.retorno-aberto {
  display: flex;
  align-items: flex-start;
  gap: var(--space-2);
  margin: var(--space-3) 0 0;
  padding: var(--space-3);
  background: var(--surface-sunken);
  border-radius: var(--radius-sm);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

.retorno-aberto p {
  margin: 0;
}

.retorno-aberto__icone {
  flex: none;
  margin-top: 2px;
  color: var(--ink-muted);
}

.nota-lembrete {
  display: flex;
  align-items: flex-start;
  gap: var(--space-2);
  margin: var(--space-3) 0 0;
  padding: var(--space-3);
  background: var(--brand-wash);
  border-radius: var(--radius-sm);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

.nota-lembrete__icone {
  flex: none;
  margin-top: 2px;
  color: var(--brand);
}

.responsavel {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: var(--space-3) 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

.responsavel__icone {
  flex: none;
  color: var(--brand);
}

/* Ações -------------------------------------------------------------------- */

.barra {
  position: sticky;
  bottom: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  margin: var(--space-4) 0 0;
  padding: var(--space-3) 0;
  background: var(--surface-page);
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
  .anexo__campos {
    grid-template-columns: 1fr 140px;
  }
}

@media (min-width: 1024px) {
  .cabecalho__nome {
    font-size: 36px;
    line-height: 40px;
  }

  .cabecalho__acoes .cabecalho__confirmar {
    display: inline-flex;
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

  .campo__erro {
    font-size: 12px;
    line-height: 16px;
  }

  .entrada-compacta {
    height: 32px;
  }

  .anexo__remover {
    width: 32px;
    height: 32px;
    margin: -6px -4px -6px 0;
  }

  .botao {
    height: 40px;
  }

  .peso {
    grid-template-columns: 200px 1fr;
    gap: var(--space-4);
  }

  /* O botão vive no cabeçalho a partir daqui; a barra fixa é do celular. */
  .barra {
    display: none;
  }
}

/* `xl` (§4.2 do briefing) é a largura de referência do ambiente do veterinário,
   e é onde o desenho põe anexos e retorno em cartões laterais. Antes de 1440 px
   a coluna lateral ficaria com pouco mais de 200 px ao lado da barra de
   navegação — dois campos de anexo espremidos onde cabe um. */
@media (min-width: 1440px) {
  .registro__grade {
    grid-template-columns: 720px minmax(320px, 1fr);
    gap: var(--space-6);
  }
}
</style>
