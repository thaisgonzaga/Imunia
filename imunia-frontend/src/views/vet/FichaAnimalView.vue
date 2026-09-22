<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  CalendarClock,
  Cat,
  ChevronLeft,
  CircleHelp,
  ClockAlert,
  Dog,
  Eye,
  FileCheck,
  FilePenLine,
  FileText,
  History,
  Hourglass,
  KeyRound,
  Moon,
  Paperclip,
  Stethoscope,
  Syringe,
  TriangleAlert,
  UserRound,
} from '@lucide/vue'
import VetShell from '@/components/vet/VetShell.vue'
import StatusPill from '@/components/base/StatusPill.vue'
import EmptyState from '@/components/base/EmptyState.vue'
import ProvenanceChip from '@/components/tutor/ProvenanceChip.vue'
import VaccineRail from '@/components/tutor/VaccineRail.vue'
import BatchSeal from '@/components/tutor/BatchSeal.vue'
import TimelineEntry from '@/components/tutor/TimelineEntry.vue'
import SolicitarAutorizacaoModal from '@/components/vet/SolicitarAutorizacaoModal.vue'
import RegistrarObitoModal from '@/components/vet/RegistrarObitoModal.vue'
import ExportarDocumentoModal from '@/components/tutor/ExportarDocumentoModal.vue'
import { apiGet } from '@/lib/api.js'
import { descreverEspecie, descreverIdade } from '@/lib/animais.js'
import { emNumeros, porExtenso } from '@/lib/datas.js'
import { procedenciaNoAmbienteClinico } from '@/lib/procedencia.js'

/**
 * V06 — ficha clínica do animal (RF19, RF35, RF52).
 *
 * O centro de trabalho do veterinário sobre um animal: identidade, carteira,
 * histórico e anexos sem troca de tela. Três decisões de desenho governam o que
 * está aqui, e as três vêm do briefing, não de conveniência:
 *
 * 1. **O aviso de outro prestador vem antes do conteúdo** (RF52b). A gravação
 *    do acesso é condição da exibição, e a ordem visual reflete a ordem dos
 *    fatos: o profissional lê que a visualização ficou registrada antes de ler
 *    o que foi registrado.
 * 2. **Alerta clínico fica acima de alerta administrativo**, sempre. Uma dose
 *    atrasada e um prazo de autorização não disputam a mesma altura de tela.
 * 3. **A ausência de autorização é um estado de tela, não um erro** (P2). A
 *    ficha sem autorização é desenhada com o mesmo cuidado da cheia: espécie,
 *    nome e código, a explicação do porquê e um caminho único.
 */
const route = useRoute()
const router = useRouter()

const ficha = ref(null)
const carregando = ref(true)
const erro = ref('')
const menuAberto = ref(false)

const ABAS = [
  { chave: 'resumo', rotulo: 'Resumo' },
  { chave: 'carteira', rotulo: 'Carteira' },
  { chave: 'historico', rotulo: 'Histórico' },
  { chave: 'anexos', rotulo: 'Anexos' },
]

/**
 * A aba viaja na URL porque V07 promete voltar para "V06, aba Carteira" depois
 * de registrar a aplicação, e um destino que não sabe dizer em que aba abre não
 * cumpre essa promessa.
 */
const aba = computed(() => {
  const pedida = route.query.aba
  return ABAS.some((item) => item.chave === pedida) ? pedida : 'resumo'
})

function abrirAba(chave) {
  router.replace({ query: { ...route.query, aba: chave === 'resumo' ? undefined : chave } })
}

const acesso = computed(() => ficha.value?.acesso ?? null)
const autorizado = computed(() => acesso.value === 'autorizado')
const animal = computed(() => ficha.value?.animal ?? null)
const autorizacao = computed(() => ficha.value?.autorizacao ?? null)
const prestador = computed(() => ficha.value?.prestador?.nome ?? null)
const alertas = computed(() => ficha.value?.alertas ?? { clinicos: [], administrativos: [] })
const resumo = computed(() => ficha.value?.resumo ?? null)
const carteira = computed(() => ficha.value?.carteira ?? null)
const historico = computed(() => ficha.value?.historico ?? null)
const anexos = computed(() => ficha.value?.anexos ?? [])
const inativo = computed(() => Boolean(animal.value?.obito))

const icone = computed(() => (animal.value?.especie === 'gato' ? Cat : Dog))

/** V10 — o modal do pedido, aberto sobre o estado P2 desta tela. */
const solicitando = ref(false)

/** T15 pela clínica — o modal de exportação, sobre a ficha autorizada. */
const exportarAberto = ref(false)

/** V12 — o modal de óbito, sobre a ficha autorizada. */
const registrandoObito = ref(false)

/** O desfecho do pedido feito nesta visita, que vira a etiqueta de espera. */
const solicitacao = ref(null)

const alvoDaSolicitacao = computed(() => (animal.value
  ? {
      tipo: 'animal',
      termo: animal.value.codigo,
      nome: animal.value.nome,
      especie: animal.value.especie,
    }
  : null))

/**
 * A pendência que troca o botão pela etiqueta: a que a ficha já trouxe, ou a
 * que acabou de nascer no modal.
 */
const solicitacaoPendente = computed(
  () => solicitacao.value ?? ficha.value?.solicitacao_pendente ?? null,
)

/**
 * A linha de metadados do cabeçalho: espécie, idade e quem o trouxe.
 *
 * O nome do tutor entra sem o rótulo "tutor" nem "tutora". O gênero é da
 * pessoa, e o sistema não o conhece — deduzi-lo do nome erraria com gente de
 * verdade. Quem precisa do rótulo o tem na aba Resumo, onde ele nomeia o campo
 * e não a pessoa.
 */
const descricao = computed(() => {
  if (!animal.value) return ''

  return [
    descreverEspecie(animal.value.especie),
    descreverIdade(animal.value.idade_em_meses, animal.value.nascimento_exato),
    animal.value.tutor?.nome,
  ]
    .filter(Boolean)
    .join(' · ')
})

/**
 * As duas tarjas que precedem o cabeçalho. A de óbito vem primeiro: muda o que
 * a tela inteira oferece, e lê-la depois das ações seria descobrir tarde demais
 * por que elas sumiram.
 */
const tarjaPreliminar = computed(() =>
  alertas.value.administrativos.find((alerta) => alerta.chave === 'cadastro-preliminar'),
)

const tarjaAutorizacao = computed(() =>
  alertas.value.administrativos.find((alerta) => alerta.chave === 'autorizacao-a-expirar'),
)

/**
 * Os alertas que sobram para o painel lateral. Preliminar e autorização a
 * expirar já viraram tarja no topo, e repeti-los ao lado seria dizer duas vezes
 * a mesma coisa na mesma tela.
 */
const alertasAdministrativos = computed(() =>
  alertas.value.administrativos.filter(
    (alerta) => !['cadastro-preliminar', 'autorizacao-a-expirar'].includes(alerta.chave),
  ),
)

const temAlertas = computed(
  () => alertas.value.clinicos.length > 0 || alertasAdministrativos.value.length > 0,
)

const ICONES = {
  'clock-alert': ClockAlert,
  'triangle-alert': TriangleAlert,
  'key-round': KeyRound,
  'calendar-clock': CalendarClock,
  'user-round': UserRound,
  moon: Moon,
}

/* Histórico ---------------------------------------------------------------- */

const CAMINHO_POR_REGISTRO = {
  vacinacao: 'vacinas',
  atendimento: 'atendimentos',
  exame: 'exames',
  obito: 'obitos',
}

