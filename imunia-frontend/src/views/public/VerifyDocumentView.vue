<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  CircleCheck,
  CircleHelp,
  ClipboardCheck,
  ClockAlert,
  Copy,
  FileCheck,
  TriangleAlert,
} from '@lucide/vue'
import PublicHeader from '@/components/public/PublicHeader.vue'
import AppButton from '@/components/base/AppButton.vue'
import AppInput from '@/components/base/AppInput.vue'
import { apiGet, ApiError } from '@/lib/api.js'
import { useContagemRegressiva } from '@/lib/contagem.js'
import {
  codigoDocumentoValido,
  formatarCodigoDocumento,
  normalizarCodigoDocumento,
} from '@/lib/documento.js'

const route = useRoute()
const router = useRouter()

/**
 * P09 — a tela que atende quem está fora da plataforma. Chega quase sempre por
 * leitura do QR Code impresso no PDF, e por isso é desenhada primeiro em 360 px.
 * Nenhum estado daqui expõe conteúdo clínico, tutor ou clínica: a tela prova a
 * autenticidade do documento, e só (RF47, RN47).
 */
const situacao = ref('inicial')
const resultado = ref(null)
const codigo = ref('')
const erroCodigo = ref('')
const copiado = ref(false)
const {
  correndo: emPausa,
  restante: segundosRestantes,
  iniciar: iniciarPausa,
} = useContagemRegressiva()

// Guarda contra verificar duas vezes o mesmo código: a URL é reescrita a cada
// consulta, e uma segunda chamada custaria uma das tentativas toleradas.
let ultimoVerificado = null

const ESPECIES = { cao: 'cão', gato: 'gato' }

const codigoFormatado = computed({
  get: () => codigo.value,
  set: (valor) => {
    codigo.value = formatarCodigoDocumento(valor)
    if (erroCodigo.value) erroCodigo.value = ''
  },
})

const gruposResumo = computed(() => (resultado.value?.resumo ?? '').match(/.{1,4}/g) ?? [])

const dataEmissao = computed(() => {
  const [ano, mes, dia] = (resultado.value?.emitido_em ?? '').split('-')

  return dia ? `${dia}/${mes}/${ano}` : ''
})

const animal = computed(() => {
  const dados = resultado.value?.animal
  if (!dados) return ''

  return `${dados.nome} (${ESPECIES[dados.especie] ?? dados.especie})`
})

const contagem = computed(() => {
  const minutos = Math.floor(segundosRestantes.value / 60)
  const segundos = segundosRestantes.value % 60

  return `${String(minutos).padStart(2, '0')}:${String(segundos).padStart(2, '0')}`
})

// Durante a consulta não há o que digitar; durante a pausa, não há o que
// adiantar digitando.
const mostraCampo = computed(() => (
  situacao.value !== 'verificando' && !(situacao.value === 'limite' && emPausa.value)
))

const rotuloCampo = computed(() => (
  situacao.value === 'autentico' || situacao.value === 'divergente'
    ? 'Verificar outro documento'
    : 'Código do documento'
))

const rotuloBotao = computed(() => (
  situacao.value === 'nao_localizado' ? 'Verificar de novo' : 'Verificar'
))

// A explicação do que a verificação prova acompanha o resultado autêntico e
// antecede a primeira consulta; nos demais estados, o que importa é o desfecho.
const mostraExplicacao = computed(() => ['inicial', 'autentico'].includes(situacao.value))

watch(() => route.params.codigo, (bruto) => {
  const normalizado = normalizarCodigoDocumento(bruto)

  if (normalizado === '' || normalizado === ultimoVerificado) return

  codigo.value = formatarCodigoDocumento(normalizado)
  verificar(normalizado, normalizarCodigoDocumento(route.query.resumo))
}, { immediate: true })

/**
 * Ao entrar pelo QR Code, o código já vem na rota e a verificação é automática.
 * O QR traz também o resumo do conteúdo emitido — é o que permite ao servidor
 * distinguir um documento alterado de um documento íntegro. Na digitação
 * manual não há resumo a confrontar, e a comparação fica com o conferente,
 * contra o que está impresso no rodapé.
 */
async function verificar(normalizado, resumo) {
  ultimoVerificado = normalizado
  situacao.value = 'verificando'
  erroCodigo.value = ''
  copiado.value = false

  const consulta = resumo ? `?resumo=${resumo}` : ''

  try {
    const resposta = await apiGet(`/api/documentos/${normalizado}${consulta}`)
    resultado.value = resposta
    situacao.value = resposta.situacao
  } catch (excecao) {
    resultado.value = null

    if (excecao instanceof ApiError && excecao.status === 429) {
      situacao.value = 'limite'
      iniciarPausa(excecao.data.segundos_restantes)
      return
    }

    situacao.value = 'inicial'
    erroCodigo.value = excecao instanceof ApiError && excecao.status === 422
      ? (excecao.errors.codigo?.[0] ?? excecao.message)
      : excecao.message
  }
}

