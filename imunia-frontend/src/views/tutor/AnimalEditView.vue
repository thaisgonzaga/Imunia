<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  Camera,
  Cat,
  ChevronLeft,
  Dog,
  Stethoscope,
  TriangleAlert,
  X,
} from '@lucide/vue'
import TutorShell from '@/components/tutor/TutorShell.vue'
import AppButton from '@/components/base/AppButton.vue'
import AppInput from '@/components/base/AppInput.vue'
import AppSelect from '@/components/base/AppSelect.vue'
import ConsentNotice from '@/components/base/ConsentNotice.vue'
import ProvenanceChip from '@/components/tutor/ProvenanceChip.vue'
import { ApiError, apiDelete, apiGet, apiPatch, apiUpload } from '@/lib/api.js'
import { formatarDataAproximada } from '@/lib/masks.js'

/**
 * T04a — editar a identificação do animal (RF16, RN20).
 *
 * A contrapartida de T03, e o mesmo desenho: primeiro o que é do tutor, sem
 * ressalva alguma; depois, explicado como divisão de trabalho, o que deixou de
 * ser dele. Duas coisas mudam entre cadastrar e corrigir, e as duas são ditas
 * na tela em vez de descobertas no envio:
 *
 * - a espécie trava quando já há registro clínico (RF19d);
 * - sexo e nascimento passam ao veterinário quando ele caracteriza o animal
 *   (RF19b, RN18) — até lá são declaração do tutor, e ele os corrige aqui.
 *
 * A fotografia é do tutor a qualquer tempo (RN20): trocar e retirar são a mesma
 * permissão, e por isso as duas ações moram lado a lado.
 */
const route = useRoute()
const router = useRouter()

const codigo = route.params.codigo

const animal = ref(null)
const carregando = ref(true)
const erro = ref('')

const form = ref({
  nome: '',
  especie: '',
  sexo: '',
  nascimento: '',
})

const errosDeCampo = ref({})
const erroDeEnvio = ref('')
const salvando = ref(false)

async function carregar() {
  carregando.value = true
  erro.value = ''

  try {
    animal.value = await apiGet(`/api/animais/${codigo}`)

    form.value = {
      nome: animal.value.nome,
      especie: animal.value.especie,
      sexo: animal.value.sexo ?? '',
      nascimento: animal.value.nascimento ?? '',
    }
  } catch (excecao) {
    erro.value = excecao.message
  } finally {
    carregando.value = false
  }
}

onMounted(carregar)

const icone = computed(() => (form.value.especie === 'gato' ? Cat : Dog))

// RN17 — enquanto o cadastro é preliminar, o declarado é do tutor.
const declaradoEDoTutor = computed(() => animal.value?.preliminar === true)

// RF19d — a espécie não muda depois do primeiro registro clínico.
const especieAlteravel = computed(() => animal.value?.especie_alteravel !== false)

const podeSalvar = computed(() => form.value.nome.trim() !== '' && form.value.especie !== '')

const declarouOpcional = computed(
  () => form.value.sexo !== '' || form.value.nascimento.trim() !== '',
)

// Fotografia --------------------------------------------------------------

/**
 * Os mesmos limites que o servidor aplica (RN20), repetidos aqui pelo mesmo
 * motivo de T03: descobrir que a foto era grande demais depois de esperar o
 * envio dela é o pior momento possível para saber disso.
 */
const FORMATOS_DA_FOTO = ['image/jpeg', 'image/png', 'image/webp']
const TAMANHO_MAXIMO_DA_FOTO_MB = 5

const arquivoDaFoto = ref(null)
const previaDaFoto = ref('')
const fotoMarcadaParaRemover = ref(false)
const erroDaFoto = ref('')
const campoDeArquivo = ref(null)

// O que o círculo mostra agora: a escolha nova, ou a que está gravada, ou nada
// — quando o tutor pediu para retirar a que havia.
const fotoExibida = computed(() => {
  if (previaDaFoto.value) return previaDaFoto.value
  if (fotoMarcadaParaRemover.value) return ''

  return animal.value?.foto_url ?? ''
})

const temFotoParaRemover = computed(
  () => previaDaFoto.value !== '' || (!fotoMarcadaParaRemover.value && !!animal.value?.foto_url),
)

