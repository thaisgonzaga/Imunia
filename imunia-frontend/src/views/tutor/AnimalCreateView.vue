<script setup>
import { computed, onUnmounted, ref } from 'vue'
import { useRouter } from 'vue-router'
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
import AnimalCard from '@/components/tutor/AnimalCard.vue'
import AppButton from '@/components/base/AppButton.vue'
import AppInput from '@/components/base/AppInput.vue'
import AppSelect from '@/components/base/AppSelect.vue'
import ConsentNotice from '@/components/base/ConsentNotice.vue'
import ProvenanceChip from '@/components/tutor/ProvenanceChip.vue'
import { ApiError, apiPost, apiUpload } from '@/lib/api.js'
import { formatarDataAproximada } from '@/lib/masks.js'

/**
 * T03 — cadastrar animal (RF16, RF17).
 *
 * A tela existe para que o tutor identifique o seu animal, e para deixar claro
 * — sem constrangê-lo — que a caracterização clínica cabe ao veterinário
 * (RF19, RN18). Daí o desenho: os campos que ele preenche vêm primeiro e sem
 * ressalva alguma; o que ele não preenche é explicado depois, como divisão de
 * trabalho, e nunca como campo bloqueado ou permissão que lhe falta.
 */
const router = useRouter()

const form = ref({
  nome: '',
  especie: '',
  sexo: '',
  nascimento: '',
})

const errosDeCampo = ref({})
const erroDeEnvio = ref('')
const enviando = ref(false)

/**
 * O cadastro possivelmente equivalente devolvido pelo servidor (RF20a). Sua
 * presença é o que troca o botão de "Cadastrar" para o par "ver o que já
 * existe" / "cadastrar mesmo assim".
 */
const duplicado = ref(null)

// Fotografia --------------------------------------------------------------

/**
 * Os mesmos limites que o servidor aplica (RN20). Repetidos aqui não para
 * substituí-lo — quem garante é ele —, mas porque o briefing pede que sejam
 * explicitados **antes** do envio: descobrir que a foto era grande demais
 * depois de esperar o envio dela é o pior momento possível para saber disso.
 */
const FORMATOS_DA_FOTO = ['image/jpeg', 'image/png', 'image/webp']
const TAMANHO_MAXIMO_DA_FOTO_MB = 5

const arquivoDaFoto = ref(null)
const previaDaFoto = ref('')
const erroDaFoto = ref('')
const campoDeArquivo = ref(null)

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
}