function submeter() {
  const normalizado = normalizarCodigoDocumento(codigo.value)

  if (!codigoDocumentoValido(normalizado)) {
    erroCodigo.value = 'O código tem 16 caracteres, em quatro grupos de quatro.'
    return
  }

  // A rota carrega o código para que a página possa ser recarregada, guardada
  // ou compartilhada. O resumo não vai junto: ele só vale quando veio do QR.
  router.replace(`/verificar/${normalizado}`)
  verificar(normalizado, null)
}

async function copiarResumo() {
  try {
    await navigator.clipboard.writeText(gruposResumo.value.join(' '))
    copiado.value = true
    setTimeout(() => { copiado.value = false }, 2000)
  } catch {
    // Sem permissão de área de transferência os grupos continuam à vista, que
    // é o que a conferência exige.
  }
}
</script>

<template>
  <div class="verify">
    <PublicHeader />

    <main class="verify__main">
      <div class="verify__column">
        <header>
          <p class="verify__eyebrow">
            <FileCheck :size="16" />
            Verificação pública
          </p>
          <h1 class="verify__title">Verificar um documento do Imunia</h1>
        </header>

        <!-- O resultado é lido em três tempos: ícone, cor, título. -->
        <section v-if="situacao === 'verificando'" class="verify-card" aria-live="polite">
          <div class="verify-skeleton verify-skeleton--mark" />
          <div class="verify-skeleton verify-skeleton--title" />
          <div class="verify-skeleton verify-skeleton--text" />
          <p class="verify-card__text verify-card__text--muted">Verificando o documento…</p>
        </section>

        <section v-else-if="situacao === 'autentico'" class="verify-card verify-card--autentico" aria-live="polite">
          <FileCheck :size="48" class="verify-card__mark verify-card__mark--consent" />
          <h2 class="verify-card__title">Documento autêntico</h2>
          <p class="verify-card__text">
            Emitido em {{ dataEmissao }}, referente ao animal {{ animal }}.
          </p>
        </section>

        <section v-else-if="situacao === 'nao_localizado'" class="verify-card" aria-live="polite">
          <CircleHelp :size="48" class="verify-card__mark verify-card__mark--neutro" />
          <h2 class="verify-card__title">Documento não localizado</h2>
          <p class="verify-card__text">Não encontramos um documento com este código.</p>
          <p class="verify-card__text verify-card__text--muted">
            Confira os caracteres impressos no rodapé do PDF. O código tem 16 caracteres, em
            quatro grupos de quatro.
          </p>
        </section>

        <section v-else-if="situacao === 'divergente'" class="verify-card verify-card--divergente" aria-live="polite">
          <TriangleAlert :size="48" class="verify-card__mark verify-card__mark--atraso" />
          <h2 class="verify-card__title">Documento divergente</h2>
          <p class="verify-card__text">O conteúdo apresentado não corresponde ao documento emitido.</p>
          <p class="verify-card__text verify-card__text--muted">
            O código existe, mas o PDF em suas mãos foi alterado depois da emissão. Peça ao tutor
            um documento novo, gerado agora.
          </p>
        </section>

        <!-- A mensagem da pausa é neutra de propósito: dizer por que ela veio
             já seria dizer se o código existe (RF47c). -->
        <section v-else-if="situacao === 'limite'" class="verify-card verify-card--limite" aria-live="polite">
          <ClockAlert :size="48" class="verify-card__mark verify-card__mark--atencao" />
          <h2 class="verify-card__title">Muitas verificações seguidas</h2>
          <p class="verify-card__text">Aguarde para verificar outro documento.</p>
          <p class="verify-card__text verify-card__text--muted">
            A pausa vale para qualquer código, existente ou não: é assim que evitamos que alguém
            descubra documentos por tentativa e erro.
          </p>
          <p v-if="emPausa" class="verify-card__contagem">{{ contagem }}</p>
        </section>

        <!-- Chega aqui quem abriu /verificar pelo rodapé, sem código na mão. -->
        <section v-else class="verify-card verify-card--inicial">
          <FileCheck :size="48" class="verify-card__mark verify-card__mark--consent" />
          <h2 class="verify-card__title">Confira um documento</h2>
          <p class="verify-card__text">
            Digite o código impresso no rodapé do PDF. Você não precisa de conta.
          </p>
        </section>

        <section
          v-if="situacao === 'autentico' || situacao === 'divergente'"
          class="verify-resumo"
          :class="{ 'verify-resumo--neutro': situacao === 'divergente' }"
        >
          <div class="verify-resumo__cabecalho">
            <div>
              <p class="verify-resumo__rotulo">
                {{ situacao === 'divergente' ? 'Resumo do documento emitido' : 'Resumo do documento' }}
              </p>
              <p v-if="situacao === 'autentico'" class="verify-resumo__texto">
                Compare com o que está impresso no rodapé do PDF.
              </p>
              <div class="verify-resumo__grupos">
                <span v-for="grupo in gruposResumo" :key="grupo" class="verify-resumo__grupo">
                  {{ grupo }}
                </span>
              </div>
            </div>

            <button
              v-if="situacao === 'autentico'"
              type="button"
              class="verify-resumo__copiar"
              @click="copiarResumo"
            >
              <component :is="copiado ? ClipboardCheck : Copy" :size="20" />
              {{ copiado ? 'Resumo copiado' : 'Copiar o resumo' }}
            </button>
          </div>

          <p v-if="situacao === 'divergente'" class="verify-resumo__texto verify-resumo__texto--fim">
            Compare com o rodapé do seu PDF: se os grupos diferirem, é outro documento.
          </p>
        </section>

        <section v-if="mostraExplicacao" class="verify-diz">
          <p class="verify-diz__rotulo">O que esta verificação diz</p>
          <ul class="verify-diz__lista">
            <li class="verify-diz__item">
              <CircleCheck :size="20" class="verify-diz__icone verify-diz__icone--ok" />
              <span>O documento foi emitido pelo Imunia nesta data.</span>
            </li>
            <li class="verify-diz__item">
              <CircleCheck :size="20" class="verify-diz__icone verify-diz__icone--ok" />
              <span>O conteúdo do PDF é o mesmo que foi emitido, sem alteração.</span>
            </li>
            <li class="verify-diz__item verify-diz__item--ressalva">
              <CircleHelp :size="20" class="verify-diz__icone verify-diz__icone--neutro" />
              <span>Não diz se o animal está com as vacinas em dia: para isso, é preciso ler o documento.</span>
            </li>
          </ul>
        </section>

        <form v-if="mostraCampo" class="verify-form" @submit.prevent="submeter">
          <AppInput
            id="codigo-documento"
            :label="rotuloCampo"
            placeholder="Digite o código do rodapé"
            autocomplete="off"
            inputmode="text"
            mono
            v-model="codigoFormatado"
            :error="erroCodigo"
          />
          <AppButton class="verify-form__submit" type="submit" variant="consentimento">
            {{ rotuloBotao }}
          </AppButton>
        </form>

        <p class="verify-saida">
          <RouterLink to="/">Conhecer o Imunia</RouterLink>
        </p>
      </div>
    </main>
  </div>
