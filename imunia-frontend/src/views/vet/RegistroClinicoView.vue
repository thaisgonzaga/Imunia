<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { CalendarClock, ChevronLeft, FilePenLine, Paperclip, TriangleAlert } from '@lucide/vue'
import VetShell from '@/components/vet/VetShell.vue'
import RetificarRegistroModal from '@/components/vet/RetificarRegistroModal.vue'
import ImmutableNotice from '@/components/base/ImmutableNotice.vue'
import ProvenanceChip from '@/components/tutor/ProvenanceChip.vue'
import RectificationBanner from '@/components/tutor/RectificationBanner.vue'
import AttachmentViewer from '@/components/tutor/AttachmentViewer.vue'
import { ApiError, apiGet, apiPost } from '@/lib/api.js'
import { emHoras, emNumeros, porExtenso } from '@/lib/datas.js'
import { procedenciaNoAmbienteClinico } from '@/lib/procedencia.js'

/**
 * V09 — o registro clínico no ambiente do veterinário, e a porta da retificação
 * (RF31, RF25, RF33).
 *
 * Uma tela para as duas espécies de registro clínico, porque o que ela faz é o
 * mesmo nas duas: mostrar o que ficou gravado, mostrar o encadeamento quando há
 * duas versões, e oferecer a correção a quem pode fazê-la.
 *
 * Não é T06 nem T08 com outra moldura. Aquelas telas partem da titularidade do
 * tutor e respondem 403 a quem escreveu o prontuário; esta parte da autorização
 * vigente do prestador ativo, grava o acesso ao registro alheio (RN49) e é a
 * única do sistema que oferece "Retificar".
 *
 * **A ação não aparece para quem não pode** (RNF09, RN27). Não desabilitada,
 * não com explicação: ausente. Quem decide é o servidor, que devolve
 * `pode_retificar` — e recusa o `POST` de todo modo, porque uma tela não é
 * lugar onde se guarda regra de autorização.
 */
const route = useRoute()
const router = useRouter()

const detalhe = ref(null)
const carregando = ref(true)
const erro = ref('')
const anexoAberto = ref(null)
const retificando = ref(false)
const enviando = ref(false)
const erros = ref({})
const falhaAoGravar = ref('')

/** Qual das duas rotas serve esta tela — é o que decide o vocabulário todo. */
const tipo = computed(() => (route.name === 'vet-vaccination-record' ? 'vacinacao' : 'atendimento'))

const prestadorAtivo = computed(
  () => detalhe.value?.prestador?.id ?? route.query.prestador ?? null,
)

/**
 * O contexto acompanha toda ligação que sai desta tela, porque a ação de
 * retificar só existe dentro do prestador que produziu o registro (RN27).
 *
 * O separador depende do caminho: nem todo destino é limpo, e um segundo `?`
 * produziria um endereço que o roteador não reconhece.
 */
function comContexto(caminho) {
  if (!prestadorAtivo.value) return caminho

  return `${caminho}${caminho.includes('?') ? '&' : '?'}prestador=${prestadorAtivo.value}`
}

const recursoDaApi = computed(
  () => `/api/clinica/animais/${route.params.codigo}`
    + `/${tipo.value === 'vacinacao' ? 'vacinas' : 'atendimentos'}/${route.params.id}`,
)

const caminhoDaApi = computed(() => comContexto(recursoDaApi.value))

async function carregar() {
  carregando.value = true
  erro.value = ''

  try {
    detalhe.value = await apiGet(caminhoDaApi.value)
  } catch (excecao) {
    erro.value = excecao.message
  } finally {
    carregando.value = false
  }
}

onMounted(carregar)

// O encadeamento navega entre dois registros na mesma rota (RF33b): sem
// recarregar aqui, ir do original à correção deixaria a versão anterior na
// tela, que é justamente o engano que a fatia existe para não cometer.
watch(() => [route.params.codigo, route.params.id, route.name], () => {
  anexoAberto.value = null
  carregar()
})

