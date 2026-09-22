<script setup>
import { computed, reactive, ref } from 'vue'
import { Building2, FileCheck, Lock, TriangleAlert } from '@lucide/vue'
import ContaShell from '@/components/prestador/ContaShell.vue'
import AppInput from '@/components/base/AppInput.vue'
import AppSelect from '@/components/base/AppSelect.vue'
import { ApiError, apiGet, apiPost } from '@/lib/api.js'
import { formatarCnpj, formatarTelefone, somenteDigitos } from '@/lib/masks.js'

/**
 * A02 — dados cadastrais do prestador (RF08).
 *
 * Os avisos de consequência vêm do servidor, e não desta tela: quem sabe o que
 * de fato mudou é quem comparou os valores antes e depois. A tela que decidisse
 * sozinha quando avisar sobre a denominação teria de refazer a comparação, e
 * passaria a avisar sobre alteração que não houve toda vez que o formulário
 * fosse salvo sem mudanças.
 */
const dados = ref(null)
const opcoes = ref({ tipos: [], ufs: [] })
const historico = ref([])
const vinculos = ref([])
const convitesPendentes = ref(0)
const contextoClinico = ref(null)
const atendeAqui = ref(false)
const vinculosClinicos = ref([])
const podeAdministrar = ref(false)
const administradaPor = ref([])
const carregando = ref(true)
const erro = ref('')

const form = reactive({})
const erros = reactive({})
const salvando = ref(false)
const erroDeEnvio = ref('')
const avisos = ref([])

const CAMPOS = [
  'tipo', 'nome', 'cnpj', 'telefone', 'endereco', 'cep', 'municipio', 'uf',
  'responsavel_tecnico_nome', 'responsavel_tecnico_crmv', 'responsavel_tecnico_crmv_uf',
]

function preencher(prestador) {
  CAMPOS.forEach((campo) => {
    form[campo] = prestador[campo] ?? ''
  })

  form.cnpj = formatarCnpj(form.cnpj)
  form.telefone = formatarTelefone(form.telefone)
  form.cep = formatarCep(form.cep)
  form.responsavel_tecnico_crmv = somenteDigitos(form.responsavel_tecnico_crmv)
}

/**
 * O cadastro em forma de leitura. É a mesma informação do formulário, na mesma
 * ordem, e por isso sai daqui e não de um segundo bloco de marcação: duas
 * listas do mesmo cadastro divergiriam no primeiro campo novo.
 */
const cadastroEmLeitura = computed(() => {
  const prestador = dados.value

  if (!prestador) return []

  const tipo = opcoes.value.tipos.find((t) => t.valor === prestador.tipo)?.rotulo ?? prestador.tipo
  const crmv = prestador.responsavel_tecnico_crmv
    ? `CRMV-${prestador.responsavel_tecnico_crmv_uf} ${prestador.responsavel_tecnico_crmv}`
    : '—'

  return [
    { rotulo: 'Tipo de estabelecimento', valor: tipo },
    { rotulo: 'Razão social ou nome', valor: prestador.nome },
    { rotulo: 'CNPJ', valor: formatarCnpj(prestador.cnpj), mono: true },
    { rotulo: 'Endereço', valor: prestador.endereco },
    { rotulo: 'Município e UF', valor: `${prestador.municipio}, ${prestador.uf}` },
    { rotulo: 'CEP', valor: formatarCep(prestador.cep) || '—', mono: true },
    { rotulo: 'Contato público', valor: formatarTelefone(prestador.telefone), mono: true },
    { rotulo: 'Responsável técnico', valor: prestador.responsavel_tecnico_nome ?? 'não informado' },
    { rotulo: 'CRMV do responsável', valor: crmv, mono: true },
  ]
})

const quemAdministra = computed(() => (administradaPor.value.length === 0
  ? 'A administração desta conta é de quem a criou.'
  : `A administração desta conta é de ${administradaPor.value.join(', ')}.`))

