<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { CalendarClock, ChevronLeft, TriangleAlert } from '@lucide/vue'
import TutorShell from '@/components/tutor/TutorShell.vue'
import AttachmentList from '@/components/tutor/AttachmentList.vue'
import AttachmentViewer from '@/components/tutor/AttachmentViewer.vue'
import ProvenanceChip from '@/components/tutor/ProvenanceChip.vue'
import RecordSection from '@/components/tutor/RecordSection.vue'
import RectificationBanner from '@/components/tutor/RectificationBanner.vue'
import ImmutableNotice from '@/components/base/ImmutableNotice.vue'
import { apiGet } from '@/lib/api.js'
import { emHoras, emNumeros, porExtenso } from '@/lib/datas.js'
import { notaDoTermo } from '@/lib/glossario.js'
import { procedenciaDaRetificacao, procedenciaDe } from '@/lib/procedencia.js'

/**
 * T08 — detalhe do atendimento (RF31, RF32, RF33). O prontuário inteiro em
 * leitura, com autoria e imutabilidade evidentes.
 *
 * Nenhuma edição em nenhuma hipótese (RN26), e a ação "Retificar" não existe
 * aqui: ela é do veterinário autor (RN27), na tela dele. Não aparece
 * desabilitada — oferecer e negar seria pior do que não oferecer.
 */
const route = useRoute()

const detalhe = ref(null)
const carregando = ref(true)
const erro = ref('')
const anexoAberto = ref(null)

async function carregar() {
  carregando.value = true
  erro.value = ''

  try {
    detalhe.value = await apiGet(
      `/api/animais/${route.params.codigo}/atendimentos/${route.params.id}`
    )
  } catch (excecao) {
    erro.value = excecao.message
  } finally {
    carregando.value = false
  }
}

onMounted(carregar)

// O encadeamento navega entre dois registros na mesma rota: sem recarregar
// aqui, ir do original à retificação deixaria a tela anterior no lugar.
watch(() => [route.params.codigo, route.params.id], () => {
  anexoAberto.value = null
  carregar()
})

const animal = computed(() => detalhe.value?.animal ?? null)
const atendimento = computed(() => detalhe.value?.atendimento ?? null)
const retificacao = computed(() => detalhe.value?.retificacao ?? null)
const original = computed(() => detalhe.value?.original ?? null)

const caminhoDoHistorico = computed(() => `/animais/${route.params.codigo}/historico`)

/** A rota do SPA para o outro elo do encadeamento, a partir da rota da API. */
function telaDe(elo) {
  return `/animais/${route.params.codigo}/atendimentos/${elo.id}`
}

const sobrelinha = computed(() => {
  if (atendimento.value?.eh_retificacao) return 'Retificação do atendimento'
  if (retificacao.value) return 'Versão original'

  return `Atendimento de ${animal.value?.nome ?? ''}`
})

/** "qua., 12 de novembro de 2025, 16h20" — quando o atendimento aconteceu. */
const quando = computed(() => [
  porExtenso(atendimento.value?.data),
  emHoras(atendimento.value?.hora),
].filter(Boolean).join(', '))

const procedencia = computed(() => procedenciaDe(atendimento.value))

/**
 * Os campos em que as duas versões diferem, indexados pela seção a que
 * pertencem. A comparação vem do servidor com o mesmo conteúdo nos dois
 * sentidos; o que muda é qual dos lados está sendo lido.
 */
const alteracoes = computed(() => {
  const encadeado = retificacao.value ?? original.value

  return new Map((encadeado?.campos_alterados ?? []).map((campo) => [campo.chave, campo]))
})

/**
 * A outra versão de uma seção, quando existir: lendo o original, o texto
 * corrigido, com o motivo e a autoria da correção; lendo a retificação, o que
 * estava escrito antes.
 */
function versaoVinculada(secao) {
  const campo = alteracoes.value.get(secao.chave)

  if (!campo) return null

  if (retificacao.value) {
    return {
      rotulo: `${secao.rotulo} · versão retificada`,
      texto: campo.depois,
      complemento: retificacao.value.motivo
        ? `Motivo da retificação: ${retificacao.value.motivo}`
        : null,
      chip: procedenciaDaRetificacao(retificacao.value),
    }
  }

  return {
    rotulo: `${secao.rotulo} · versão original`,
    texto: campo.antes,
    complemento: `Registrada em ${emNumeros(original.value.em)} e mantida no sistema.`,
    chip: null,
  }
}