const animal = computed(() => detalhe.value?.animal ?? null)
const atendimento = computed(() => detalhe.value?.atendimento ?? null)
const aplicacao = computed(() => detalhe.value?.aplicacao ?? null)
const imunobiologico = computed(() => detalhe.value?.imunobiologico ?? null)
const proximaDose = computed(() => detalhe.value?.proxima_dose ?? null)
const retificacao = computed(() => detalhe.value?.retificacao ?? null)
const original = computed(() => detalhe.value?.original ?? null)
const podeRetificar = computed(() => detalhe.value?.pode_retificar === true)
const campos = computed(() => detalhe.value?.campos ?? [])

const ehRetificacao = computed(() => Boolean(original.value))

const caminhoDaFicha = computed(
  () => comContexto(`/clinica/animais/${route.params.codigo}`),
)

/** A rota do SPA para o outro elo do encadeamento. */
function telaDe(elo) {
  const recurso = tipo.value === 'vacinacao' ? 'vacinas' : 'atendimentos'

  return comContexto(`/clinica/animais/${route.params.codigo}/${recurso}/${elo.id}`)
}

const titulo = computed(() => {
  if (tipo.value === 'vacinacao') {
    return [imunobiologico.value?.nome, aplicacao.value?.rotulo].filter(Boolean).join(' · ')
  }

  return atendimento.value?.titulo ?? ''
})

const sobrelinha = computed(() => {
  const substantivo = tipo.value === 'vacinacao' ? 'aplicação' : 'atendimento'

  if (ehRetificacao.value) return `Retificação da ${substantivo}`.replace('da atendimento', 'do atendimento')
  if (retificacao.value) return 'Versão original'

  return `${substantivo === 'aplicação' ? 'Aplicação' : 'Atendimento'} de ${animal.value?.nome ?? ''}`
})

const quando = computed(() => {
  const registro = tipo.value === 'vacinacao' ? aplicacao.value : atendimento.value

  return [porExtenso(registro?.data), emHoras(registro?.hora)].filter(Boolean).join(', ')
})

const procedencia = computed(() => {
  const registro = tipo.value === 'vacinacao' ? aplicacao.value : atendimento.value

  return registro ? procedenciaNoAmbienteClinico(registro, detalhe.value?.prestador?.nome) : null
})

const motivoDaRetificacao = computed(() => (tipo.value === 'vacinacao'
  ? aplicacao.value?.motivo_retificacao
  : atendimento.value?.motivo_retificacao))

/**
 * O que mudou entre as duas versões, para exibir junto do conteúdo. A
 * comparação vem do servidor nos dois sentidos; o que muda é de qual lado se
 * está lendo.
 */
const alteracoes = computed(() => (retificacao.value ?? original.value)?.campos_alterados ?? [])

/** O nome do modal: o registro que está sendo corrigido, em uma linha. */
const tituloDaCorrecao = computed(() => {
  const registro = tipo.value === 'vacinacao' ? aplicacao.value : atendimento.value

  return [titulo.value, emNumeros(registro?.data)].filter(Boolean).join(' · ')
})

const autorDaCorrecao = computed(() => {
  const registro = tipo.value === 'vacinacao' ? aplicacao.value : atendimento.value

  return registro?.aplicador ?? null
})

async function retificar(valores) {
  enviando.value = true
  erros.value = {}
  falhaAoGravar.value = ''

  try {
    const { destino } = await apiPost(comContexto(`${recursoDaApi.value}/retificar`), valores)

    retificando.value = false
    router.push(destino)
  } catch (excecao) {
    // O 422 marca os campos e mantém o modal aberto com o que foi redigido: o
    // texto da correção é trabalho, e perdê-lo por um campo faltando seria
    // pedir que fosse escrito de novo. Qualquer outra falha fecha o modal e
    // aparece na tela, porque não é no formulário que ela se resolve.
    if (excecao instanceof ApiError && excecao.status === 422) {
      erros.value = Object.fromEntries(
        Object.entries(excecao.errors).map(([campo, mensagens]) => [campo, mensagens[0]]),
      )
    } else {
      retificando.value = false
      falhaAoGravar.value = excecao.message
    }
  } finally {
    enviando.value = false
  }
}
</script>