function formatarCep(valor) {
  const digitos = String(valor ?? '').replace(/\D/g, '').slice(0, 8)

  return digitos.length > 5 ? `${digitos.slice(0, 5)}-${digitos.slice(5)}` : digitos
}

function limparErros() {
  Object.keys(erros).forEach((campo) => delete erros[campo])
}

async function carregar(prestadorId) {
  carregando.value = true
  erro.value = ''

  try {
    const consulta = prestadorId ? `?prestador=${prestadorId}` : ''
    const resposta = await apiGet(`/api/prestador/dados${consulta}`)

    dados.value = resposta.prestador
    opcoes.value = resposta.opcoes
    historico.value = resposta.historico
    vinculos.value = resposta.vinculos
    convitesPendentes.value = resposta.equipe.convites_pendentes
    contextoClinico.value = resposta.contexto_clinico
    atendeAqui.value = resposta.atende_aqui
    vinculosClinicos.value = resposta.vinculos_clinicos
    podeAdministrar.value = resposta.pode_administrar
    administradaPor.value = resposta.administrada_por ?? []
    preencher(resposta.prestador)
  } catch (excecao) {
    erro.value = excecao.message
  } finally {
    carregando.value = false
  }
}

async function salvar() {
  salvando.value = true
  erroDeEnvio.value = ''
  avisos.value = []
  limparErros()

  try {
    const resposta = await apiPost(`/api/prestador/dados?prestador=${dados.value.id}`, { ...form })

    dados.value = resposta.prestador
    historico.value = resposta.historico
    avisos.value = resposta.avisos
    preencher(resposta.prestador)
  } catch (excecao) {
    if (excecao instanceof ApiError && excecao.status === 422) {
      Object.entries(excecao.errors).forEach(([campo, mensagens]) => {
        erros[campo] = mensagens[0]
      })
      erroDeEnvio.value = excecao.message
    } else {
      erroDeEnvio.value = excecao.message
    }
  } finally {
    salvando.value = false
  }
}

function descartar() {
  limparErros()
  erroDeEnvio.value = ''
  avisos.value = []
  preencher(dados.value)
}

carregar()
</script>

