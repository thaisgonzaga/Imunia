<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import QRCode from 'qrcode'
import {
  Cat,
  Check,
  ChevronLeft,
  CircleHelp,
  CirclePlus,
  Copy,
  Dog,
  FileCheck,
  Pencil,
  QrCode,
  Stethoscope,
  TriangleAlert,
  UserRound,
  X,
} from '@lucide/vue'
import TutorShell from '@/components/tutor/TutorShell.vue'
import StatusPill from '@/components/base/StatusPill.vue'
import EmptyState from '@/components/base/EmptyState.vue'
import ExportarDocumentoModal from '@/components/tutor/ExportarDocumentoModal.vue'
import { apiGet } from '@/lib/api.js'
import { descreverEspecie, descreverIdade } from '@/lib/animais.js'

/**
 * T04 — perfil do animal (RF16, RF17, RF19). Página-âncora do animal:
 * identidade, código único, situação vacinal resumida e portas para carteira,
 * histórico e exportação. A caracterização (RF19) e a vacinação (RF25, RF26)
 * ainda não têm fatia própria — os blocos já existem aqui no formato final,
 * desenhando o vazio correto, e ganham conteúdo quando aquelas fatias forem
 * construídas, sem precisar tocar nesta tela.
 */
const route = useRoute()
const router = useRouter()

const animal = ref(null)
const carregando = ref(true)
const erro = ref('')

async function carregar() {
  carregando.value = true
  erro.value = ''

  try {
    animal.value = await apiGet(`/api/animais/${route.params.codigo}`)

    // T15 — quem chegou pelo endereço antigo `/animais/:codigo/exportar`
    // (redirecionado com `?exportar=1`) veio exportar: o modal abre sozinho.
    if (route.query.exportar === '1') exportarAberto.value = true
  } catch (excecao) {
    erro.value = excecao.message
  } finally {
    carregando.value = false
  }
}

onMounted(carregar)
watch(() => route.params.codigo, carregar)

const icone = computed(() => (animal.value?.especie === 'gato' ? Cat : Dog))

/**
 * Chegada vinda de T03, logo depois do cadastro. O que muda é o destaque do
 * código único: é a única informação da tela que o tutor ainda não viu, e é a
 * que ele vai precisar ditar no balcão da clínica (RF17).
 */
const recemCadastrado = computed(() => route.query.novo === '1')

// Volta de T04a. A confirmação é a mesma de sempre — discreta e sem ação —,
// mas o que mudou já está na tela: quem salvou vê o cadastro novo, não um
// aviso dizendo que ele mudou.
const recemEditado = computed(() => route.query.salvo === '1')

const linhaDeIdade = computed(() => {
  if (!animal.value) return ''

  const idade = descreverIdade(animal.value.idade_em_meses, animal.value.nascimento_exato)
  if (idade === null) return 'idade não informada'

  return animal.value.nascimento_exato ? `${idade} · nascimento confirmado` : `${idade} · nascimento estimado`
})

// Código, cópia e QR Code -----------------------------------------------

const copiado = ref(false)
let timeoutCopia = null

async function copiarCodigo() {
  if (!animal.value) return

  try {
    await navigator.clipboard.writeText(animal.value.codigo)
  } catch {
    // Permissão de área de transferência negada pelo navegador: o código
    // continua visível e selecionável na tela, então não há nada a recuperar.
    return
  }

  copiado.value = true

  clearTimeout(timeoutCopia)
  timeoutCopia = setTimeout(() => { copiado.value = false }, 2000)
}

// Exportação (T15) --------------------------------------------------------

const exportarAberto = ref(false)

function fecharExportar() {
  exportarAberto.value = false

  // Some com o `?exportar=1` do endereço antigo: fechado o modal, o endereço
  // volta a dizer só onde se está.
  if (route.query.exportar) {
    router.replace({ path: route.path })
  }
}

const mostrarQr = ref(false)
const qrDataUrl = ref('')

async function abrirQr() {
  if (!animal.value) return

  mostrarQr.value = true
  // RF17 — o código, e nada além dele, é o que o QR Code precisa carregar:
  // é por ele que uma clínica encontra o animal (RF18) sem dado algum do
  // tutor.
  qrDataUrl.value = await QRCode.toDataURL(animal.value.codigo, { width: 240, margin: 1 })
}

function fecharQr() {
  mostrarQr.value = false
}

