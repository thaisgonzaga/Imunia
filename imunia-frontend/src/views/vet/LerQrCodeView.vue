<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  CameraOff,
  ClipboardPlus,
  FileCheck,
  ImageUp,
  Keyboard,
  QrCode,
  ShieldCheck,
  Syringe,
  TriangleAlert,
} from '@lucide/vue'
import jsQR from 'jsqr'
import VetShell from '@/components/vet/VetShell.vue'
import { apiGet } from '@/lib/api.js'
import { ANIMAL, DOCUMENTO, descreverFalhaDaCamera, interpretarQr } from '@/lib/leitorQr.js'
import { useContextoClinicoStore } from '@/stores/contextoClinico.js'

/**
 * V03 pelo celular — ler o QR Code do animal (RF17, RF51).
 *
 * O botão ao lado do campo de busca apontava para cá desde a fatia de V03, e
 * caía em E02. A tela faz uma coisa só: transforma o QR Code do perfil do
 * animal (T04) no código que o campo receberia digitado, e o entrega à tela
 * que pediu a leitura — a busca, ou a escolha do animal de V07a/V08a. Nada é
 * consultado aqui: o âmbito (RN48), a parcimônia do resultado (RN12) e o
 * registro de acesso (RF18b) continuam onde sempre estiveram.
 *
 * A leitura acontece no aparelho. O quadro da câmera vai a um canvas e é
 * decodificado ali — pelo `BarcodeDetector` do navegador quando existe, por
 * jsQR quando não —, e só o código lido segue, no endereço. Sem câmera (acesso
 * negado, aparelho sem ela, endereço sem https), restam a foto do QR Code e o
 * campo de texto de onde se veio.
 */
const route = useRoute()
const router = useRouter()
const contextoClinico = useContextoClinicoStore()

const ACOES = {
  vacinacao: { rotulo: 'Registrar vacinação', icone: Syringe, caminho: '/clinica/registrar/vacinacao' },
  atendimento: { rotulo: 'Registrar atendimento', icone: ClipboardPlus, caminho: '/clinica/registrar/atendimento' },
}

const BUSCA = { rotulo: 'Buscar animal', icone: QrCode, caminho: '/clinica/buscar' }

/** Quem pediu a leitura é quem recebe o código: sem `acao`, a busca. */
const origem = computed(() => ACOES[route.query.acao] ?? BUSCA)

/**
 * A moldura começa com o que a tela anterior sabia e é atualizada pela mesma
 * rota que V03 chama sem termo — a que devolve só o contexto, sem consultar
 * nem registrar nada.
 */
const contexto = ref({ prestador: contextoClinico.prestador, vinculos: contextoClinico.vinculos })

const video = ref(null)

/** `abrindo` → `lendo` → `lido`; ou `indisponivel`, quando a câmera não abre. */
const estado = ref('abrindo')
const falha = ref('')
const lido = ref('')
const decodificandoFoto = ref(false)

/** O que a última leitura disse, quando não foi um animal. */
const aviso = ref(null)

let fluxo = null
let temporizador = null
let detector = null
let encerrado = false
let ultimoConteudo = ''

/** O canvas de trabalho: o quadro é desenhado aqui para ser lido. */
const tela = document.createElement('canvas')

async function carregarContexto(prestador = null) {
  const parametros = new URLSearchParams()
  if (prestador) parametros.set('prestador', prestador)

  try {
    const resposta = await apiGet(`/api/clinica/buscar?${parametros}`)
    contexto.value = { prestador: resposta.prestador, vinculos: resposta.vinculos ?? [] }
  } catch {
    // A moldura fica com o que a tela anterior sabia; a leitura não depende dela.
  }
}

/* Câmera ----------------------------------------------------------------- */

async function abrirCamera() {
  estado.value = 'abrindo'
  falha.value = ''

  if (!navigator.mediaDevices?.getUserMedia) {
    indisponivel(null, { suportada: false, seguro: window.isSecureContext })

    return
  }

  try {
    fluxo = await navigator.mediaDevices.getUserMedia({
      audio: false,
      video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
    })
  } catch (erro) {
    indisponivel(erro)

    return
  }

  // A pessoa saiu da tela enquanto o navegador pedia permissão: a câmera que
  // acabou de abrir fecha sem ter mostrado um quadro.
  if (encerrado || !video.value) {
    pararCamera()

    return
  }

  video.value.srcObject = fluxo

  try {
    await video.value.play()
  } catch {
    // Reprodução automática recusada: o elemento tem `autoplay` e `muted`, e o
    // quadro chega assim mesmo — a leitura só precisa dele.
  }

  await prepararDetector()

  estado.value = 'lendo'
  agendarLeitura(0)
}