<template>
  <VetShell
    :titulo="tipo === 'vacinacao' ? 'Aplicação' : 'Atendimento'"
    :prestador="detalhe?.prestador"
    :vinculos="detalhe?.vinculos ?? []"
  >
    <div class="registro">
      <RouterLink :to="caminhoDaFicha" class="registro__voltar">
        <ChevronLeft :size="20" :stroke-width="1.75" />
        Voltar para a ficha
      </RouterLink>

      <div v-if="carregando" class="registro__conteudo" aria-busy="true" aria-live="polite">
        <span class="visually-hidden">Abrindo o registro.</span>
        <div class="esqueleto esqueleto--titulo" />
        <div class="esqueleto esqueleto--linha" />
        <div class="esqueleto esqueleto--bloco" />
      </div>

      <div v-else-if="erro" class="aviso" role="alert">
        <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
        <div>
          <p class="aviso__titulo">Não conseguimos abrir este registro.</p>
          <p class="aviso__texto">{{ erro }}</p>
          <button type="button" class="botao botao--secundario" @click="carregar">
            Tentar novamente
          </button>
        </div>
      </div>

      <div v-else class="registro__conteudo">
        <!-- RF33b — o encadeamento antes do conteúdo: quem abre um registro
             retificado precisa saber disso antes de ler, e não depois de já ter
             lido um diagnóstico corrigido três dias depois. -->
        <RectificationBanner
          v-if="retificacao"
          variante="retificado"
          :registro="tipo === 'vacinacao' ? 'aplicacao' : 'atendimento'"
          :em="retificacao.em"
          :destino="telaDe(retificacao)"
          class="registro__tarja"
        />
        <RectificationBanner
          v-else-if="original"
          variante="retificacao"
          :registro="tipo === 'vacinacao' ? 'aplicacao' : 'atendimento'"
          :em="original.em"
          :destino="telaDe(original)"
          class="registro__tarja"
        />

        <header class="registro__cabecalho">
          <div class="registro__identificacao">
            <p class="registro__sobrelinha">{{ sobrelinha }}</p>
            <h1 class="registro__titulo">{{ titulo }}</h1>
            <p class="registro__quando">{{ quando }}</p>
            <ProvenanceChip
              v-if="procedencia"
              :variante="procedencia.variante"
              :texto="procedencia.texto"
              class="registro__procedencia"
            />
          </div>

          <!-- RN27 — a ação existe só para o autor, no prestador que produziu o
               registro. Para todos os demais ela não é desenhada. -->
          <button
            v-if="podeRetificar"
            type="button"
            class="botao botao--retificar"
            @click="retificando = true"
          >
            <FilePenLine :size="16" :stroke-width="1.75" />
            Retificar
          </button>
        </header>

        <section v-if="motivoDaRetificacao" class="motivo">
          <h2 class="motivo__rotulo">Motivo da retificação</h2>
          <p class="motivo__texto">{{ motivoDaRetificacao }}</p>
        </section>

        <p v-if="falhaAoGravar" class="aviso aviso--curto" role="alert">
          <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
          <span>{{ falhaAoGravar }}</span>
        </p>

        <!-- Prontuário (RF31). -->
        <div v-if="tipo === 'atendimento'" class="prontuario">
          <section v-for="secao in atendimento.secoes" :key="secao.chave" class="secao">
            <h2 class="secao__rotulo">{{ secao.rotulo }}</h2>
            <p class="secao__texto">{{ secao.texto }}</p>
          </section>

          <section v-if="atendimento.retorno" class="secao">
            <h2 class="secao__rotulo">Retorno programado</h2>
            <p class="secao__texto">
              <CalendarClock :size="16" :stroke-width="1.75" class="secao__icone" />
              {{ porExtenso(atendimento.retorno.em) }} · {{ atendimento.retorno.finalidade }}
            </p>
          </section>

          <section v-if="atendimento.anexos.length" class="secao">
            <h2 class="secao__rotulo">Anexos</h2>
            <ul class="anexos">
              <li v-for="anexo in atendimento.anexos" :key="anexo.id">
                <button type="button" class="anexos__item" @click="anexoAberto = anexo">
                  <Paperclip :size="16" :stroke-width="1.75" />
                  <span>{{ anexo.descricao }}</span>
                  <span v-if="anexo.exame_em" class="anexos__data">
                    {{ emNumeros(anexo.exame_em) }}
                  </span>
                </button>
              </li>
            </ul>
          </section>
        </div>

        <!-- Aplicação de vacina (RF25). -->
        <div v-else class="prontuario">
          <dl class="dados">
            <div v-for="campo in campos" :key="campo.chave" class="dados__item">
              <dt class="secao__rotulo">{{ campo.rotulo }}</dt>
              <dd class="dados__valor">{{ campo.exibido || 'Não informado' }}</dd>
            </div>
          </dl>

          <section v-if="proximaDose" class="secao">
            <h2 class="secao__rotulo">Próxima dose</h2>
            <p class="secao__texto">
              <CalendarClock :size="16" :stroke-width="1.75" class="secao__icone" />
              {{ proximaDose.rotulo }} · {{ emNumeros(proximaDose.prevista_para) }}
            </p>
            <p class="secao__nota">{{ proximaDose.regra_texto }}</p>
          </section>
        </div>

        <!-- RF33b — o que mudou, campo a campo, sem sair da tela. -->
        <section v-if="alteracoes.length" class="comparacao">
          <h2 class="comparacao__rotulo">
            {{ ehRetificacao ? 'O que esta versão corrigiu' : 'O que a retificação corrigiu' }}
          </h2>
          <div v-for="campo in alteracoes" :key="campo.chave" class="comparacao__campo">
            <p class="comparacao__nome">{{ campo.rotulo }}</p>
            <p class="comparacao__antes">
              <span class="comparacao__marca">antes</span>
              {{ campo.antes || 'Não informado' }}
            </p>
            <p class="comparacao__depois">
              <span class="comparacao__marca comparacao__marca--depois">depois</span>
              {{ campo.depois || 'Não informado' }}
            </p>
          </div>
        </section>

        <ImmutableNotice :variante="retificacao || original ? 'encadeada' : 'informativa'" />
      </div>

      <AttachmentViewer
        v-if="anexoAberto"
        :anexo="anexoAberto"
        @fechar="anexoAberto = null"
      />

      <RetificarRegistroModal
        :aberto="retificando"
        :titulo="tituloDaCorrecao"
        :registro="tipo === 'vacinacao' ? 'aplicacao' : 'atendimento'"
        :campos="campos"
        :autor="autorDaCorrecao"
        :erros="erros"
        :enviando="enviando"
        @confirmar="retificar"
        @cancelar="retificando = false"
      />
    </div>
  </VetShell>