const secoes = computed(() => (atendimento.value?.secoes ?? []).map((secao) => ({
  ...secao,
  // O rótulo diz de qual das versões é o texto sempre que houver duas — sem
  // isso, o par ficaria sendo dois textos parecidos, um embaixo do outro.
  rotulo: alteracoes.value.has(secao.chave)
    ? `${secao.rotulo} · versão ${atendimento.value.eh_retificacao ? 'retificada' : 'original'}`
    : secao.rotulo,
  nota: notaDoTermo(secao.chave, animal.value?.nome ?? 'seu animal'),
  vinculada: versaoVinculada(secao),
})))
</script>

<template>
  <!--
    `amplo` mais a coluna centrada abaixo, como em T01. Aqui a largura de
    leitura é a de 640 px do desenho, e não a de 880: o que muda é só o
    alinhamento, que passa a ser o centro da área de conteúdo.
  -->
  <TutorShell amplo>
    <div class="atendimento">
      <RouterLink :to="caminhoDoHistorico" class="atendimento__voltar">
        <ChevronLeft :size="20" :stroke-width="1.75" />
        Voltar para o histórico
      </RouterLink>

      <!-- Carregando: esqueleto na forma exata do conteúdo que substitui. -->
      <div v-if="carregando" class="atendimento__conteudo" aria-busy="true" aria-live="polite">
        <span class="visually-hidden">Carregando o atendimento.</span>
        <div class="esqueleto esqueleto--titulo" />
        <div class="esqueleto esqueleto--linha" />
        <div class="esqueleto esqueleto--prontuario" />
        <div class="esqueleto esqueleto--bloco" />
      </div>

      <div v-else-if="erro" class="aviso aviso--erro" role="alert">
        <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
        <div>
          <p class="aviso__titulo">Não conseguimos carregar este atendimento.</p>
          <p class="aviso__texto">{{ erro }}</p>
          <button type="button" class="botao botao--secundario" @click="carregar">
            Tentar novamente
          </button>
        </div>
      </div>

      <div v-else class="atendimento__conteudo">
        <!-- RF33b — o encadeamento antes do conteúdo, nos dois sentidos. -->
        <RectificationBanner
          v-if="retificacao"
          variante="retificado"
          :em="retificacao.em"
          :destino="telaDe(retificacao)"
          class="atendimento__tarja"
        />
        <RectificationBanner
          v-else-if="original"
          variante="retificacao"
          :em="original.em"
          :destino="telaDe(original)"
          class="atendimento__tarja"
        />

        <header>
          <p class="atendimento__sobrelinha">{{ sobrelinha }}</p>
          <h1 class="atendimento__titulo">{{ atendimento.titulo }}</h1>
          <p class="atendimento__quando">{{ quando }}</p>
          <ProvenanceChip
            :variante="procedencia.variante"
            :texto="procedencia.texto"
            class="atendimento__procedencia"
          />
        </header>

        <!-- RF33 — a correção diz por que existe antes de dizer o que corrigiu. -->
        <section v-if="atendimento.motivo_retificacao" class="motivo">
          <h2 class="motivo__rotulo">Motivo da retificação</h2>
          <p class="motivo__texto">{{ atendimento.motivo_retificacao }}</p>
        </section>

        <div class="prontuario">
          <RecordSection
            v-for="secao in secoes"
            :key="secao.chave"
            :rotulo="secao.rotulo"
            :texto="secao.texto"
            :nota="secao.nota"
            :vinculada="secao.vinculada"
          />
        </div>

        <AttachmentList
          :anexos="atendimento.anexos"
          :data-do-registro="atendimento.data"
          @abrir="anexoAberto = $event"
          @recarregar="carregar"
        >
          <template #acao-vazio>
            <RouterLink :to="caminhoDoHistorico" class="botao botao--secundario botao--largo">
              Ver o histórico completo
            </RouterLink>
          </template>
        </AttachmentList>

        <!-- RF34 — retorno programado. -->
        <section v-if="atendimento.retorno" class="retorno">
          <h2 class="retorno__rotulo">Retorno programado</h2>
          <div class="retorno__corpo">
            <CalendarClock :size="20" :stroke-width="1.75" class="retorno__icone" />
            <div>
              <p class="retorno__data">{{ porExtenso(atendimento.retorno.em) }}</p>
              <p class="retorno__finalidade">{{ atendimento.retorno.finalidade }}</p>
            </div>
          </div>
        </section>

        <ImmutableNotice :variante="retificacao || original ? 'encadeada' : 'informativa'" />
      </div>

      <AttachmentViewer
        v-if="anexoAberto"
        :anexo="anexoAberto"
        @fechar="anexoAberto = null"
      />
    </div>
  </TutorShell>
