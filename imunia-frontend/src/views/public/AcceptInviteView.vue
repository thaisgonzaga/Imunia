<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Building2, CircleCheck, ClockAlert, Lock, TriangleAlert, UserRoundCheck } from '@lucide/vue'
import AuthPage from '@/components/auth/AuthPage.vue'
import AuthCard from '@/components/auth/AuthCard.vue'
import PasswordChecklist from '@/components/auth/PasswordChecklist.vue'
import AppButton from '@/components/base/AppButton.vue'
import AppCheckbox from '@/components/base/AppCheckbox.vue'
import AppInput from '@/components/base/AppInput.vue'
import { apiGet, apiPost, ApiError } from '@/lib/api.js'
import { senhaForte } from '@/lib/senha.js'

const route = useRoute()
const router = useRouter()

const token = String(route.params.token ?? '')

const situacao = ref('verificando')
const convite = ref(null)
const senha = ref('')
const nome = ref('')
const aceiteTermos = ref(false)
const erroSenha = ref('')
const erroNome = ref('')
const erroAceite = ref('')
const erroGeral = ref('')
const submetendo = ref(false)
const reenviando = ref(false)
const reenviado = ref(false)

const deVeterinario = computed(() => convite.value?.tipo === 'veterinario')

/**
 * RF09 — o mesmo profissional mantém vínculo com mais de um prestador. Quem já
 * tem conta não redefine senha nem nome ao aceitar o segundo convite: ele já
 * tem os dois, e pedi-los de novo sobrescreveria a senha com que ele entra no
 * primeiro. É o servidor que decide o que falta, e a tela só obedece.
 */
const defineSenha = computed(() => convite.value?.define_senha !== false)
const defineNome = computed(() => convite.value?.define_nome === true)

/**
 * Quem foi cadastrado pela clínica nunca esteve diante dos termos: quem
 * preencheu o formulário foi a recepção. A ativação é a primeira vez que o
 * titular pode aceitá-los, e sem esse aceite a conta seria usada sob um
 * documento que ele não teve oportunidade de conhecer (CDC, art. 46).
 *
 * O convite de veterinário não pede: quem respondeu pelos termos foi quem
 * cadastrou o estabelecimento, em P04. Quem decide é o servidor.
 */
const exigeAceite = computed(() => convite.value?.aceita_termos === true)

const podeEnviar = computed(() => {
  if (defineNome.value && nome.value.trim() === '') return false
  if (exigeAceite.value && !aceiteTermos.value) return false

  return defineSenha.value ? senhaForte(senha.value) : true
})

onMounted(async () => {
  if (!token) {
    situacao.value = 'invalido'
    return
  }

  try {
    const resposta = await apiGet(`/api/convites/${token}`)
    situacao.value = resposta.situacao
    convite.value = resposta.convite ?? null
  } catch (excecao) {
    if (excecao instanceof ApiError && excecao.data.situacao) {
      situacao.value = excecao.data.situacao
      convite.value = excecao.data.convite ?? null
    } else {
      situacao.value = 'invalido'
    }
  }
})

async function ativar() {
  if (submetendo.value) return

  submetendo.value = true
  erroSenha.value = ''
  erroNome.value = ''
  erroAceite.value = ''
  erroGeral.value = ''

  try {
    const corpo = {}
    if (defineSenha.value) corpo.password = senha.value
    if (defineNome.value) corpo.nome = nome.value
    if (exigeAceite.value) corpo.aceite_termos = aceiteTermos.value

    const resposta = await apiPost(`/api/convites/${token}`, corpo)
    router.push(resposta.usuario.rota_inicial)
  } catch (excecao) {
    if (excecao instanceof ApiError && excecao.status === 422) {
      erroSenha.value = excecao.errors.password?.[0] ?? ''
      erroNome.value = excecao.errors.nome?.[0] ?? ''
      erroAceite.value = excecao.errors.aceite_termos?.[0] ?? ''
      if (!erroSenha.value && !erroNome.value && !erroAceite.value) {
        erroGeral.value = excecao.message
      }
    } else if (excecao instanceof ApiError && excecao.status === 410) {
      // O convite venceu entre a abertura da tela e o envio.
      situacao.value = 'expirado'
    } else {
      erroGeral.value = excecao.message
    }
  } finally {
    submetendo.value = false
  }
}

async function solicitarNovoConvite() {
  if (reenviando.value) return

  reenviando.value = true
  erroGeral.value = ''

  try {
    await apiPost(`/api/convites/${token}/reenviar`)
    reenviado.value = true
  } catch (excecao) {
    erroGeral.value = excecao.message
  } finally {
    reenviando.value = false
  }
}
</script>