function escolherFoto(evento) {
  const escolhido = evento.target.files?.[0]
  if (!escolhido) return

  erroDaFoto.value = ''

  if (!FORMATOS_DA_FOTO.includes(escolhido.type)) {
    erroDaFoto.value = 'A foto precisa ser JPG, PNG ou WebP.'
    limparCampoDeArquivo()

    return
  }

  if (escolhido.size > TAMANHO_MAXIMO_DA_FOTO_MB * 1024 * 1024) {
    erroDaFoto.value = `A foto precisa ter até ${TAMANHO_MAXIMO_DA_FOTO_MB} MB.`
    limparCampoDeArquivo()

    return
  }

  descartarPrevia()
  arquivoDaFoto.value = escolhido
  previaDaFoto.value = URL.createObjectURL(escolhido)

  // Escolher uma foto nova desfaz o pedido de retirar a antiga: substituir e
  // remover são a mesma decisão, e a última é a que vale.
  fotoMarcadaParaRemover.value = false
}

/**
 * Retirar a foto não acontece aqui: é o salvamento que a apaga, como acontece
 * com qualquer outro campo desta tela. Até lá, o que há é um pedido — e
 * "Manter a foto" o desfaz.
 */
function removerFoto() {
  if (arquivoDaFoto.value !== null) {
    descartarPrevia()
    arquivoDaFoto.value = null
    limparCampoDeArquivo()

    return
  }

  fotoMarcadaParaRemover.value = true
  erroDaFoto.value = ''
}

function manterFoto() {
  fotoMarcadaParaRemover.value = false
}

function limparCampoDeArquivo() {
  // Sem isto, escolher o mesmo arquivo duas vezes seguidas não dispara evento
  // algum — e a segunda tentativa, depois de uma recusa, é exatamente o caso.
  if (campoDeArquivo.value) campoDeArquivo.value.value = ''
}

function descartarPrevia() {
  if (previaDaFoto.value) URL.revokeObjectURL(previaDaFoto.value)
  previaDaFoto.value = ''
}

onUnmounted(descartarPrevia)

// Envio --------------------------------------------------------------------

/**
 * O caso em que os campos já foram gravados e só a foto não subiu. É o mesmo
 * estado de T03, pela mesma razão: o que foi salvo está salvo, e insistir na
 * foto ou seguir sem ela é decisão do tutor.
 */
const salvoSemFoto = ref(false)

// O mesmo formato de T03 e T09: a barra entra sozinha quando o que foi escrito
// já não pode ser um ano.
function aoDigitarNascimento(valor) {
  form.value.nascimento = formatarDataAproximada(valor)
}

function preenchidoOuNulo(valor) {
  const limpo = valor.trim()

  return limpo === '' ? null : limpo
}

async function salvar() {
  salvando.value = true
  erroDeEnvio.value = ''
  errosDeCampo.value = {}

  try {
    // Caracterizado o animal, o bloco declarado já não é do tutor: as chaves
    // vão nulas, e nulo não é tentativa de escrita — é o que a API aceita sem
    // recusar o pedido inteiro (RF16e).
    await apiPatch(`/api/animais/${codigo}`, {
      nome: form.value.nome.trim(),
      especie: form.value.especie,
      sexo: declaradoEDoTutor.value ? preenchidoOuNulo(form.value.sexo) : null,
      nascimento: declaradoEDoTutor.value ? preenchidoOuNulo(form.value.nascimento) : null,
    })

    await aplicarFotografia()
  } catch (excecao) {
    tratarFalha(excecao)
  } finally {
    salvando.value = false
  }
}

/**
 * RF16b — a foto viaja por rota própria, depois dos campos. A falha dela não
 * desfaz o que já foi gravado, e a tela diz as duas coisas na ordem em que
 * importam.
 */
async function aplicarFotografia() {
  try {
    if (arquivoDaFoto.value !== null) {
      await apiUpload(`/api/animais/${codigo}/foto`, arquivoDaFoto.value).promessa
    } else if (fotoMarcadaParaRemover.value) {
      await apiDelete(`/api/animais/${codigo}/foto`)
    }

    concluir()
  } catch (excecao) {
    salvoSemFoto.value = true
    erroDaFoto.value = excecao.message
  }
}

async function reenviarFoto() {
  salvando.value = true
  erroDaFoto.value = ''

  try {
    await aplicarFotografia()
  } finally {
    salvando.value = false
  }
}

function tratarFalha(excecao) {
  if (!(excecao instanceof ApiError)) {
    erroDeEnvio.value = excecao.message

    return
  }

  if (excecao.status === 422) {
    errosDeCampo.value = excecao.errors
    erroDeEnvio.value = excecao.message

    return
  }

  erroDeEnvio.value = excecao.message
}

// Sucesso → T04, que confirma a alteração com o cadastro já atualizado.
function concluir() {
  router.push({ path: `/animais/${codigo}`, query: { salvo: '1' } })
}