</template>

<style scoped>
.verify {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
  background: var(--surface-page);
}

.verify__main {
  display: flex;
  justify-content: center;
  padding: var(--space-6) var(--space-4) var(--space-12);
}

.verify__column {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  width: 100%;
  max-width: 560px;
}

.verify__eyebrow {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--consent);
}

.verify__eyebrow svg {
  flex: none;
}

.verify__title {
  margin: var(--space-1) 0 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  color: var(--ink);
}

/* Cartão de resultado */
.verify-card {
  padding: var(--space-6) 20px;
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-md);
  text-align: center;
}

.verify-card--autentico,
.verify-card--inicial {
  border-color: var(--consent);
}

.verify-card--divergente {
  background: var(--status-late-wash);
  border-color: var(--status-late);
}

.verify-card--limite {
  border-color: var(--status-due);
}

.verify-card__mark--consent {
  color: var(--consent);
}

.verify-card__mark--neutro {
  color: var(--unverified);
}

.verify-card__mark--atraso {
  color: var(--status-late);
}

/* O âmbar não serve de cor de texto nem de traço fino: o ícone usa a variante
   escura, e o contorno fica com o âmbar cheio. */
.verify-card__mark--atencao {
  color: var(--status-due-text);
}

.verify-card__title {
  margin: var(--space-4) 0 0;
  font-family: var(--font-display);
  font-size: 22px;
  line-height: 28px;
  font-weight: 600;
  color: var(--ink);
}

.verify-card__text {
  margin: var(--space-2) 0 0;
  color: var(--ink);
}

.verify-card__text--muted {
  color: var(--ink-muted);
}

.verify-card__contagem {
  margin: var(--space-4) 0 0;
  font-family: var(--font-mono);
  font-weight: 500;
  font-variant-numeric: tabular-nums;
  color: var(--ink);
}

