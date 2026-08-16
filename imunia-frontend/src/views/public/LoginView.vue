<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { CircleCheck, Lock, TriangleAlert } from '@lucide/vue'
import AuthSplitPage from '@/components/auth/AuthSplitPage.vue'
import AppButton from '@/components/base/AppButton.vue'
import AppCheckbox from '@/components/base/AppCheckbox.vue'
import AppInput from '@/components/base/AppInput.vue'
import { apiPost, ApiError } from '@/lib/api.js'
import { useContagemRegressiva } from '@/lib/contagem.js'

const route = useRoute()
const router = useRouter()

/**
 * Quem entra aqui já conhece o produto: em vez de argumentar pela adesão, como
 * faz o autocadastro, o painel lembra o que espera do outro lado. A lista fala
 * do que o sistema faz, e não do que "você" faz, porque por esta mesma tela
 * entram a tutora, o veterinário e o administrador.
 */
const LEMBRETES = [
  'As doses atrasadas e as próximas abrem a tela inicial',
  'Cada registro traz a clínica, o profissional e o CRMV de quem o fez',
  'As autorizações concedidas continuam sob controle do tutor',
]

const form = reactive({ email: '', password: '', lembrar: false })
const submetendo = ref(false)
const erroCredenciais = ref('')
const bloqueio = ref('')
const {
  correndo: emPausa,
  formatado: tempoRestante,
  iniciar: iniciarPausa,
} = useContagemRegressiva()

// P06 encaminha para cá depois de trocar a senha; a confirmação aparece na
// própria tela, e não como aviso solto (§6.1, estado 6).
const senhaRedefinida = computed(() => route.query.redefinida === '1')

const podeEnviar = computed(() => form.email.trim() !== '' && form.password !== '')

onMounted(() => {
  document.getElementById('email')?.focus()
})

async function entrar() {
  if (submetendo.value || emPausa.value) return

  submetendo.value = true
  erroCredenciais.value = ''

  try {
    const resposta = await apiPost('/api/sessao', {
      email: form.email,
      password: form.password,
      lembrar: form.lembrar,
    })

    router.push(resposta.usuario.rota_inicial)
  } catch (erro) {
    if (erro instanceof ApiError && erro.status === 429) {
      bloqueio.value = erro.message
      iniciarPausa(erro.data.segundos_restantes)
    } else if (erro instanceof ApiError && erro.status === 422) {
      erroCredenciais.value = erro.errors.email?.[0] ?? 'E-mail ou senha incorretos.'
    } else {
      erroCredenciais.value = erro.message
    }
  } finally {
    submetendo.value = false
  }
}
</script>

<template>
  <AuthSplitPage
    titulo="Bem-vindo de volta!"
    subtitulo="O histórico de saúde dos seus animais continua onde você parou — e quem decide quem o vê continua sendo você."
    :itens="LEMBRETES"
    rodape="Imunia · calendário vacinal e prontuário para cães e gatos"
    acao-rotulo="Criar conta"
    acao-destino="/criar-conta"
  >
    <!-- Bloqueio por tentativas: a tela inteira troca de estado, porque não há
         o que corrigir num campo enquanto durar a pausa. -->
    <template v-if="emPausa">
      <p class="auth-eyebrow auth-eyebrow--atencao">
        <Lock :size="16" />
        Entrada bloqueada
      </p>
      <h1 class="auth-title">Aguarde para tentar de novo</h1>
      <p class="auth-text">{{ bloqueio }}</p>

      <div class="auth-counter" aria-live="polite">
        <div class="auth-counter__label">Nova tentativa em</div>
        <div class="auth-counter__value">{{ tempoRestante }}</div>
      </div>

      <RouterLink to="/recuperar-senha" class="auth-link-button auth-link-button--secondary">
        Recuperar minha senha
      </RouterLink>
    </template>

    <template v-else>
      <h1 class="auth-title">Entrar</h1>

      <div v-if="senhaRedefinida" class="auth-notice auth-notice--consentimento" role="status">
        <CircleCheck :size="20" />
        <div>
          <p class="auth-notice__title">Senha alterada.</p>
          <p class="auth-notice__detail">Entre com a nova senha.</p>
        </div>
      </div>

      <div v-if="erroCredenciais" class="auth-notice auth-notice--erro" role="alert">
        <TriangleAlert :size="20" />
        <div>
          <p class="auth-notice__title">{{ erroCredenciais }}</p>
          <p class="auth-notice__detail">Confira os dois campos e tente novamente.</p>
        </div>
      </div>

      <form @submit.prevent="entrar">
        <div class="auth-field">
          <AppInput
            id="email" label="E-mail" type="email" autocomplete="email"
            v-model="form.email"
            :invalido="erroCredenciais !== ''"
          />
        </div>

        <div class="auth-field">
          <AppInput
            id="senha" label="Senha" type="password" autocomplete="current-password" revelavel
            v-model="form.password"
            :invalido="erroCredenciais !== ''"
          />
        </div>

        <div class="auth-field">
          <AppCheckbox id="lembrar" v-model="form.lembrar">Manter conectado</AppCheckbox>
        </div>

        <AppButton
          class="auth-submit" type="submit"
          :disabled="!podeEnviar" :loading="submetendo"
        >
          {{ submetendo ? 'Entrando…' : 'Entrar' }}
        </AppButton>
      </form>

      <p v-if="erroCredenciais" class="auth-note">
        A mensagem é a mesma se o e-mail não tiver conta ou se a senha estiver errada:
        o Imunia não revela quais endereços estão cadastrados.
      </p>

      <div class="auth-footer">
        <RouterLink to="/recuperar-senha" class="auth-link">Esqueci minha senha</RouterLink>
        <span class="auth-footer__text">
          Ainda não tem conta? <RouterLink to="/criar-conta">Criar conta de tutor</RouterLink>
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
</style>
