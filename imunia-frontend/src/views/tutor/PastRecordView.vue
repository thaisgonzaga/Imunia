<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ChevronLeft, CircleHelp, TriangleAlert } from '@lucide/vue'
import TutorShell from '@/components/tutor/TutorShell.vue'
import AppButton from '@/components/base/AppButton.vue'
import AppInput from '@/components/base/AppInput.vue'
import AppSelect from '@/components/base/AppSelect.vue'
import ConfirmDialog from '@/components/base/ConfirmDialog.vue'
import ConsentNotice from '@/components/base/ConsentNotice.vue'
import BatchSeal from '@/components/tutor/BatchSeal.vue'
import { ApiError, apiGet, apiPost } from '@/lib/api.js'
import { formatarDataAproximada } from '@/lib/masks.js'

/**
 * T09 — lançar histórico pregresso não verificado (RF29).
 *
 * A tela inteira existe para tornar visível uma consequência antes que ela
 * seja irreversível. A pré-visualização não é conveniência: mostrar o selo
 * hachurado, cinza, com "não informado" onde o tutor não soube dizer, é a
 * forma mais honesta de comunicar o que a marca de não verificado significa
 * — mais honesta do que qualquer frase sobre ela (RN24, §5.2 do briefing).
 */
const route = useRoute()
const router = useRouter()

const opcoes = ref(null)
const carregando = ref(true)
const erro = ref('')

const form = ref({
  imunobiologico: '',
  data: '',
  local_aplicacao: '',
  fabricante: '',
  lote: '',
})

const errosDeCampo = ref({})
const confirmando = ref(false)
const enviando = ref(false)
const erroDeEnvio = ref('')

async function carregar() {
  carregando.value = true
  erro.value = ''

  try {
    opcoes.value = await apiGet(`/api/animais/${route.params.codigo}/pregresso`)
  } catch (excecao) {
    erro.value = excecao.message
  } finally {
    carregando.value = false
  }
}

onMounted(carregar)
watch(() => route.params.codigo, carregar)

const animal = computed(() => opcoes.value?.animal ?? null)

/**
 * "Não sei informar" é a última opção, e não a primeira: a lista existe para
 * ser lida antes de ser desistida. O valor vazio é o que o servidor entende
 * como ausência de vacina identificada.
 */
const opcoesDeVacina = computed(() => [
  ...(opcoes.value?.imunobiologicos ?? []).map((item) => ({
    value: item.chave,
    label: item.nome,
  })),
  { value: 'nao-sei', label: 'Não sei informar' },
])

// Pré-visualização ao vivo ------------------------------------------------

const nomeDaVacina = computed(() => {
  const escolhida = (opcoes.value?.imunobiologicos ?? [])
    .find((item) => item.chave === form.value.imunobiologico)

  return escolhida?.nome ?? 'Vacina não identificada'
})

/**
 * A data que o tutor está escrevendo, no formato ISO que o selo consome. O
 * campo aceita `aaaa` e `mm/aaaa`, e enquanto não formar nenhum dos dois a
 * pré-visualização mostra "sem data" — que é, afinal, o que ainda se sabe.
 */
const dataPrevista = computed(() => {
  const escrito = form.value.data.trim()
  const partes = /^(?:(0?[1-9]|1[0-2])\/)?((?:19|20)\d{2})$/.exec(escrito)

  if (partes === null) return null

  return `${partes[2]}-${String(partes[1] ?? 1).padStart(2, '0')}-01`
})

function preenchidoOuNulo(valor) {
  const limpo = valor.trim()

  return limpo === '' ? null : limpo
}

// A barra entra sozinha: quem digita "042024" no teclado numérico do celular
// não tem a tecla dela à mão, e a pré-visualização depende do formato para
// mostrar a data no selo.
function aoDigitarData(valor) {
  form.value.data = formatarDataAproximada(valor)
}