.verify-skeleton {
  margin: 0 auto;
  background: var(--surface-sunken);
  border-radius: var(--radius-xs);
  animation: verify-shimmer 1.2s ease-in-out infinite;
}

.verify-skeleton--mark {
  width: 48px;
  height: 48px;
  border-radius: var(--radius-pill);
}

.verify-skeleton--title {
  height: 28px;
  width: 70%;
  margin-top: var(--space-4);
}

.verify-skeleton--text {
  height: 24px;
  width: 85%;
  margin-top: var(--space-2);
}

@keyframes verify-shimmer {
  0% { opacity: .55; }
  50% { opacity: 1; }
  100% { opacity: .55; }
}

/* Resumo criptográfico */
.verify-resumo {
  padding: var(--space-4);
  background: var(--consent-wash);
  border: 1px solid var(--consent);
  border-radius: var(--radius-md);
}

/* No documento divergente o resumo não é a boa notícia: serve de prova a
   conferir, e por isso perde a cor de destaque. */
.verify-resumo--neutro {
  background: var(--surface-card);
  border-color: var(--border-hairline);
}

.verify-resumo__cabecalho {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}

.verify-resumo__rotulo {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--consent);
}

.verify-resumo--neutro .verify-resumo__rotulo {
  color: var(--ink-muted);
}

.verify-resumo__texto {
  margin: var(--space-1) 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.verify-resumo__texto--fim {
  margin-top: var(--space-3);
  font-size: 16px;
  line-height: 24px;
}

.verify-resumo__grupos {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  margin: var(--space-3) 0 0;
}

.verify-resumo__grupo {
  padding: 6px 10px;
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-xs);
  font-family: var(--font-mono);
  font-weight: 500;
  font-variant-numeric: tabular-nums;
  color: var(--ink);
}

.verify-resumo__copiar {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-2);
  width: 100%;
  height: 48px;
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  font-size: 16px;
  font-weight: 600;
  color: var(--ink);
  cursor: pointer;
}

.verify-resumo__copiar:hover {
  background: var(--surface-sunken);
}

.verify-resumo__copiar svg {
  flex: none;
  color: var(--consent);
}

/* O que a verificação diz */
.verify-diz {
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

.verify-diz__rotulo {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

.verify-diz__lista {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  margin: var(--space-3) 0 0;
  padding: 0;
  list-style: none;
}

.verify-diz__item {
  display: flex;
  align-items: flex-start;
  gap: var(--space-2);
  color: var(--ink);
}

.verify-diz__item--ressalva {
  color: var(--ink-muted);
}

.verify-diz__icone {
  flex: none;
  margin-top: 2px;
}

.verify-diz__icone--ok {
  color: var(--status-ok);
}

.verify-diz__icone--neutro {
  color: var(--unverified);
}

/* Campo de digitação */
.verify-form {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.verify-form :deep(.app-field__input:not(.app-field__input--error)) {
  border-color: var(--consent);
}

/* Única saída da tela, e a maior parte dos acessos vem de celular: o alvo de
   toque tem de valer os 44 px, ainda que o rótulo seja discreto. */
.verify-saida {
  display: flex;
  justify-content: center;
  margin: var(--space-2) 0 0;
  font-size: 14px;
  line-height: 20px;
}

.verify-saida a {
  display: inline-flex;
  align-items: center;
  height: 44px;
  padding: 0 var(--space-3);
}

@media (min-width: 768px) {
  .verify__main {
    padding: var(--space-12) var(--space-8) var(--space-16);
  }

  .verify__title {
    font-size: 36px;
    line-height: 40px;
    letter-spacing: -.02em;
  }

  .verify-card {
    padding: var(--space-8) var(--space-6);
  }

  .verify-resumo,
  .verify-diz {
    padding: 20px;
  }

  /* Acima de 768 px o botão de cópia cabe ao lado dos grupos, e volta à
     altura de 40 px do desenho derivado. */
  .verify-resumo__cabecalho {
    flex-direction: row;
    align-items: flex-end;
    justify-content: space-between;
    gap: var(--space-4);
    flex-wrap: wrap;
  }

  .verify-resumo__copiar {
    width: auto;
    height: 40px;
    padding: 0 var(--space-4);
    font-size: 14px;
  }

  /* Os quatro grupos ocupam largura fixa; o botão só cabe ao lado deles — como
     no desenho derivado — reduzido ao rótulo. */
  .verify-resumo__copiar svg {
    display: none;
  }

  .verify-form {
    flex-direction: row;
    align-items: flex-end;
  }

  .verify-form :deep(.app-field) {
    flex: 1;
    min-width: 0;
  }

  .verify-form__submit {
    flex: none;
  }
}
</style>
