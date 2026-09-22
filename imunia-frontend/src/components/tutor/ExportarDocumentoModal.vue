<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import QRCode from 'qrcode'
import { Check, Copy, Download, FileCheck, TriangleAlert, X } from '@lucide/vue'
import AppButton from '@/components/base/AppButton.vue'
import ConsentNotice from '@/components/base/ConsentNotice.vue'
import { apiGet, apiPost } from '@/lib/api.js'

/**
 * T15 — exportar histórico (RF46). Modal sobre T04, T05 ou T07: escolhe-se o
 * recorte, lê-se a advertência de RN46 **antes** do botão, e o resultado toma o
 * lugar da configuração — identificador da emissão, QR Code e as duas saídas
 * (baixar o arquivo, copiar o link de verificação, que aponta para P09).
 *
 * O modal conta os registros por conta própria (uma leitura de T07) para
 * desabilitar a geração quando o recorte escolhido está vazio — o servidor
 * recusaria com a mesma frase, mas botão que leva à recusa é convite a ela.
 *
 * RF46 nomeia dois atores, e o modal serve aos dois: sobre V06 ele emite pela
 * porta clínica (`contexto="clinica"`), cujo âmbito é a autorização vigente do
 * prestador ativo — o contexto viaja em `prestadorId`, porque a emissão e o
 * download recaem no primeiro vínculo do profissional sem ele. A ficha já traz
 * o histórico inteiro, e `entradas` poupa a segunda leitura do mesmo dado —
 * que aqui não seria só desperdício: a rota de contagem de T07 é do tutor, e
 * responderia 403 ao veterinário.
 */
const props = defineProps({
  animal: { type: Object, required: true },
  conteudoInicial: {
    type: String,
    default: 'historico',
    validator: (v) => ['carteira', 'historico'].includes(v),
  },
  contexto: {
    type: String,
    default: 'tutor',
    validator: (v) => ['tutor', 'clinica'].includes(v),
  },
  prestadorId: { type: [Number, String], default: null },
  entradas: { type: Array, default: null },
})

const emit = defineEmits(['fechar'])

// Configuração ------------------------------------------------------------

const conteudo = ref(props.conteudoInicial)
const meses = ref(null)

const PERIODOS = [
  { valor: null, rotulo: 'Todo o período' },
  { valor: 12, rotulo: 'Últimos 12 meses' },
  { valor: 6, rotulo: 'Últimos 6 meses' },
]

// Contagem de registros — a leitura de T07, feita uma vez na abertura, ou as
// entradas que a tela de origem já tinha (V06 responde a ficha inteira).
const registros = ref(props.entradas)
const carregando = ref(props.entradas === null)
const erroDeCarga = ref('')

async function carregar() {
  if (registros.value !== null) return

  carregando.value = true
  erroDeCarga.value = ''

  try {
    const resposta = await apiGet(`/api/animais/${props.animal.codigo}/historico`)
    registros.value = resposta.entradas
  } catch (excecao) {
    erroDeCarga.value = excecao.message
  } finally {
    carregando.value = false
  }
}

const totalNoRecorte = computed(() => {
  if (registros.value === null) return 0

  if (conteudo.value === 'carteira') {
    return registros.value.filter((entrada) => entrada.registro === 'vacinacao').length
  }

  if (meses.value === null) return registros.value.length

  const corte = new Date()
  corte.setMonth(corte.getMonth() - meses.value)
  const limite = corte.toISOString().slice(0, 10)

  // Entrada sem data fica fora do recorte: não dá para afirmar que pertence
  // aos últimos N meses — mesma régua do servidor.
  return registros.value.filter((entrada) => entrada.data !== null && entrada.data >= limite).length
})

// Geração -----------------------------------------------------------------

const gerando = ref(false)
const erroDeGeracao = ref('')
const emissao = ref(null)
const qrDataUrl = ref('')

async function gerar() {
  gerando.value = true
  erroDeGeracao.value = ''

  try {
    // A porta clínica reverifica o âmbito (autorização vigente, não
    // titularidade) e presta as contas de RN49; o corpo do pedido é o mesmo.
    const destino = props.contexto === 'clinica'
      ? `/api/clinica/animais/${props.animal.codigo}/exportacoes`
        + (props.prestadorId ? `?prestador=${props.prestadorId}` : '')
      : `/api/animais/${props.animal.codigo}/exportacoes`

    const resposta = await apiPost(destino, {
      conteudo: conteudo.value,
      meses: conteudo.value === 'historico' ? meses.value : null,
    })

    emissao.value = resposta.exportacao
    // RF46 — o QR do resultado é o mesmo impresso no documento: o link da
    // verificação pública, com o resumo que permite acusar divergência.
    qrDataUrl.value = await QRCode.toDataURL(resposta.exportacao.link_verificacao, {
      width: 176,
      margin: 1,
    })
  } catch (excecao) {
    erroDeGeracao.value = excecao.message
  } finally {
    gerando.value = false
  }
}