<template>
  <ContaShell
    titulo="Dados do prestador"
    :prestador="dados ? { id: dados.id, nome: dados.nome } : null"
    :vinculos="vinculos"
    :convites-pendentes="convitesPendentes"
    :contexto-clinico="contextoClinico"
    :atende-aqui="atendeAqui"
    :vinculos-clinicos="vinculosClinicos"
    @trocar-prestador="carregar"
  >
    <div v-if="carregando" class="coluna" aria-busy="true" aria-live="polite">
      <span class="visually-hidden">Carregando os dados do prestador.</span>
      <div class="cartao">
        <div class="esqueleto esqueleto--titulo" />
        <div class="grupo__campos">
          <div v-for="n in 8" :key="n" class="esqueleto esqueleto--campo" />
        </div>
      </div>
    </div>

    <div v-else-if="erro" class="aviso aviso--erro" role="alert">
      <TriangleAlert :size="20" :stroke-width="1.75" />
      <div>
        <p class="aviso__titulo">Não conseguimos carregar os dados.</p>
        <p class="aviso__texto">{{ erro }}</p>
        <button type="button" class="botao botao--secundario" @click="carregar()">Tentar novamente</button>
      </div>
    </div>

    <!--
      Leitura não é formulário desabilitado: campo cinza que não recebe foco
      convida a tentar mesmo assim e diz "quebrado" onde deveria dizer "não é
      seu". Quem só atende aqui vê o cadastro como cadastro — texto —, e a
      frase de enquadramento nomeia quem pode mudá-lo.
    -->
    <div v-else-if="!podeAdministrar" class="coluna">
      <div class="cartao">
        <p class="sobrelinha">Onde você atende</p>
        <h1 class="titulo">{{ dados.nome }}</h1>

        <p class="leitura__nota">
          <Lock :size="16" :stroke-width="1.75" />
          <span>Você atende aqui. {{ quemAdministra }}</span>
        </p>

        <dl class="leitura">
          <div v-for="campo in cadastroEmLeitura" :key="campo.rotulo">
            <dt>{{ campo.rotulo }}</dt>
            <dd :class="{ mono: campo.mono }">{{ campo.valor }}</dd>
          </div>
        </dl>
      </div>
    </div>

    <form v-else class="coluna" novalidate @submit.prevent="salvar">
      <div class="cartao">
        <p class="sobrelinha">Dados do prestador</p>
        <h1 class="titulo">{{ dados.nome }}</h1>

        <fieldset class="grupo">
          <legend class="grupo__rotulo">Denominação</legend>
          <div class="grupo__campos grupo__campos--um">
            <AppSelect
              id="tipo"
              v-model="form.tipo"
              label="Tipo de estabelecimento"
              :options="opcoes.tipos.map((t) => ({ value: t.valor, label: t.rotulo }))"
              :error="erros.tipo"
            />
            <AppInput
              id="nome"
              v-model="form.nome"
              label="Razão social ou nome"
              :error="erros.nome"
            />
            <AppInput
              id="cnpj"
              v-model="form.cnpj"
              label="CNPJ"
              mono
              :error="erros.cnpj"
              @update:model-value="form.cnpj = formatarCnpj($event)"
            />
          </div>

          <!-- RF08a — o documento já emitido conserva a denominação da época. -->
          <p class="nota">
            <FileCheck :size="20" :stroke-width="1.75" />
            <span>
              Documentos já exportados continuam exibindo o nome vigente na data de emissão.
              A alteração vale para os próximos.
            </span>
          </p>
        </fieldset>

        <fieldset id="endereco" class="grupo">
          <legend class="grupo__rotulo">Endereço e diretório</legend>
          <div class="grupo__campos grupo__campos--endereco">
            <AppInput id="endereco-rua" v-model="form.endereco" label="Endereço" :error="erros.endereco" />
            <AppInput id="municipio" v-model="form.municipio" label="Município" :error="erros.municipio" />
            <AppSelect
              id="uf"
              v-model="form.uf"
              label="UF"
              :options="opcoes.ufs.map((uf) => ({ value: uf, label: uf }))"
              :error="erros.uf"
            />
            <AppInput
              id="cep"
              v-model="form.cep"
              label="CEP"
              placeholder="00000-000"
              inputmode="numeric"
              mono
              :error="erros.cep"
              @update:model-value="form.cep = formatarCep($event)"
            />
            <AppInput
              id="telefone"
              v-model="form.telefone"
              label="Contato público"
              inputmode="tel"
              mono
              :error="erros.telefone"
              @update:model-value="form.telefone = formatarTelefone($event)"
            />
          </div>

          <!-- RF08b — o município alimenta o diretório consultado pelos tutores. -->
          <p class="nota nota--marca">
            <Building2 :size="20" :stroke-width="1.75" />
            <span>Alterar o município muda onde o estabelecimento aparece no diretório consultado pelos tutores.</span>
          </p>
        </fieldset>

        <fieldset id="responsavel-tecnico" class="grupo">
          <legend class="grupo__rotulo">Responsável técnico</legend>
          <div class="grupo__campos grupo__campos--responsavel">
            <AppInput
              id="rt-nome"
              v-model="form.responsavel_tecnico_nome"
              label="Nome"
              :error="erros.responsavel_tecnico_nome"
            />
            <AppInput
              id="rt-crmv"
              v-model="form.responsavel_tecnico_crmv"
              label="CRMV"
              mono
              inputmode="numeric"
              hint="Apenas o número da inscrição, sem “CRMV” e sem a UF."
              :error="erros.responsavel_tecnico_crmv"
              @update:model-value="form.responsavel_tecnico_crmv = somenteDigitos($event)"
            />
            <AppSelect
              id="rt-uf"
              v-model="form.responsavel_tecnico_crmv_uf"
              label="UF"
              :options="opcoes.ufs.map((uf) => ({ value: uf, label: uf }))"
              :error="erros.responsavel_tecnico_crmv_uf"
            />
          </div>
          <!--
            RF07c — deixar os três em branco é estado válido, e a consequência
            precisa estar dita antes de acontecer, não depois.
          -->
          <p class="nota nota--fraca">
            Os três campos podem ficar em branco — mas, sem responsável técnico, nenhuma informação
            clínica pode ser registrada por esta equipe.
          </p>
        </fieldset>

        <p v-if="erroDeEnvio" class="aviso aviso--erro" role="alert">
          <TriangleAlert :size="20" :stroke-width="1.75" />
          <span>{{ erroDeEnvio }}</span>
        </p>

        <ul v-if="avisos.length" class="avisos" role="status">
          <li v-for="aviso in avisos" :key="aviso">{{ aviso }}</li>
        </ul>

        <div class="acoes">
          <span v-if="salvando" class="acoes__estado">
            <span class="acoes__giro" aria-hidden="true" />Salvando…
          </span>
          <span v-else />
          <div class="acoes__botoes">
            <button type="button" class="botao botao--secundario" :disabled="salvando" @click="descartar">
              Descartar
            </button>
            <button type="submit" class="botao botao--primario" :disabled="salvando">
              Salvar alterações
            </button>
          </div>
        </div>

        <aside class="limitacao">
          <Lock :size="16" :stroke-width="1.75" />
          <p>
            Este perfil administra a conta do prestador. Dados de tutores, animais e registros
            clínicos não são acessíveis por ele.
          </p>
        </aside>
      </div>

      <!--
        RF08 — o histórico responde à pergunta que um documento antigo levanta:
        por que este PDF de janeiro diz outro nome?
      -->
      <section class="cartao">
        <h2 class="cartao__titulo">Histórico de alterações</h2>
        <p v-if="!historico.length" class="historico__vazio">
          Nenhuma alteração registrada até aqui.
        </p>
        <ol v-else class="historico">
          <li v-for="(alteracao, indice) in historico" :key="indice" class="historico__item">
            <p class="historico__campo">{{ alteracao.campo }}</p>
            <p class="historico__mudanca">
              <span class="historico__de">{{ alteracao.de ?? 'não informado' }}</span>
              <span aria-hidden="true">→</span>
              <span class="historico__para">{{ alteracao.para ?? 'não informado' }}</span>
            </p>
            <p class="historico__autoria">
              {{ alteracao.autor ?? 'conta removida' }} · {{ alteracao.ocorrido_em }}
            </p>
          </li>
        </ol>
      </section>
    </form>
  </ContaShell>