/**
 * O detalhe de cada registro é tela própria do ambiente clínico (V09), com a
 * ação de retificar para o autor. Não são as rotas do tutor: aquelas recusam
 * quem não é titular do animal, e o profissional receberia 403 no lugar do
 * prontuário que ele mesmo escreveu.
 *
 * Exame e óbito ainda não têm tela, e o destino deles cai em E02 — a resposta
 * correta para uma rota que ainda não existe, e a mesma convenção que a moldura
 * e V03 já seguem.
 *
 * O contexto vai junto: sem ele, a tela de destino recairia no primeiro vínculo
 * do profissional, e a ação de retificar só existe dentro do prestador que
 * produziu o registro (RN27).
 */
function destinoDe(entrada) {
  return comContexto(
    `/clinica/animais/${route.params.codigo}/${CAMINHO_POR_REGISTRO[entrada.registro]}/${entrada.id}`,
  )
}

const itensDoHistorico = computed(() => {
  const entradas = historico.value?.entradas ?? []
  const lista = entradas.map((entrada) => ({ entrada, vinculadas: [] }))

  // A chave é o par registro+id, e não o id sozinho: desde V09 a retificação
  // também existe para a aplicação de vacina, e uma vacinação e um atendimento
  // podem ter o mesmo id sem terem relação alguma.
  const porRegistro = new Map(
    lista
      .filter(({ entrada }) => entrada.tipo !== 'retificacao')
      .map((item) => [`${item.entrada.registro}-${item.entrada.id}`, item]),
  )

  return lista
    .filter((item) => {
      const original = item.entrada.vinculada_a
        ? porRegistro.get(`${item.entrada.registro}-${item.entrada.vinculada_a}`)
        : null
      if (!original) return true

      original.vinculadas.push(item.entrada)

      return false
    })
    .map((item) => ({
      entrada: item.entrada,
      destino: destinoDe(item.entrada),
      vinculadas: item.vinculadas.map((entrada) => ({ entrada, destino: destinoDe(entrada) })),
    }))
})

/* Carteira ----------------------------------------------------------------- */

const grupos = computed(() => carteira.value?.grupos ?? [])

function notaDaRegra(grupo) {
  return grupo.proxima_dose?.regra_texto ?? ''
}

/* Anexos ------------------------------------------------------------------- */

const anexosPorAtendimento = computed(() => {
  const grupos = new Map()

  for (const anexo of anexos.value) {
    const chave = anexo.atendimento.id

    if (!grupos.has(chave)) {
      grupos.set(chave, { atendimento: anexo.atendimento, anexos: [] })
    }

    grupos.get(chave).anexos.push(anexo)
  }

  return [...grupos.values()]
})

/* Carga -------------------------------------------------------------------- */

/**
 * O prestador ativo, na ordem em que ele pode ser conhecido: o que a tela já
 * carregou, ou o que veio no endereço.
 *
 * A segunda metade nasceu com V07. Sem ela, voltar do registro de vacinação —
 * ou abrir a ficha por uma ligação que carrega o contexto — recaía no primeiro
 * vínculo do profissional, e a tela mostrava a ficha de uma clínica enquanto o
 * endereço falava de outra.
 */
const prestadorAtivo = computed(
  () => ficha.value?.prestador?.id ?? route.query.prestador ?? null,
)

function parametros() {
  const busca = new URLSearchParams()

  if (prestadorAtivo.value) busca.set('prestador', prestadorAtivo.value)

  return busca
}

/**
 * O contexto acompanha toda ligação que sai desta tela. O registro clínico é
 * imutável (RN26) e a retificação é privativa do autor **dentro do prestador
 * que produziu o registro** (RN27): um destino que perde o contexto recai no
 * primeiro vínculo do profissional, e a tela de lá deixa de oferecer a correção
 * — ou, pior antes de V09 existir, carimbava o prestador errado num registro
 * sem conserto.
 *
 * O separador depende do caminho porque nem todo destino é limpo: a aba do
 * histórico já viaja em `?aba=historico`, e um segundo `?` produziria um
 * endereço que o roteador não reconhece — a tela cairia em "Esta página não
 * existe" vinda de um link da própria ficha.
 */
function comContexto(caminho) {
  if (!prestadorAtivo.value) return caminho

  return `${caminho}${caminho.includes('?') ? '&' : '?'}prestador=${prestadorAtivo.value}`
}

async function carregar() {
  carregando.value = true
  erro.value = ''

  try {
    ficha.value = await apiGet(`/api/clinica/animais/${route.params.codigo}?${parametros()}`)
    solicitacao.value = null

    // V10 — `/clinica/autorizacoes/nova?animal=X` redireciona para cá com
    // `?solicitar=1`: o modal abre na chegada, e o parâmetro sai do endereço
    // para que recarregar ou voltar não reabra um pedido já decidido. Com
    // pedido pendente o modal não abre — a etiqueta já responde.
    if (route.query.solicitar) {
      solicitando.value = ficha.value.acesso === 'sem_autorizacao'
        && !ficha.value.solicitacao_pendente

      router.replace({ query: { ...route.query, solicitar: undefined } })
    }

    // T15 — `/clinica/animais/:codigo/exportar` redireciona para cá com
    // `?exportar=1`, como o endereço antigo do tutor: o modal abre na chegada.
    // Só com autorização vigente — sem ela não há histórico a exportar, e a
    // tela já explica o porquê — e o parâmetro sai do endereço, como acima.
    if (route.query.exportar) {
      exportarAberto.value = ficha.value.acesso === 'autorizado'

      router.replace({ query: { ...route.query, exportar: undefined } })
    }

    // V12 — `/clinica/animais/:codigo/obito` redireciona para cá com
    // `?obito=1`: o modal abre na chegada, também só com autorização vigente.
    // Para o animal que já tem óbito o modal abre do mesmo jeito — em estado
    // "já registrado", que informa a data e o autor em vez de oferecer
    // formulário — e o parâmetro sai do endereço, como acima.
    if (route.query.obito) {
      registrandoObito.value = ficha.value.acesso === 'autorizado'

      router.replace({ query: { ...route.query, obito: undefined } })
    }

    revelarNovoRegistro()
  } catch (excecao) {
    erro.value = excecao.message
  } finally {
    carregando.value = false
  }
}

/**
 * V07 chega aqui com `?aba=carteira&novo=<id>` depois de gravar a aplicação. O
 * registro recém-criado pode ter caído em qualquer grupo da carteira, e é a
 * carteira que sabe onde — não a tela que o criou.
 *
 * O destaque dura dois segundos, como o briefing pede: ele responde "onde foi
 * parar o que acabei de registrar?", e essa pergunta tem prazo. Passado ele, o
 * selo é um selo como os outros, que é o que ele de fato é.
 */
const novoRegistro = computed(() => Number(route.query.novo) || null)
const destacando = ref(false)
let relogioDoDestaque = null

/**
 * A âncora de uma entrada da linha do tempo. Só o atendimento a recebe, porque
 * só ele chega aqui recém-criado (V08); a vacinação de V07 volta para a aba
 * Carteira, onde o selo já tem endereço próprio.
 */
function ancoraDaEntrada(entrada) {
  return entrada.registro === 'atendimento' ? `atendimento-${entrada.id}` : undefined
}

async function revelarNovoRegistro() {
  if (novoRegistro.value === null) return

  await nextTick()

  destacando.value = true
  const alvo = document.getElementById(`aplicacao-${novoRegistro.value}`)
    ?? document.getElementById(`atendimento-${novoRegistro.value}`)

  alvo?.scrollIntoView({ behavior: 'smooth', block: 'center' })

  clearTimeout(relogioDoDestaque)
  relogioDoDestaque = setTimeout(() => { destacando.value = false }, 2000)
}

