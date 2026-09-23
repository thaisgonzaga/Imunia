<script setup>
import { computed, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { CircleCheck, ClockAlert, LogOut, TriangleAlert } from '@lucide/vue'
import AppButton from '@/components/base/AppButton.vue'
import AppInput from '@/components/base/AppInput.vue'
import ConfirmDialog from '@/components/base/ConfirmDialog.vue'
import PasswordChecklist from '@/components/auth/PasswordChecklist.vue'
import RoleShell from '@/components/base/RoleShell.vue'
import { ApiError, apiGet, apiPost } from '@/lib/api.js'
import { useReenvioDeConfirmacao } from '@/lib/confirmacaoDeEmail.js'
import { formatarCpf } from '@/lib/masks.js'
import { senhaForte } from '@/lib/senha.js'
import { useSessaoStore } from '@/stores/sessao.js'

/**
 * T18 — minha conta (RF04, RF06), a única tela que os quatro ambientes
 * compartilham. A moldura é a de quem chegou, resolvida por `RoleShell`: o
 * veterinário que vem trocar a senha não deve perder a barra lateral clínica
 * por ter clicado no próprio nome.
 *
 * O que a tela deliberadamente **não** oferece: correção de CPF, que identifica
 * o titular perante RF13 e viaja nos documentos exportados, e por isso não é
 * matéria de formulário; e as seções de preferências de notificação (T16) e de
 * direitos do titular (RF54, RF55), ainda não construídas — anunciá-las em
 * botão que não leva a lugar algum seria repetir aqui o erro que trouxe você a
 * esta tela. O histórico de notificações (T17) já existe, e se chega a ele pelo
 * sino do cabeçalho.
 *
 * A relação de papéis da conta e as ofertas de acrescentar o que falta também
 * não estão mais aqui (§9.29): quem descobre que lhe falta um papel descobre na
 * porta de entrada de P02, no momento em que esbarra na falta.
 */
const router = useRouter()
const sessao = useSessaoStore()

const conta = ref(null)
const carregando = ref(true)
const erro = ref('')

const form = reactive({ nome: '', email: '' })
const erros = reactive({})
const salvando = ref(false)
const erroDeEnvio = ref('')
const avisos = ref([])
const confirmandoEmail = ref(false)

const formSenha = reactive({ senha_atual: '', password: '', password_confirmation: '' })
const errosSenha = reactive({})
const trocandoSenha = ref(false)
const erroDeSenha = ref('')
const avisoDeSenha = ref('')

const saindo = ref(false)

// O reenvio da confirmação é o mesmo da tarja de T01 (RF05c) — endereço
// gravado, e não o que está sendo digitado: reenviar para o campo em edição
// mandaria a mensagem a um endereço que ainda não pertence a conta alguma.
const {
  reenviando,
  aviso: avisoDeReenvio,
  erro: erroDeReenvio,
  emPausa,
  tempoRestante,
  reenviar,
} = useReenvioDeConfirmacao(() => conta.value?.email ?? '')

const emailMudou = computed(
  () => conta.value !== null && form.email.trim().toLowerCase() !== conta.value.email.toLowerCase(),
)

const nadaMudou = computed(
  () => conta.value !== null && form.nome.trim() === conta.value.nome && !emailMudou.value,
)

const senhaPreenchida = computed(
  () => Boolean(formSenha.senha_atual && formSenha.password && formSenha.password_confirmation),
)

function preencher(dados) {
  form.nome = dados.nome ?? ''
  form.email = dados.email ?? ''
}

function limpar(alvo) {
  Object.keys(alvo).forEach((campo) => delete alvo[campo])
}

function absorverErros(excecao, alvo) {
  if (excecao instanceof ApiError && excecao.status === 422) {
    Object.entries(excecao.errors).forEach(([campo, mensagens]) => {
      alvo[campo] = mensagens[0]
    })
  }

  return excecao.message
}

async function carregar() {
  carregando.value = true
  erro.value = ''

  try {
    const resposta = await apiGet('/api/conta')
    conta.value = resposta.conta
    preencher(resposta.conta)
  } catch (excecao) {
    erro.value = excecao.message
  } finally {
    carregando.value = false
  }
}

/**
 * RF06a — a troca de endereço reinicia a verificação e suspende os lembretes.
 * A consequência é dita antes de acontecer, no diálogo, e não depois em aviso
 * de rodapé: quem já trocou não tem o que fazer com a informação.
 */
function submeter() {
  if (emailMudou.value) {
    confirmandoEmail.value = true

    return
  }

  salvar()
}

async function salvar() {
  salvando.value = true
  erroDeEnvio.value = ''
  avisos.value = []
  limpar(erros)

  try {
    const resposta = await apiPost('/api/conta', { nome: form.nome, email: form.email })

    conta.value = resposta.conta
    avisos.value = resposta.avisos
    preencher(resposta.conta)
    confirmandoEmail.value = false

    // O cabeçalho exibe as iniciais e a tarja de e-mail não confirmado a partir
    // da sessão: sem recarregá-la, a moldura contradiz a tela que acabou de
    // salvar.
    await sessao.carregar({ recarregar: true })
  } catch (excecao) {
    erroDeEnvio.value = absorverErros(excecao, erros)
    confirmandoEmail.value = false
  } finally {
    salvando.value = false
  }
}

function descartar() {
  limpar(erros)
  erroDeEnvio.value = ''
  avisos.value = []
  preencher(conta.value)
}

async function trocarSenha() {
  trocandoSenha.value = true
  erroDeSenha.value = ''
  avisoDeSenha.value = ''
  limpar(errosSenha)

  try {
    const resposta = await apiPost('/api/conta/senha', { ...formSenha })

    avisoDeSenha.value = resposta.message
    formSenha.senha_atual = ''
    formSenha.password = ''
    formSenha.password_confirmation = ''
  } catch (excecao) {
    erroDeSenha.value = absorverErros(excecao, errosSenha)
  } finally {
    trocandoSenha.value = false
  }
}

async function sair() {
  saindo.value = true
  await sessao.encerrar()
  router.push({ name: 'login' })
}

carregar()
</script>

<template>
  <!--
    `amplo` para que a coluna se centre na área de conteúdo inteira: a tela traz
    a própria largura de leitura, e o limite de 880 px da moldura do tutor,
    alinhado à esquerda, a deixaria fora do centro em telas largas.
  -->
  <RoleShell titulo="Minha conta" amplo>
    <div class="conta">
      <div v-if="carregando" class="cartao" aria-busy="true" aria-live="polite">
        <span class="oculto">Carregando os dados da sua conta.</span>
        <div class="esqueleto esqueleto--titulo" />
        <div class="esqueleto esqueleto--campo" />
        <div class="esqueleto esqueleto--campo" />
        <div class="esqueleto esqueleto--campo" />
      </div>

      <div v-else-if="erro" class="aviso aviso--erro" role="alert">
        <TriangleAlert :size="20" :stroke-width="1.75" />
        <div>
          <p class="aviso__titulo">Não conseguimos carregar sua conta.</p>
          <p class="aviso__texto">{{ erro }}</p>
          <button type="button" class="botao botao--secundario" @click="carregar">Tentar novamente</button>
        </div>
      </div>

      <template v-else>
        <header class="cabecalho">
          <p class="sobrelinha">Minha conta</p>
          <h1 class="titulo">{{ conta.nome }}</h1>
          <p class="cabecalho__detalhe">
            Estes são os dados com que você entra no Imunia. Eles não pertencem a clínica alguma:
            valem para todos os ambientes a que sua conta dá acesso.
          </p>
        </header>

        <form class="cartao" novalidate @submit.prevent="submeter">
          <h2 class="cartao__titulo">Dados pessoais</h2>

          <div class="campos">
            <AppInput
              id="conta-nome"
              v-model="form.nome"
              label="Nome completo"
              autocomplete="name"
              :error="erros.nome"
              hint="É o nome que aparece na carteira de vacinação dos seus animais."
            />

            <AppInput
              v-if="conta.cpf"
              id="conta-cpf"
              :model-value="formatarCpf(conta.cpf)"
              label="CPF"
              mono
              readonly
              hint="O CPF identifica você nos documentos já emitidos e não pode ser alterado por aqui."
            />

            <AppInput
              id="conta-email"
              v-model="form.email"
              label="E-mail"
              type="email"
              autocomplete="email"
              :error="erros.email"
            />
          </div>

          <!-- RF05 — a situação do endereço vale mais do que parece: sem
               confirmação, nenhum lembrete de dose é enviado (RN42). -->
          <p v-if="conta.email_verificado" class="situacao situacao--verificado">
            <CircleCheck :size="18" :stroke-width="1.75" />
            <span>E-mail confirmado. Os lembretes das próximas doses chegam neste endereço.</span>
          </p>

          <div v-else class="situacao situacao--pendente">
            <ClockAlert :size="18" :stroke-width="1.75" />
            <div class="situacao__corpo">
              <p class="situacao__texto">
                E-mail ainda não confirmado. Enquanto ele não for, nenhum lembrete de vacina é enviado.
              </p>
              <p v-if="avisoDeReenvio" class="situacao__nota" aria-live="polite">{{ avisoDeReenvio }}</p>
              <p v-if="erroDeReenvio" class="situacao__erro" aria-live="polite">{{ erroDeReenvio }}</p>
              <button
                type="button"
                class="botao botao--secundario"
                :disabled="reenviando || emPausa"
                @click="reenviar"
              >
                <template v-if="emPausa">Reenviar em {{ tempoRestante }}</template>
                <template v-else-if="reenviando">Reenviando…</template>
                <template v-else>Reenviar o e-mail de confirmação</template>
              </button>
            </div>
          </div>

          <p v-if="erroDeEnvio" class="aviso aviso--erro" role="alert">
            <TriangleAlert :size="20" :stroke-width="1.75" />
            <span>{{ erroDeEnvio }}</span>
          </p>

          <ul v-if="avisos.length" class="avisos" role="status">
            <li v-for="aviso in avisos" :key="aviso">{{ aviso }}</li>
          </ul>

          <div class="acoes">
            <button
              type="button"
              class="botao botao--secundario"
              :disabled="salvando || nadaMudou"
              @click="descartar"
            >
              Descartar
            </button>
            <button type="submit" class="botao botao--primario" :disabled="salvando || nadaMudou">
              {{ salvando ? 'Salvando…' : 'Salvar alterações' }}
            </button>
          </div>
        </form>

        <form class="cartao" novalidate @submit.prevent="trocarSenha">
          <h2 class="cartao__titulo">Senha</h2>
          <p class="cartao__texto">
            Trocar a senha encerra as sessões abertas em outros aparelhos. Esta continua valendo.
          </p>

          <div class="campos">
            <AppInput
              id="senha-atual"
              v-model="formSenha.senha_atual"
              label="Senha atual"
              type="password"
              autocomplete="current-password"
              revelavel
              :error="errosSenha.senha_atual"
            />

            <div>
              <AppInput
                id="senha-nova"
                v-model="formSenha.password"
                label="Nova senha"
                type="password"
                autocomplete="new-password"
                revelavel
                :error="errosSenha.password"
              />
              <PasswordChecklist :senha="formSenha.password" />
            </div>

            <AppInput
              id="senha-nova-2"
              v-model="formSenha.password_confirmation"
              label="Repita a nova senha"
              type="password"
              autocomplete="new-password"
              revelavel
              :invalido="Boolean(
                formSenha.password_confirmation && formSenha.password !== formSenha.password_confirmation,
              )"
            />
          </div>

          <p v-if="erroDeSenha" class="aviso aviso--erro" role="alert">
            <TriangleAlert :size="20" :stroke-width="1.75" />
            <span>{{ erroDeSenha }}</span>
          </p>

          <p v-if="avisoDeSenha" class="avisos" role="status">{{ avisoDeSenha }}</p>

          <div class="acoes">
            <button
              type="submit"
              class="botao botao--primario"
              :disabled="trocandoSenha || !senhaPreenchida || !senhaForte(formSenha.password)"
            >
              {{ trocandoSenha ? 'Alterando…' : 'Alterar senha' }}
            </button>
          </div>
        </form>

        <section class="cartao">
          <h2 class="cartao__titulo">Sessão</h2>
          <p class="cartao__texto">
            Sair encerra o acesso apenas neste aparelho. Nada é apagado, e você entra de novo com
            o mesmo e-mail e senha.
          </p>
          <div class="acoes">
            <AppButton variant="secondary" :loading="saindo" @click="sair">
              <LogOut :size="18" :stroke-width="1.75" />
              Sair da conta
            </AppButton>
          </div>
        </section>
      </template>
    </div>

    <ConfirmDialog
      :aberto="confirmandoEmail"
      titulo="Trocar o endereço de e-mail?"
      :acontece="[
        `Enviamos um link de confirmação para ${form.email}.`,
        'Você passa a entrar no Imunia com o novo endereço.',
        'Os lembretes de vacina ficam suspensos até você confirmar o novo e-mail.',
      ]"
      :nao-acontece="[
        'Sua senha continua a mesma.',
        'O histórico dos seus animais não muda.',
        'As autorizações que você concedeu continuam valendo.',
      ]"
      rotulo-confirmar="Trocar e salvar"
      :carregando="salvando"
      @confirmar="salvar"
      @cancelar="confirmandoEmail = false"
    />
  </RoleShell>