const emitidoEm = computed(() => {
  if (!emissao.value) return ''

  return new Date(emissao.value.emitido_em).toLocaleString('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
})

// Cópia do link de verificação -------------------------------------------

const linkCopiado = ref(false)
let timeoutCopia = null

async function copiarLink() {
  if (!emissao.value) return

  try {
    await navigator.clipboard.writeText(emissao.value.link_verificacao)
  } catch {
    return
  }

  linkCopiado.value = true
  clearTimeout(timeoutCopia)
  timeoutCopia = setTimeout(() => { linkCopiado.value = false }, 2000)
}

// Moldura -----------------------------------------------------------------

function fechar() {
  emit('fechar')
}

function aoTeclar(evento) {
  if (evento.key === 'Escape') fechar()
}

onMounted(() => {
  window.addEventListener('keydown', aoTeclar)
  carregar()
})

onUnmounted(() => {
  window.removeEventListener('keydown', aoTeclar)
  clearTimeout(timeoutCopia)
})
</script>

<template>
  <div class="sobreposicao" @click.self="fechar">
    <div class="exportar" role="dialog" aria-modal="true" aria-label="Exportar documento em PDF">
      <button type="button" class="exportar__fechar" aria-label="Fechar" @click="fechar">
        <X :size="20" :stroke-width="1.75" />
      </button>

      <h2 class="exportar__titulo">Exportar documento</h2>

      <!-- Pronto: o resultado toma o lugar da configuração. -->
      <div v-if="emissao" class="exportar__resultado">
        <p class="exportar__confirmacao" role="status">
          <Check :size="20" :stroke-width="1.75" />
          Documento de {{ animal.nome }} emitido em {{ emitidoEm }}.
        </p>

        <img
          v-if="qrDataUrl"
          :src="qrDataUrl"
          alt="QR Code do link de verificação"
          class="exportar__qr"
          width="176"
          height="176"
        />

        <p class="exportar__rotulo-codigo">Identificador da emissão</p>
        <p class="exportar__codigo">{{ emissao.codigo_formatado }}</p>
        <p class="exportar__nota">
          Quem receber o arquivo confere a autenticidade pelo QR Code impresso
          no rodapé ou pelo link de verificação — sem precisar de conta.
        </p>

        <div class="exportar__acoes">
          <a
            :href="emissao.url_documento"
            download
            class="botao botao--primario"
          >
            <Download :size="18" :stroke-width="1.75" />
            Baixar PDF
          </a>
          <button type="button" class="botao botao--secundario" @click="copiarLink">
            <Check v-if="linkCopiado" :size="18" :stroke-width="1.75" />
            <Copy v-else :size="18" :stroke-width="1.75" />
            {{ linkCopiado ? 'Link copiado' : 'Copiar link de verificação' }}
          </button>
        </div>
      </div>

      <!-- Carregando a contagem de registros. -->
      <div v-else-if="carregando" class="exportar__carregando" aria-busy="true" aria-live="polite">
        <span class="visually-hidden">Carregando os registros do animal.</span>
        <div class="esqueleto" />
        <div class="esqueleto esqueleto--curto" />
      </div>

      <div v-else-if="erroDeCarga" class="exportar__erro" role="alert">
        <TriangleAlert :size="20" :stroke-width="1.75" class="exportar__erro-icone" />
        <div>
          <p class="exportar__erro-titulo">Não conseguimos preparar a exportação.</p>
          <p class="exportar__erro-texto">{{ erroDeCarga }}</p>
          <button type="button" class="botao botao--secundario" @click="carregar">
            Tentar novamente
          </button>
        </div>
      </div>

      <!-- Configuração. -->
      <form v-else class="exportar__configuracao" @submit.prevent="gerar">
        <fieldset class="exportar__campo">
          <legend class="exportar__legenda">O que o documento contém</legend>
          <label class="exportar__opcao" :class="{ 'exportar__opcao--ativa': conteudo === 'carteira' }">
            <input v-model="conteudo" type="radio" value="carteira" name="conteudo" />
            <span>
              <span class="exportar__opcao-titulo">Carteira de vacinação</span>
              <span class="exportar__opcao-nota">Doses aplicadas e próximas previstas</span>
            </span>
          </label>
          <label class="exportar__opcao" :class="{ 'exportar__opcao--ativa': conteudo === 'historico' }">
            <input v-model="conteudo" type="radio" value="historico" name="conteudo" />
            <span>
              <span class="exportar__opcao-titulo">Histórico completo</span>
              <span class="exportar__opcao-nota">Vacinações, atendimentos e a origem de cada registro</span>
            </span>
          </label>
        </fieldset>

        <div v-if="conteudo === 'historico'" class="exportar__campo">
          <label class="exportar__legenda" for="exportar-periodo">Período</label>
          <select id="exportar-periodo" v-model="meses" class="exportar__select">
            <option v-for="periodo in PERIODOS" :key="periodo.rotulo" :value="periodo.valor">
              {{ periodo.rotulo }}
            </option>
          </select>
        </div>

        <!-- Pré-visualização em miniatura: a anatomia da primeira página, não o
             conteúdo — o documento de verdade só existe depois da emissão. -->
        <div class="miniatura" aria-hidden="true">
          <div class="miniatura__pagina">
            <div class="miniatura__cabecalho">
              <span class="miniatura__marca">Imunia</span>
              <span class="miniatura__tipo">
                {{ conteudo === 'carteira' ? 'Carteira de vacinação' : 'Histórico do animal' }}
              </span>
            </div>
            <div class="miniatura__nome">{{ animal.nome }}</div>
            <div class="miniatura__linha" />
            <div class="miniatura__linha miniatura__linha--media" />
            <div class="miniatura__linha" />
            <div class="miniatura__linha miniatura__linha--curta" />
            <div class="miniatura__rodape">
              <span class="miniatura__qr" />
              <span class="miniatura__linha miniatura__linha--rodape" />
            </div>
          </div>
        </div>

        <!-- RN46 — a advertência vem antes da ação, nunca como nota de rodapé. -->
        <ConsentNotice :icone="FileCheck">
          <p>
            Ao compartilhar este arquivo, os dados saem do controle da plataforma
            e <strong>a responsabilidade pela difusão passa a ser sua</strong>.
          </p>
        </ConsentNotice>

        <p v-if="erroDeGeracao" class="exportar__erro-texto" role="alert">{{ erroDeGeracao }}</p>

        <!-- Sem registro no recorte, o botão explica em vez de recusar. -->
        <p v-if="totalNoRecorte === 0" class="exportar__vazio">
          {{ conteudo === 'carteira'
            ? `Ainda não há vacinas registradas para ${animal.nome}.`
            : 'Não há registros no período escolhido. Ajuste a seleção acima.' }}
        </p>

        <AppButton type="submit" :loading="gerando" :disabled="totalNoRecorte === 0">
          Gerar documento
        </AppButton>
      </form>
    </div>
  </div>
</template>

<style scoped>
.sobreposicao {
  position: fixed;
  inset: 0;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  background: rgba(20, 35, 31, .5);
  z-index: 100;
}

/* Folha inferior no celular (90% da altura), modal de 560 px do `md` para
   cima — o desenho responsivo que o briefing fixa para T15. */
.exportar {
  position: relative;
  display: flex;
  flex-direction: column;
  width: 100%;
  max-height: 90dvh;
  padding: var(--space-6);
  overflow-y: auto;
  background: var(--surface-card);
  border-radius: var(--radius-md) var(--radius-md) 0 0;
}

.exportar__fechar {
  position: absolute;
  top: var(--space-2);
  right: var(--space-2);
  display: flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  background: none;
  border: 0;
  border-radius: var(--radius-sm);
  color: var(--ink-muted);
  cursor: pointer;
}

.exportar__fechar:hover {
  background: var(--surface-sunken);
  color: var(--ink);
}

.exportar__titulo {
  margin: 0 0 var(--space-4);
  font-family: var(--font-display);
  font-size: 22px;
  line-height: 28px;
  font-weight: 600;
  color: var(--ink);
}

/* Configuração --------------------------------------------------------------- */

.exportar__configuracao {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.exportar__campo {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  border: 0;
  padding: 0;
  margin: 0;
}

.exportar__legenda {
  padding: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

.exportar__opcao {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  padding: var(--space-3);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  cursor: pointer;
}

.exportar__opcao--ativa {
  border-color: var(--brand);
  background: var(--brand-wash);
}

.exportar__opcao input {
  margin-top: 4px;
  accent-color: var(--brand);
}

.exportar__opcao-titulo {
  display: block;
  font-size: 16px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.exportar__opcao-nota {
  display: block;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.exportar__select {
  height: 48px;
  padding: 0 var(--space-3);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  background: var(--surface-card);
  font-family: var(--font-body);
  font-size: 16px;
  color: var(--ink);
}

.exportar__vazio {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

/* Miniatura da primeira página ---------------------------------------------- */

.miniatura {
  display: flex;
  justify-content: center;
  padding: var(--space-3);
  background: var(--surface-sunken);
  border-radius: var(--radius-sm);
}

.miniatura__pagina {
  display: flex;
  flex-direction: column;
  gap: 6px;
  width: 120px;
  padding: 10px;
  background: #FFFFFF;
  border: 1px solid var(--border-hairline);
  border-radius: 2px;
  box-shadow: 0 1px 3px rgba(20, 35, 31, .12);
}

.miniatura__cabecalho {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  padding-bottom: 4px;
  border-bottom: 1.5px solid var(--brand);
}

.miniatura__marca {
  font-size: 7px;
  font-weight: 700;
  color: var(--brand);
}

.miniatura__tipo {
  font-size: 4.5px;
  color: var(--ink-muted);
}

.miniatura__nome {
  font-size: 8px;
  font-weight: 600;
  color: var(--ink);
}

.miniatura__linha {
  height: 3px;
  border-radius: 1px;
  background: var(--surface-sunken);
}

.miniatura__linha--media { width: 75%; }
.miniatura__linha--curta { width: 55%; }

.miniatura__rodape {
  display: flex;
  align-items: center;
  gap: 4px;
  margin-top: 6px;
  padding-top: 4px;
  border-top: 1px solid var(--border-hairline);
}

.miniatura__qr {
  flex: none;
  width: 10px;
  height: 10px;
  background:
    conic-gradient(var(--ink-muted) 90deg, transparent 90deg 180deg, var(--ink-muted) 180deg 270deg, transparent 270deg)
    0 0 / 5px 5px;
  opacity: .7;
}

.miniatura__linha--rodape {
  flex: 1;
  height: 2px;
}

/* Resultado ------------------------------------------------------------------ */

.exportar__resultado {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
}

.exportar__confirmacao {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: 0 0 var(--space-4);
  font-size: 16px;
  line-height: 24px;
  color: var(--brand);
  font-weight: 600;
}

.exportar__qr {
  width: 176px;
  height: 176px;
}

.exportar__rotulo-codigo {
  margin: var(--space-4) 0 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

.exportar__codigo {
  margin: var(--space-1) 0 0;
  font-family: var(--font-mono);
  font-size: 18px;
  line-height: 24px;
  font-weight: 500;
  color: var(--ink);
  font-variant-numeric: tabular-nums;
}

.exportar__nota {
  margin: var(--space-2) 0 0;
  max-width: 42ch;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.exportar__acoes {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  width: 100%;
  margin: var(--space-5, 20px) 0 0;
}

/* Carregando e erro ----------------------------------------------------------- */

.exportar__carregando {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}

.esqueleto {
  height: 64px;
  border-radius: var(--radius-xs);
  background: var(--surface-sunken);
  animation: pulsar 1.2s ease-in-out infinite;
}

.esqueleto--curto {
  height: 48px;
  width: 60%;
}

@keyframes pulsar {
  0% { opacity: .55; }
  50% { opacity: 1; }
  100% { opacity: .55; }
}

.exportar__erro {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
}

.exportar__erro-icone {
  flex: none;
  margin-top: 2px;
  color: var(--status-late);
}

.exportar__erro-titulo {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.exportar__erro-texto {
  margin: var(--space-1) 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--status-late);
}

.exportar__erro .botao {
  margin-top: var(--space-3);
}

/* Botões — o mesmo vocabulário das telas do tutor. --------------------------- */

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

.visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip-path: inset(50%);
  white-space: nowrap;
}

@media (min-width: 768px) {
  .sobreposicao {
    align-items: center;
    padding: var(--space-4);
  }

  .exportar {
    max-width: 560px;
    max-height: calc(100dvh - var(--space-8));
    border-radius: var(--radius-md);
  }

  .exportar__acoes {
    flex-direction: row;
  }

  .exportar__acoes .botao {
    flex: 1;
  }
}
</style>