function trocarPrestador(id) {
  ficha.value = { ...ficha.value, prestador: { ...ficha.value.prestador, id } }
  carregar()
}

/**
 * O menu de ações menos frequentes fecha por clique fora e por `Esc`, como o da
 * moldura. Sem isso ele fica aberto por cima do conteúdo enquanto o
 * profissional lê o histórico — e é ele quem carrega as duas ações mais graves
 * da tela, óbito e retificação.
 */
function aoClicarNoDocumento(evento) {
  const alvo = evento.target
  if (alvo instanceof Element && alvo.closest('[data-menu]')) return

  menuAberto.value = false
}

function aoTeclar(evento) {
  if (evento.key === 'Escape') menuAberto.value = false
}

onMounted(() => {
  carregar()
  document.addEventListener('click', aoClicarNoDocumento)
  document.addEventListener('keydown', aoTeclar)
})

onBeforeUnmount(() => {
  clearTimeout(relogioDoDestaque)
  document.removeEventListener('click', aoClicarNoDocumento)
  document.removeEventListener('keydown', aoTeclar)
})

watch(() => route.params.codigo, carregar)
</script>

<template>
  <VetShell
    titulo="Ficha do animal"
    :prestador="ficha?.prestador"
    :vinculos="ficha?.vinculos ?? []"
    @trocar-prestador="trocarPrestador"
  >
    <div v-if="carregando" class="ficha" aria-busy="true" aria-live="polite">
      <span class="visually-hidden">Abrindo a ficha do animal.</span>
      <div class="cabecalho">
        <div class="esqueleto esqueleto--retrato" />
        <div class="cabecalho__texto">
          <div class="esqueleto esqueleto--titulo" />
          <div class="esqueleto esqueleto--linha" />
        </div>
      </div>
      <div class="conteudo">
        <div class="coluna">
          <div class="esqueleto esqueleto--bloco" />
          <div class="esqueleto esqueleto--bloco" />
        </div>
        <div class="coluna">
          <div class="esqueleto esqueleto--cartao" />
          <div class="esqueleto esqueleto--cartao" />
        </div>
      </div>
    </div>

    <div v-else-if="erro" class="ficha">
      <div class="aviso aviso--erro" role="alert">
        <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
        <div>
          <p class="aviso__titulo">Não conseguimos abrir esta ficha.</p>
          <p class="aviso__texto">{{ erro }}</p>
          <button type="button" class="botao botao--secundario" @click="carregar">
            Tentar novamente
          </button>
        </div>
      </div>
    </div>

    <!-- Estado P2: sem autorização vigente. Espécie, nome e código, a razão da
         ausência e um caminho único — pedir a autorização a quem pode dá-la. -->
    <div v-else-if="!autorizado" class="ficha ficha--estreita">
      <RouterLink to="/clinica/buscar" class="voltar">
        <ChevronLeft :size="16" :stroke-width="1.75" />
        Voltar à busca
      </RouterLink>

      <section class="sem-autorizacao">
        <header class="sem-autorizacao__topo">
          <KeyRound :size="24" :stroke-width="1.75" class="sem-autorizacao__icone" />
          <h1 class="sem-autorizacao__titulo">Sem autorização vigente para este animal</h1>
        </header>

        <div class="sem-autorizacao__corpo">
          <h2 class="rotulo">O que a clínica pode ver agora</h2>
          <dl class="identificacao-minima">
            <div class="identificacao-minima__item">
              <dt class="identificacao-minima__rotulo">Nome</dt>
              <dd class="identificacao-minima__valor">{{ animal.nome }}</dd>
            </div>
            <div class="identificacao-minima__item">
              <dt class="identificacao-minima__rotulo">Espécie</dt>
              <dd class="identificacao-minima__valor identificacao-minima__valor--especie">
                <component :is="icone" :size="16" :stroke-width="1.75" />
                {{ descreverEspecie(animal.especie) }}
              </dd>
            </div>
            <div class="identificacao-minima__item">
              <dt class="identificacao-minima__rotulo">Código</dt>
              <dd class="identificacao-minima__valor identificacao-minima__valor--codigo">
                {{ animal.codigo }}
              </dd>
            </div>
          </dl>

          <p class="sem-autorizacao__texto">
            Vacinas, atendimentos, anexos e os dados do tutor aparecem depois que ele autorizar
            {{ prestador }}. Você pode registrar um atendimento agora: o que você registrar fica no
            prontuário desta clínica.
          </p>

          <!-- V10 — o pedido é modal sobre este estado. Pendente, o botão
               vira etiqueta de espera: pedir de novo não apressaria ninguém. -->
          <p v-if="solicitacaoPendente" class="etiqueta-de-espera">
            <Hourglass :size="16" :stroke-width="1.75" />
            Solicitação enviada · aguardando o tutor até
            {{ emNumeros(solicitacaoPendente.expira_em) }}
          </p>

          <div class="sem-autorizacao__acoes">
            <button
              v-if="!solicitacaoPendente"
              type="button"
              class="botao botao--consentimento"
              @click="solicitando = true"
            >
              <KeyRound :size="16" :stroke-width="1.75" />
              Solicitar autorização ao tutor
            </button>
            <RouterLink
              :to="comContexto(`/clinica/animais/${animal.codigo}/atender`)"
              class="botao botao--secundario"
            >
              Registrar atendimento
            </RouterLink>
          </div>
        </div>

        <!-- RF18b e RF52 — a consulta ficou registrada, e o profissional fica
             sabendo disso na mesma tela em que ela aconteceu. -->
        <footer class="sem-autorizacao__registro">
          <Eye :size="20" :stroke-width="1.75" class="sem-autorizacao__registro-icone" />
          <p class="sem-autorizacao__registro-texto">
            Esta consulta ficou registrada: o tutor verá que {{ prestador }} abriu a ficha de
            {{ animal.nome }}, com data e hora.
          </p>
        </footer>
      </section>
    </div>

    <div v-else class="ficha">
      <!-- Tarjas, na ordem em que mudam a leitura do resto da tela. -->
      <div v-if="inativo" class="tarja tarja--obito">
        <Moon :size="16" :stroke-width="1.75" class="tarja__icone" />
        <p class="tarja__texto">
          Óbito registrado em {{ emNumeros(animal.obito.em) }}. O histórico permanece disponível;
          o calendário e os lembretes foram encerrados.
        </p>
      </div>

      <div v-if="tarjaPreliminar" class="tarja tarja--preliminar">
        <UserRound :size="16" :stroke-width="1.75" class="tarja__icone" />
        <p class="tarja__texto">{{ tarjaPreliminar.texto }}</p>
        <!-- Com contexto, como toda ligação que sai da ficha: a caracterização
             exige autorização vigente do prestador ativo, não do primeiro
             vínculo do profissional. -->
        <RouterLink :to="comContexto(tarjaPreliminar.acao.destino)" class="botao botao--consentimento botao--sm">
          {{ tarjaPreliminar.acao.rotulo }}
        </RouterLink>
      </div>

      <div v-if="tarjaAutorizacao" class="tarja tarja--autorizacao">
        <ClockAlert :size="16" :stroke-width="1.75" class="tarja__icone" />
        <p class="tarja__texto">
          {{ tarjaAutorizacao.texto }} {{ tarjaAutorizacao.nota }}
        </p>
        <RouterLink :to="tarjaAutorizacao.acao.destino" class="botao botao--secundario botao--sm">
          {{ tarjaAutorizacao.acao.rotulo }}
        </RouterLink>
      </div>

      <!-- Cabeçalho de 96 px: o essencial da identificação, presente durante
           toda a rolagem. -->
      <header class="cabecalho">
        <img v-if="animal.foto_url" :src="animal.foto_url" alt="" class="cabecalho__retrato">
        <span v-else class="cabecalho__retrato cabecalho__retrato--icone">
          <component :is="icone" :size="24" :stroke-width="1.75" />
        </span>

        <div class="cabecalho__texto">
          <div class="cabecalho__linha">
            <h1 class="cabecalho__nome">{{ animal.nome }}</h1>
            <!-- Em celular o código desce para a segunda linha, no lugar da
                 meta: é o que o desenho de 360 px mostra, e é o dado que se
                 confere contra o que o tutor dita. -->
            <span class="cabecalho__codigo">{{ animal.codigo }}</span>

            <span v-if="inativo" class="selo selo--obito">
              <Moon :size="14" :stroke-width="1.75" />
              Óbito em {{ emNumeros(animal.obito.em) }}
            </span>
            <span v-else-if="autorizacao" class="selo selo--autorizacao">
              <KeyRound :size="14" :stroke-width="1.75" />
              Autorizado até {{ emNumeros(autorizacao.expira_em) }}
            </span>
          </div>
          <p class="cabecalho__meta">{{ descricao }}</p>
        </div>

        <!-- RF22 — registrado o óbito, as ações de registro clínico não
             aparecem desabilitadas: não aparecem. A única escrita possível é a
             retificação de um registro já existente (RF33). -->
        <div class="cabecalho__acoes">
          <template v-if="!inativo">
            <RouterLink
              :to="comContexto(`/clinica/animais/${animal.codigo}/vacinar`)"
              class="botao botao--primario"
            >
              <Syringe :size="16" :stroke-width="1.75" />
              <span class="botao__rotulo-longo">Registrar vacinação</span>
              <span class="botao__rotulo-curto" aria-hidden="true">Vacinar</span>
            </RouterLink>
            <RouterLink
              :to="comContexto(`/clinica/animais/${animal.codigo}/atender`)"
              class="botao botao--secundario"
            >
              <Stethoscope :size="16" :stroke-width="1.75" />
              <span class="botao__rotulo-longo">Registrar atendimento</span>
              <span class="botao__rotulo-curto" aria-hidden="true">Atender</span>
            </RouterLink>
          </template>

          <!-- T15 pela clínica — modal, não navegação: o endereço
               `/clinica/animais/:codigo/exportar` segue vivo como redirect
               para quem o guardou. -->
          <button
            type="button"
            class="botao botao--secundario botao--exportar"
            @click="exportarAberto = true"
          >
            <FileCheck :size="16" :stroke-width="1.75" class="botao__icone-consentimento" />
            Exportar PDF
          </button>

          <div class="menu" data-menu>
            <button
              type="button"
              class="botao botao--secundario botao--icone"
              aria-haspopup="menu"
              :aria-expanded="menuAberto"
              aria-label="Mais ações"
              @click="menuAberto = !menuAberto"
            >
              <span aria-hidden="true">⋯</span>
            </button>

            <div v-if="menuAberto" class="menu__lista" role="menu">
              <!-- V09 não é uma tela por animal, e não pode ser: retificar
                   exige saber **qual** registro se corrige, e a resposta a isso
                   é a lista. A ação vive no registro, para o autor dele (RN27);
                   daqui sai o caminho até ele. -->
              <RouterLink
                :to="comContexto(`/clinica/animais/${animal.codigo}?aba=historico`)"
                class="menu__item"
                role="menuitem"
                @click="menuAberto = false"
              >
                <FilePenLine :size="16" :stroke-width="1.75" />
                <span>
                  Retificar um registro
                  <span class="menu__nota">Escolha no histórico qual corrigir</span>
                </span>
              </RouterLink>
              <template v-if="!inativo">
                <!-- RF30 (reação adversa) está em Trabalhos Futuros; nenhuma
                     ação aqui até existir rota e tabela para sustentá-la. -->

                <!-- V12 — modal, não navegação: o endereço
                     `/clinica/animais/:codigo/obito` segue vivo como redirect
                     para quem o guardou, como o de exportar. -->
                <button
                  type="button"
                  class="menu__item"
                  role="menuitem"
                  @click="menuAberto = false; registrandoObito = true"
                >
                  <Moon :size="16" :stroke-width="1.75" />
                  Registrar óbito
                </button>
              </template>
            </div>
          </div>
        </div>
      </header>

      <!-- Abas: pílulas em desktop, seletor abaixo de 1024 px. -->
      <div class="abas">
        <nav class="abas__lista" aria-label="Seções da ficha">
          <button
            v-for="item in ABAS"
            :key="item.chave"
            type="button"
            class="abas__item"
            :class="{ 'abas__item--ativa': aba === item.chave }"
            :aria-current="aba === item.chave ? 'page' : undefined"
            @click="abrirAba(item.chave)"
          >
            {{ item.rotulo }}
            <span v-if="item.chave === 'anexos' && anexos.length" class="abas__contador">
              {{ anexos.length }}
            </span>
          </button>
        </nav>

        <label class="abas__seletor">
          <span class="visually-hidden">Seção da ficha</span>
          <select :value="aba" @change="abrirAba($event.target.value)">
            <option v-for="item in ABAS" :key="item.chave" :value="item.chave">
              {{ item.rotulo }}
            </option>
          </select>
        </label>
      </div>

      <div class="conteudo">
        <div class="coluna coluna--principal">
          <!-- RF52b — antes do conteúdo, porque a gravação do acesso é
               condição da exibição, e não consequência dela. -->
          <div v-if="ficha.aviso_outro_prestador" class="aviso-de-acesso">
            <Eye :size="20" :stroke-width="1.75" class="aviso-de-acesso__icone" />
            <p class="aviso-de-acesso__texto">
              Parte deste histórico foi produzida por outro prestador. Sua visualização é
              registrada e fica visível ao tutor.
            </p>
          </div>

          <!-- Aba Resumo ----------------------------------------------------- -->
          <template v-if="aba === 'resumo'">
            <section class="bloco">
              <h2 class="bloco__titulo">Identificação</h2>
              <dl class="dados">
                <div class="dados__item">
                  <dt class="dados__rotulo">Espécie</dt>
                  <dd class="dados__valor">{{ descreverEspecie(animal.especie) }}</dd>
                </div>
                <div class="dados__item">
                  <dt class="dados__rotulo">Sexo</dt>
                  <dd class="dados__valor">{{ animal.sexo === 'femea' ? 'fêmea' : animal.sexo ?? 'não informado' }}</dd>
                </div>
                <div class="dados__item">
                  <dt class="dados__rotulo">Nascimento</dt>
                  <dd class="dados__valor">
                    {{ animal.nascimento_em ? emNumeros(animal.nascimento_em) : 'não informado' }}
                    <!-- RN14 — a natureza da data acompanha a data por toda
                         parte: é o que separa aferição de estimativa. -->
                    <span v-if="animal.nascimento_em && !animal.nascimento_exato" class="dados__nota">
                      estimado pelo tutor
                    </span>
                  </dd>
                </div>
                <div class="dados__item">
                  <dt class="dados__rotulo">Micro-chip</dt>
                  <dd class="dados__valor dados__valor--codigo">
                    {{ animal.microchip ?? 'não informado' }}
                  </dd>
                </div>
                <div class="dados__item">
                  <dt class="dados__rotulo">Código do animal</dt>
                  <dd class="dados__valor dados__valor--codigo">{{ animal.codigo }}</dd>
                </div>
                <div class="dados__item">
                  <dt class="dados__rotulo">Tutor</dt>
                  <dd class="dados__valor">
                    {{ animal.tutor.nome }}
                    <span v-if="animal.tutor.email" class="dados__nota">{{ animal.tutor.email }}</span>
                  </dd>
                </div>
              </dl>
            </section>

            <section class="bloco">
              <div class="bloco__cabecalho">
                <h2 class="bloco__titulo">Situação vacinal</h2>
                <StatusPill
                  v-if="resumo.situacao"
                  :tipo="resumo.situacao.tipo"
                  :texto="resumo.situacao.texto"
                />
                <StatusPill v-else tipo="nao-verificada" texto="sem registros" />
              </div>

              <ul v-if="resumo.proximas_doses.length" class="proximas">
                <li v-for="dose in resumo.proximas_doses" :key="dose.imunobiologico_chave" class="proximas__item">
                  <div class="proximas__texto">
                    <p class="proximas__nome">{{ dose.imunobiologico }} · {{ dose.rotulo }}</p>
                    <p class="proximas__data">{{ porExtenso(dose.prevista_para) }}</p>
                    <!-- P4 — o sistema calcula e sugere; quem decide é o
                         profissional, e para discordar do número ele precisa
                         saber de onde o número veio. -->
                    <p class="proximas__regra">{{ dose.regra_texto }}</p>
                  </div>
                  <StatusPill :tipo="dose.situacao.tipo" :texto="dose.situacao.texto_curto" />
                </li>
              </ul>

              <p v-else class="bloco__vazio">
                Nenhuma dose prevista: não há registro de vacinação com data suficiente para
                calcular o calendário.
              </p>
            </section>

            <section v-if="resumo.ultimo_atendimento" class="bloco">
              <div class="bloco__cabecalho">
                <h2 class="bloco__titulo">Último atendimento</h2>
                <span class="bloco__contagem">
                  {{ resumo.total_de_atendimentos }}
                  {{ resumo.total_de_atendimentos === 1 ? 'registro' : 'registros' }}
                </span>
              </div>

              <p class="atendimento__titulo">
                <RouterLink
                  :to="comContexto(
                    `/clinica/animais/${animal.codigo}/atendimentos/${resumo.ultimo_atendimento.id}`,
                  )"
                >
                  {{ resumo.ultimo_atendimento.titulo }}
                </RouterLink>
                <span class="atendimento__data">{{ emNumeros(resumo.ultimo_atendimento.data) }}</span>
              </p>
              <p class="atendimento__motivo">{{ resumo.ultimo_atendimento.motivo }}</p>
              <ProvenanceChip
                :variante="resumo.ultimo_atendimento.de_outro_prestador ? 'other-provider' : 'professional'"
                :texto="[
                  resumo.ultimo_atendimento.prestador,
                  resumo.ultimo_atendimento.profissional,
                  resumo.ultimo_atendimento.crmv,
                ].filter(Boolean).join(' · ')"
              />
            </section>
          </template>

          <!-- Aba Carteira --------------------------------------------------- -->
          <template v-else-if="aba === 'carteira'">
            <section v-for="grupo in grupos" :key="grupo.imunobiologico.chave" class="bloco">
              <div class="bloco__cabecalho">
                <h2 class="bloco__titulo">{{ grupo.imunobiologico.nome }}</h2>
                <StatusPill :tipo="grupo.situacao.tipo" :texto="grupo.situacao.texto" />
              </div>

              <VaccineRail :estacoes="grupo.estacoes" :nota-regra="notaDaRegra(grupo)" />

              <!-- Cada selo abre o registro da aplicação (V09): é de lá que sai
                   a correção de um lote transcrito errado, e é lá que a versão
                   substituída continua alcançável (RF33b). -->
              <div class="selos">
                <RouterLink
                  v-for="aplicacao in grupo.aplicacoes"
                  :key="aplicacao.id"
                  :id="`aplicacao-${aplicacao.id}`"
                  :to="comContexto(`/clinica/animais/${animal.codigo}/vacinas/${aplicacao.id}`)"
                  class="selos__link"
                  :class="{ 'selo--novo': destacando && aplicacao.id === novoRegistro }"
                >
                  <BatchSeal :aplicacao="aplicacao" />
                </RouterLink>
              </div>
            </section>

            <EmptyState
              v-if="!grupos.length"
              :icone="Syringe"
              titulo="Nenhuma vacinação registrada"
              descricao="Este animal ainda não tem aplicação registrada na plataforma, nem histórico pregresso lançado pelo tutor."
            >
              <RouterLink
                v-if="!inativo"
                :to="comContexto(`/clinica/animais/${animal.codigo}/vacinar`)"
                class="botao botao--primario"
              >
                Registrar vacinação
              </RouterLink>
            </EmptyState>
          </template>

          <!-- Aba Histórico -------------------------------------------------- -->
          <template v-else-if="aba === 'historico'">
            <p v-if="historico.entradas.length" class="contagem" aria-live="polite">
              {{ historico.resumo.total }}
              {{ historico.resumo.total === 1 ? 'registro' : 'registros' }} ·
              {{ historico.resumo.prestadores }}
              {{ historico.resumo.prestadores === 1 ? 'prestador' : 'prestadores' }}
            </p>

            <ol v-if="itensDoHistorico.length" class="linha-do-tempo">
              <!-- O prestador ativo faz o chip do registro alheio ganhar o
                   ícone de olho: quem lê fica sabendo, na própria entrada, que
                   aquela leitura foi registrada (RF52). -->
              <TimelineEntry
                v-for="item in itensDoHistorico"
                :id="ancoraDaEntrada(item.entrada)"
                :key="`${item.entrada.tipo}-${item.entrada.id}`"
                :entrada="item.entrada"
                :destino="item.destino"
                :vinculadas="item.vinculadas"
                :prestador-ativo="prestador"
                :class="{ 'entrada--nova': destacando && item.entrada.id === novoRegistro
                  && item.entrada.registro === 'atendimento' }"
              />
            </ol>

            <EmptyState
              v-else
              :icone="History"
              titulo="Nenhum registro no histórico"
              descricao="Quando houver vacinação ou atendimento registrado, de qualquer prestador autorizado, tudo aparece aqui em ordem cronológica."
            />
          </template>

          <!-- Aba Anexos ----------------------------------------------------- -->
          <template v-else>
            <section
              v-for="grupo in anexosPorAtendimento"
              :key="grupo.atendimento.id"
              class="bloco"
            >
              <div class="bloco__cabecalho">
                <h2 class="bloco__titulo">{{ grupo.atendimento.titulo }}</h2>
                <span class="bloco__contagem">{{ emNumeros(grupo.atendimento.data) }}</span>
              </div>
              <p class="anexos__origem">{{ grupo.atendimento.prestador }}</p>

              <ul class="anexos">
                <li v-for="anexo in grupo.anexos" :key="anexo.id" class="anexos__item">
                  <component
                    :is="anexo.tipo === 'imagem' ? Paperclip : FileText"
                    :size="20"
                    :stroke-width="1.75"
                    class="anexos__icone"
                  />
                  <div class="anexos__texto">
                    <p class="anexos__descricao">{{ anexo.descricao }}</p>
                    <p v-if="anexo.exame_em" class="anexos__data">
                      Exame de {{ emNumeros(anexo.exame_em) }}
                    </p>
                  </div>

                  <!-- RF32c — o arquivo sai pela rota que confere a autorização
                       a cada pedido, nunca por endereço do armazenamento. -->
                  <a
                    v-if="anexo.disponivel"
                    :href="anexo.url"
                    target="_blank"
                    rel="noopener"
                    class="botao botao--secundario botao--sm"
                  >
                    Abrir
                  </a>
                  <span v-else class="anexos__indisponivel">
                    <CircleHelp :size="16" :stroke-width="1.75" />
                    indisponível
                  </span>
                </li>
              </ul>
            </section>

            <EmptyState
              v-if="!anexos.length"
              :icone="Paperclip"
              titulo="Nenhum anexo"
              descricao="Exames e documentos anexados aos atendimentos aparecem aqui, com a consulta que os originou."
            />
          </template>
        </div>

        <!-- Painel de alertas: clínico acima de administrativo, sempre. -->
        <aside v-if="temAlertas" class="coluna coluna--alertas" aria-label="Alertas">
          <template v-if="alertas.clinicos.length">
            <h2 class="rotulo rotulo--clinico">Alertas clínicos</h2>
            <div
              v-for="alerta in alertas.clinicos"
              :key="alerta.chave"
              class="alerta"
              :class="`alerta--${alerta.tom}`"
            >
              <p class="alerta__titulo">
                <component :is="ICONES[alerta.icone]" :size="16" :stroke-width="1.75" />
                {{ alerta.titulo }}
              </p>
              <p class="alerta__texto">{{ alerta.texto }}</p>
              <p v-if="alerta.nota" class="alerta__nota">{{ alerta.nota }}</p>
              <RouterLink
                v-if="alerta.acao && !inativo"
                :to="alerta.acao.destino"
                class="botao botao--primario botao--sm"
              >
                {{ alerta.acao.rotulo }}
              </RouterLink>
            </div>
          </template>

          <template v-if="alertasAdministrativos.length">
            <h2 class="rotulo">Administrativo</h2>
            <div
              v-for="alerta in alertasAdministrativos"
              :key="alerta.chave"
              class="alerta"
              :class="`alerta--${alerta.tom}`"
            >
              <p class="alerta__titulo">
                <component :is="ICONES[alerta.icone]" :size="16" :stroke-width="1.75" />
                {{ alerta.titulo }}
              </p>
              <p class="alerta__texto">{{ alerta.texto }}</p>
              <p v-if="alerta.nota" class="alerta__nota">{{ alerta.nota }}</p>
            </div>
          </template>
        </aside>
      </div>

      <!-- Abaixo de 768 px as ações saem do cabeçalho e viram barra fixa, acima
           da barra de abas da moldura. -->
      <div v-if="!inativo" class="barra-inferior">
        <RouterLink :to="comContexto(`/clinica/animais/${animal.codigo}/vacinar`)" class="botao botao--primario botao--lg">
          <Syringe :size="20" :stroke-width="1.75" />
          Vacinar
        </RouterLink>
        <RouterLink :to="comContexto(`/clinica/animais/${animal.codigo}/atender`)" class="botao botao--secundario botao--lg">
          <Stethoscope :size="20" :stroke-width="1.75" />
          Atender
        </RouterLink>
      </div>
    </div>

    <SolicitarAutorizacaoModal
      :aberto="solicitando"
      :prestador="prestador ?? 'este prestador'"
      :prestador-id="prestadorAtivo"
      :alvo="alvoDaSolicitacao"
      @fechar="solicitando = false"
      @enviada="solicitacao = $event"
    />

    <!-- V12 — registrar óbito (RF22). A ficha recarrega no registro, e não no
         fechamento: quando o modal se despedir, a tarja, o selo e a supressão
         das ações já estarão atrás dele. -->
    <RegistrarObitoModal
      :aberto="registrandoObito"
      :animal="animal"
      :prestador-id="prestadorAtivo"
      @fechar="registrandoObito = false"
      @registrado="carregar"
    />

    <!-- T15 — exportar em PDF verificável (RF46), pela porta clínica. As
         entradas vêm da própria ficha: a contagem do modal não repete a
         leitura, e a rota de T07 nem responderia ao veterinário. -->
    <ExportarDocumentoModal
      v-if="exportarAberto && autorizado"
      :animal="animal"
      :conteudo-inicial="aba === 'carteira' ? 'carteira' : 'historico'"
      contexto="clinica"
      :prestador-id="prestadorAtivo"
      :entradas="historico?.entradas ?? []"
      @fechar="exportarAberto = false"
    />
  </VetShell>