function removerFoto() {
  descartarPrevia()
  arquivoDaFoto.value = null
  erroDaFoto.value = ''
  limparCampoDeArquivo()
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

// Pré-visualização do que está sendo declarado ----------------------------

const icone = computed(() => (form.value.especie === 'gato' ? Cat : Dog))

const podeEnviar = computed(() => form.value.nome.trim() !== '' && form.value.especie !== '')

const declarouOpcional = computed(
  () => form.value.sexo !== '' || form.value.nascimento.trim() !== '',
)

// Envio --------------------------------------------------------------------

/**
 * O animal já criado quando o envio da fotografia falhou. É o único estado em
 * que a tela permanece aberta depois do sucesso: o cadastro existe, e insistir
 * na foto ou seguir sem ela é decisão do tutor, não do sistema.
 */
const cadastradoSemFoto = ref(null)

function preenchidoOuNulo(valor) {
  const limpo = valor.trim()

  return limpo === '' ? null : limpo
}

// O mesmo formato de T09, e a mesma máscara: a barra entra sozinha quando o
// que foi escrito já não pode ser um ano.
function aoDigitarNascimento(valor) {
  form.value.nascimento = formatarDataAproximada(valor)
}

async function cadastrar({ apesarDaDuplicidade = false } = {}) {
  enviando.value = true
  erroDeEnvio.value = ''
  errosDeCampo.value = {}

  try {
    const animal = await apiPost('/api/animais', {
      nome: form.value.nome.trim(),
      especie: form.value.especie,
      sexo: preenchidoOuNulo(form.value.sexo),
      nascimento: preenchidoOuNulo(form.value.nascimento),
      ...(apesarDaDuplicidade ? { confirmar_duplicidade: true } : {}),
    })

    duplicado.value = null

    if (arquivoDaFoto.value === null) {
      concluir(animal)

      return
    }

    await enviarFoto(animal)
  } catch (excecao) {
    tratarFalha(excecao)
  } finally {
    enviando.value = false
  }
}

/**
 * RF16b — a foto sobe depois, por rota própria, e a falha dela não desfaz o
 * cadastro: o animal já tem código e já aparece na relação do tutor.
 */
async function enviarFoto(animal) {
  try {
    await apiUpload(`/api/animais/${animal.codigo}/foto`, arquivoDaFoto.value).promessa

    concluir(animal)
  } catch (excecao) {
    cadastradoSemFoto.value = animal
    erroDaFoto.value = excecao.message
  }
}

async function reenviarFoto() {
  enviando.value = true
  erroDaFoto.value = ''

  try {
    await apiUpload(
      `/api/animais/${cadastradoSemFoto.value.codigo}/foto`,
      arquivoDaFoto.value,
    ).promessa

    concluir(cadastradoSemFoto.value)
  } catch (excecao) {
    erroDaFoto.value = excecao.message
  } finally {
    enviando.value = false
  }
}

function tratarFalha(excecao) {
  if (!(excecao instanceof ApiError)) {
    erroDeEnvio.value = excecao.message

    return
  }

  // RF20a — o alerta identifica o cadastro possivelmente equivalente e precede
  // a confirmação. Não é erro de preenchimento, e por isso não pinta campo
  // algum de vermelho: é uma pergunta que só o tutor sabe responder.
  if (excecao.status === 409) {
    duplicado.value = excecao.data.duplicado

    return
  }

  if (excecao.status === 422) {
    errosDeCampo.value = excecao.errors
    erroDeEnvio.value = excecao.message

    return
  }

  erroDeEnvio.value = excecao.message
}

// Sucesso → T04 do animal recém-criado, com o código único em destaque.
function concluir(animal) {
  router.push({ path: `/animais/${animal.codigo}`, query: { novo: '1' } })
}

function primeiroErro(campo) {
  return errosDeCampo.value[campo]?.[0] ?? ''
}
</script>

<template>
  <!--
    Como em T01 e T02, `amplo` para que a coluna se centre na área de conteúdo
    inteira: o formulário traz a própria largura de leitura, e o limite de
    880 px da moldura, alinhado à esquerda, o deixaria fora do centro em telas
    largas.
  -->
  <TutorShell amplo>
    <div class="cadastro">
      <RouterLink to="/animais" class="cadastro__voltar">
        <ChevronLeft :size="20" :stroke-width="1.75" />
        Meus animais
      </RouterLink>

      <div>
        <p class="cadastro__sobrelinha">Titularidade</p>
        <h1 class="cadastro__titulo">Cadastrar animal</h1>
      </div>

      <form class="cadastro__form" novalidate @submit.prevent="cadastrar()">
        <!-- Fotografia — opcional, e dita opcional antes de qualquer limite. -->
        <section class="cartao cadastro__foto">
          <div class="cadastro__foto-previa">
            <img v-if="previaDaFoto" :src="previaDaFoto" alt="Pré-visualização da foto escolhida" />
            <component :is="icone" v-else :size="32" :stroke-width="1.5" />
          </div>

          <div class="cadastro__foto-corpo">
            <h2 class="cartao__rotulo">Fotografia</h2>
            <p class="cadastro__ajuda">
              Opcional. JPG, PNG ou WebP, até {{ TAMANHO_MAXIMO_DA_FOTO_MB }} MB. Você pode trocar
              a foto quando quiser.
            </p>

            <input
              id="cadastro-foto"
              ref="campoDeArquivo"
              type="file"
              class="visually-hidden"
              :accept="FORMATOS_DA_FOTO.join(',')"
              @change="escolherFoto"
            >

            <div class="cadastro__foto-acoes">
              <label for="cadastro-foto" class="botao botao--secundario">
                <Camera :size="18" :stroke-width="1.75" />
                {{ arquivoDaFoto ? 'Trocar foto' : 'Escolher foto' }}
              </label>
              <button
                v-if="arquivoDaFoto"
                type="button"
                class="botao botao--secundario"
                @click="removerFoto"
              >
                <X :size="18" :stroke-width="1.75" />
                Remover
              </button>
            </div>

            <p v-if="erroDaFoto && !cadastradoSemFoto" class="cadastro__erro-campo">
              <TriangleAlert :size="16" :stroke-width="1.75" />
              <span>{{ erroDaFoto }}</span>
            </p>
          </div>
        </section>

        <div class="cartao cadastro__campos">
          <AppInput
            id="cadastro-nome"
            v-model="form.nome"
            label="Nome"
            placeholder="como você chama seu animal"
            :error="primeiroErro('nome')"
          />

          <!-- O seletor de espécie é o elemento mais destacado da tela: é a
               única escolha que deixa de ser reversível depois do primeiro
               registro clínico (RF19d). -->
          <fieldset class="cadastro__especie">
            <legend class="app-field__label">Espécie</legend>
            <div class="cadastro__especie-cartoes">
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
            <p v-if="primeiroErro('especie')" class="cadastro__erro-campo">
              <TriangleAlert :size="16" :stroke-width="1.75" />
              <span>{{ primeiroErro('especie') }}</span>
            </p>
          </fieldset>

          <AppSelect
            id="cadastro-sexo"
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
            id="cadastro-nascimento"
            :model-value="form.nascimento"
            label="Nascimento estimado"
            placeholder="aaaa ou mm/aaaa"
            inputmode="numeric"
            mono
            hint="Opcional. Só o ano já serve. Se lembrar o mês, escreva mm/aaaa."
            :error="primeiroErro('nascimento')"
            @update:model-value="aoDigitarNascimento"
          />

          <!-- RN14 — o que o tutor declara viaja marcado como declaração
               dele, e é assim que vai aparecer para o veterinário. -->
          <div v-if="declarouOpcional" class="cadastro__declarado">
            <ProvenanceChip variante="owner-declared" texto="Declarado por você" />
            <p class="cadastro__ajuda">
              O veterinário confirma ou corrige na primeira consulta.
            </p>
          </div>
        </div>

        <ConsentNotice :icone="Stethoscope">
          <p>
            Raça, peso, situação reprodutiva e micro-chip são preenchidos pelo veterinário na
            primeira consulta. Até lá, o cadastro fica marcado como <strong>preliminar</strong>.
          </p>
          <p>
            Não é uma pendência sua: é o que faz a ficha do seu animal valer como documento, porque
            quem responde pelo dado clínico é quem tem responsabilidade técnica sobre ele.
          </p>
        </ConsentNotice>

        <!-- RF20a — o alerta de duplicidade precede a confirmação e identifica
             o cadastro possivelmente equivalente. -->
        <section v-if="duplicado" class="cartao cadastro__duplicidade" role="alert">
          <h2 class="cadastro__duplicidade-titulo">Você já tem um cadastro parecido com este</h2>
          <p class="cadastro__ajuda">
            Se for o mesmo animal, use o cadastro que já existe: ele já tem código único e pode já
            ter vacinas registradas.
          </p>

          <AnimalCard :animal="duplicado" class="cadastro__duplicidade-cartao" />

          <div class="cadastro__duplicidade-acoes">
            <RouterLink :to="`/animais/${duplicado.codigo}`" class="botao botao--secundario">
              Ver o cadastro existente
            </RouterLink>
            <AppButton
              variant="secondary"
              :loading="enviando"
              @click="cadastrar({ apesarDaDuplicidade: true })"
            >
              Cadastrar mesmo assim
            </AppButton>
          </div>
        </section>

        <!-- O cadastro existe; só a foto não subiu. O texto diz as duas coisas
             na ordem em que importam. -->
        <section v-if="cadastradoSemFoto" class="cartao cadastro__foto-falhou" role="alert">
          <h2 class="cadastro__duplicidade-titulo">
            {{ cadastradoSemFoto.nome }} foi cadastrado, mas a foto não subiu
          </h2>
          <p class="cadastro__ajuda">{{ erroDaFoto }}</p>

          <div class="cadastro__duplicidade-acoes">
            <AppButton :loading="enviando" @click="reenviarFoto">Tentar enviar a foto</AppButton>
            <AppButton variant="secondary" @click="concluir(cadastradoSemFoto)">
              Continuar sem a foto
            </AppButton>
          </div>
        </section>

        <p v-if="erroDeEnvio" class="aviso aviso--erro cadastro__erro-envio" role="alert">
          <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
          <span>{{ erroDeEnvio }}</span>
        </p>

        <div v-if="!duplicado && !cadastradoSemFoto" class="cadastro__acao">
          <AppButton type="submit" :disabled="!podeEnviar" :loading="enviando">
            Cadastrar animal
          </AppButton>
          <p v-if="!podeEnviar" class="cadastro__ajuda">
            Informe o nome e escolha a espécie para cadastrar.
          </p>
        </div>
      </form>
    </div>
  </TutorShell>
</template>

<style scoped>
.cadastro {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  width: 100%;
  max-width: 880px;
  margin: 0 auto;
}

.cadastro__voltar {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  color: var(--ink-muted);
  font-size: 14px;
  font-weight: 600;
}

.cadastro__voltar:hover {
  color: var(--ink);
}

.cadastro__sobrelinha {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--brand-bright);
}