/**
 * O objeto tem exatamente a forma que a carteira devolve para uma aplicação
 * já gravada — é o mesmo `BatchSeal`, com os mesmos campos, e nenhuma
 * maquiagem de pré-visualização. O que o tutor vê aqui é o que ele verá lá.
 */
const aplicacaoPrevista = computed(() => ({
  rotulo: nomeDaVacina.value,
  data: dataPrevista.value,
  data_aproximada: true,
  origem: 'pregresso',
  fabricante: preenchidoOuNulo(form.value.fabricante),
  lote: preenchidoOuNulo(form.value.lote),
  validade: null,
  via_administracao: null,
  validade_expirada_confirmada: false,
  aplicador: null,
  lancado_por: opcoes.value?.lancado_por ?? null,
}))

// RF29 — sem vacina e sem data o registro não afirma fato algum. A mesma
// regra do servidor, dita aqui para que o botão não convide ao erro.
const identificouAlgo = computed(
  () => (form.value.imunobiologico !== '' && form.value.imunobiologico !== 'nao-sei')
    || form.value.data.trim() !== ''
)

const semDataParaCalculo = computed(() => dataPrevista.value === null)

// Envio -------------------------------------------------------------------

function abrirConfirmacao() {
  errosDeCampo.value = {}
  erroDeEnvio.value = ''
  confirmando.value = true
}