</template>

<style scoped>
.ficha {
  max-width: 1280px;
  margin: 0 auto;
}

/* Como `.painel--estreito`: o estado sem autorização é um cartão só, e a
   coluna inteira (voltar + cartão) se centraliza na área de conteúdo. */
.ficha--estreita {
  max-width: 640px;
}

/* Tarjas ------------------------------------------------------------------- */

.tarja {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  flex-wrap: wrap;
  margin: 0 0 var(--space-3);
  padding: var(--space-2) var(--space-4);
  border-radius: var(--radius-sm);
}

.tarja__icone {
  flex: none;
}

.tarja__texto {
  flex: 1;
  min-width: 240px;
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  color: var(--ink);
}

/* §4.1 — óbito é neutro. Nem vermelho de erro, nem âmbar de atenção. */
.tarja--obito {
  background: var(--surface-sunken);
  border: 1px solid var(--border-strong);
  color: var(--ink-muted);
}

/* O índigo aparece só onde o assunto é quem pode dizer o quê sobre o animal. */
.tarja--preliminar {
  background: var(--consent-wash);
  border: 1px solid var(--consent);
  color: var(--consent);
}

.tarja--autorizacao {
  background: var(--surface-card);
  border: 1px solid var(--status-due);
  color: var(--status-due-text);
}