.cadastro__titulo {
  margin: var(--space-1) 0 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  color: var(--ink);
}

.cadastro__form {
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

.cadastro__ajuda {
  margin: var(--space-1) 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

/* Fotografia ---------------------------------------------------------------- */

.cadastro__foto {
  display: flex;
  align-items: flex-start;
  gap: var(--space-4);
}

/* O recorte é circular porque é assim que a foto aparece no perfil (T04) e no
   cartão (T02): o que o tutor vê aqui é o enquadramento final. */
.cadastro__foto-previa {
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

.cadastro__foto-previa img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.cadastro__foto-corpo {
  flex: 1;
  min-width: 0;
}

.cadastro__foto-acoes {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  margin: var(--space-3) 0 0;
}

/* Campos --------------------------------------------------------------------- */

.cadastro__campos {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.cadastro__especie {
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

.cadastro__especie-cartoes {
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

.cadastro__erro-campo {
  display: flex;
  align-items: flex-start;
  gap: var(--space-2);
  margin: 6px 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--status-late);
}

.cadastro__erro-campo svg {
  flex: none;
  margin-top: 2px;
}

.cadastro__declarado {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: var(--space-2);
}

.cadastro__declarado .cadastro__ajuda {
  margin: 0;
}

/* Duplicidade e falha da foto ----------------------------------------------- */

.cadastro__duplicidade,
.cadastro__foto-falhou {
  border-color: var(--consent);
  background: var(--consent-wash);
}

.cadastro__duplicidade-titulo {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.cadastro__duplicidade-cartao {
  margin: var(--space-3) 0 0;
}

.cadastro__duplicidade-acoes {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  margin: var(--space-4) 0 0;
}

/* Ação e avisos — mesmo vocabulário das demais telas do tutor. -------------- */

.cadastro__acao {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.cadastro__acao :deep(.app-button) {
  width: 100%;
}

.cadastro__acao .cadastro__ajuda {
  margin: 0;
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

.cadastro__erro-envio {
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

/* Larguras derivadas --------------------------------------------------------- */

@media (min-width: 768px) {
  .cadastro__duplicidade-acoes {
    flex-direction: row;
  }

  .cadastro__acao {
    align-items: flex-start;
  }

  .cadastro__acao :deep(.app-button) {
    width: auto;
  }
}

@media (min-width: 1024px) {
  .cadastro__titulo {
    font-size: 36px;
    line-height: 40px;
    letter-spacing: -.02em;
  }
}
</style>