<template>
  <AuthPage acao-rotulo="Entrar" acao-destino="/entrar">
    <AuthCard v-if="situacao === 'verificando'" largura="md">
      <div class="auth-skeleton auth-skeleton--titulo" />
      <div class="auth-skeleton" />
      <div class="auth-skeleton" />
    </AuthCard>

    <AuthCard v-else-if="situacao === 'expirado'" tom="atencao">
      <div class="auth-mark auth-mark--atencao">
        <ClockAlert :size="32" />
      </div>
      <h1 class="auth-title">Este convite expirou</h1>
      <p class="auth-text">
        Convites valem por sete dias.
        <template v-if="convite">
          Este foi enviado pela {{ convite.prestador.nome }} em {{ convite.enviado_em }}.
        </template>
      </p>
      <p class="auth-text auth-text--muted">
        Peça um convite novo à clínica, ou crie sua conta direto — depois você autoriza quem quiser.
      </p>

      <div v-if="reenviado" class="auth-notice auth-notice--consentimento" role="status">
        <CircleCheck :size="20" />
        <p class="auth-notice__text">
          Pedimos um convite novo. A clínica envia a mensagem para o mesmo endereço.
        </p>
      </div>

      <AppButton
        v-else class="auth-submit"
        :loading="reenviando" @click="solicitarNovoConvite"
      >
        Solicitar novo convite
      </AppButton>

      <RouterLink to="/criar-conta" class="auth-link">Criar conta por conta própria</RouterLink>
    </AuthCard>

    <AuthCard v-else-if="situacao === 'aceito'">
      <div class="auth-mark auth-mark--marca">
        <CircleCheck :size="32" />
      </div>
      <h1 class="auth-title">Este convite já foi usado</h1>
      <p class="auth-text">
        A conta já foi ativada. Entre com o seu e-mail e a senha que você definiu.
      </p>

      <RouterLink to="/entrar" class="auth-link-button auth-link-button--primary">Entrar</RouterLink>
      <RouterLink to="/recuperar-senha" class="auth-link">Esqueci minha senha</RouterLink>
    </AuthCard>

    <AuthCard v-else-if="situacao === 'invalido'">
      <div class="auth-mark auth-mark--atencao">
        <ClockAlert :size="32" />
      </div>
      <h1 class="auth-title">Não encontramos este convite</h1>
      <p class="auth-text">
        A ligação pode ter sido copiada pela metade. Abra o convite direto pela mensagem que
        você recebeu, ou crie sua conta por conta própria.
      </p>

      <RouterLink to="/criar-conta" class="auth-link-button auth-link-button--primary">
        Criar conta de tutor
      </RouterLink>
      <RouterLink to="/entrar" class="auth-link">Voltar para entrar</RouterLink>
    </AuthCard>

    <!-- Convite válido: 480 px, para caber o cabeçalho de quem convidou. -->
    <AuthCard v-else largura="md">
      <template #cabecalho>
        <p class="convite-eyebrow">
          <component :is="deVeterinario ? UserRoundCheck : Building2" :size="16" />
          {{ deVeterinario ? 'Convite de equipe' : 'Convite' }}
        </p>
        <p class="convite-convidante">
          <template v-if="deVeterinario">
            {{ convite.convidante }}, da {{ convite.prestador.nome }}, convidou você
          </template>
          <template v-else>
            A {{ convite.prestador.nome }} criou uma conta para você
          </template>
        </p>
        <p class="convite-detalhe">
          <template v-if="deVeterinario">
            Para atuar como médico-veterinário · convite enviado em {{ convite.enviado_em }}
          </template>
          <template v-else>
            {{ convite.prestador.municipio }}, {{ convite.prestador.uf }} ·
            convite enviado em {{ convite.enviado_em }}
          </template>
        </p>
      </template>

      <h1 class="auth-title">{{ deVeterinario ? 'Ativar seu acesso' : 'Ativar sua conta' }}</h1>
      <p v-if="!deVeterinario" class="auth-text auth-text--muted">
        Defina uma senha para acessar a carteira de vacinação dos seus animais. A partir daí,
        quem decide quais clínicas veem o histórico deles é você.
      </p>

      <!-- RF09: o CRMV acompanha cada registro, e por isso é conferido antes
           da ativação — e corrigido só por quem administra a conta. -->
      <div v-if="deVeterinario" class="convite-dados">
        <p class="convite-dados__label">Dados informados pela clínica</p>
        <div class="convite-dados__grade">
          <div v-if="convite.nome">
            <div class="convite-dados__campo">Nome</div>
            <div class="convite-dados__valor">{{ convite.nome }}</div>
          </div>
          <div v-else>
            <div class="convite-dados__campo">E-mail</div>
            <div class="convite-dados__valor">{{ convite.email }}</div>
          </div>
          <div>
            <div class="convite-dados__campo">CRMV e UF</div>
            <div class="convite-dados__valor convite-dados__valor--mono">
              CRMV-{{ convite.crmv_uf }} {{ convite.crmv }}
            </div>
          </div>
        </div>
        <p class="convite-dados__nota">
          Confira antes de ativar: o CRMV acompanha cada registro que você fizer. Se algo estiver
          errado, peça a correção a quem administra a conta da clínica — você não pode alterar aqui.
        </p>
      </div>

      <form @submit.prevent="ativar">
        <div v-if="!deVeterinario" class="auth-field">
          <span class="auth-label">E-mail</span>
          <div class="auth-readonly">
            <span class="auth-readonly__value">{{ convite.email }}</span>
            <Lock :size="16" class="auth-readonly__icon" />
          </div>
          <p class="auth-hint">Foi o endereço informado no convite. Para trocar, fale com a clínica.</p>
        </div>

        <!-- RF09 — o nome acompanha cada registro que este profissional assinar,
             e por isso quem o escreve é ele, não quem o convidou. -->
        <div v-if="defineNome" class="auth-field">
          <AppInput
            id="nome" label="Seu nome" autocomplete="name"
            placeholder="como você assina profissionalmente"
            v-model="nome"
            :error="erroNome"
            hint="Aparece em cada registro que você fizer, junto do seu CRMV."
          />
        </div>

        <div v-if="defineSenha" class="auth-field">
          <AppInput
            id="senha" label="Crie uma senha" type="password" autocomplete="new-password" revelavel
            placeholder="ao menos 10 caracteres"
            v-model="senha"
            :error="erroSenha"
          />
          <PasswordChecklist :senha="senha" />
        </div>

        <!-- Conta que já existe: o convite só confirma o vínculo novo. -->
        <p v-else class="auth-notice auth-notice--neutro">
          Você já tem conta no Imunia. Aceitar este convite apenas acrescenta o vínculo com
          {{ convite.prestador.nome }} — sua senha continua a mesma.
        </p>

        <!-- Mesma marcação de P05: as ligações abrem em nova aba para não
             descartar a senha já digitada, e param a propagação para não
             marcarem a caixa ao serem clicadas. -->
        <div v-if="exigeAceite" class="auth-field">
          <AppCheckbox id="aceite" v-model="aceiteTermos">
            Li e aceito os
            <a href="/termos" target="_blank" rel="noopener" @click.stop>termos de uso</a>
            e a
            <a href="/privacidade" target="_blank" rel="noopener" @click.stop>política de privacidade</a>.
          </AppCheckbox>
          <p v-if="erroAceite" class="consent-error" role="alert">
            <TriangleAlert :size="16" />
            <span>{{ erroAceite }}</span>
          </p>
        </div>

        <p v-if="erroGeral" class="auth-notice auth-notice--erro" role="alert">{{ erroGeral }}</p>

        <AppButton
          class="auth-submit" type="submit"
          :disabled="!podeEnviar" :loading="submetendo"
        >
          <template v-if="!defineSenha">Aceitar o vínculo</template>
          <template v-else>{{ deVeterinario ? 'Ativar meu acesso' : 'Ativar minha conta' }}</template>
        </AppButton>
      </form>
    </AuthCard>
  </AuthPage>
</template>

<style scoped>
/* Mesmo tratamento de P05, para que o erro de aceite se leia igual nas duas
   telas em que o aceite é pedido. */
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

.convite-eyebrow {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--brand);
}

.convite-eyebrow svg {
  flex: none;
}

.convite-convidante {
  margin: var(--space-2) 0 0;
  font-size: 18px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.convite-detalhe {
  margin: var(--space-1) 0 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
}

.convite-dados {
  margin: var(--space-4) 0 0;
  padding: var(--space-4);
  background: var(--surface-sunken);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
}

.convite-dados__label {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

.convite-dados__grade {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--space-3);
  margin: var(--space-3) 0 0;
}

.convite-dados__campo {
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-faint);
}

.convite-dados__valor {
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);
}

.convite-dados__valor--mono {
  font-family: var(--font-mono);
  font-weight: 500;
  font-variant-numeric: tabular-nums;
}

.convite-dados__nota {
  margin: var(--space-3) 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

@media (max-width: 479px) {
  .convite-dados__grade {
    grid-template-columns: 1fr;
  }
}
</style>