function primeiroErro(campo) {
  return errosDeCampo.value[campo]?.[0] ?? ''
}
</script>

<template>
  <!-- Como em T03, `amplo`: o formulário traz a própria largura de leitura. -->
  <TutorShell amplo>
    <div class="edicao">
      <RouterLink :to="`/animais/${codigo}`" class="edicao__voltar">
        <ChevronLeft :size="20" :stroke-width="1.75" />
        Voltar ao perfil
      </RouterLink>

      <div v-if="carregando" class="edicao__esqueleto" aria-busy="true" aria-live="polite">
        <span class="visually-hidden">Carregando o cadastro do animal.</span>
        <div class="esqueleto esqueleto--titulo" />
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

      <template v-else>
        <div>
          <p class="edicao__sobrelinha">Identificação</p>
          <h1 class="edicao__titulo">Editar {{ animal.nome }}</h1>
        </div>

        <form class="edicao__form" novalidate @submit.prevent="salvar">
          <!-- RN20 — a foto é do tutor a qualquer tempo: trocar e retirar são
               a mesma permissão. -->
          <section class="cartao edicao__foto">
            <div class="edicao__foto-previa">
              <img v-if="fotoExibida" :src="fotoExibida" :alt="`Foto de ${animal.nome}`" />
              <component :is="icone" v-else :size="32" :stroke-width="1.5" />
            </div>

            <div class="edicao__foto-corpo">
              <h2 class="cartao__rotulo">Fotografia</h2>
              <p class="edicao__ajuda">
                JPG, PNG ou WebP, até {{ TAMANHO_MAXIMO_DA_FOTO_MB }} MB. A foto é sua e você pode
                trocá-la quando quiser.
              </p>

              <input
                id="edicao-foto"
                ref="campoDeArquivo"
                type="file"
                class="visually-hidden"
                :accept="FORMATOS_DA_FOTO.join(',')"
                @change="escolherFoto"
              >

              <div class="edicao__foto-acoes">
                <label for="edicao-foto" class="botao botao--secundario">
                  <Camera :size="18" :stroke-width="1.75" />
                  {{ fotoExibida ? 'Trocar foto' : 'Escolher foto' }}
                </label>
                <button
                  v-if="temFotoParaRemover"
                  type="button"
                  class="botao botao--secundario"
                  @click="removerFoto"
                >
                  <X :size="18" :stroke-width="1.75" />
                  {{ arquivoDaFoto ? 'Descartar escolha' : 'Remover foto' }}
                </button>
                <button
                  v-if="fotoMarcadaParaRemover"
                  type="button"
                  class="botao botao--secundario"
                  @click="manterFoto"
                >
                  Manter a foto atual
                </button>
              </div>

              <p v-if="fotoMarcadaParaRemover" class="edicao__ajuda edicao__ajuda--atencao">
                A foto sai do cadastro quando você salvar.
              </p>

              <p v-if="erroDaFoto && !salvoSemFoto" class="edicao__erro-campo">
                <TriangleAlert :size="16" :stroke-width="1.75" />
                <span>{{ erroDaFoto }}</span>
              </p>
            </div>
          </section>

          <div class="cartao edicao__campos">
            <AppInput
              id="edicao-nome"
              v-model="form.nome"
              label="Nome"
              placeholder="como você chama seu animal"
              :error="primeiroErro('nome')"
            />

            <fieldset class="edicao__especie" :disabled="!especieAlteravel">
              <legend class="app-field__label">Espécie</legend>
              <div class="edicao__especie-cartoes">
                <button
                  v-for="opcao in [
                    { valor: 'cao', rotulo: 'Cão', icone: Dog },
                    { valor: 'gato', rotulo: 'Gato', icone: Cat },
                  ]"
                  :key="opcao.valor"
                  type="button"
                  class="especie-cartao"
                  :class="{ 'especie-cartao--ativo': form.especie === opcao.valor }"
                  :aria-pressed="form.especie === opcao.valor"
                  @click="form.especie = opcao.valor"
                >
                  <component :is="opcao.icone" :size="32" :stroke-width="1.5" />
                  {{ opcao.rotulo }}
                </button>
              </div>

              <!-- RF19d — dito antes, como razão, e não depois, como recusa. -->
              <p v-if="!especieAlteravel" class="edicao__ajuda">
                A espécie deixa de mudar quando há registro clínico no histórico: as vacinas e o
                calendário de {{ animal.nome }} foram calculados a partir dela. Se estiver errada,
                fale com o veterinário que o atende.
              </p>

              <p v-if="primeiroErro('especie')" class="edicao__erro-campo">
                <TriangleAlert :size="16" :stroke-width="1.75" />
                <span>{{ primeiroErro('especie') }}</span>
              </p>
            </fieldset>

            <!-- RN17 — declarado pelo tutor, e dele enquanto o veterinário não
                 confirma. -->
            <template v-if="declaradoEDoTutor">
              <AppSelect
                id="edicao-sexo"
                v-model="form.sexo"
                label="Sexo"
                placeholder="Prefiro não informar"
                :options="[
                  { value: 'macho', label: 'Macho' },
                  { value: 'femea', label: 'Fêmea' },
                ]"
                :error="primeiroErro('sexo')"
              />

              <AppInput
                id="edicao-nascimento"
                :model-value="form.nascimento"
                label="Nascimento estimado"
                placeholder="aaaa ou mm/aaaa"
                inputmode="numeric"
                mono
                hint="Só o ano já serve. Se lembrar o mês, escreva mm/aaaa. Deixe em branco se não souber."
                :error="primeiroErro('nascimento')"
                @update:model-value="aoDigitarNascimento"
              />

              <div v-if="declarouOpcional" class="edicao__declarado">
                <ProvenanceChip variante="owner-declared" texto="Declarado por você" />
                <p class="edicao__ajuda">
                  O veterinário confirma ou corrige na consulta.
                </p>
              </div>
            </template>

            <!-- RF19b, RN18 — confirmado na consulta, o dado passa a ser
                 mantido por quem responde tecnicamente por ele. Mostrado em
                 leitura, e não escondido: o tutor continua precisando vê-lo. -->
            <div v-else class="edicao__confirmado">
              <ProvenanceChip variante="professional" texto="Confirmado pelo veterinário" />
              <dl class="edicao__confirmado-lista">
                <div>
                  <dt>Sexo</dt>
                  <dd>{{ { macho: 'Macho', femea: 'Fêmea' }[animal.sexo] ?? 'Não informado' }}</dd>
                </div>
                <div>
                  <dt>Nascimento</dt>
                  <dd>{{ animal.nascimento ?? 'Não informado' }}</dd>
                </div>
              </dl>
              <p class="edicao__ajuda">
                Sexo e data de nascimento foram conferidos na consulta e passam a ser mantidos pelo
                veterinário. É o que faz a ficha de {{ animal.nome }} valer como documento.
              </p>
            </div>
          </div>

          <ConsentNotice :icone="Stethoscope">
            <p>
              Raça, pelagem, peso, situação reprodutiva e micro-chip continuam com o veterinário —
              esta tela não os altera, e a sua não é a permissão que falta: é a responsabilidade
              técnica que responde por eles.
            </p>
          </ConsentNotice>

          <!-- Os campos foram gravados; só a foto não. -->
          <section v-if="salvoSemFoto" class="cartao edicao__foto-falhou" role="alert">
            <h2 class="edicao__bloco-titulo">As alterações foram salvas, mas a foto não</h2>
            <p class="edicao__ajuda">{{ erroDaFoto }}</p>

            <div class="edicao__bloco-acoes">
              <AppButton :loading="salvando" @click="reenviarFoto">Tentar de novo</AppButton>
              <AppButton variant="secondary" @click="concluir">Continuar assim mesmo</AppButton>
            </div>
          </section>

          <p v-if="erroDeEnvio" class="aviso aviso--erro edicao__erro-envio" role="alert">
            <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
            <span>{{ erroDeEnvio }}</span>
          </p>

          <div v-if="!salvoSemFoto" class="edicao__acao">
            <AppButton type="submit" :disabled="!podeSalvar" :loading="salvando">
              Salvar alterações
            </AppButton>
            <RouterLink :to="`/animais/${codigo}`" class="botao botao--secundario">
              Cancelar
            </RouterLink>
          </div>
        </form>
      </template>
    </div>
  </TutorShell>
