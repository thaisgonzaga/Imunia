<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { CircleCheck, KeyRound, Lock, TriangleAlert } from '@lucide/vue'
import AuthSplitPage from '@/components/auth/AuthSplitPage.vue'
import AppButton from '@/components/base/AppButton.vue'
import AppCheckbox from '@/components/base/AppCheckbox.vue'
import AppInput from '@/components/base/AppInput.vue'
import PasswordChecklist from '@/components/auth/PasswordChecklist.vue'
import { cpfValido, formatarCpf, somenteDigitos } from '@/lib/masks.js'
import { apiPost, ApiError } from '@/lib/api.js'
import { senhaForte } from '@/lib/senha.js'
import { useSessaoStore } from '@/stores/sessao.js'

const router = useRouter()
const sessao = useSessaoStore()

/**
 * Quem já está numa conta não cria uma segunda: o papel de tutor cabe na que
 * ela já tem (RN05), e é para lá que este caminho leva. A aba antiga deixada
 * aberta e a ligação guardada nos favoritos chegam aqui autenticadas, e sem
 * este desvio leriam "já existe uma conta com estes dados" estando dentro da
 * sua — o servidor recusa o mesmo envio com 409, e este é o desvio amável.
 */
onMounted(async () => {
  await sessao.carregar()

  if (!sessao.autenticado) return

  router.replace((sessao.usuario.papeis ?? []).includes('tutor') ? '/inicio' : '/conta/tutor')
})

const submitting = ref(false)
const submitError = ref('')
const contaJaExiste = ref(false)
const resultado = ref(null)
const cpfTocado = ref(false)

// O que o tutor faz sem depender de clínica alguma (RF12, RF16, RF29, RF36).
const ARGUMENTOS = [
  'Cadastro do animal em poucos campos',
  'Lembrete por e-mail antes de cada dose',
  'Você autoriza e revoga clínicas quando quiser',
]

const form = reactive({
  nome: '',
  cpf: '',
  email: '',
  password: '',
  password_confirmation: '',
  aceite_termos: false,
})

const errors = reactive({})

function limparErro(campo) {
  delete errors[campo]
}

function setCampo(campo, valor) {
  form[campo] = valor
  limparErro(campo)
}

function onCpfInput(valor) {
  form.cpf = formatarCpf(valor)
  limparErro('cpf')
}

const cpfCompleto = computed(() => somenteDigitos(form.cpf).length === 11)
const cpfEhValido = computed(() => cpfCompleto.value && cpfValido(form.cpf))

function validarCpf() {
  cpfTocado.value = true
  if (cpfCompleto.value && !cpfEhValido.value) {
    errors.cpf = 'Este CPF não é válido: o dígito verificador não confere.'
  }
}

function validarEmail() {
  if (form.email && !/^\S+@\S+\.\S+$/.test(form.email)) {
    errors.email = 'Falta a parte final do endereço, depois do ponto.'
  }
}

function validarConfirmacaoSenha() {
  if (form.password_confirmation && form.password !== form.password_confirmation) {
    errors.password_confirmation = 'As duas senhas não são iguais.'
  }
}

/**
 * Campo vazio só é cobrado no envio: quem sai de um campo intocado ainda está
 * percorrendo o formulário, e não errando nele. O "deixei em branco" tem
 * mensagem própria porque é falha diferente do "escrevi errado" — e é a única
 * que explica por que a conta não foi criada.
 */
function validarObrigatorios() {
  if (!form.nome.trim()) errors.nome = 'Informe o nome completo.'
  if (!form.cpf.trim()) errors.cpf = 'Informe o CPF.'
  if (!form.email.trim()) errors.email = 'Informe o e-mail.'
  if (!form.password) errors.password = 'Crie uma senha.'
  if (!form.password_confirmation) errors.password_confirmation = 'Repita a senha para confirmar.'
}

// O botão espera apenas pelo preenchimento; campo preenchido e ainda inválido
// não o desabilita, porque o erro se diz na mensagem do campo (§P03).
const camposObrigatoriosPreenchidos = computed(() => (
  form.nome.trim() !== ''
  && form.cpf.trim() !== ''
  && form.email.trim() !== ''
  && form.password !== ''
  && form.password_confirmation !== ''
  && form.aceite_termos
))