</template>

<style scoped>
/* Coluna de leitura de 640 px: o prontuário é texto corrido, e linha longa
   demais é o que faz o olho perder a próxima. */
.atendimento {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  width: 100%;
  max-width: 640px;
  margin: 0 auto;
}

.atendimento__voltar {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  padding: 10px 0;
  margin: -10px 0;
  color: var(--ink-muted);
  font-size: 14px;
  font-weight: 600;
}

.atendimento__voltar:hover {
  color: var(--ink);
}

.atendimento__conteudo {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

/* A tarja sangra até a borda do conteúdo: ela é sobre a tela inteira, não um
   bloco dentro dela. */
.atendimento__tarja {
  margin: calc(var(--space-4) * -1) calc(var(--space-4) * -1) 0;
}

.atendimento__sobrelinha {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--brand-bright);
}

.atendimento__titulo {
  margin: var(--space-1) 0 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  color: var(--ink);
}

.atendimento__quando {
  margin: var(--space-1) 0 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
  font-variant-numeric: tabular-nums;
}

.atendimento__procedencia {
  margin: var(--space-3) 0 0;
}

.prontuario {
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
  overflow: hidden;
}

/* Motivo da retificação ---------------------------------------------------- */

.motivo {
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--status-due);
  border-radius: var(--radius-md);
}

.motivo__rotulo {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--status-due-text);
}

.motivo__texto {
  margin: var(--space-2) 0 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);
  text-wrap: pretty;
}

/* Retorno programado ------------------------------------------------------ */

.retorno {
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

.retorno__rotulo {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

.retorno__corpo {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  margin: var(--space-3) 0 0;
}

.retorno__icone {
  flex: none;
  margin-top: 2px;
  color: var(--ink-muted);
}

.retorno__data {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
  font-variant-numeric: tabular-nums;
}

.retorno__finalidade {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
}

/* Botões, avisos e esqueleto — mesmo vocabulário das demais telas do tutor. */

.botao {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-2);
  height: 48px;
  padding: 0 var(--space-6);
  border-radius: var(--radius-sm);
  font-family: var(--font-body);
  font-size: 16px;
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
}

.botao--largo {
  display: flex;
  margin: var(--space-4) 0 0;
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
  color: var(--status-late);
}

.aviso--erro {
  background: var(--status-late-wash);
  border: 1px solid var(--status-late);
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
}

.esqueleto {
  border-radius: var(--radius-xs);
  background: var(--surface-sunken);
  animation: pulsar 1.2s ease-in-out infinite;
}

.esqueleto--titulo {
  height: 34px;
  width: 55%;
}

.esqueleto--linha {
  height: 24px;
  width: 70%;
}

.esqueleto--prontuario {
  height: 320px;
  border-radius: var(--radius-md);
}

.esqueleto--bloco {
  height: 120px;
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

@media (min-width: 768px) {
  .atendimento__tarja {
    margin: calc(var(--space-6) * -1) calc(var(--space-6) * -1) 0;
  }

  .retorno {
    padding: var(--space-4) var(--space-5, 20px);
  }
}

@media (min-width: 1024px) {
  .atendimento__titulo {
    font-size: 36px;
    line-height: 40px;
    letter-spacing: -.02em;
  }

  /* A partir daqui o conteúdo já tem folga lateral própria: a tarja volta a
     acompanhar a coluna de leitura, em vez de sangrar até a barra lateral. */
  .atendimento__tarja {
    margin: 0;
    border-left: 1px solid var(--status-due);
    border-right: 1px solid var(--status-due);
    border-radius: var(--radius-sm);
  }
}
</style>
