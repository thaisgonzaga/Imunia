<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { CircleCheck, Lock, TriangleAlert, UserRoundPlus } from '@lucide/vue'
import AuthSplitPage from '@/components/auth/AuthSplitPage.vue'
import AppButton from '@/components/base/AppButton.vue'
import AppCheckbox from '@/components/base/AppCheckbox.vue'
import AppInput from '@/components/base/AppInput.vue'
import { apiPost, ApiError } from '@/lib/api.js'
import { useContagemRegressiva } from '@/lib/contagem.js'
import { useSessaoStore } from '@/stores/sessao.js'

/**
 * P02 — entrar (RF01), em três portas que são a mesma.
 *
 * A credencial é única: um endereço de correio, uma conta, os papéis que a
 * pessoa tiver (RN05). O que muda de porta para porta é **com qual papel ela
 * quer trabalhar agora** — e isso importa porque a precedência de
 * `rotaInicial()` manda ao ambiente clínico quem acumula, de modo que a
 * veterinária que vem ver a carteira do próprio gato caía todo dia no painel
 * do trabalho.
 *
 * Quem entra por uma porta cujo papel a conta não tem não é recusado: a
 * credencial estava certa, e o que falta é um cadastro que ela mesma cria dali
 * — sem segunda conta e sem segundo endereço.
 */
const route = useRoute()
const router = useRouter()
const sessao = useSessaoStore()

/**
 * A porta pela qual se entrou. Vem da rota, e não de um seletor dentro do
 * formulário: o endereço é o que a pessoa guarda nos favoritos, e é ele que
 * precisa lembrar por onde ela entra todo dia.
 */
const porta = computed(() => route.meta.papel ?? '')

const PORTAS = {
  tutor: {
    titulo: 'A carteira de vacinação do seu animal, sempre com você.',
    subtitulo:
      'As doses aplicadas, as que vêm e quem pode consultá-las — tudo onde você deixou da última vez.',
    itens: [
      'As doses atrasadas e as próximas abrem a tela inicial',
      'Lembrete por e-mail antes de cada dose',
      'Você autoriza e revoga clínicas quando quiser',
    ],
    tituloDoFormulario: 'Entrar como tutor',
    legenda: 'Para quem cuida dos próprios animais.',
    cabecalhoRotulo: 'Criar conta',
    cabecalhoDestino: '/criar-conta',
    rodapePergunta: 'Ainda não tem conta?',
    rodapeRotulo: 'Criar conta de tutor',
    rodapeDestino: '/criar-conta',
    outraPortaRotulo: 'Entrar como veterinário',
    outraPortaDestino: '/entrar/veterinario',
  },
  veterinario: {
    titulo: 'O atendimento com responsabilidade técnica registrada em cada dose.',
    subtitulo:
      'O plantel autorizado, as rechamadas pendentes e o prontuário de quem você atende — sob o CRMV de quem assina.',
    itens: [
      'As pendências vacinais do plantel abrem o painel',
      'Cada registro leva o profissional e o CRMV de quem aplicou',
      'Histórico de outra clínica, mediante autorização do tutor',
    ],
    tituloDoFormulario: 'Entrar como veterinário',
    legenda: 'Para quem atende em clínica, hospital ou por conta própria.',
    cabecalhoRotulo: 'Cadastrar estabelecimento',
    cabecalhoDestino: '/cadastrar-prestador',
    rodapePergunta: 'Ainda não tem estabelecimento no Imunia?',
    rodapeRotulo: 'Cadastrar meu estabelecimento',
    rodapeDestino: '/cadastrar-prestador',
    outraPortaRotulo: 'Entrar como tutor',
    outraPortaDestino: '/entrar/tutor',
  },
  // A porta sem papel, que continua existindo: é para onde a guarda de rota
  // devolve quem perdeu a sessão, e aí ninguém escolheu papel algum — o
  // destino é a precedência do servidor, como sempre foi.
  '': {
    titulo: 'Bem-vindo de volta!',
    subtitulo:
      'O histórico de saúde dos seus animais continua onde você parou — e quem decide quem o vê continua sendo você.',
    itens: [
      'As doses atrasadas e as próximas abrem a tela inicial',
      'Cada registro traz a clínica, o profissional e o CRMV de quem o fez',
      'As autorizações concedidas continuam sob controle do tutor',
    ],
    tituloDoFormulario: 'Entrar',
    legenda: '',
    cabecalhoRotulo: 'Criar conta',
    cabecalhoDestino: '/criar-conta',
    rodapePergunta: 'Ainda não tem conta?',
    rodapeRotulo: 'Criar conta de tutor',
    rodapeDestino: '/criar-conta',
    outraPortaRotulo: '',
    outraPortaDestino: '',
  },
}

const textos = computed(() => PORTAS[porta.value] ?? PORTAS[''])

/**
 * O que oferecer a quem entrou pela porta certa com a conta certa e o papel
 * que falta. Não é erro nem recusa: é o cadastro que ainda não existe, e que
 * cabe na mesma conta.
 */