</template>

<style scoped>
.registro {
  max-width: 880px;
  margin: 0 auto;
  padding: var(--space-4);
}

.registro__voltar {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  min-height: 44px;
  font-size: 14px;
  font-weight: 600;
}

.registro__conteudo {
  display: flex;
  flex-direction: column;
  gap: var(--space-6);
  margin-top: var(--space-2);
}

.registro__tarja {
  margin: 0 calc(var(--space-4) * -1);
}

.registro__cabecalho {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--space-4);
  flex-wrap: wrap;
}

.registro__sobrelinha {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

.registro__titulo {
  margin: var(--space-1) 0 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  color: var(--ink);
  text-wrap: pretty;
}

.registro__quando {
  margin: var(--space-1) 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.registro__procedencia {
  margin-top: var(--space-3);
}

.botao {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-2);
  min-height: 44px;
  padding: 0 var(--space-4);
  border-radius: var(--radius-sm);
  font-family: var(--font-body);
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
}

.botao--retificar {
  flex: none;
  background: var(--brand);
  border: 1px solid var(--brand);
  color: var(--surface-card);
}

.botao--retificar:hover {
  background: var(--brand-hover);
}

.botao--secundario {
  margin-top: var(--space-3);
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  color: var(--ink);
}

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

.prontuario {
  display: flex;
  flex-direction: column;
  gap: var(--space-6);
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

.secao__texto {
  display: flex;
  align-items: baseline;
  gap: var(--space-2);
  margin: 6px 0 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);
  white-space: pre-wrap;
  text-wrap: pretty;
}

.secao__icone {
  flex: none;
  align-self: center;
  color: var(--ink-muted);
}

.secao__nota {
  margin: var(--space-1) 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-faint);
}