function aoTeclar(evento) {
  if (evento.key === 'Escape' && mostrarQr.value) fecharQr()
}

onMounted(() => window.addEventListener('keydown', aoTeclar))
onUnmounted(() => {
  window.removeEventListener('keydown', aoTeclar)
  clearTimeout(timeoutCopia)
})
</script>

<template>
  <!--
    Como em T01 e T02, `amplo` para que a coluna se centre na área de conteúdo
    inteira: o perfil traz a própria largura de leitura, e o limite de 880 px da
    moldura, alinhado à esquerda, o deixaria fora do centro em telas largas.
  -->
  <TutorShell amplo>
    <div class="perfil">
      <RouterLink to="/animais" class="perfil__voltar">
        <ChevronLeft :size="20" :stroke-width="1.75" />
        Meus animais
      </RouterLink>

      <!-- Carregando: esqueleto na forma exata do conteúdo que substitui. -->
      <div v-if="carregando" class="perfil__conteudo" aria-busy="true" aria-live="polite">
        <span class="visually-hidden">Carregando o perfil do animal.</span>
        <div class="esqueleto-cabecalho">
          <div class="esqueleto esqueleto--foto" />
          <div class="esqueleto-cabecalho__texto">
            <div class="esqueleto esqueleto--nome" />
            <div class="esqueleto esqueleto--meta" />
          </div>
        </div>
        <div class="esqueleto esqueleto--bloco" />
        <div class="esqueleto esqueleto--bloco" />
      </div>

      <div v-else-if="erro" class="aviso aviso--erro" role="alert">
        <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
        <div>
          <p class="aviso__titulo">Não conseguimos carregar este animal.</p>
          <p class="aviso__texto">{{ erro }}</p>
          <button type="button" class="botao botao--secundario" @click="carregar">
            Tentar novamente
          </button>
        </div>
      </div>

      <div v-else class="perfil__conteudo">
        <!-- Confirmação do cadastro recém-feito (T03). Discreta e sem ação:
             quem chegou aqui já fez o que queria fazer. -->
        <div v-if="recemCadastrado" class="confirmacao" role="status">
          <Check :size="20" :stroke-width="1.75" class="confirmacao__icone" />
          <p class="confirmacao__texto">
            {{ animal.nome }} está cadastrado. O código abaixo é como uma clínica encontra
            {{ animal.nome }} sem precisar dos seus dados.
          </p>
        </div>

        <div v-if="recemEditado" class="confirmacao" role="status">
          <Check :size="20" :stroke-width="1.75" class="confirmacao__icone" />
          <p class="confirmacao__texto">Cadastro de {{ animal.nome }} atualizado.</p>
        </div>

        <!-- RN17 — visível em toda tela que exiba o animal, e diz de quem é a
             pendência: do veterinário, não do tutor. -->
        <div v-if="animal.preliminar" class="tarja">
          <UserRound :size="20" :stroke-width="1.75" class="tarja__icone" />
          <p class="tarja__texto">Um veterinário ainda não completou a caracterização deste animal.</p>
        </div>

        <div class="perfil__cabecalho">
          <span class="perfil__foto">
            <img v-if="animal.foto_url" :src="animal.foto_url" :alt="`Foto de ${animal.nome}`" />
            <component :is="icone" v-else :size="28" :stroke-width="1.75" />
          </span>
          <div>
            <h1 class="perfil__nome">{{ animal.nome }}</h1>
            <div class="perfil__especie">
              <component :is="icone" :size="16" :stroke-width="1.75" />
              {{ descreverEspecie(animal.especie) }}
            </div>
            <p class="perfil__idade">{{ linhaDeIdade }}</p>
          </div>

          <!-- T04a — RF16, RN20. Fica no cabeçalho, junto do que ele altera:
               nome, espécie e fotografia são identificação, e identificação é
               do tutor. O que o veterinário mantém continua sem botão algum
               nesta tela (RN18), e o cartão de caracterização diz por quê. -->
          <RouterLink
            :to="`/animais/${animal.codigo}/editar`"
            class="botao botao--secundario perfil__editar"
          >
            <Pencil :size="18" :stroke-width="1.75" />
            Editar
          </RouterLink>
        </div>

        <div class="perfil__grade">
          <div class="perfil__coluna">
            <section class="cartao" :class="{ 'cartao--destacado': recemCadastrado }">
              <h2 class="cartao__rotulo">Código do animal</h2>
              <p class="perfil__codigo">{{ animal.codigo }}</p>
              <p class="cartao__nota">É por este código que uma clínica encontra {{ animal.nome }} sem precisar dos seus dados.</p>
              <div class="perfil__acoes-codigo">
                <button type="button" class="botao botao--secundario" @click="copiarCodigo">
                  <Check v-if="copiado" :size="18" :stroke-width="1.75" />
                  <Copy v-else :size="18" :stroke-width="1.75" />
                  {{ copiado ? 'Copiado' : 'Copiar' }}
                </button>
                <button type="button" class="botao botao--secundario" @click="abrirQr">
                  <QrCode :size="18" :stroke-width="1.75" class="perfil__icone-consent" />
                  QR Code
                </button>
              </div>
            </section>

            <section class="cartao">
              <h2 class="cartao__rotulo">Caracterização</h2>

              <!-- RF19, RN18 — privativa do veterinário. Sem ela, nenhum botão
                   de edição aparece nesta tela; com ela, tampouco: o que se lê
                   aqui foi registrado na consulta, com autor e data. -->
              <EmptyState
                v-if="!animal.caracterizacao"
                :icone="Stethoscope"
                titulo="Ainda não preenchida"
                :descricao="`Raça, peso, situação reprodutiva e micro-chip são registrados pelo veterinário na consulta. Esses campos não são editáveis por você — é o que garante que a ficha de ${animal.nome} valha como documento.`"
              />

              <template v-else>
                <dl class="caracterizacao">
                  <div class="caracterizacao__item">
                    <dt class="caracterizacao__rotulo">Raça</dt>
                    <dd class="caracterizacao__valor">{{ animal.caracterizacao.raca ?? 'Não informada' }}</dd>
                  </div>
                  <div class="caracterizacao__item">
                    <dt class="caracterizacao__rotulo">Pelagem</dt>
                    <dd class="caracterizacao__valor">{{ animal.caracterizacao.pelagem ?? 'Não informada' }}</dd>
                  </div>
                  <div class="caracterizacao__item">
                    <dt class="caracterizacao__rotulo">Situação reprodutiva</dt>
                    <dd class="caracterizacao__valor">
                      {{ { inteiro: 'Inteiro', castrado: 'Castrado' }[animal.caracterizacao.situacao_reprodutiva] ?? 'Não informada' }}
                    </dd>
                  </div>
                  <div class="caracterizacao__item">
                    <dt class="caracterizacao__rotulo">Micro-chip</dt>
                    <dd class="caracterizacao__valor caracterizacao__valor--mono">
                      {{ animal.caracterizacao.microchip ?? 'Não informado' }}
                    </dd>
                  </div>
                </dl>

                <!-- RF19c — autor e data acompanham o dado, porque é a
                     assinatura que o faz valer como documento. -->
                <p v-if="animal.caracterizacao.caracterizado_por" class="caracterizacao__assinatura">
                  <Stethoscope :size="14" :stroke-width="1.75" />
                  Registrada por {{ animal.caracterizacao.caracterizado_por }}
                </p>
              </template>
            </section>
          </div>

          <div class="perfil__coluna">
            <section class="cartao">
              <h2 class="cartao__rotulo">Situação vacinal</h2>
              <p class="cartao__nota">Calculada pelo Imunia a partir dos registros da carteira</p>

              <!-- Sem vacinação registrada, não se afirma que está em dia: o
                   sistema ainda não sabe, e diz que ainda não sabe (mesmo
                   vocabulário do painel, T01). -->
              <div v-if="animal.situacao_vacinal === 'sem_registros'" class="situacao-vazia">
                <CircleHelp :size="20" :stroke-width="1.75" class="situacao-vazia__icone" />
                <p class="situacao-vazia__texto">Ainda não há vacinas registradas.</p>
              </div>
              <StatusPill
                v-else-if="animal.situacao"
                :tipo="animal.situacao.tipo"
                :texto="animal.situacao.texto"
                class="perfil__situacao-pill"
              />
            </section>

            <div class="perfil__acoes-principais">
              <RouterLink :to="`/animais/${animal.codigo}/carteira`" class="botao botao--primario">
                Ver carteira de vacinação
              </RouterLink>
              <RouterLink :to="`/animais/${animal.codigo}/historico`" class="botao botao--secundario">
                Ver histórico completo
              </RouterLink>
              <!-- T15 — modal sobre esta tela, não rota própria. -->
              <button type="button" class="botao botao--secundario" @click="exportarAberto = true">
                <FileCheck :size="20" :stroke-width="1.75" class="perfil__icone-consent" />
                Exportar PDF
              </button>
            </div>

            <section class="cartao perfil__acoes-secundarias">
              <RouterLink :to="`/animais/${animal.codigo}/pregresso/novo`" class="ligacao">
                <CirclePlus :size="20" :stroke-width="1.75" />
                Lançar histórico pregresso
              </RouterLink>
              <RouterLink :to="`/animais/${animal.codigo}/transferir`" class="ligacao">
                <UserRound :size="20" :stroke-width="1.75" />
                Transferir titularidade
              </RouterLink>
            </section>
          </div>
        </div>
      </div>
    </div>

    <!-- T15 — exportar histórico em PDF verificável (RF46). -->
    <ExportarDocumentoModal
      v-if="exportarAberto && animal"
      :animal="animal"
      @fechar="fecharExportar"
    />

    <!-- Modal do QR Code — RF17c: o mesmo código, em formato apto a ser lido
         por outro dispositivo. -->
    <div v-if="mostrarQr" class="sobreposicao" @click.self="fecharQr">
      <div class="qr-modal" role="dialog" aria-modal="true" aria-label="QR Code do código do animal">
        <button type="button" class="qr-modal__fechar" aria-label="Fechar" @click="fecharQr">
          <X :size="20" :stroke-width="1.75" />
        </button>
        <img v-if="qrDataUrl" :src="qrDataUrl" :alt="`QR Code do código ${animal?.codigo}`" class="qr-modal__imagem" width="240" height="240" />
        <p class="qr-modal__codigo">{{ animal?.codigo }}</p>
        <p class="qr-modal__nota">Aponte a câmera de outro dispositivo para ler o código de {{ animal?.nome }}.</p>
      </div>
    </div>
  </TutorShell>