</template>

<style scoped>
.coluna {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  width: 100%;
  max-width: 720px;
  margin: 0 auto;
}

.cartao {
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
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

.cartao__titulo {
  margin: 0 0 var(--space-3);
  font-size: 18px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.grupo {
  margin: var(--space-6) 0 0;
  padding: var(--space-4) 0 0;
  border: 0;
  border-top: 1px solid var(--border-hairline);
}

.grupo__rotulo {
  padding: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

.grupo__campos {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--space-4);
  margin: var(--space-3) 0 0;
}

/* Duas colunas onde couberem, uma no celular: os pares são curtos e a leitura
   é de conferência — procura-se um campo, não se lê a lista inteira. */
.leitura {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--space-4);
  margin: var(--space-4) 0 0;
}

.leitura dt {
  margin: 0 0 2px;
  font-size: 13px;
  line-height: 16px;
  color: var(--ink-muted);
}

.leitura dd {
  margin: 0;
  font-size: 15px;
  line-height: 20px;
  color: var(--ink);
}

.leitura__nota {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: var(--space-3) 0 0;
  padding: var(--space-2) var(--space-3);
  background: var(--surface-sunken);
  border-radius: var(--radius-sm);
  font-size: 13px;
  line-height: 18px;
  color: var(--ink-muted);
}

.leitura__nota svg {
  flex: none;
}

@media (min-width: 768px) {
  .leitura {
    grid-template-columns: 1fr 1fr;
  }
}

.nota {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  margin: var(--space-3) 0 0;
  padding: var(--space-3);
  background: var(--surface-sunken);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

.nota svg {
  flex: none;
  margin-top: 2px;
  color: var(--ink-muted);
}

.nota--marca {
  background: var(--brand-wash);
  border-color: transparent;
}

.nota--marca svg {
  color: var(--brand);
}

.nota--fraca {
  display: block;
  background: none;
  border: 0;
  padding: 0;
  margin-top: var(--space-2);
  font-size: 13px;
  line-height: 18px;
  color: var(--ink-muted);
}

.avisos {
  margin: var(--space-4) 0 0;
  padding: var(--space-3) var(--space-3) var(--space-3) var(--space-6);
  background: var(--brand-wash);
  border-radius: var(--radius-sm);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

.avisos li + li {
  margin-top: var(--space-2);
}

.acoes {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-4);
  flex-wrap: wrap;
  margin: var(--space-6) 0 0;
  padding: var(--space-4) 0 0;
  border-top: 1px solid var(--border-hairline);
}

.acoes__estado {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.acoes__giro {
  display: inline-block;
  width: 16px;
  height: 16px;
  border: 2px solid var(--border-strong);
  border-top-color: var(--brand);
  border-radius: var(--radius-pill);
  animation: giro .9s linear infinite;
}

@keyframes giro {
  to { transform: rotate(360deg); }
}

.acoes__botoes {
  display: flex;
  gap: var(--space-2);
}

.botao {
  display: inline-flex;
  align-items: center;
  justify-content: center;
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

.botao--primario:hover {
  background: var(--brand-hover);
}

.botao--secundario {
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  color: var(--ink);
}

.botao:disabled {
  opacity: .6;
  cursor: progress;
}

.limitacao {
  display: flex;
  align-items: flex-start;
  gap: var(--space-2);
  margin: var(--space-6) 0 0;
  padding: var(--space-3);
  background: var(--surface-sunken);
  border-radius: var(--radius-sm);
}

.limitacao svg {
  flex: none;
  margin-top: 2px;
  color: var(--ink-muted);
}

.limitacao p {
  margin: 0;
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-muted);
}

.historico {
  margin: 0;
  padding: 0;
  list-style: none;
}

.historico__item + .historico__item {
  margin-top: var(--space-3);
  padding-top: var(--space-3);
  border-top: 1px solid var(--border-hairline);
}

.historico__campo {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

.historico__mudanca {
  display: flex;
  align-items: baseline;
  gap: var(--space-2);
  flex-wrap: wrap;
  margin: var(--space-1) 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

.historico__de {
  color: var(--ink-faint);
  text-decoration: line-through;
}

.historico__para {
  font-weight: 600;
}

.historico__autoria,
.historico__vazio {
  margin: var(--space-1) 0 0;
  font-size: 13px;
  line-height: 18px;
  color: var(--ink-faint);
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
  color: var(--ink);
}

.aviso__texto {
  margin: var(--space-1) 0 var(--space-3);
  color: var(--ink-muted);
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
}

@keyframes esqueleto {
  0% { opacity: .55; }
  50% { opacity: 1; }
  100% { opacity: .55; }
}

@media (prefers-reduced-motion: reduce) {
  .esqueleto,
  .acoes__giro {
    animation: none;
  }
}

.visually-hidden {
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
  .cartao {
    padding: var(--space-6);
  }

  .grupo__campos--endereco {
    grid-template-columns: 2fr 1fr 1fr;
  }

  .grupo__campos--endereco > *:first-child {
    grid-column: 1 / -1;
  }

  .grupo__campos--responsavel {
    grid-template-columns: 2fr 1fr 1fr;
  }

  .botao {
    min-height: 40px;
  }
}
</style>