/**
 * O endereço segue no estado da navegação, e não na URL: a tela de espera
 * precisa dele para exibir a máscara, e endereço em barra de endereços é
 * dado pessoal exposto (§6.3).
 */
function irParaConfirmacao() {
  router.push({ path: '/verificar-email', state: { email: resultado.value.email } })
}

async function enviar() {
  validarObrigatorios()
  validarCpf()
  validarEmail()
  validarConfirmacaoSenha()
  if (form.password && !senhaForte(form.password)) {
    errors.password = 'A senha ainda não atende aos critérios abaixo.'
  }
  if (Object.keys(errors).length > 0) return

  submitting.value = true
  submitError.value = ''
  contaJaExiste.value = false

  try {
    const resposta = await apiPost('/api/tutores', {
      nome: form.nome,
      cpf: form.cpf,
      email: form.email,
      password: form.password,
      password_confirmation: form.password_confirmation,
      aceite_termos: form.aceite_termos,
    })

    resultado.value = { ...resposta.tutor, email: form.email }
  } catch (erro) {
    if (erro instanceof ApiError && erro.status === 422) {
      // A recusa por cadastro existente vem numa chave própria, e não em `cpf`
      // ou `email`: qual dos dois coincidiu é justamente o que não se conta.
      if (erro.errors.conta) {
        contaJaExiste.value = true
      } else {
        Object.entries(erro.errors).forEach(([campo, mensagens]) => {
          errors[campo] = mensagens[0]
        })
        submitError.value = 'Há campos que precisam ser corrigidos. Revise as informações abaixo.'
      }
    } else {
      submitError.value = erro.message
    }
  } finally {
    submitting.value = false
  }
}

// Volta ao formulário preenchido: sem saber qual dado coincidiu, limpar campo
// algum seria adivinhação — e refazer tudo, castigo por um erro de digitação.
function revisarDados() {
  contaJaExiste.value = false
}
</script>