const PAPEL_AUSENTE = {
  tutor: {
    titulo: 'Esta conta ainda não tem cadastro de tutor',
    detalhe:
      'Seu acesso está correto. Para ver a carteira dos seus próprios animais, falta criar o cadastro de tutor — '
      + 'no mesmo e-mail e na mesma senha, sem segunda conta.',
    acaoRotulo: 'Criar meu cadastro de tutor',
    acaoDestino: '/conta/tutor',
    nota: '',
  },
  veterinario: {
    titulo: 'Esta conta ainda não atende em nenhum estabelecimento',
    detalhe:
      'Seu acesso está correto. O ambiente clínico se abre pelo vínculo com um estabelecimento — '
      + 'cadastre o seu, no mesmo e-mail e na mesma senha.',
    acaoRotulo: 'Cadastrar meu estabelecimento',
    acaoDestino: '/cadastrar-prestador',
    nota:
      'Se você atende em uma clínica que já usa o Imunia, peça a quem administra a conta que convide '
      + 'este mesmo endereço: o vínculo chega por convite, sem criar conta nova.',
  },
}

const form = reactive({ email: '', password: '', lembrar: false })
const submetendo = ref(false)
const erroCredenciais = ref('')
const bloqueio = ref('')
const papelAusente = ref(null)
const {
  correndo: emPausa,
  formatado: tempoRestante,
  iniciar: iniciarPausa,
} = useContagemRegressiva()

const ofertaDePapel = computed(() => (papelAusente.value ? PAPEL_AUSENTE[papelAusente.value] : null))

// P06 encaminha para cá depois de trocar a senha; a confirmação aparece na
// própria tela, e não como aviso solto (§6.1, estado 6).
const senhaRedefinida = computed(() => route.query.redefinida === '1')

const podeEnviar = computed(() => form.email.trim() !== '' && form.password !== '')

onMounted(() => {
  document.getElementById('email')?.focus()
})

// Trocar de porta sem recarregar a página troca também a oferta pendente: a
// frase sobre o papel que falta é da porta anterior, e não desta.
watch(porta, () => {
  papelAusente.value = null
  erroCredenciais.value = ''
})

async function entrar() {
  if (submetendo.value || emPausa.value) return

  submetendo.value = true
  erroCredenciais.value = ''
  papelAusente.value = null

  try {
    const resposta = await apiPost('/api/sessao', {
      email: form.email,
      password: form.password,
      lembrar: form.lembrar,
      ...(porta.value ? { papel: porta.value } : {}),
    })

    // A sessão recém-aberta já vem descrita na resposta: guardá-la aqui evita
    // que a tela de destino tenha de perguntar de novo quem entrou.
    sessao.registrar(resposta.usuario)

    // A sessão está aberta em qualquer dos dois caminhos; o que muda é se há
    // para onde ir. Sem o papel da porta, a tela é que oferece criá-lo.
    if (resposta.usuario.papel_ausente) {
      papelAusente.value = resposta.usuario.papel_ausente
      return
    }

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

/** A saída de quem decidiu não criar o papel agora: o ambiente que já é seu. */
function seguirComOPapelQueTenho() {
  router.push(sessao.usuario?.rota_inicial ?? '/')
}
</script>

<template>
  <AuthSplitPage
    :titulo="textos.titulo"
    :subtitulo="textos.subtitulo"
    :itens="textos.itens"
    rodape="Imunia · calendário vacinal e prontuário para cães e gatos"
    :acao-rotulo="textos.cabecalhoRotulo"
    :acao-destino="textos.cabecalhoDestino"
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

    <!-- Entrou, mas por uma porta cujo papel a conta ainda não tem. A sessão
         está aberta: daqui sai o cadastro que falta, ou o ambiente que já é
         seu. -->
    <template v-else-if="ofertaDePapel">
      <p class="auth-eyebrow auth-eyebrow--marca">
        <UserRoundPlus :size="16" />
        Falta um passo
      </p>
      <h1 class="auth-title">{{ ofertaDePapel.titulo }}</h1>
      <p class="auth-text">{{ ofertaDePapel.detalhe }}</p>

      <RouterLink :to="ofertaDePapel.acaoDestino" class="auth-link-button auth-link-button--primary">
        {{ ofertaDePapel.acaoRotulo }}
      </RouterLink>
      <AppButton class="auth-submit" variant="secondary" @click="seguirComOPapelQueTenho">
        Continuar no meu ambiente de sempre
      </AppButton>

      <p v-if="ofertaDePapel.nota" class="auth-note">{{ ofertaDePapel.nota }}</p>
    </template>

    <template v-else>
      <h1 class="auth-title">{{ textos.tituloDoFormulario }}</h1>
      <p v-if="textos.legenda" class="auth-text auth-text--muted">{{ textos.legenda }}</p>

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

      <!-- A outra porta. Fica aqui, e não escondida num menu, porque é a única
           pista de que o mesmo endereço serve aos dois papéis (RN05) — quem não
           a vê conclui que precisa de uma segunda conta. -->
      <p v-if="textos.outraPortaRotulo" class="auth-note auth-note--porta">
        É veterinário e tutor com o mesmo e-mail? A conta é a mesma.
        <RouterLink :to="textos.outraPortaDestino" class="auth-link">
          {{ textos.outraPortaRotulo }}
        </RouterLink>
      </p>

      <div class="auth-footer">
        <RouterLink to="/recuperar-senha" class="auth-link">Esqueci minha senha</RouterLink>
        <span class="auth-footer__text">
          {{ textos.rodapePergunta }} <RouterLink :to="textos.rodapeDestino">{{ textos.rodapeRotulo }}</RouterLink>
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

/* A outra porta não é rodapé nem erro: fica entre o formulário e os atalhos,
   com um filete que a separa do que se acabou de preencher. */
.auth-note--porta {
  margin-top: var(--space-6);
  padding-top: var(--space-6);
  border-top: 1px solid var(--border-hairline);
}
</style>