function indisponivel(erro, opcoes) {
  estado.value = 'indisponivel'
  falha.value = descreverFalhaDaCamera(erro, opcoes)
}

function pararCamera() {
  clearTimeout(temporizador)
  fluxo?.getTracks().forEach((faixa) => faixa.stop())
  fluxo = null
  if (video.value) video.value.srcObject = null
}

/**
 * O `BarcodeDetector` é o leitor do próprio navegador: mais rápido e mais
 * tolerante que o em JavaScript. Onde não existe, ou não lê QR Code, jsQR
 * responde por tudo.
 */
async function prepararDetector() {
  if (!('BarcodeDetector' in window)) return

  try {
    const formatos = await window.BarcodeDetector.getSupportedFormats()
    if (formatos.includes('qr_code')) detector = new window.BarcodeDetector({ formats: ['qr_code'] })
  } catch {
    detector = null
  }
}

/* Leitura ---------------------------------------------------------------- */

function agendarLeitura(atraso = 150) {
  clearTimeout(temporizador)
  temporizador = setTimeout(lerQuadro, atraso)
}

async function lerQuadro() {
  if (encerrado || estado.value !== 'lendo') return

  const quadro = video.value

  // HAVE_CURRENT_DATA: há um quadro para desenhar. Antes disso, `drawImage`
  // pinta preto, e o decodificador gastaria tempo lendo nada.
  if (quadro && quadro.readyState >= 2) {
    let conteudo = null

    try {
      conteudo = await decodificar(quadro, quadro.videoWidth, quadro.videoHeight)
    } catch {
      // Um quadro que não se deixou ler não é falha: o próximo vem em seguida.
    }

    if (conteudo && tratar(conteudo)) return
  }

  agendarLeitura()
}

/**
 * Desenha a fonte no canvas de trabalho, reduzida, e tenta ler o QR Code nela.
 * A redução é o que mantém a leitura em JavaScript a tempo de vídeo num
 * celular modesto; um QR Code que preencha um quarto do quadro ainda tem, a
 * 640 px, folga de sobra.
 */
async function decodificar(fonte, largura, altura, { ladoMaximo = 640, inversao = 'dontInvert' } = {}) {
  if (!largura || !altura) return null

  const escala = Math.min(1, ladoMaximo / Math.max(largura, altura))
  tela.width = Math.round(largura * escala)
  tela.height = Math.round(altura * escala)

  const contexto2d = tela.getContext('2d', { willReadFrequently: true })
  contexto2d.drawImage(fonte, 0, 0, tela.width, tela.height)

  if (detector) {
    try {
      const [codigo] = await detector.detect(tela)
      if (codigo?.rawValue) return codigo.rawValue
    } catch {
      // Cai no decodificador em JavaScript.
    }
  }

  const imagem = contexto2d.getImageData(0, 0, tela.width, tela.height)
  const resultado = jsQR(imagem.data, imagem.width, imagem.height, { inversionAttempts: inversao })

  return resultado?.data ?? null
}

/**
 * O que fazer com o que foi lido. Devolve `true` quando a leitura terminou —
 * o código do animal foi encontrado e a tela está indo embora. Nos outros
 * casos a câmera continua aberta: a pessoa vai apontá-la para o QR Code certo,
 * e o aviso fica até que outra coisa seja lida.
 */
function tratar(conteudo) {
  const leitura = interpretarQr(conteudo)

  if (leitura.tipo === ANIMAL) {
    concluir(leitura.codigo)

    return true
  }

  if (conteudo !== ultimoConteudo) {
    ultimoConteudo = conteudo
    aviso.value = leitura
  }

  return false
}

function concluir(codigo) {
  estado.value = 'lido'
  lido.value = codigo
  aviso.value = null
  pararCamera()

  router.push({ path: origem.value.caminho, query: { termo: codigo } })
}

/**
 * A foto no lugar da câmera: a do sistema, quando o navegador não teve acesso
 * à câmera, ou a que o tutor mandou por mensagem. Numa foto o QR Code pode
 * ocupar um canto e estar invertido, por isso a leitura vai mais larga e tenta
 * as duas polaridades.
 */