.dados {
  display: grid;
  gap: var(--space-4);
  margin: 0;
}

.dados__valor {
  margin: 6px 0 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);
}

.anexos {
  margin: var(--space-2) 0 0;
  padding: 0;
  list-style: none;
}

.anexos__item {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  width: 100%;
  min-height: 44px;
  padding: var(--space-2) var(--space-3);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-sm);
  font-family: var(--font-body);
  font-size: 14px;
  color: var(--ink);
  text-align: left;
  cursor: pointer;
}

.anexos li + li {
  margin-top: var(--space-2);
}

.anexos__data {
  margin-left: auto;
  color: var(--ink-muted);
}

.comparacao {
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

.comparacao__rotulo {
  margin: 0 0 var(--space-3);
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

.comparacao__campo + .comparacao__campo {
  margin-top: var(--space-4);
  padding-top: var(--space-4);
  border-top: 1px solid var(--border-hairline);
}

.comparacao__nome {
  margin: 0 0 var(--space-2);
  font-size: 14px;
  font-weight: 600;
  color: var(--ink);
}

.comparacao__antes,
.comparacao__depois {
  margin: 0;
  padding-left: var(--space-3);
  border-left: 2px solid var(--border-strong);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
  white-space: pre-wrap;
}

.comparacao__depois {
  margin-top: var(--space-2);
  border-left-color: var(--status-due);
  color: var(--ink);
}

.comparacao__marca {
  display: block;
  font-size: 12px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-faint);
}

.comparacao__marca--depois {
  color: var(--status-due-text);
}

.aviso {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  padding: var(--space-4);
  background: var(--status-late-wash);
  border: 1px solid var(--status-late);
  border-radius: var(--radius-md);
}

.aviso--curto {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

.aviso__icone {
  flex: none;
  margin-top: 2px;
  color: var(--status-late);
}

.aviso__titulo {
  margin: 0;
  font-size: 16px;
  font-weight: 600;
  color: var(--ink);
}

.aviso__texto {
  margin: var(--space-1) 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.esqueleto {
  background: var(--surface-sunken);
  border-radius: var(--radius-sm);
  animation: imunia-shimmer 1.4s ease-in-out infinite;
}

.esqueleto--titulo {
  width: 60%;
  height: 34px;
}

.esqueleto--linha {
  width: 40%;
  height: 20px;
}

.esqueleto--bloco {
  height: 240px;
}

@media (min-width: 1024px) {
  .registro {
    padding: var(--space-6);
  }

  .registro__tarja {
    margin: 0 calc(var(--space-6) * -1);
  }

  .dados {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .secao__texto,
  .dados__valor {
    font-size: 14px;
    line-height: 20px;
  }
}
</style>