</template>

<style scoped>
.edicao {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  width: 100%;
  max-width: 880px;
  margin: 0 auto;
}

.edicao__voltar {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  color: var(--ink-muted);
  font-size: 14px;
  font-weight: 600;
}

.edicao__voltar:hover {
  color: var(--ink);
}

.edicao__sobrelinha {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--brand-bright);
}

.edicao__titulo {
  margin: var(--space-1) 0 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  color: var(--ink);
}

.edicao__form {
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

.edicao__ajuda {
  margin: var(--space-1) 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.edicao__ajuda--atencao {
  color: var(--ink);
  font-weight: 600;
}

/* Fotografia ---------------------------------------------------------------- */

.edicao__foto {
  display: flex;
  align-items: flex-start;
  gap: var(--space-4);
}

/* Recorte circular, como no perfil (T04) e no cartão (T02): o que se vê aqui é
   o enquadramento final. */
.edicao__foto-previa {
  display: flex;
  align-items: center;
  justify-content: center;
  flex: none;
  width: 88px;
  height: 88px;
  overflow: hidden;
  border-radius: var(--radius-pill);
  background: var(--surface-sunken);
  color: var(--ink-muted);
}

.edicao__foto-previa img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.edicao__foto-corpo {
  flex: 1;
  min-width: 0;
}

.edicao__foto-acoes {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  margin: var(--space-3) 0 0;
}

/* Campos --------------------------------------------------------------------- */

.edicao__campos {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.edicao__especie {
  margin: 0;
  padding: 0;
  border: none;
}

.app-field__label {
  display: block;
  margin: 0 0 6px;
  padding: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink);
}

.edicao__especie-cartoes {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--space-3);
}

.especie-cartao {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: var(--space-2);
  min-height: 112px;
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-md);
  font-family: var(--font-body);
  font-size: 16px;
  font-weight: 600;
  color: var(--ink);
  cursor: pointer;
}