async function lerFoto(evento) {
  const arquivo = evento.target.files?.[0]
  // O mesmo arquivo escolhido duas vezes ainda é uma escolha: sem isto, o
  // `change` não dispararia de novo.
  evento.target.value = ''
  if (!arquivo) return

  decodificandoFoto.value = true
  aviso.value = null

  try {
    const imagem = await createImageBitmap(arquivo)
    const conteudo = await decodificar(imagem, imagem.width, imagem.height, {
      ladoMaximo: 1024,
      inversao: 'attemptBoth',
    })
    imagem.close?.()

    if (!conteudo) aviso.value = { tipo: 'nenhum' }
    else if (!tratar(conteudo)) ultimoConteudo = ''
  } catch {
    aviso.value = { tipo: 'nenhum' }
  } finally {
    decodificandoFoto.value = false
  }
}

/** Com a aba escondida não chega quadro novo; ler o mesmo de novo é só gasto. */
function aoMudarVisibilidade() {
  if (document.hidden) clearTimeout(temporizador)
  else if (estado.value === 'lendo') agendarLeitura(0)
}

onMounted(() => {
  document.addEventListener('visibilitychange', aoMudarVisibilidade)
  carregarContexto()
  abrirCamera()
})

onBeforeUnmount(() => {
  encerrado = true
  document.removeEventListener('visibilitychange', aoMudarVisibilidade)
  pararCamera()
})
</script>

<template>
  <VetShell
    titulo="Ler QR Code"
    :prestador="contexto.prestador"
    :vinculos="contexto.vinculos"
    @trocar-prestador="carregarContexto"
  >
    <div class="leitor">
      <p class="leitor__acao">
        <component :is="origem.icone" :size="14" :stroke-width="1.75" />
        {{ origem.rotulo }}
      </p>
      <h1 class="leitor__titulo">Aponte a câmera para o QR Code do animal</h1>
      <p class="leitor__explicacao">
        O tutor encontra o QR Code no perfil do animal, ao lado do código. Lido, o código
        segue para a busca como se tivesse sido digitado — com o mesmo âmbito e o mesmo
        registro.
      </p>

      <div
        v-if="estado !== 'indisponivel'"
        class="visor"
        :class="{ 'visor--abrindo': estado === 'abrindo', 'visor--lido': estado === 'lido' }"
      >
        <video ref="video" class="visor__video" autoplay muted playsinline aria-label="Imagem da câmera" />
        <div class="visor__moldura" aria-hidden="true">
          <span class="visor__canto visor__canto--no" />
          <span class="visor__canto visor__canto--ne" />
          <span class="visor__canto visor__canto--so" />
          <span class="visor__canto visor__canto--se" />
        </div>
        <p v-if="estado === 'abrindo'" class="visor__estado">Abrindo a câmera…</p>
      </div>

      <div v-else class="nota" role="alert">
        <CameraOff :size="20" :stroke-width="1.75" class="nota__icone" />
        <div>
          <p class="nota__titulo">A câmera não está disponível.</p>
          <p class="nota__texto">{{ falha }}</p>
        </div>
      </div>

      <p class="leitor__situacao" aria-live="polite">
        <template v-if="estado === 'lendo'">Procurando um QR Code…</template>
        <template v-else-if="estado === 'lido'">
          Código <span class="leitor__codigo">{{ lido }}</span> lido. Abrindo a busca…
        </template>
      </p>

      <div v-if="aviso" class="nota" role="status">
        <component
          :is="aviso.tipo === DOCUMENTO ? FileCheck : TriangleAlert"
          :size="20"
          :stroke-width="1.75"
          class="nota__icone"
        />
        <div>
          <template v-if="aviso.tipo === DOCUMENTO">
            <p class="nota__titulo">Este QR Code é de um documento exportado, não do animal.</p>
            <p class="nota__texto">
              Ele leva à verificação pública de autenticidade do PDF. O QR Code do animal
              está no perfil dele, ao lado do código.
            </p>
            <div class="nota__acao">
              <RouterLink :to="`/verificar/${aviso.codigo}`" class="botao botao--secundario">
                Verificar o documento
              </RouterLink>
            </div>
          </template>
          <template v-else-if="aviso.tipo === 'nenhum'">
            <p class="nota__titulo">Não encontramos um QR Code nesta foto.</p>
            <p class="nota__texto">
              Tente uma foto mais de perto, com o código inteiro no quadro e sem reflexo.
            </p>
          </template>
          <template v-else>
            <p class="nota__titulo">Este QR Code não é de um animal do Imunia.</p>
            <p class="nota__texto">
              O código do animal tem o formato IM-0000-0000. Aponte para o QR Code do perfil
              dele.
            </p>
          </template>
        </div>
      </div>

      <div class="leitor__acoes">
        <label class="botao botao--secundario" :class="{ 'botao--aguardando': decodificandoFoto }">
          <ImageUp :size="16" :stroke-width="1.75" />
          {{ decodificandoFoto ? 'Lendo a foto…' : 'Escolher uma foto do QR Code' }}
          <input
            type="file"
            accept="image/*"
            class="visually-hidden"
            :disabled="decodificandoFoto"
            @change="lerFoto"
          >
        </label>
        <RouterLink :to="origem.caminho" class="botao botao--secundario">
          <Keyboard :size="16" :stroke-width="1.75" />
          Digitar o código
        </RouterLink>
      </div>

      <!-- §6.3 — o sistema diz o que faz com o que vê. -->
      <p class="leitor__privacidade">
        <ShieldCheck :size="16" :stroke-width="1.75" class="leitor__privacidade-icone" />
        A imagem da câmera não sai do seu aparelho: o QR Code é lido aqui mesmo, e só o
        código segue para a busca.
      </p>
    </div>
  </VetShell>