async function lancar() {
  enviando.value = true
  erroDeEnvio.value = ''

  try {
    const { vacinacao } = await apiPost(`/api/animais/${route.params.codigo}/pregresso`, {
      imunobiologico: form.value.imunobiologico === 'nao-sei'
        ? null
        : preenchidoOuNulo(form.value.imunobiologico),
      data: preenchidoOuNulo(form.value.data),
      local_aplicacao: preenchidoOuNulo(form.value.local_aplicacao),
      fabricante: preenchidoOuNulo(form.value.fabricante),
      lote: preenchidoOuNulo(form.value.lote),
      // RF29a — o aceite da permanência da marca, que o diálogo acaba de
      // explicar. Sem ele o servidor recusa o lançamento.
      ciente_nao_verificado: true,
    })

    // Sucesso → T05, com o novo registro destacado.
    router.push({
      path: `/animais/${route.params.codigo}/carteira`,
      query: { novo: vacinacao.id },
    })
  } catch (excecao) {
    confirmando.value = false

    if (excecao instanceof ApiError && excecao.status === 422) {
      errosDeCampo.value = excecao.errors
      erroDeEnvio.value = excecao.message
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
</script>

<template>
  <!--
    `amplo` mais a coluna centrada abaixo, como em T01: o limite de 880 px da
    moldura, alinhado à esquerda, deixaria o conteúdo fora do centro da área de
    leitura em telas largas.
  -->
  <TutorShell amplo>
    <div class="pregresso">
      <RouterLink :to="`/animais/${route.params.codigo}/carteira`" class="pregresso__voltar">
        <ChevronLeft :size="20" :stroke-width="1.75" />
        Histórico pregresso
      </RouterLink>

      <div v-if="carregando" class="pregresso__conteudo" aria-busy="true" aria-live="polite">
        <span class="visually-hidden">Carregando o formulário de histórico pregresso.</span>
        <div class="esqueleto esqueleto--titulo" />
        <div class="esqueleto esqueleto--tarja" />
        <div class="esqueleto esqueleto--bloco" />
      </div>

      <div v-else-if="erro" class="aviso aviso--erro" role="alert">
        <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
        <div>
          <p class="aviso__titulo">Não conseguimos abrir este formulário.</p>
          <p class="aviso__texto">{{ erro }}</p>
          <button type="button" class="botao botao--secundario" @click="carregar">
            Tentar novamente
          </button>
        </div>
      </div>

      <div v-else class="pregresso__conteudo">
        <div>
          <p class="pregresso__sobrelinha">{{ animal.nome }}</p>
          <h1 class="pregresso__titulo">Lançar uma vacina antiga</h1>
        </div>

        <ConsentNotice :icone="CircleHelp">
          <p>
            Use esta tela para guardar uma aplicação feita antes de {{ animal.nome }} estar no
            Imunia — da carteirinha de papel ou de uma campanha pública.
          </p>
          <p>
            O registro entra marcado como <strong>não verificado</strong>, porque quem informou
            foi você, e não um veterinário. Preencha só o que você tiver certeza; o que faltar
            fica como "não informado".
          </p>
        </ConsentNotice>

        <div class="pregresso__colunas">
          <form class="pregresso__form" novalidate @submit.prevent="abrirConfirmacao">
            <div class="cartao pregresso__campos">
              <AppSelect
                id="pregresso-vacina"
                v-model="form.imunobiologico"
                label="Vacina"
                placeholder="Selecione"
                :options="opcoesDeVacina"
                :error="primeiroErro('imunobiologico')"
                class="pregresso__campo-largo"
              />
              <p v-if="!primeiroErro('imunobiologico')" class="pregresso__ajuda pregresso__campo-largo">
                Se não souber o nome, escolha "não sei informar".
              </p>

              <AppInput
                id="pregresso-data"
                :model-value="form.data"
                label="Data aproximada"
                placeholder="aaaa ou mm/aaaa"
                inputmode="numeric"
                mono
                hint="Só o ano já serve. Se lembrar o mês, escreva mm/aaaa."
                :error="primeiroErro('data')"
                @update:model-value="aoDigitarData"
              />

              <AppInput
                id="pregresso-local"
                v-model="form.local_aplicacao"
                label="Onde foi aplicada"
                placeholder="campanha pública, clínica antiga…"
                :error="primeiroErro('local_aplicacao')"
              />

              <AppInput
                id="pregresso-fabricante"
                v-model="form.fabricante"
                label="Fabricante"
                placeholder="deixe em branco se não souber"
                :error="primeiroErro('fabricante')"
              />

              <AppInput
                id="pregresso-lote"
                v-model="form.lote"
                label="Lote"
                placeholder="como está no selo da carteirinha"
                mono
                :error="primeiroErro('lote')"
              />
            </div>

            <!-- Em celular a pré-visualização fica acima do botão: é a última
                 coisa a ser vista antes da decisão. -->
            <section class="cartao pregresso__previa pregresso__previa--empilhada">
              <h2 class="pregresso__previa-rotulo">Como vai aparecer na carteira</h2>
              <BatchSeal :aplicacao="aplicacaoPrevista" previsto />

              <div v-if="semDataParaCalculo" class="pregresso__nota">
                <CircleHelp :size="20" :stroke-width="1.75" class="pregresso__nota-icone" />
                <p>
                  Sem a data, o Imunia não consegue calcular a próxima dose. O registro serve
                  como lembrança do que já foi aplicado.
                </p>
              </div>
              <p v-else class="pregresso__ajuda">
                Esta é a aparência final do registro: hachurado e em cinza, com os campos vazios
                marcados como "não informado".
              </p>
            </section>

            <p v-if="erroDeEnvio" class="aviso aviso--erro pregresso__erro-envio" role="alert">
              <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
              <span>{{ erroDeEnvio }}</span>
            </p>

            <div class="pregresso__acao">
              <AppButton type="submit" :disabled="!identificouAlgo">
                Lançar registro não verificado
              </AppButton>
              <p v-if="!identificouAlgo" class="pregresso__ajuda">
                Informe ao menos a vacina ou a data para lançar o registro.
              </p>
            </div>
          </form>

          <!-- A partir de `lg` a pré-visualização acompanha o formulário de
               lado, e a cópia empilhada some. -->
          <aside class="cartao pregresso__previa pregresso__previa--lado">
            <h2 class="pregresso__previa-rotulo">Como vai aparecer na carteira</h2>
            <BatchSeal :aplicacao="aplicacaoPrevista" previsto />

            <div v-if="semDataParaCalculo" class="pregresso__nota">
              <CircleHelp :size="20" :stroke-width="1.75" class="pregresso__nota-icone" />
              <p>
                Sem a data, o Imunia não consegue calcular a próxima dose. O registro serve como
                lembrança do que já foi aplicado.
              </p>
            </div>
            <p v-else class="pregresso__ajuda">A pré-visualização muda a cada campo preenchido.</p>
          </aside>
        </div>
      </div>

      <ConfirmDialog
        :aberto="confirmando"
        titulo="Lançar registro não verificado"
        :acontece="[
          `O registro entra na carteira de ${animal?.nome ?? 'seu animal'} marcado como não verificado, permanentemente.`,
          'Ele aparece na carteira e no histórico com hachura e com o seu nome como quem informou.',
        ]"
        :nao-acontece="[
          'Esta marca não pode ser removida por ninguém, nem por um veterinário.',
          'O registro não conta como comprovação para viagem, hospedagem ou exigência legal.',
          `Nada do que já está na carteira de ${animal?.nome ?? 'seu animal'} é alterado.`,
        ]"
        rotulo-confirmar="Lançar registro não verificado"
        :carregando="enviando"
        @confirmar="lancar"
        @cancelar="confirmando = false"
      />
    </div>
  </TutorShell>
</template>

<style scoped>
.pregresso {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  width: 100%;
  max-width: 880px;
  margin: 0 auto;
}

.pregresso__voltar {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  color: var(--ink-muted);
  font-size: 14px;
  font-weight: 600;
}

.pregresso__voltar:hover {
  color: var(--ink);
}

.pregresso__conteudo {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.pregresso__sobrelinha {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--brand-bright);
}

.pregresso__titulo {
  margin: var(--space-1) 0 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  color: var(--ink);
}

.pregresso__colunas {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.pregresso__form {
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

.pregresso__campos {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.pregresso__ajuda {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
}

/* A ajuda do seletor pertence ao campo acima dela, e não à distância padrão
   entre campos. */
.pregresso__campos > .pregresso__ajuda {
  margin-top: calc(var(--space-4) * -1 + 6px);
}

.pregresso__previa {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}

.pregresso__previa-rotulo {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

.pregresso__previa--lado {
  display: none;
}

.pregresso__nota {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  padding: var(--space-3);
  background: var(--surface-sunken);
  border-radius: var(--radius-sm);
}

.pregresso__nota-icone {
  flex: none;
  margin-top: 2px;
  color: var(--ink-muted);
}

.pregresso__nota p {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);
}

.pregresso__acao {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.pregresso__acao :deep(.app-button) {
  width: 100%;
}

.pregresso__erro-envio {
  align-items: center;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);
}

/* Botões e avisos — mesmo vocabulário das demais telas do tutor. ---------- */

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

/* Esqueleto de carregamento ------------------------------------------------ */

.esqueleto {
  border-radius: var(--radius-xs);
  background: var(--surface-sunken);
  animation: pulsar 1.2s ease-in-out infinite;
}

.esqueleto--titulo {
  height: 34px;
  width: 55%;
}

.esqueleto--tarja {
  height: 96px;
  border-radius: var(--radius-md);
}

.esqueleto--bloco {
  height: 320px;
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

/* Larguras derivadas -------------------------------------------------------- */

@media (min-width: 768px) {
  .pregresso__campos {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-4);
  }

  .pregresso__campo-largo {
    grid-column: 1 / -1;
  }

  .pregresso__campos > .pregresso__ajuda {
    margin-top: calc(var(--space-4) * -1 + 6px);
  }

  .pregresso__acao {
    align-items: flex-start;
  }

  .pregresso__acao :deep(.app-button) {
    width: auto;
  }
}

@media (min-width: 1024px) {
  .pregresso__titulo {
    font-size: 36px;
    line-height: 40px;
    letter-spacing: -.02em;
  }

  .pregresso__colunas {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 360px;
    gap: var(--space-6);
    align-items: flex-start;
  }

  .pregresso__previa--empilhada {
    display: none;
  }

  .pregresso__previa--lado {
    display: flex;
    position: sticky;
    top: var(--space-8);
  }
}
</style>