/* Cabeçalho ---------------------------------------------------------------- */

.cabecalho {
  display: flex;
  align-items: center;
  gap: var(--space-4);
  flex-wrap: wrap;
  min-height: 64px;
  padding: var(--space-3) var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

.cabecalho__retrato {
  flex: none;
  width: 40px;
  height: 40px;
  border-radius: var(--radius-pill);
  object-fit: cover;
}

.cabecalho__retrato--icone {
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--surface-sunken);
  color: var(--ink-muted);
}

.cabecalho__texto {
  flex: 1;
  min-width: 0;
}

/* Gap de 8, e não de 12: nome, código e selo disputam a mesma linha com a barra
   de ações, e a quebra do selo levaria o cabeçalho de 96 px para 122. */
.cabecalho__linha {
  display: flex;
  align-items: baseline;
  gap: var(--space-2);
  flex-wrap: wrap;
}

.cabecalho__nome {
  margin: 0;
  font-family: var(--font-display);
  font-size: 22px;
  line-height: 28px;
  font-weight: 600;
  color: var(--ink);
}

.cabecalho__codigo {
  font-family: var(--font-mono);
  font-size: 12px;
  line-height: 16px;
  font-weight: 500;
  color: var(--ink-muted);
}

/* Entre 768 e 1279 px o cabeçalho enxuga, como o frame de 1024 do desenho: nome
   e ações disputam a linha, e o código monoespaçado a levaria a quebrar,
   passando dos 96 px. Ele não se perde — está na aba Resumo, que é onde se
   confere identificador caractere a caractere. O selo fica: prazo de
   autorização e óbito são estado, e estado não desaparece com a largura. */
