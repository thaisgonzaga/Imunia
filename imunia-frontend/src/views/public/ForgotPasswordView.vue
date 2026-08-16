<script setup>
import { computed, ref } from 'vue'
import { Bell, ClockAlert } from '@lucide/vue'
import AuthPage from '@/components/auth/AuthPage.vue'
import AuthCard from '@/components/auth/AuthCard.vue'
import AppButton from '@/components/base/AppButton.vue'
import AppInput from '@/components/base/AppInput.vue'
import { apiPost, ApiError } from '@/lib/api.js'
import { useContagemRegressiva } from '@/lib/contagem.js'

const email = ref('')
const submetendo = ref(false)
const enviado = ref(false)
const erro = ref('')
const limite = ref('')
const {
  correndo: emPausa,
  formatado: tempoRestante,
  iniciar: iniciarPausa,
} = useContagemRegressiva()

const podeEnviar = computed(() => /^\S+@\S+\.\S+$/.test(email.value))

async function enviar() {
  if (submetendo.value) return

  submetendo.value = true
  erro.value = ''

  try {
    await apiPost('/api/senha/recuperar', { email: email.value })
    enviado.value = true
  } catch (excecao) {
    if (excecao instanceof ApiError && excecao.status === 429) {
      limite.value = excecao.message
      iniciarPausa(excecao.data.segundos_restantes)
    } else if (excecao instanceof ApiError && excecao.status === 422) {
      erro.value = excecao.errors.email?.[0] ?? excecao.message
    } else {
      erro.value = excecao.message
    }
  } finally {
    submetendo.value = false
  }
}
</script>

<template>
  <AuthPage acao-rotulo="Entrar" acao-destino="/entrar">
    <!-- Pedidos repetidos: a pausa é anunciada com o tempo que falta, para que
         ninguém fique tentando de novo às cegas. -->
    <AuthCard v-if="emPausa" tom="atencao">
      <p class="auth-eyebrow auth-eyebrow--atencao">
        <ClockAlert :size="16" />
        Muitos pedidos seguidos
      </p>
      <h1 class="auth-title">Aguarde alguns minutos</h1>
      <p class="auth-text">{{ limite }}</p>
      <p class="auth-text auth-text--muted">
        Se você já pediu e o e-mail não chegou, confira a caixa de spam antes de tentar de novo.
      </p>

      <div class="auth-counter" aria-live="polite">
        <div class="auth-counter__value">{{ tempoRestante }}</div>
      </div>
    </AuthCard>

    <!-- Confirmação idêntica exista ou não a conta (RF03). -->
    <AuthCard v-else-if="enviado" tom="sucesso">
      <div class="auth-mark auth-mark--marca">
        <Bell :size="32" />
      </div>
      <h1 class="auth-title">Instruções enviadas</h1>
      <p class="auth-text">
        Se houver uma conta com este endereço, enviaremos as instruções em instantes.
        Verifique também a caixa de spam.
      </p>
      <p class="auth-note">
        Esta confirmação é a mesma em qualquer caso, exista a conta ou não: é assim que
        evitamos revelar quais endereços estão cadastrados.
      </p>

      <RouterLink to="/entrar" class="auth-link-button auth-link-button--secondary">
        Voltar para entrar
      </RouterLink>
    </AuthCard>

    <AuthCard v-else>
      <h1 class="auth-title auth-title--solto">Recuperar senha</h1>
      <p class="auth-text auth-text--muted">
        Informe o e-mail da sua conta e enviaremos as instruções para definir uma senha nova.
      </p>

      <form @submit.prevent="enviar">
        <div class="auth-field">
          <AppInput
            id="email" label="E-mail" type="email" autocomplete="email"
            placeholder="seu@email.com"
            v-model="email"
            :error="erro"
          />
        </div>

        <AppButton
          class="auth-submit" type="submit"
          :disabled="!podeEnviar" :loading="submetendo"
        >
          {{ submetendo ? 'Enviando…' : 'Enviar instruções' }}
        </AppButton>
      </form>

      <RouterLink to="/entrar" class="auth-link">Voltar para entrar</RouterLink>
    </AuthCard>
  </AuthPage>
</template>
