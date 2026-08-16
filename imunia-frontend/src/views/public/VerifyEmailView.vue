<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { Bell, CircleCheck, ClockAlert } from '@lucide/vue'
import AuthPage from '@/components/auth/AuthPage.vue'
import AuthCard from '@/components/auth/AuthCard.vue'
import AppButton from '@/components/base/AppButton.vue'
import AppInput from '@/components/base/AppInput.vue'
import { apiPost, ApiError } from '@/lib/api.js'
import { useContagemRegressiva } from '@/lib/contagem.js'

const route = useRoute()

const token = String(route.params.token ?? '')

// Sem token, a tela é a da espera: quem acabou de criar a conta chega aqui
// vindo de P03, com o endereço no estado da navegação — nunca na URL.
const situacao = ref(token ? 'verificando' : 'aguardando')
const email = ref(window.history.state?.email ?? '')
const emailMascarado = ref('')
const erro = ref('')
const reenviando = ref(false)
const {
  correndo: emPausa,
  restante: segundosRestantes,
  iniciar: iniciarPausa,
} = useContagemRegressiva()

const podeReenviar = computed(() => /^\S+@\S+\.\S+$/.test(email.value) && !emPausa.value)

onMounted(async () => {
  if (!token) {
    if (email.value) emailMascarado.value = mascarar(email.value)
    return
  }

  try {
    const resposta = await apiPost(`/api/email/verificar/${token}`)
    situacao.value = resposta.situacao
  } catch (excecao) {
    if (excecao instanceof ApiError && excecao.data.situacao) {
      situacao.value = excecao.data.situacao
      emailMascarado.value = excecao.data.email_mascarado ?? ''
    } else {
      situacao.value = 'invalida'
    }
  }
})

/**
 * Espelho da máscara do servidor, para quando o endereço só existe aqui, na
 * navegação vinda do cadastro.
 */
function mascarar(endereco) {
  const [local, dominio] = endereco.split('@')
  if (!dominio) return '•••'

  return `${local.slice(0, 3)}•••@${dominio}`
}

async function reenviar() {
  if (reenviando.value || !podeReenviar.value) return

  reenviando.value = true
  erro.value = ''

  try {
    const resposta = await apiPost('/api/email/reenviar', { email: email.value })
    emailMascarado.value = resposta.email_mascarado
    situacao.value = 'reenviado'
    iniciarPausa(60)
  } catch (excecao) {
    if (excecao instanceof ApiError && excecao.status === 429) {
      iniciarPausa(excecao.data.segundos_restantes)
      erro.value = excecao.message
    } else if (excecao instanceof ApiError && excecao.status === 422) {
      erro.value = excecao.errors.email?.[0] ?? excecao.message
    } else {
      erro.value = excecao.message
    }
  } finally {
    reenviando.value = false
  }
}
</script>

<template>
  <AuthPage acao-rotulo="Entrar" acao-destino="/entrar">
    <AuthCard v-if="situacao === 'verificando'">
      <div class="auth-skeleton auth-skeleton--titulo" />
      <div class="auth-skeleton" />
    </AuthCard>

    <AuthCard v-else-if="situacao === 'confirmado'" tom="sucesso">
      <div class="auth-mark auth-mark--marca">
        <CircleCheck :size="32" />
      </div>
      <h1 class="auth-title">E-mail confirmado</h1>
      <p class="auth-text">
        Pronto. Agora você recebe os lembretes das próximas doses e pode autorizar clínicas a ver
        o histórico dos seus animais.
      </p>

      <RouterLink to="/entrar" class="auth-link-button auth-link-button--primary">
        Ir para o início
      </RouterLink>
    </AuthCard>

    <!-- Ligação vencida: a tela oferece o reenvio ali mesmo, sem mandar o
         usuário procurar por onde recomeçar. -->
    <AuthCard v-else-if="situacao === 'expirada' || situacao === 'invalida'" tom="atencao">
      <div class="auth-mark auth-mark--atencao">
        <ClockAlert :size="32" />
      </div>
      <h1 class="auth-title">Esta ligação expirou</h1>
      <p class="auth-text">
        A confirmação de e-mail vale por 24 horas. Podemos enviar outra agora mesmo.
      </p>

      <div v-if="!emailMascarado" class="auth-field">
        <AppInput
          id="email" label="E-mail" type="email" autocomplete="email"
          placeholder="seu@email.com"
          v-model="email"
          :error="erro"
        />
      </div>
      <p v-else class="auth-text auth-text--muted">
        Enviaremos para <strong>{{ emailMascarado }}</strong>.
      </p>

      <AppButton
        class="auth-submit"
        :disabled="!podeReenviar" :loading="reenviando"
        @click="reenviar"
      >
        {{ emPausa ? `Reenviar em 0:${String(segundosRestantes).padStart(2, '0')}` : 'Enviar nova confirmação' }}
      </AppButton>
    </AuthCard>

    <AuthCard v-else-if="situacao === 'reenviado'">
      <p class="auth-eyebrow auth-eyebrow--marca">
        <Bell :size="16" />
        Mensagem reenviada
      </p>
      <h1 class="auth-title">Enviamos de novo</h1>
      <p class="auth-text">
        A mensagem saiu para <strong>{{ emailMascarado }}</strong> agora. Se não chegar em alguns
        minutos, verifique a caixa de spam.
      </p>

      <AppButton
        class="auth-submit" variant="secondary"
        :disabled="!podeReenviar" :loading="reenviando"
        @click="reenviar"
      >
        {{ emPausa ? `Reenviar em 0:${String(segundosRestantes).padStart(2, '0')}` : 'Reenviar' }}
      </AppButton>
    </AuthCard>

    <!-- Espera: o endereço aparece mascarado, e a tela explica o que se perde
         enquanto a confirmação não vem (RN42). -->
    <AuthCard v-else tom="atencao">
      <p class="auth-eyebrow auth-eyebrow--atencao">
        <Bell :size="16" />
        Aguardando confirmação
      </p>
      <h1 class="auth-title">Confirme seu e-mail</h1>
      <p v-if="emailMascarado" class="auth-text">
        Enviamos uma mensagem para <strong>{{ emailMascarado }}</strong>. Abra o e-mail e toque no
        botão de confirmação.
      </p>
      <p v-else class="auth-text">
        Informe o endereço que você usou no cadastro e reenviamos a mensagem de confirmação.
      </p>

      <div class="auth-notice auth-notice--neutro">
        <ClockAlert :size="20" />
        <p class="auth-notice__text">
          Sem o endereço confirmado, não conseguimos avisar você sobre as próximas doses — e você
          também não consegue autorizar clínicas.
        </p>
      </div>

      <div v-if="!emailMascarado" class="auth-field">
        <AppInput
          id="email" label="E-mail" type="email" autocomplete="email"
          placeholder="seu@email.com"
          v-model="email"
          :error="erro"
        />
      </div>

      <AppButton
        class="auth-submit" variant="secondary"
        :disabled="!podeReenviar" :loading="reenviando"
        @click="reenviar"
      >
        {{ emPausa ? `Reenviar em 0:${String(segundosRestantes).padStart(2, '0')}` : 'Reenviar confirmação' }}
      </AppButton>
      <p v-if="emPausa" class="auth-note" aria-live="polite">
        Não recebeu? O reenvio libera em {{ segundosRestantes }} segundos.
      </p>
    </AuthCard>
  </AuthPage>
</template>