</template>

<style scoped>
.perfil {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  width: 100%;
  max-width: 880px;
  margin: 0 auto;
}

.perfil__voltar {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  color: var(--ink-muted);
  font-size: 14px;
  font-weight: 600;
}

.perfil__voltar:hover {
  color: var(--ink);
}

.perfil__conteudo {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

/* Tarja de cadastro preliminar --------------------------------------------- */

.tarja {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
  background: var(--consent-wash);
  border: 1px solid var(--consent);
  border-radius: var(--radius-md);
  color: var(--ink);
}

.tarja__icone {
  flex: none;
  margin-top: 2px;
  color: var(--consent);
}

.tarja__texto {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
}

/* Cabeçalho ----------------------------------------------------------------- */

.perfil__cabecalho {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-4);
}

/* Em telas estreitas desce para a linha de baixo, alinhado à foto: ali ele
   continua lendo como parte do bloco de identidade, e não como uma ação solta
   no canto. Da largura de tablet em diante volta para a borda oposta ao nome
   (ver `@media`). */
.perfil__editar {
  flex: none;
}

.perfil__foto {
  display: flex;
  align-items: center;
  justify-content: center;
  flex: none;
  width: 72px;
  height: 72px;
  overflow: hidden;
  border-radius: var(--radius-pill);
  background: var(--surface-sunken);
  color: var(--ink-muted);
}