.especie-cartao:hover {
  background: var(--surface-sunken);
}

.especie-cartao--ativo,
.especie-cartao--ativo:hover {
  background: var(--consent-wash);
  border-color: var(--brand);
  color: var(--brand);
}

/* Travada (RF19d): a espécie do animal continua marcada pela borda da marca —
   é informação, e informação não esmaece —, e a outra é que recua. Sem cadeado
   e sem vermelho: não é erro do tutor. */
.edicao__especie:disabled .especie-cartao {
  cursor: default;
  border-color: var(--border-hairline);
  color: var(--ink-faint);
}

.edicao__especie:disabled .especie-cartao:hover {
  background: var(--surface-card);
}

.edicao__especie:disabled .especie-cartao--ativo,
.edicao__especie:disabled .especie-cartao--ativo:hover {
  background: var(--surface-card);
  border-color: var(--brand);
  color: var(--ink);
}

.edicao__erro-campo {
  display: flex;
  align-items: flex-start;
  gap: var(--space-2);
  margin: 6px 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--status-late);
}

.edicao__erro-campo svg {
  flex: none;
  margin-top: 2px;
}

.edicao__declarado {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: var(--space-2);
}

.edicao__declarado .edicao__ajuda {
  margin: 0;
}

/* Bloco confirmado pelo veterinário ------------------------------------------ */

.edicao__confirmado {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: var(--space-3);
  padding: var(--space-4);
  background: var(--surface-sunken);
  border-radius: var(--radius-md);
}

.edicao__confirmado-lista {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: var(--space-3);
  width: 100%;
  margin: 0;
}

.edicao__confirmado-lista dt {
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
}

.edicao__confirmado-lista dd {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  font-weight: 500;
  color: var(--ink);
}

.edicao__confirmado .edicao__ajuda {
  margin: 0;
}

/* Falha da foto -------------------------------------------------------------- */

.edicao__foto-falhou {
  border-color: var(--consent);
  background: var(--consent-wash);
}

.edicao__bloco-titulo {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.edicao__bloco-acoes {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  margin: var(--space-4) 0 0;
}

/* Ação e avisos — mesmo vocabulário das demais telas do tutor. -------------- */

.edicao__acao {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.edicao__acao :deep(.app-button) {
  width: 100%;
}

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
}

.edicao__erro-envio {
  align-items: center;
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);
}

.visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip-path: inset(50%);
  white-space: nowrap;
}

/* Esqueleto de carregamento -------------------------------------------------- */

.edicao__esqueleto {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.esqueleto {
  border-radius: var(--radius-xs);
  background: var(--surface-sunken);
  animation: pulsar 1.2s ease-in-out infinite;
}

.esqueleto--titulo {
  height: 34px;
  width: 45%;
}

.esqueleto--bloco {
  height: 140px;
}

@keyframes pulsar {
  0% { opacity: .55; }
  50% { opacity: 1; }
  100% { opacity: .55; }
}

/* Larguras derivadas --------------------------------------------------------- */

@media (min-width: 768px) {
  .edicao__bloco-acoes {
    flex-direction: row;
  }

  .edicao__acao {
    flex-direction: row;
    align-items: center;
  }

  .edicao__acao :deep(.app-button) {
    width: auto;
  }
}

@media (min-width: 1024px) {
  .edicao__titulo {
    font-size: 36px;
    line-height: 40px;
    letter-spacing: -.02em;
  }
}
</style>