</template>

<style scoped>
.conta {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  width: 100%;
  max-width: 640px;
  margin: 0 auto;
  padding: var(--space-4);
}

.cabecalho {
  padding: var(--space-2) 0 0;
}

.sobrelinha {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--brand);
}

.titulo {
  margin: var(--space-1) 0 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  color: var(--ink);
}

.cabecalho__detalhe {
  margin: var(--space-2) 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.cartao {
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

.cartao__titulo {
  margin: 0;
  font-size: 18px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.cartao__texto {
  margin: var(--space-2) 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.campos {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  margin: var(--space-4) 0 0;
}

.situacao {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  margin: var(--space-4) 0 0;
  padding: var(--space-3);
  border-radius: var(--radius-sm);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

.situacao svg {
  flex: none;
  margin-top: 2px;
}

.situacao--verificado {
  background: var(--surface-sunken);
  color: var(--ink-muted);
}

.situacao--verificado svg {
  color: var(--status-ok);
}

.situacao--pendente {
  background: var(--surface-sunken);
  border: 1px solid var(--status-due);
}

.situacao--pendente svg {
  color: var(--status-due-text);
}

.situacao__corpo {
  flex: 1;
  min-width: 0;
}

.situacao__texto {
  margin: 0;
}

.situacao__nota {
  margin: var(--space-2) 0 0;
  color: var(--ink-muted);
}

.situacao__erro {
  margin: var(--space-2) 0 0;
  color: var(--status-late);
}

.situacao__corpo .botao {
  margin: var(--space-3) 0 0;
}

.avisos {
  margin: var(--space-4) 0 0;
  padding: var(--space-3);
  background: var(--brand-wash);
  border-radius: var(--radius-sm);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
  list-style: none;
}

.avisos li + li {
  margin-top: var(--space-2);
}

.aviso {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  margin: var(--space-4) 0 0;
  padding: var(--space-4);
  background: var(--status-late-wash);
  border: 1px solid var(--status-late);
  border-radius: var(--radius-sm);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

.aviso svg {
  flex: none;
  color: var(--status-late);
}

.aviso__titulo {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  font-weight: 600;
}

.aviso__texto {
  margin: var(--space-1) 0 var(--space-3);
  color: var(--ink-muted);
}

.acoes {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: var(--space-2);
  margin: var(--space-6) 0 0;
  padding: var(--space-4) 0 0;
  border-top: 1px solid var(--border-hairline);
}

.botao {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-2);
  min-height: 44px;
  padding: 0 var(--space-4);
  border-radius: var(--radius-sm);
  font-family: var(--font-body);
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
}

.botao--primario {
  background: var(--brand);
  border: 1px solid var(--brand);
  color: #fff;
}

.botao--primario:hover:not(:disabled) {
  background: var(--brand-hover);
}

.botao--secundario {
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  color: var(--ink);
}

.botao--secundario:hover:not(:disabled) {
  background: var(--surface-sunken);
}

.botao:disabled {
  opacity: .55;
  cursor: not-allowed;
}

.esqueleto {
  border-radius: var(--radius-xs);
  background: var(--surface-sunken);
  animation: esqueleto 1.2s ease-in-out infinite;
}

.esqueleto--titulo {
  height: 24px;
  width: 40%;
  margin-bottom: var(--space-4);
}

.esqueleto--campo {
  height: 40px;
  margin-bottom: var(--space-4);
}

@keyframes esqueleto {
  0% { opacity: .55; }
  50% { opacity: 1; }
  100% { opacity: .55; }
}

@media (prefers-reduced-motion: reduce) {
  .esqueleto {
    animation: none;
  }
}

.oculto {
  position: absolute;
  width: 1px;
  height: 1px;
  margin: -1px;
  padding: 0;
  overflow: hidden;
  clip: rect(0 0 0 0);
  white-space: nowrap;
  border: 0;
}

@media (min-width: 768px) {
  .conta {
    padding: var(--space-6) var(--space-4);
  }

  .cartao {
    padding: var(--space-6);
  }
}

/*
  A densidade de desktop só entra depois do alvo mínimo de toque: §4.3 exige
  44 px abaixo de 1024 px, e a faixa de 768 a 1023 px — tablet em retrato, e o
  celular de clínica deitado — já tem largura de desktop sem ter mouse.
*/
@media (min-width: 1024px) {
  .botao {
    min-height: 40px;
  }
}
</style>