.perfil__foto img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.perfil__nome {
  margin: 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  color: var(--ink);
}

.perfil__especie {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin-top: var(--space-1);
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
}

.perfil__idade {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
}

/* Grade de duas colunas ------------------------------------------------------ */

.perfil__grade {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.perfil__coluna {
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

.cartao__rotulo {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

.cartao__nota {
  margin: var(--space-1) 0 0;
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
}

.cartao--destacado {
  border-color: var(--brand);
}

/* Caracterização preenchida (RF19) ------------------------------------------- */

.caracterizacao {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: var(--space-3);
  margin: var(--space-3) 0 0;
}

.caracterizacao__item {
  margin: 0;
}

.caracterizacao__rotulo {
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
}

.caracterizacao__valor {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  font-weight: 500;
  color: var(--ink);
}

.caracterizacao__valor--mono {
  font-family: var(--font-mono);
  font-size: 13px;
  font-variant-numeric: tabular-nums;
}

.caracterizacao__assinatura {
  display: flex;
  align-items: center;
  gap: var(--space-1);
  margin: var(--space-3) 0 0;
  padding: var(--space-2) 0 0;
  border-top: 1px solid var(--border-hairline);
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-muted);
}

/* Confirmação do cadastro ---------------------------------------------------- */

.confirmacao {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
  background: var(--brand-wash);
  border: 1px solid var(--brand);
  border-radius: var(--radius-md);
}

.confirmacao__icone {
  flex: none;
  margin-top: 2px;
  color: var(--brand);
}

.confirmacao__texto {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);
}

/* Código ---------------------------------------------------------------- */

.perfil__codigo {
  margin: var(--space-2) 0 0;
  font-family: var(--font-mono);
  font-size: 18px;
  line-height: 24px;
  font-weight: 500;
  color: var(--ink);
  font-variant-numeric: tabular-nums;
}

.perfil__acoes-codigo {
  display: flex;
  gap: var(--space-2);
  margin: var(--space-3) 0 0;
}

.perfil__acoes-codigo .botao {
  flex: 1;
}

.perfil__icone-consent {
  color: var(--consent);
}

/* Situação vacinal -------------------------------------------------------- */

.situacao-vazia {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: var(--space-3) 0 0;
}

.situacao-vazia__icone {
  flex: none;
  color: var(--unverified);
}

.situacao-vazia__texto {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
}

.perfil__situacao-pill {
  margin: var(--space-3) 0 0;
}

/* Ações --------------------------------------------------------------------- */

.perfil__acoes-principais {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.perfil__acoes-secundarias {
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
  padding: var(--space-2) var(--space-4);
}

.ligacao {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  height: 48px;
  font-size: 16px;
  font-weight: 600;
  color: var(--ink);
}

.ligacao:hover {
  color: var(--brand);
}

/* Botões e avisos — mesmo vocabulário das demais telas do tutor. ----------- */

.botao {
  display: inline-flex;
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

/* Modal do QR Code ---------------------------------------------------------- */

.sobreposicao {
  position: fixed;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: var(--space-4);
  background: rgba(20, 35, 31, .5);
  z-index: 100;
}

.qr-modal {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  width: 100%;
  max-width: 320px;
  padding: var(--space-8) var(--space-6) var(--space-6);
  background: var(--surface-card);
  border-radius: var(--radius-md);
  text-align: center;
}

.qr-modal__fechar {
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

.qr-modal__fechar:hover {
  background: var(--surface-sunken);
  color: var(--ink);
}

.qr-modal__imagem {
  width: 240px;
  height: 240px;
}

.qr-modal__codigo {
  margin: var(--space-4) 0 0;
  font-family: var(--font-mono);
  font-size: 18px;
  font-weight: 500;
  color: var(--ink);
  font-variant-numeric: tabular-nums;
}

.qr-modal__nota {
  margin: var(--space-2) 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

/* Esqueleto de carregamento -------------------------------------------------- */

.esqueleto {
  border-radius: var(--radius-xs);
  background: var(--surface-sunken);
  animation: pulsar 1.2s ease-in-out infinite;
}

.esqueleto--foto {
  flex: none;
  width: 72px;
  height: 72px;
  border-radius: var(--radius-pill);
}

.esqueleto--nome {
  height: 28px;
  width: 50%;
}

.esqueleto--meta {
  height: 20px;
  width: 35%;
  margin: var(--space-2) 0 0;
}

.esqueleto--bloco {
  height: 120px;
}

.esqueleto-cabecalho {
  display: flex;
  align-items: center;
  gap: var(--space-4);
}

.esqueleto-cabecalho__texto {
  flex: 1;
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

/* Larguras derivadas ---------------------------------------------------------- */

@media (min-width: 768px) {
  .perfil__editar {
    margin-left: auto;
  }

  .perfil__grade {
    flex-direction: row;
    align-items: flex-start;
  }

  .perfil__coluna:first-child {
    flex: none;
    width: 320px;
  }

  .perfil__coluna:last-child {
    flex: 1;
    min-width: 0;
  }

  .perfil__acoes-principais {
    flex-direction: row;
    flex-wrap: wrap;
  }

  .perfil__acoes-principais .botao {
    flex: 1;
    min-width: 180px;
  }

  .aviso--erro .botao {
    width: auto;
  }
}

@media (min-width: 1024px) {
  .perfil__nome {
    font-size: 36px;
    line-height: 40px;
    letter-spacing: -.02em;
  }
}
</style>
