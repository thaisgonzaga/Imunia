<script setup>
import { computed, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { Building2, CircleCheck, KeyRound, Lock, TriangleAlert, UserRoundCheck } from '@lucide/vue'
import AuthSplitPage from '@/components/auth/AuthSplitPage.vue'
import AppButton from '@/components/base/AppButton.vue'
import AppInput from '@/components/base/AppInput.vue'
import AppSelect from '@/components/base/AppSelect.vue'
import StepIndicator from '@/components/base/StepIndicator.vue'
import PasswordChecklist from '@/components/auth/PasswordChecklist.vue'
import { ESTADOS } from '@/lib/estados.js'
import { formatarDocumento, formatarTelefone, somenteDigitos } from '@/lib/masks.js'
import { apiPost, ApiError } from '@/lib/api.js'
import { senhaForte } from '@/lib/senha.js'

const router = useRouter()

const ufOptions = ESTADOS.map((estado) => ({ value: estado.sigla, label: `${estado.sigla} — ${estado.nome}` }))

// O que a conta administradora resolve a partir de hoje (RF07, RF25, RF08).
const ARGUMENTOS = [
  'Calendário vacinal calculado a cada dose registrada',
  'Cada registro leva o profissional e o CRMV de quem aplicou',
  'Histórico de outra clínica, mediante autorização do tutor',
]

const step = ref(1)
const submitting = ref(false)
const submitError = ref('')
const documentoJaCadastrado = ref(false)
const resultado = ref(null)

const form = reactive({
  tipo: 'clinica',
  nome: '',
  documento: '',
  telefone: '',
  endereco: '',
  municipio: '',
  uf: '',
  responsavel_tecnico_nome: '',
  responsavel_tecnico_crmv: '',
  responsavel_tecnico_crmv_uf: '',
  email: '',
  password: '',
  password_confirmation: '',
})

const errors = reactive({})

function limparErro(campo) {
  delete errors[campo]
}

function setCampo(campo, valor) {
  form[campo] = valor
  limparErro(campo)
}

function substituirErros(camposDoPasso, novosErros) {
  camposDoPasso.forEach((campo) => delete errors[campo])
  Object.assign(errors, novosErros)
}

/** Valida só o campo que perdeu o foco, sem tocar nos erros dos vizinhos ainda não preenchidos. */
function validarCampo(validarPasso, campo) {
  const novosErros = validarPasso()
  if (novosErros[campo]) errors[campo] = novosErros[campo]
  else delete errors[campo]
}

const CAMPOS_PASSO_1 = ['nome', 'documento', 'telefone', 'endereco', 'municipio', 'uf']
const CAMPOS_PASSO_2 = ['responsavel_tecnico_nome', 'responsavel_tecnico_crmv', 'responsavel_tecnico_crmv_uf']
const CAMPOS_PASSO_3 = ['email', 'password', 'password_confirmation']

function validarPasso1() {
  const novosErros = {}
  if (!form.nome.trim()) novosErros.nome = 'Informe a razão social ou o nome.'
  const digitosDocumento = somenteDigitos(form.documento)
  if (digitosDocumento.length !== 11 && digitosDocumento.length !== 14) {
    novosErros.documento = 'Informe um CPF ou CNPJ válido.'
  }
  if (!form.telefone.trim()) novosErros.telefone = 'Informe um telefone de contato.'
  if (!form.endereco.trim()) novosErros.endereco = 'Informe o endereço.'
  if (!form.municipio.trim()) novosErros.municipio = 'Informe o município.'
  if (!form.uf) novosErros.uf = 'Selecione a UF.'
  return novosErros
}

function validarPasso2() {
  const novosErros = {}
  if (!form.responsavel_tecnico_nome.trim()) novosErros.responsavel_tecnico_nome = 'Informe o nome.'
  if (!form.responsavel_tecnico_crmv.trim()) novosErros.responsavel_tecnico_crmv = 'Informe o número do CRMV.'
  if (!form.responsavel_tecnico_crmv_uf) novosErros.responsavel_tecnico_crmv_uf = 'Selecione a UF do registro.'
  return novosErros
}

function validarPasso3() {
  const novosErros = {}
  if (!/^\S+@\S+\.\S+$/.test(form.email)) novosErros.email = 'Informe um e-mail válido.'
  if (!senhaForte(form.password)) novosErros.password = 'A senha ainda não atende aos critérios abaixo.'
  if (form.password !== form.password_confirmation) {
    novosErros.password_confirmation = 'As duas senhas não são iguais.'
  }
  return novosErros
}

const passo1Valido = computed(() => Object.keys(validarPasso1()).length === 0)
const passo2Valido = computed(() => Object.keys(validarPasso2()).length === 0)
const passo3Valido = computed(() => Object.keys(validarPasso3()).length === 0)

const ehAutonomo = computed(() => form.tipo === 'autonomo')

const tituloPasso2 = computed(() => (ehAutonomo.value ? 'Seus dados profissionais' : 'Responsável técnico'))
const rotuloNomePasso2 = computed(() => (ehAutonomo.value ? 'Seu nome' : 'Nome do responsável técnico'))
const rotuloCrmvPasso2 = computed(() => (ehAutonomo.value ? 'Seu CRMV' : 'CRMV'))

function onDocumentoInput(valor) {
  form.documento = formatarDocumento(valor)
  limparErro('documento')
}

function onTelefoneInput(valor) {
  form.telefone = formatarTelefone(valor)
  limparErro('telefone')
}

function avancarPasso1() {
  const novosErros = validarPasso1()
  substituirErros(CAMPOS_PASSO_1, novosErros)
  if (Object.keys(novosErros).length === 0) {
    documentoJaCadastrado.value = false
    step.value = 2
  }
}

function avancarPasso2() {
  const novosErros = validarPasso2()
  substituirErros(CAMPOS_PASSO_2, novosErros)
  if (Object.keys(novosErros).length === 0) {
    step.value = 3
  }
}

function voltar() {
  submitError.value = ''
  step.value = Math.max(1, step.value - 1)
}

const nomeEstabelecimentoResumo = computed(() => {
  const rotuloTipo = ehAutonomo.value ? 'profissional autônomo' : 'clínica veterinária'
  return `${form.nome} · ${rotuloTipo}`
})

/** O endereço vai no estado da navegação, e não na URL (§6.3) — mesmo caminho de P03. */
function irParaConfirmacao() {
  router.push({ path: '/verificar-email', state: { email: resultado.value.email } })
}

async function enviar() {
  const novosErros = validarPasso3()
  substituirErros(CAMPOS_PASSO_3, novosErros)
  if (Object.keys(novosErros).length > 0) return

  submitting.value = true
  submitError.value = ''
  documentoJaCadastrado.value = false

  try {
    const resposta = await apiPost('/api/prestadores', {
      tipo: form.tipo,
      nome: form.nome,
      documento: form.documento,
      telefone: form.telefone,
      endereco: form.endereco,
      municipio: form.municipio,
      uf: form.uf,
      responsavel_tecnico_nome: form.responsavel_tecnico_nome,
      responsavel_tecnico_crmv: form.responsavel_tecnico_crmv,
      responsavel_tecnico_crmv_uf: form.responsavel_tecnico_crmv_uf,
      email: form.email,
      password: form.password,
      password_confirmation: form.password_confirmation,
    })

    resultado.value = { ...resposta.prestador, email: form.email }
  } catch (erro) {
    if (erro instanceof ApiError && erro.status === 422) {
      if (erro.errors.documento) {
        documentoJaCadastrado.value = true
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

function corrigirDocumento() {
  documentoJaCadastrado.value = false
}
</script>

<template>
  <AuthSplitPage
    titulo="O consultório com responsabilidade técnica registrada em cada atendimento."
    subtitulo="Cadastre sua clínica, hospital veterinário ou atendimento autônomo e comece a registrar vacinação e prontuário com o CRMV de cada profissional."
    :itens="ARGUMENTOS"
    rodape="Imunia · calendário vacinal e prontuário para cães e gatos"
    acao-rotulo="Entrar"
    acao-destino="/entrar"
    largo
  >
    <!-- Sucesso: o estado troca na própria tela, e não em aviso solto (§6.1). -->
    <template v-if="resultado">
      <section class="wizard-card" aria-live="polite">
        <div class="wizard-card__body wizard-card__body--center">
          <p class="auth-eyebrow auth-eyebrow--marca">
            <CircleCheck :size="16" />
            Estabelecimento cadastrado
          </p>
          <h1 class="auth-title">Falta confirmar seu e-mail</h1>
          <p class="auth-text">
            <strong>{{ resultado.nome }}</strong> foi cadastrado no Imunia, em {{ resultado.municipio }}/{{ resultado.uf }}.
            Enviamos uma mensagem para <strong>{{ resultado.email }}</strong> — confirme o endereço para manter o
            acesso protegido e receber avisos sobre a conta.
          </p>

          <AppButton class="auth-submit" @click="irParaConfirmacao">Confirmar meu e-mail</AppButton>

          <div class="auth-footer">
            <RouterLink to="/entrar" class="auth-link">Entrar agora</RouterLink>
          </div>
        </div>
      </section>
    </template>

    <!-- Documento já cadastrado: informa a existência e nada além dela, mesmo padrão de P03 (RF07). -->
    <template v-else-if="documentoJaCadastrado">
      <section class="wizard-card">
        <div class="wizard-card__body">
          <h1 class="auth-title">Cadastrar meu estabelecimento</h1>

          <div class="auth-notice auth-notice--consentimento" role="alert">
            <KeyRound :size="20" />
            <div>
              <p class="auth-notice__title">Já existe um estabelecimento cadastrado com este documento.</p>
              <p class="auth-notice__detail">{{ form.documento }}</p>
            </div>
          </div>

          <RouterLink to="/entrar" class="auth-link-button auth-link-button--primary">
            Entrar na conta existente
          </RouterLink>
          <AppButton class="auth-submit" variant="secondary" @click="corrigirDocumento">
            Corrigir documento
          </AppButton>

          <p class="auth-note">
            Se você faz parte da equipe, peça a quem administra a conta que envie um convite.
          </p>
        </div>
      </section>
    </template>

    <template v-else>
      <section class="wizard-card">
        <StepIndicator :current="step" :total="3" :label="step === 1 ? 'estabelecimento' : step === 2 ? 'responsável técnico' : 'acesso'" />

        <div class="wizard-card__body">
          <!-- Passo 1 -->
          <template v-if="step === 1">
            <h1 class="auth-title auth-title--wizard">Cadastrar meu estabelecimento</h1>

            <div class="field-group">
              <span class="auth-label">Tipo</span>
              <div class="radio-list">
                <label class="radio-card" :class="{ 'radio-card--checked': form.tipo === 'clinica' }">
                  <input v-model="form.tipo" type="radio" value="clinica" name="tipo">
                  <span>Clínica ou hospital veterinário</span>
                </label>
                <label class="radio-card" :class="{ 'radio-card--checked': form.tipo === 'autonomo' }">
                  <input v-model="form.tipo" type="radio" value="autonomo" name="tipo">
                  <span>Profissional autônomo</span>
                </label>
              </div>
            </div>

            <div class="fields-grid">
              <div class="fields-grid__full">
                <AppInput
                  id="nome" label="Razão social ou nome"
                  :model-value="form.nome" @update:model-value="setCampo('nome', $event)"
                  @blur="validarCampo(validarPasso1, 'nome')"
                  :error="errors.nome"
                />
              </div>
              <AppInput
                id="documento" label="Documento de inscrição" mono inputmode="numeric"
                :model-value="form.documento" @update:model-value="onDocumentoInput"
                @blur="validarCampo(validarPasso1, 'documento')"
                :error="errors.documento"
              />
              <AppInput
                id="telefone" label="Telefone público" mono inputmode="numeric"
                :model-value="form.telefone" @update:model-value="onTelefoneInput"
                @blur="validarCampo(validarPasso1, 'telefone')"
                :error="errors.telefone"
              />
              <div class="fields-grid__full">
                <AppInput
                  id="endereco" label="Endereço"
                  :model-value="form.endereco" @update:model-value="setCampo('endereco', $event)"
                  @blur="validarCampo(validarPasso1, 'endereco')"
                  :error="errors.endereco"
                />
              </div>
              <AppInput
                id="municipio" label="Município"
                :model-value="form.municipio" @update:model-value="setCampo('municipio', $event)"
                @blur="validarCampo(validarPasso1, 'municipio')"
                :error="errors.municipio"
              />
              <AppSelect
                id="uf" label="UF" :options="ufOptions"
                :model-value="form.uf" @update:model-value="setCampo('uf', $event)"
                :error="errors.uf"
              />
            </div>

            <div class="auth-notice auth-notice--neutro">
              <Building2 :size="20" />
              <p class="auth-notice__text">
                Nome, tipo, município e contato aparecem no diretório público, onde os tutores encontram
                a clínica para autorizar. Endereço completo e documento de inscrição não são publicados.
              </p>
            </div>

            <div class="wizard-card__actions wizard-card__actions--end">
              <AppButton :disabled="!passo1Valido" @click="avancarPasso1">Continuar</AppButton>
            </div>
          </template>

          <!-- Passo 2 -->
          <template v-else-if="step === 2">
            <div v-if="ehAutonomo" class="badge">
              <UserRoundCheck :size="14" />
              Profissional autônomo
            </div>
            <h1 class="auth-title auth-title--wizard" :class="{ 'auth-title--tight': ehAutonomo }">{{ tituloPasso2 }}</h1>

            <div v-if="ehAutonomo" class="auth-notice auth-notice--consentimento">
              <KeyRound :size="20" />
              <p class="auth-notice__text">
                Como você atende sozinho, a mesma conta acumula a administração do cadastro e o atendimento
                clínico. Os dados abaixo são os seus.
              </p>
            </div>
            <p v-else class="auth-text auth-text--muted">
              O CRMV do responsável técnico identifica a clínica perante o Conselho. Cada profissional
              da equipe terá o próprio registro nos atendimentos que fizer.
            </p>

            <div class="fields-grid">
              <div class="fields-grid__full">
                <AppInput
                  id="rt-nome" :label="rotuloNomePasso2"
                  :model-value="form.responsavel_tecnico_nome" @update:model-value="setCampo('responsavel_tecnico_nome', $event)"
                  @blur="validarCampo(validarPasso2, 'responsavel_tecnico_nome')"
                  :error="errors.responsavel_tecnico_nome"
                />
              </div>
              <AppInput
                id="rt-crmv" :label="rotuloCrmvPasso2" mono inputmode="numeric"
                :model-value="form.responsavel_tecnico_crmv" @update:model-value="setCampo('responsavel_tecnico_crmv', $event)"
                @blur="validarCampo(validarPasso2, 'responsavel_tecnico_crmv')"
                :error="errors.responsavel_tecnico_crmv"
              />
              <AppSelect
                id="rt-crmv-uf" label="UF do registro" :options="ufOptions"
                :model-value="form.responsavel_tecnico_crmv_uf" @update:model-value="setCampo('responsavel_tecnico_crmv_uf', $event)"
                :error="errors.responsavel_tecnico_crmv_uf"
              />
            </div>

            <div class="wizard-card__actions">
              <AppButton variant="secondary" @click="voltar">Voltar</AppButton>
              <AppButton :disabled="!passo2Valido" @click="avancarPasso2">Continuar</AppButton>
            </div>
          </template>

          <!-- Passo 3 -->
          <template v-else>
            <h1 class="auth-title auth-title--wizard">Acesso do administrador</h1>

            <div class="fields-grid">
              <div class="fields-grid__full">
                <AppInput
                  id="email" label="E-mail" type="email" autocomplete="email"
                  :model-value="form.email" @update:model-value="setCampo('email', $event)"
                  @blur="validarCampo(validarPasso3, 'email')"
                  :error="errors.email"
                  hint="É por aqui que você recebe avisos importantes sobre a conta do estabelecimento."
                />
              </div>
              <AppInput
                id="password" label="Senha" type="password" autocomplete="new-password" revelavel
                :model-value="form.password" @update:model-value="setCampo('password', $event)"
                :error="errors.password"
              >
                <template #feedback>
                  <PasswordChecklist :senha="form.password" />
                </template>
              </AppInput>
              <AppInput
                id="password2" label="Repita a senha" type="password" autocomplete="new-password" revelavel
                :model-value="form.password_confirmation" @update:model-value="setCampo('password_confirmation', $event)"
                @blur="validarCampo(validarPasso3, 'password_confirmation')"
                :error="errors.password_confirmation"
              />
            </div>

            <div class="review-box">
              <div class="review-box__header">
                <span>Revise antes de confirmar</span>
                <button type="button" class="review-box__edit" @click="step = 1">Editar</button>
              </div>
              <div class="review-box__grid">
                <div>
                  <div class="review-box__label">Estabelecimento</div>
                  <div class="review-box__value">{{ nomeEstabelecimentoResumo }}</div>
                </div>
                <div>
                  <div class="review-box__label">Município</div>
                  <div class="review-box__value">{{ form.municipio }}, {{ form.uf }}</div>
                </div>
                <div>
                  <div class="review-box__label">Documento de inscrição</div>
                  <div class="review-box__value review-box__value--mono">{{ form.documento }}</div>
                </div>
                <div>
                  <div class="review-box__label">Responsável técnico</div>
                  <div class="review-box__value">
                    {{ form.responsavel_tecnico_nome }} ·
                    <span class="review-box__value--mono">CRMV-{{ form.responsavel_tecnico_crmv_uf }} {{ form.responsavel_tecnico_crmv }}</span>
                  </div>
                </div>
              </div>
            </div>

            <div v-if="submitError" class="auth-notice auth-notice--erro" role="alert">
              <TriangleAlert :size="20" />
              <p class="auth-notice__text">{{ submitError }}</p>
            </div>

            <div class="wizard-card__actions">
              <AppButton variant="secondary" @click="voltar" :disabled="submitting">Voltar</AppButton>
              <AppButton :disabled="!passo3Valido" :loading="submitting" @click="enviar">
                Cadastrar estabelecimento
              </AppButton>
            </div>

            <p class="auth-reassurance">
              <Lock :size="16" />
              Dados de tutores, animais e prontuários nunca passam pelo papel administrativo.
            </p>
          </template>
        </div>
      </section>
    </template>
  </AuthSplitPage>
</template>

<style scoped>
.wizard-card {
  width: 100%;
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
  overflow: hidden;
}

.wizard-card__body {
  padding: var(--space-6);
}

.wizard-card__body--center {
  text-align: center;
}

.wizard-card__body--center .auth-submit {
  margin-left: auto;
  margin-right: auto;
}

.auth-title--wizard {
  margin-top: 0;
}

.auth-title--tight {
  margin-top: var(--space-3);
}

.wizard-card__actions {
  display: flex;
  justify-content: space-between;
  gap: var(--space-3);
  margin-top: var(--space-6);
}

.wizard-card__actions--end {
  justify-content: flex-end;
}

.field-group {
  margin-top: var(--space-4);
}

.radio-list {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  margin-top: var(--space-2);
}

.radio-card {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  cursor: pointer;
  font-size: 16px;
  color: var(--ink);
}

.radio-card input {
  accent-color: var(--brand);
  width: 20px;
  height: 20px;
}

.radio-card--checked {
  background: var(--brand-wash);
  border-color: var(--brand);
  font-weight: 600;
}

.fields-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--space-4);
  margin-top: var(--space-4);
}

.fields-grid__full {
  grid-column: 1 / -1;
}

.badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 22px;
  padding: 0 10px;
  border-radius: var(--radius-pill);
  background: var(--surface-sunken);
  color: var(--ink-muted);
  font-size: 12px;
  font-weight: 600;
}

.review-box {
  margin-top: var(--space-6);
  padding: var(--space-4);
  background: var(--surface-sunken);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-sm);
}

.review-box__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
}

.review-box__header span {
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

.review-box__edit {
  background: none;
  border: none;
  padding: 0;
  font-size: 14px;
  font-weight: 600;
  color: var(--brand-bright);
  cursor: pointer;
}

.review-box__grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--space-3) var(--space-6);
  margin-top: var(--space-3);
}

.review-box__label {
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-faint);
}

.review-box__value {
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);
}

.review-box__value--mono {
  font-family: var(--font-mono);
  font-size: 15px;
  font-weight: 500;
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

@media (max-width: 767px) {
  .fields-grid,
  .review-box__grid {
    grid-template-columns: 1fr;
  }
}
</style>