</template>

<style scoped>
.leitor {
  max-width: 880px;
  margin: 0 auto;
}

.leitor__acao {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 22px;
  margin: 0;
  padding: 0 10px;
  border-radius: var(--radius-pill);
  background: var(--brand-wash);
  font-size: 12px;
  line-height: 16px;
  font-weight: 600;
  color: var(--brand);
}

.leitor__titulo {
  margin: var(--space-3) 0 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  letter-spacing: -.02em;
  color: var(--ink);
}

.leitor__explicacao {
  margin: var(--space-3) 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

/* Visor -------------------------------------------------------------------- */

.visor {
  position: relative;
  width: 100%;
  max-width: 420px;
  aspect-ratio: 1;
  margin: var(--space-6) 0 0;
  overflow: hidden;
  border-radius: var(--radius-md);
  background: var(--ink);
}

.visor__video {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.visor--abrindo .visor__video {
  opacity: 0;
}

.visor--lido .visor__video {
  opacity: .5;
}

.visor__moldura {
  position: absolute;
  inset: 14%;
  pointer-events: none;
}

.visor__canto {
  position: absolute;
  width: 28px;
  height: 28px;
  border: 3px solid var(--surface-card);
  border-radius: 2px;
}

.visor__canto--no { top: 0; left: 0; border-right: 0; border-bottom: 0; }
.visor__canto--ne { top: 0; right: 0; border-left: 0; border-bottom: 0; }
.visor__canto--so { bottom: 0; left: 0; border-right: 0; border-top: 0; }
.visor__canto--se { bottom: 0; right: 0; border-left: 0; border-top: 0; }

.visor__estado {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0;
  font-size: 14px;
  color: var(--surface-card);
}

.leitor__situacao {
  min-height: 20px;
  margin: var(--space-3) 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.leitor__codigo {
  font-family: var(--font-mono);
  color: var(--ink);
}

/* Notas -------------------------------------------------------------------- */

.nota {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  margin: var(--space-4) 0 0;
  padding: var(--space-4);
  background: var(--surface-sunken);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

.nota__icone {
  flex: none;
  margin-top: 2px;
  color: var(--ink-muted);
}

.nota__titulo {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.nota__texto {
  margin: var(--space-1) 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.nota__acao {
  margin-top: var(--space-3);
}

/* Ações -------------------------------------------------------------------- */

.leitor__acoes {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  margin: var(--space-6) 0 0;
}

.leitor__acoes .botao {
  flex: 1 1 100%;
}

.botao {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-2);
  height: 44px;
  padding: 0 var(--space-4);
  border-radius: var(--radius-sm);
  font-family: inherit;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
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

/* O rótulo é o botão, e o campo de arquivo mora escondido dentro dele: o anel
   de foco precisa aparecer no rótulo quando o teclado chega ao campo. */
.botao:focus-within {
  outline: 2px solid var(--brand-bright);
  outline-offset: 2px;
}

.botao--aguardando {
  opacity: .6;
  pointer-events: none;
}

.leitor__privacidade {
  display: flex;
  align-items: flex-start;
  gap: var(--space-2);
  margin: var(--space-6) 0 0;
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-muted);
}

.leitor__privacidade-icone {
  flex: none;
  color: var(--consent);
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
  .leitor__titulo {
    font-size: 36px;
    line-height: 40px;
  }

  .leitor__acoes .botao {
    flex: 0 1 auto;
  }
}

/* A densidade do desenho de 1440 px começa aqui, e não em 768: entre uma
   largura e outra a tela ainda é tocada com o dedo, e §4.3 pede 44 px de alvo. */
@media (min-width: 1024px) {
  .botao {
    height: 40px;
  }
}
</style>
