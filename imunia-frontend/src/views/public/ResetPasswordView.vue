<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { CircleCheck, ClockAlert, Lock } from '@lucide/vue'
import AuthPage from '@/components/auth/AuthPage.vue'
import AuthCard from '@/components/auth/AuthCard.vue'
import PasswordChecklist from '@/components/auth/PasswordChecklist.vue'
import AppButton from '@/components/base/AppButton.vue'
import AppInput from '@/components/base/AppInput.vue'
import { apiGet, apiPost, ApiError } from '@/lib/api.js'
import { senhaForte } from '@/lib/senha.js'

const route = useRoute()
const router = useRouter()

const token = String(route.params.token ?? '')
const email = String(route.query.email ?? '')

// 'verificando' enquanto a tela decide em qual estado entrar; depois, o que o
// servidor disser sobre a ligação: válida, expirada ou já utilizada.
const situacao = ref('verificando')
const submetendo = ref(false)
const erroGeral = ref('')

const form = reactive({ password: '', password_confirmation: '' })
const errors = reactive({})

const podeEnviar = computed(() => (
  senhaForte(form.password) && form.password === form.password_confirmation
))

onMounted(async () => {
  if (!token || !email) {
    situacao.value = 'utilizada'
    return
  }

  try {
    const resposta = await apiGet(`/api/senha/redefinir/${token}?email=${encodeURIComponent(email)}`)
    situacao.value = resposta.situacao
  } catch {
    situacao.value = 'utilizada'
  }
})

function validarConfirmacao() {
  if (form.password_confirmation && form.password !== form.password_confirmation) {
    errors.password_confirmation = 'As duas senhas não são iguais.'
  } else {
    delete errors.password_confirmation
  }
}

async function redefinir() {
  if (submetendo.value) return

  validarConfirmacao()
  if (Object.keys(errors).length > 0) return

  submetendo.value = true
  erroGeral.value = ''

  try {
    await apiPost('/api/senha/redefinir', {
      token,
      email,
      password: form.password,
      password_confirmation: form.password_confirmation,
    })

    router.push({ path: '/entrar', query: { redefinida: '1' } })
  } catch (excecao) {
    if (excecao instanceof ApiError && excecao.status === 422) {
      // A ligação pode ter vencido entre a abertura da tela e o envio.
      if (excecao.data.situacao) {
        situacao.value = excecao.data.situacao
      } else {
        Object.entries(excecao.errors).forEach(([campo, mensagens]) => {
          errors[campo] = mensagens[0]
        })
      }
    } else {
      erroGeral.value = excecao.message
    }
  } finally {
    submetendo.value = false
  }
}
</script>

<template>
  <AuthPage acao-rotulo="Entrar" acao-destino="/entrar">
    <AuthCard v-if="situacao === 'verificando'">
      <div class="auth-skeleton auth-skeleton--titulo" />
      <div class="auth-skeleton" />
      <div class="auth-skeleton" />
    </AuthCard>

    <AuthCard v-else-if="situacao === 'expirada'" tom="atencao">
      <div class="auth-mark auth-mark--atencao">
        <ClockAlert :size="32" />
      </div>
      <h1 class="auth-title">Esta ligação expirou</h1>
      <p class="auth-text">
        As instruções de recuperação valem por 30 minutos, e o prazo desta já passou.
        Sua senha atual continua valendo.
      </p>

      <RouterLink to="/recuperar-senha" class="auth-link-button auth-link-button--primary">
        Pedir novas instruções
      </RouterLink>
      <RouterLink to="/entrar" class="auth-link">Voltar para entrar</RouterLink>
    </AuthCard>

    <AuthCard v-else-if="situacao === 'utilizada'" tom="atencao">
      <div class="auth-mark auth-mark--atencao">
        <CircleCheck :size="32" />
      </div>
      <h1 class="auth-title">Esta ligação já foi usada</h1>
      <p class="auth-text">
        A senha desta conta já foi trocada com estas instruções. Cada envio vale uma única vez.
      </p>
      <p class="auth-text auth-text--muted">
        Se não foi você quem trocou, peça novas instruções agora e entre em contato conosco.
      </p>

      <RouterLink to="/entrar" class="auth-link-button auth-link-button--primary">
        Entrar com a nova senha
      </RouterLink>
      <RouterLink to="/recuperar-senha" class="auth-link">Pedir novas instruções</RouterLink>
    </AuthCard>

    <AuthCard v-else>
      <h1 class="auth-title auth-title--solto">Definir nova senha</h1>

      <form @submit.prevent="redefinir">
        <div class="auth-field">
          <AppInput
            id="senha" label="Nova senha" type="password" autocomplete="new-password" revelavel
            v-model="form.password"
            :error="errors.password"
          />
          <PasswordChecklist :senha="form.password" />
        </div>

        <div class="auth-field">
          <AppInput
            id="senha2" label="Repita a nova senha" type="password" autocomplete="new-password"
            v-model="form.password_confirmation"
            @blur="validarConfirmacao"
            :error="errors.password_confirmation"
          />
        </div>

        <!-- P3: a tela enuncia o efeito antes de o usuário confirmar. -->
        <div class="auth-notice auth-notice--consentimento">
          <Lock :size="20" />
          <p class="auth-notice__text">
            Ao definir a nova senha, você será desconectado dos outros dispositivos.
          </p>
        </div>

        <p v-if="erroGeral" class="auth-notice auth-notice--erro" role="alert">
          {{ erroGeral }}
        </p>

        <AppButton
          class="auth-submit" type="submit"
          :disabled="!podeEnviar" :loading="submetendo"
        >
          {{ submetendo ? 'Definindo…' : 'Definir nova senha' }}
        </AppButton>
      </form>
    </AuthCard>
  </AuthPage>
</template>