@media (min-width: 768px) and (max-width: 1279px) {
  .cabecalho__codigo {
    display: none;
  }
}

.cabecalho__meta {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.cabecalho__acoes {
  display: none;
  align-items: center;
  gap: var(--space-3);
  flex: none;
}

.selo {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 22px;
  padding: 0 10px;
  border-radius: var(--radius-pill);
  font-size: 12px;
  font-weight: 600;
}

.selo--autorizacao {
  background: var(--consent-wash);
  color: var(--consent);
}

.selo--obito {
  background: var(--surface-sunken);
  color: var(--ink-muted);
}

/* Abas --------------------------------------------------------------------- */

.abas {
  margin: var(--space-4) 0 0;
  border-bottom: 1px solid var(--border-hairline);
}

.abas__lista {
  display: none;
  align-items: center;
  gap: var(--space-6);
}

.abas__item {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  padding: var(--space-3) 0;
  background: none;
  border: 0;
  border-bottom: 2px solid transparent;
  font-family: inherit;
  font-size: 14px;
  line-height: 20px;
  font-weight: 600;
  color: var(--ink-muted);
  cursor: pointer;
}

.abas__item:hover {
  color: var(--ink);
}

.abas__item--ativa {
  border-bottom-color: var(--brand);
  color: var(--brand);
}

.abas__contador {
  font-size: 12px;
  font-weight: 400;
  color: var(--ink-faint);
  font-variant-numeric: tabular-nums;
}

.abas__seletor select {
  width: 100%;
  height: 48px;
  padding: 0 var(--space-3);
  margin: 0 0 var(--space-3);
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  font-family: inherit;
  font-size: 16px;
  font-weight: 600;
  color: var(--ink);
}

/* Conteúdo ----------------------------------------------------------------- */

.conteudo {
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

.aviso-de-acesso {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
  background: var(--consent-wash);
  border: 1px solid var(--consent);
  border-radius: var(--radius-sm);
}

.aviso-de-acesso__icone {
  flex: none;
  color: var(--consent);
}

.aviso-de-acesso__texto {
  margin: 0;
  max-width: 75ch;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

.bloco {
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

.bloco__cabecalho {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
  flex-wrap: wrap;
  margin: 0 0 var(--space-3);
}

.bloco__titulo {
  margin: 0;
  font-size: 18px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.bloco__contagem {
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
  font-variant-numeric: tabular-nums;
}

.bloco__vazio {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.rotulo {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

.rotulo--clinico {
  color: var(--status-late);
}

/* Aba Resumo --------------------------------------------------------------- */

.dados {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--space-3);
  margin: 0;
}

.dados__item {
  min-width: 0;
}

.dados__rotulo {
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
}

.dados__valor {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

.dados__valor--codigo {
  font-family: var(--font-mono);
  font-size: 13px;
  line-height: 18px;
  font-weight: 500;
  font-variant-numeric: tabular-nums;
}

.dados__nota {
  display: block;
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-muted);
}

.proximas {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
  margin: 0;
  padding: 0;
  list-style: none;
}

.proximas__item {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--space-3);
  padding: var(--space-3);
  background: var(--surface-sunken);
  border-radius: var(--radius-sm);
}

.proximas__texto {
  min-width: 0;
}

.proximas__nome {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  font-weight: 600;
  color: var(--ink);
}

.proximas__data {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
  font-variant-numeric: tabular-nums;
}

.proximas__regra {
  margin: var(--space-1) 0 0;
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-muted);
}

.atendimento__titulo {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: var(--space-3);
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  font-weight: 600;
  color: var(--ink);
}

.atendimento__data {
  flex: none;
  font-family: var(--font-mono);
  font-size: 13px;
  font-weight: 500;
  color: var(--ink-muted);
  font-variant-numeric: tabular-nums;
}

.atendimento__motivo {
  margin: var(--space-1) 0 var(--space-3);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

/* Aba Carteira ------------------------------------------------------------- */

.selos {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--space-3);
  margin: var(--space-4) 0 0;
}

/* O selo inteiro é o alvo, e não um "ver mais" ao lado dele: a área que
   descreve a aplicação é a que o profissional aponta quando quer abri-la. */
.selos__link {
  display: block;
  border-radius: var(--radius-sm);
  color: inherit;
}

.selos__link:hover :deep(.batch-seal) {
  border-color: var(--border-strong);
}

/* O destaque do registro recém-gravado (V07) é um anel, e não uma cor de
   fundo — a mesma escolha de T05: o selo já usa cor e hachura para dizer a
   procedência (RN24), e tingi-lo apagaria o que ele precisa comunicar. */
.selo--novo,
.entrada--nova {
  border-radius: var(--radius-xs);
  outline: 2px solid var(--brand-bright);
  outline-offset: 3px;
}

/* Aba Histórico ------------------------------------------------------------ */

.contagem {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
  font-variant-numeric: tabular-nums;
}

.linha-do-tempo {
  margin: 0;
  padding: 0;
  list-style: none;
}

/* Aba Anexos --------------------------------------------------------------- */

.anexos__origem {
  margin: -8px 0 var(--space-3);
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
}

.anexos {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  margin: 0;
  padding: 0;
  list-style: none;
}

.anexos__item {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-3);
  background: var(--surface-sunken);
  border-radius: var(--radius-sm);
}

.anexos__icone {
  flex: none;
  color: var(--ink-muted);
}

.anexos__texto {
  flex: 1;
  min-width: 0;
}

.anexos__descricao {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

.anexos__data {
  margin: 0;
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-muted);
}

.anexos__indisponivel {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  flex: none;
  font-size: 12px;
  line-height: 16px;
  color: var(--unverified);
}

/* Alertas ------------------------------------------------------------------ */

.coluna--alertas {
  gap: var(--space-3);
}

.alerta {
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

.alerta--atraso {
  background: var(--status-late-wash);
  border-color: var(--status-late);
}

.alerta--atencao {
  border-color: var(--status-due);
}

.alerta__titulo {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: 0;
  font-size: 12px;
  line-height: 16px;
  font-weight: 600;
  color: var(--ink-muted);
}

.alerta--atraso .alerta__titulo {
  color: var(--status-late);
}

.alerta--atencao .alerta__titulo {
  color: var(--status-due-text);
}

.alerta--consentimento .alerta__titulo {
  color: var(--consent);
}

.alerta__texto {
  margin: var(--space-2) 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

.alerta__nota {
  margin: var(--space-1) 0 0;
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-muted);
}

.alerta .botao {
  margin: var(--space-2) 0 0;
}

/* Estado sem autorização --------------------------------------------------- */

.voltar {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  margin: 0 0 var(--space-4);
  font-size: 14px;
  line-height: 20px;
  font-weight: 600;
  color: var(--brand-bright);
}

.sem-autorizacao {
  background: var(--surface-card);
  border: 1px solid var(--consent);
  border-radius: var(--radius-md);
  overflow: hidden;
}

.sem-autorizacao__topo {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-4) var(--space-6);
  background: var(--consent-wash);
  border-bottom: 1px solid var(--consent);
}

.sem-autorizacao__icone {
  flex: none;
  color: var(--consent);
}

.sem-autorizacao__titulo {
  margin: 0;
  font-family: var(--font-display);
  font-size: 22px;
  line-height: 28px;
  font-weight: 600;
  color: var(--ink);
}

.sem-autorizacao__corpo {
  padding: var(--space-6);
}

.identificacao-minima {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--space-4);
  margin: var(--space-3) 0 0;
  padding: var(--space-4);
  background: var(--surface-sunken);
  border-radius: var(--radius-sm);
}

.identificacao-minima__rotulo {
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
}

.identificacao-minima__valor {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

.identificacao-minima__valor--especie {
  display: flex;
  align-items: center;
  gap: 6px;
  color: var(--ink);
}

.identificacao-minima__valor--codigo {
  font-family: var(--font-mono);
  font-size: 13px;
  line-height: 18px;
  font-weight: 500;
}

.sem-autorizacao__texto {
  margin: var(--space-4) 0 0;
  max-width: 75ch;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

/* A etiqueta de espera (V10): no lugar do botão, o oposto do convite — o
   pedido já foi feito e a vez agora é do tutor. */
.etiqueta-de-espera {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  margin: var(--space-4) 0 0;
  padding: var(--space-2) var(--space-3);
  background: var(--consent-wash);
  border: 1px solid var(--consent);
  border-radius: var(--radius-pill);
  font-size: 13px;
  line-height: 18px;
  font-weight: 600;
  color: var(--consent);
}

.sem-autorizacao__acoes {
  display: flex;
  gap: var(--space-3);
  flex-wrap: wrap;
  margin: var(--space-6) 0 0;
}

.sem-autorizacao__registro {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  padding: var(--space-4) var(--space-6);
  border-top: 1px solid var(--border-hairline);
}

.sem-autorizacao__registro-icone {
  flex: none;
  color: var(--consent);
}

.sem-autorizacao__registro-texto {
  margin: 0;
  max-width: 75ch;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

/* Barra inferior ----------------------------------------------------------- */

/* A moldura já reserva 56 px para a barra de abas do celular; a de ações da
   ficha assenta exatamente sobre ela. */
.barra-inferior {
  position: fixed;
  inset: auto 0 56px;
  z-index: 9;
  display: flex;
  gap: var(--space-2);
  padding: var(--space-3) var(--space-4);
  background: var(--surface-card);
  border-top: 1px solid var(--border-hairline);
}

.barra-inferior .botao {
  flex: 1;
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

.botao--sm {
  height: 32px;
  padding: 0 var(--space-3);
}

.botao--lg {
  height: 48px;
  font-size: 16px;
}

.botao--icone {
  width: 40px;
  padding: 0;
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

.botao__icone-consentimento {
  color: var(--consent);
}

.botao__rotulo-curto {
  display: none;
}

.menu {
  position: relative;
}

.menu__lista {
  position: absolute;
  top: calc(100% + var(--space-2));
  right: 0;
  z-index: 20;
  min-width: 232px;
  padding: var(--space-1);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-sm);
  box-shadow: var(--shadow-overlay);
}

/* O zerado de botão está junto do resto porque o item ora é ligação (retificar
   leva ao histórico), ora é botão (óbito abre modal, V12) — e os dois precisam
   ser indistinguíveis a quem olha o menu. */
.menu__item {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  width: 100%;
  padding: var(--space-2) var(--space-3);
  background: none;
  border: 0;
  border-radius: var(--radius-xs);
  font-family: inherit;
  font-size: 14px;
  line-height: 20px;
  text-align: left;
  color: var(--ink);
  cursor: pointer;
}

.menu__item:hover {
  background: var(--surface-sunken);
  color: var(--ink);
}

/* O item leva a uma escolha, e não à ação: dizê-lo aqui evita que o
   profissional clique esperando um formulário e receba uma lista. */
.menu__nota {
  display: block;
  margin-top: 2px;
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
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

.esqueleto--retrato {
  flex: none;
  width: 40px;
  height: 40px;
  border-radius: var(--radius-pill);
}

.esqueleto--titulo {
  height: 28px;
  width: 40%;
}

.esqueleto--linha {
  height: 20px;
  width: 60%;
  margin: var(--space-2) 0 0;
}

.esqueleto--bloco {
  height: 160px;
  border-radius: var(--radius-md);
}

.esqueleto--cartao {
  height: 96px;
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

/* §4.3 — alvo mínimo de 44 px em qualquer largura abaixo de 1024. A regra vale
   inclusive para os botões pequenos dos alertas: "Registrar agora" é o atalho
   que a Dra. Larissa usa em campo, com o animal no colo. */
@media (max-width: 1023px) {
  .botao--sm {
    min-height: 44px;
  }
}

/* §8.3 — em `base` o cabeçalho cai para 64 px e guarda apenas o que identifica
   o animal: retrato, nome e código. Espécie, idade e tutor continuam a um toque
   de distância, na aba Resumo, e o prazo da autorização só interessa nesta
   largura quando está acabando — e aí ele é tarja, não selo. */
@media (max-width: 767px) {
  .cabecalho {
    min-height: 64px;
    padding: var(--space-2) var(--space-4);
  }

  .cabecalho__meta,
  .selo--autorizacao {
    display: none;
  }

  .cabecalho__nome {
    font-size: 16px;
    line-height: 24px;
  }

  /* Nome e código empilhados, e não lado a lado: em 360 px eles não cabem na
     mesma linha, e o código quebraria o cabeçalho para uma terceira. */
  .cabecalho__linha {
    flex-direction: column;
    align-items: flex-start;
    gap: 0;
  }
}

@media (min-width: 768px) {
  .cabecalho {
    flex-wrap: nowrap;
    min-height: 96px;
    padding: var(--space-4);
  }

  .cabecalho__retrato {
    width: 64px;
    height: 64px;
  }

  .cabecalho__nome {
    font-size: 28px;
    line-height: 34px;
  }

  .cabecalho__codigo {
    font-size: 13px;
    line-height: 18px;
  }

  /* A moldura já não tem barra de abas nesta largura: a de ações encosta no
     rodapé da janela. */
  .barra-inferior {
    bottom: 0;
  }

  .dados,
  .identificacao-minima {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }

  .selos {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (min-width: 1024px) {
  /* §8.3 — de `lg` para cima o cabeçalho comporta as ações, e as abas voltam a
     ser abas. Abaixo daqui elas são seletor e as ações vivem na barra fixa do
     rodapé: em tablet, a Dra. Larissa opera a ficha com uma mão só. */
  .cabecalho__acoes {
    display: flex;
  }

  .botao__rotulo-longo {
    display: none;
  }

  .botao__rotulo-curto {
    display: inline;
  }

  .abas__lista {
    display: flex;
  }

  .abas__seletor {
    display: none;
  }

  .barra-inferior {
    display: none;
  }

  /* §8.3 — em `lg` o painel de alertas passa a faixa acima do conteúdo, e os
     cartões dividem a largura entre si. */
  .conteudo {
    display: flex;
    flex-direction: column-reverse;
  }

  .coluna--alertas {
    flex-direction: row;
    flex-wrap: wrap;
    align-items: stretch;
    gap: var(--space-3);
  }

  .coluna--alertas .rotulo {
    flex-basis: 100%;
  }

  .coluna--alertas .alerta {
    flex: 1;
    min-width: 280px;
  }
}

@media (min-width: 1280px) {
  .conteudo {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: var(--space-6);
  }

  .coluna--alertas {
    flex-direction: column;
    flex-wrap: nowrap;
  }

  .coluna--alertas .alerta {
    min-width: 0;
  }
}

/* §8.3 — o rótulo por extenso só na largura de referência do ambiente do
   veterinário. Abaixo dela as ações roubam do cabeçalho o espaço de que o selo
   de autorização precisa, e o cabeçalho passaria dos 96 px. */
@media (min-width: 1440px) {
  .botao__rotulo-longo {
    display: inline;
  }

  .botao__rotulo-curto {
    display: none;
  }
}
</style>