<template>
  <AuthSplitPage
    titulo="A carteira de vacinação do seu animal, sempre com você."
    subtitulo="Guarde o histórico de vacinas de cada animal em um só lugar, receba aviso antes de cada dose e escolha quais clínicas podem consultar."
    :itens="ARGUMENTOS"
    rodape="Imunia · calendário vacinal e prontuário para cães e gatos"
    acao-rotulo="Entrar"
    acao-destino="/entrar/tutor"
  >
    <!-- Sucesso: o estado troca na própria tela, e não em aviso solto (§6.1). -->
    <template v-if="resultado">
      <p class="auth-eyebrow auth-eyebrow--marca">
        <CircleCheck :size="16" />
        Conta criada
      </p>
      <h1 class="auth-title">Falta confirmar seu e-mail</h1>
      <p class="auth-text">
        Enviamos uma mensagem para <strong>{{ resultado.email }}</strong>. Confirme o endereço para
        receber os lembretes das próximas doses.
      </p>

      <AppButton class="auth-submit" @click="irParaConfirmacao">Confirmar meu e-mail</AppButton>

      <div class="auth-footer">
        <RouterLink to="/animais/novo" class="auth-link">Cadastrar meu primeiro animal</RouterLink>
      </div>
    </template>

    <!-- Conta existente: informa a existência e nada além dela (RF12b). -->
    <template v-else-if="contaJaExiste">
      <h1 class="auth-title">Criar conta de tutor</h1>

      <div class="auth-notice auth-notice--consentimento" role="alert">
        <KeyRound :size="20" />
        <div>
          <p class="auth-notice__title">Já existe uma conta com estes dados.</p>
          <p class="auth-notice__detail">Se a conta for sua, recupere o acesso para entrar.</p>
        </div>
      </div>

      <RouterLink to="/recuperar-senha" class="auth-link-button auth-link-button--primary">
        Recuperar o acesso
      </RouterLink>
      <AppButton class="auth-submit" variant="secondary" @click="revisarDados">Revisar meus dados</AppButton>

      <p class="auth-note">
        É tudo o que mostramos: nem qual dado já está em uso, nem nome, nem data de cadastro.
      </p>
    </template>

    <template v-else>
      <h1 class="auth-title">Criar conta de tutor</h1>
      <p class="auth-text auth-text--muted">Para tutores de cães e gatos.</p>

      <form @submit.prevent="enviar">
        <div class="auth-field">
          <AppInput
            id="nome" label="Nome completo" autocomplete="name"
            :model-value="form.nome" @update:model-value="setCampo('nome', $event)"
            :error="errors.nome"
          />
        </div>

        <div class="auth-field">
          <AppInput
            id="cpf" label="CPF" mono inputmode="numeric"
            :model-value="form.cpf" @update:model-value="onCpfInput"
            @blur="validarCpf"
            :error="errors.cpf"
          >
            <template v-if="cpfTocado && cpfEhValido && !errors.cpf" #feedback>
              <div class="valid-hint">
                <CircleCheck :size="16" />
                <span>CPF válido</span>
              </div>
            </template>
          </AppInput>
        </div>

        <div class="auth-field">
          <AppInput
            id="email" label="E-mail" type="email" autocomplete="email"
            :model-value="form.email" @update:model-value="setCampo('email', $event)"
            @blur="validarEmail"
            :error="errors.email"
            hint="É por aqui que enviamos os lembretes das próximas doses."
          />
        </div>

        <div class="auth-field">
          <AppInput
            id="password" label="Senha" type="password" autocomplete="new-password" revelavel
            :model-value="form.password" @update:model-value="setCampo('password', $event)"
            :error="errors.password"
          />
          <PasswordChecklist :senha="form.password" />
        </div>

        <div class="auth-field">
          <AppInput
            id="password2" label="Repita a senha" type="password" autocomplete="new-password" revelavel
            :model-value="form.password_confirmation" @update:model-value="setCampo('password_confirmation', $event)"
            @blur="validarConfirmacaoSenha"
            :error="errors.password_confirmation"
          />
        </div>

        <!-- As ligações abrem em nova aba para não descartar o formulário meio
             preenchido, e param a propagação para não marcarem a caixa ao serem
             clicadas — o clique cairia no rótulo que a envolve. -->
        <div class="auth-field">
          <AppCheckbox id="aceite" v-model="form.aceite_termos">
            Li e aceito os
            <a href="/termos" target="_blank" rel="noopener" @click.stop>termos de uso</a>
            e a
            <a href="/privacidade" target="_blank" rel="noopener" @click.stop>política de privacidade</a>.
          </AppCheckbox>
          <p v-if="errors.aceite_termos" class="consent-error" role="alert">
            <TriangleAlert :size="16" />
            <span>{{ errors.aceite_termos }}</span>
          </p>
        </div>

        <div v-if="submitError" class="auth-notice auth-notice--erro" role="alert">
          <TriangleAlert :size="20" />
          <p class="auth-notice__text">{{ submitError }}</p>
        </div>

        <AppButton
          class="auth-submit" type="submit"
          :disabled="submitting || !camposObrigatoriosPreenchidos" :loading="submitting"
        >
          {{ submitting ? 'Criando sua conta…' : 'Criar minha conta' }}
        </AppButton>

        <!-- Botão desabilitado sem explicação é armadilha: quem não vê o que
             falta desiste da conta (§6.1). -->
        <p v-if="!camposObrigatoriosPreenchidos" class="auth-note">
          Todos os campos são obrigatórios, e o aceite dos termos também.
        </p>
      </form>

      <p class="auth-reassurance">
        <Lock :size="16" />
        Ninguém vê os dados dos seus animais até você autorizar.
      </p>

      <div class="auth-footer">
        <span class="auth-footer__text">
          Já tem conta? <RouterLink to="/entrar/tutor">Entrar</RouterLink>
        </span>
      </div>
    </template>
  </AuthSplitPage>
</template>

<style scoped>
/* Sem cartão, o título abre a coluna: o afastamento do topo vem do painel. */
.auth-title {
  margin-top: 0;
}

.valid-hint {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.valid-hint svg {
  flex: none;
  color: var(--brand);
}

.consent-error {
  display: flex;
  align-items: flex-start;
  gap: var(--space-2);
  margin: 6px 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--status-late);
}

.consent-error svg {
  flex: none;
  margin-top: 2px;
}

.auth-reassurance {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-2);
  margin: var(--space-4) 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-faint);
}

.auth-reassurance svg {
  flex: none;
}
</style>
