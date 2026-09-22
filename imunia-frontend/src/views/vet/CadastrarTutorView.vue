<script setup>
import { computed, onMounted, ref } from 'vue'
import {
  Cat,
  CircleCheck,
  Dog,
  Eye,
  Hourglass,
  KeyRound,
  Mail,
  TriangleAlert,
} from '@lucide/vue'
import VetShell from '@/components/vet/VetShell.vue'
import AppInput from '@/components/base/AppInput.vue'
import AppButton from '@/components/base/AppButton.vue'
import StatusPill from '@/components/base/StatusPill.vue'
import SolicitarAutorizacaoModal from '@/components/vet/SolicitarAutorizacaoModal.vue'
import { ApiError, apiGet, apiPost } from '@/lib/api.js'
import { descreverAnimal } from '@/lib/animais.js'
import { emNumeros } from '@/lib/datas.js'
import { cpfValido, formatarCpf, somenteDigitos } from '@/lib/masks.js'

/**
 * V04 — cadastrar tutor (RF12, RF13, RF14).
 *
 * Fluxo de duas etapas em uma tela: o CPF é a chave e a barreira. Enquanto não
 * verificado, o restante do formulário permanece oculto — não desabilitado,
 * oculto —, porque um formulário visível sugere que o caminho normal é
 * preenchê-lo, e o caminho normal começa por saber se ele deve existir.
 *
 * A verificação é a própria busca de V03: mesma rota, mesma regra de revelar só
 * a existência (RN12), mesmo registro no livro de acessos (RF18b). O que muda é
 * o que a tela faz com cada resposta — "nenhum cadastro" abre o formulário;
 * "existe cadastro" é o estado crítico, e dele não sai dado algum do tutor.
 */
const contexto = ref(null)
const carregando = ref(true)
const erroDeContexto = ref('')

/** cpf → formulario | existente | vinculado → sucesso */
const etapa = ref('cpf')

const cpf = ref('')
const erroDoCpf = ref('')
const verificando = ref(false)

const formulario = ref({ nome: '', email: '' })
const errosDeCampo = ref({})
const erroDeEnvio = ref('')
const enviando = ref(false)

/** Animais do tutor já sob autorização vigente, quando a verificação os achou. */
const autorizados = ref([])

/** O cartão de existência da verificação — traz a pendência de V10, se houver. */
const existencia = ref(null)

/** V10 — o modal do pedido, aberto sobre o estado "existe cadastro". */
const solicitando = ref(false)

/** O desfecho do pedido feito nesta visita, que vira a etiqueta de espera. */
const solicitacao = ref(null)

const alvoDaSolicitacao = computed(() => ({ tipo: 'cpf', termo: somenteDigitos(cpf.value) }))

const solicitacaoPendente = computed(
  () => solicitacao.value ?? existencia.value?.solicitacao_pendente ?? null,
)

/** A resposta do cadastro — e-mail do convite e prazo, para o estado de sucesso. */
const criado = ref(null)

const prestador = computed(() => contexto.value?.prestador?.nome ?? 'este prestador')

function iconeDaEspecie(especie) {
  return especie === 'gato' ? Cat : Dog
}

function primeiroErro(campo) {
  return errosDeCampo.value[campo]?.[0] ?? ''
}

async function carregar() {
  carregando.value = true
  erroDeContexto.value = ''

  try {
    // Sem termo, a busca devolve só o contexto clínico — prestador ativo e
    // vínculos —, que é o que a moldura precisa. Nada é consultado nem
    // registrado.
    contexto.value = await apiGet('/api/clinica/buscar')
  } catch (excecao) {
    erroDeContexto.value = excecao.message
  } finally {
    carregando.value = false
  }
}

async function verificar() {
  erroDoCpf.value = ''

  // RF13 — a conferência do dígito acontece antes da consulta, e por isso a
  // requisição sequer sai: um número digitado errado não pode gerar registro
  // de acesso ao CPF de outra pessoa.
  if (!cpfValido(cpf.value)) {
    erroDoCpf.value =
      'Este CPF não é válido: o dígito verificador não confere. Confira o número com o tutor.'

    return
  }

  verificando.value = true

  try {
    const busca = new URLSearchParams({ termo: somenteDigitos(cpf.value) })
    if (contexto.value?.prestador) busca.set('prestador', contexto.value.prestador.id)

    const consulta = await apiGet(`/api/clinica/buscar?${busca}`)
    existencia.value = consulta.existencia ?? null
    solicitacao.value = null

    if (consulta.autorizados?.length) {
      autorizados.value = consulta.autorizados
      etapa.value = 'vinculado'
    } else if (consulta.existencia?.tipo === 'tutor') {
      etapa.value = 'existente'
    } else {
      etapa.value = 'formulario'
    }
  } catch (excecao) {
    erroDoCpf.value = excecao.message
  } finally {
    verificando.value = false
  }
}

async function cadastrar() {
  if (enviando.value) return

  enviando.value = true
  errosDeCampo.value = {}
  erroDeEnvio.value = ''

  try {
    const busca = new URLSearchParams()
    if (contexto.value?.prestador) busca.set('prestador', contexto.value.prestador.id)

    criado.value = await apiPost(`/api/clinica/tutores?${busca}`, {
      nome: formulario.value.nome,
      cpf: somenteDigitos(cpf.value),
      email: formulario.value.email,
    })

    etapa.value = 'sucesso'
  } catch (excecao) {
    if (excecao instanceof ApiError && excecao.status === 422) {
      errosDeCampo.value = excecao.errors
    }

    // RF12b — a janela entre a verificação e o envio: alguém cadastrou este
    // CPF nesse meio-tempo. A resposta é a mesma da verificação, e a tela
    // volta ao estado que teria mostrado.
    if (excecao instanceof ApiError && excecao.status === 409) {
      etapa.value = 'existente'
    } else {
      erroDeEnvio.value = excecao.message
    }
  } finally {
    enviando.value = false
  }
}

/** Volta à barreira do CPF, mantendo o que foi digitado no formulário. */
function trocarCpf() {
  etapa.value = 'cpf'
  autorizados.value = []
  existencia.value = null
  solicitacao.value = null
  erroDeEnvio.value = ''
  errosDeCampo.value = {}
}

function cadastrarOutro() {
  cpf.value = ''
  formulario.value = { nome: '', email: '' }
  criado.value = null
  trocarCpf()
}

function trocarPrestador(id) {
  const vinculo = (contexto.value?.vinculos ?? []).find((candidato) => candidato.id === id)
  if (!vinculo) return

  contexto.value = { ...contexto.value, prestador: vinculo }

  // O âmbito de autorização é do prestador: o que era "existe cadastro" numa
  // clínica pode ser "já autorizado" na outra. O CPF novo, não — o cadastro é
  // global, e o formulário aberto continua valendo.
  if (etapa.value === 'existente' || etapa.value === 'vinculado') verificar()
}

onMounted(carregar)
</script>

<template>
  <VetShell
    titulo="Cadastrar tutor"
    :prestador="contexto?.prestador"
    :vinculos="contexto?.vinculos ?? []"
    @trocar-prestador="trocarPrestador"
  >
    <div v-if="carregando" class="tela" aria-busy="true" aria-live="polite">
      <span class="visually-hidden">Abrindo o cadastro.</span>
      <div class="esqueleto esqueleto--titulo" />
      <div class="esqueleto esqueleto--campo" />
    </div>

    <div v-else-if="erroDeContexto" class="tela">
      <div class="aviso aviso--erro" role="alert">
        <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
        <div>
          <p class="aviso__titulo">Não conseguimos abrir o cadastro.</p>
          <p class="aviso__texto">{{ erroDeContexto }}</p>
          <button type="button" class="botao botao--secundario" @click="carregar">
            Tentar novamente
          </button>
        </div>
      </div>
    </div>

    <div v-else class="tela">
      <!-- Sucesso: o convite saiu, e a tela diz o que ele ainda não é (RF14). -->
      <section v-if="etapa === 'sucesso'" class="sucesso" role="status">
        <CircleCheck :size="32" :stroke-width="1.75" class="sucesso__icone" />
        <h1 class="sucesso__titulo">Tutor cadastrado</h1>
        <p class="sucesso__texto">
          Enviamos o convite de ativação para
          <strong>{{ criado?.convite?.email }}</strong>. Ele vale por
          {{ criado?.convite?.validade_em_dias }} dias, e é por ele que
          {{ criado?.tutor?.nome }} define a própria senha.
        </p>
        <p class="sucesso__ressalva">
          Até ativar o acesso, o tutor não recebe lembretes de vacina nem pode
          conceder autorizações. O registro clínico que você fizer vale desde já.
        </p>
        <div class="sucesso__acoes">
          <RouterLink to="/clinica/animais/novo" class="botao botao--primario">
            Cadastrar animal
          </RouterLink>
          <button type="button" class="botao botao--secundario" @click="cadastrarOutro">
            Cadastrar outro tutor
          </button>
        </div>
        <RouterLink to="/clinica/buscar" class="sucesso__voltar">Voltar à busca</RouterLink>
      </section>

      <template v-else>
        <header class="cabecalho">
          <p class="cabecalho__sobrelinha">Cadastro</p>
          <h1 class="cabecalho__titulo">Cadastrar tutor</h1>
          <p class="cabecalho__texto">
            O cadastro é único na plataforma: verifique o CPF antes de preencher
            qualquer coisa. Se o tutor já está no Imunia, o caminho é a
            autorização — nunca um segundo cadastro.
          </p>
        </header>

        <!-- Etapa 1 — o CPF, isolado. -->
        <form v-if="etapa === 'cpf'" class="etapa-cpf" @submit.prevent="verificar">
          <AppInput
            id="v04-cpf"
            :model-value="cpf"
            label="CPF do tutor"
            inputmode="numeric"
            autocomplete="off"
            placeholder="000.000.000-00"
            mono
            :error="erroDoCpf"
            hint="A verificação diz apenas se o CPF já tem cadastro — nada mais."
            @update:model-value="cpf = formatarCpf($event)"
          />
          <AppButton type="submit" :loading="verificando" class="etapa-cpf__botao">
            Verificar
          </AppButton>
        </form>

        <div v-if="etapa === 'cpf' && erroDoCpf" class="nada-consultado">
          <p class="nada-consultado__texto">
            Nenhuma consulta foi feita e nada foi registrado: a validação
            acontece antes de qualquer busca, para não gerar registro de acesso
            a partir de um número digitado errado.
          </p>
        </div>

        <!-- RF18b anunciado antes de acontecer, como em V03. -->
        <div v-if="etapa === 'cpf'" class="aviso-de-registro">
          <Eye :size="20" :stroke-width="1.75" class="aviso-de-registro__icone" />
          <p class="aviso-de-registro__texto">
            Se o CPF já tiver cadastro fora da sua carteira de autorizações, a
            consulta fica registrada e visível ao tutor.
          </p>
        </div>

        <!-- O CPF verificado, travado: trocá-lo é voltar à barreira. -->
        <div v-if="etapa !== 'cpf'" class="cpf-verificado">
          <div>
            <p class="cpf-verificado__rotulo">CPF do tutor</p>
            <p class="cpf-verificado__numero">{{ formatarCpf(cpf) }}</p>
          </div>
          <button type="button" class="cpf-verificado__trocar" @click="trocarCpf">
            Trocar CPF
          </button>
        </div>

        <!-- Estado crítico — existe cadastro, e é tudo o que a tela diz (RF13). -->
        <section v-if="etapa === 'existente'" class="secao-existente">
          <div class="consentimento">
            <div class="consentimento__topo">
              <KeyRound :size="24" :stroke-width="1.75" class="consentimento__icone" />
              <p class="consentimento__titulo">
                Já existe um cadastro com este CPF na plataforma.
              </p>
            </div>
            <p class="consentimento__detalhe">
              Para vincular este tutor ao atendimento e ver o histórico dos
              animais dele, solicite a autorização. Nome, contato e animais
              aparecem somente depois que ele autorizar {{ prestador }}.
            </p>
            <!-- V10 — o pedido é modal sobre esta tela: o CPF verificado
                 permanece atrás dele. Pendente, o botão vira etiqueta. -->
            <p v-if="solicitacaoPendente" class="consentimento__etiqueta">
              <Hourglass :size="16" :stroke-width="1.75" />
              Solicitação enviada · aguardando o tutor até
              {{ emNumeros(solicitacaoPendente.expira_em) }}
            </p>
            <button
              v-else
              type="button"
              class="botao botao--consentimento"
              @click="solicitando = true"
            >
              <KeyRound :size="16" :stroke-width="1.75" />
              Solicitar autorização ao tutor
            </button>
            <p class="consentimento__registro">
              <Eye :size="16" :stroke-width="1.75" class="consentimento__registro-icone" />
              Esta consulta ficou registrada. O tutor verá que {{ prestador }}
              pesquisou por este CPF, com data e hora.
            </p>
          </div>
        </section>

        <!-- Já autorizado: o tutor não é novo nem está fora do âmbito. -->
        <section v-else-if="etapa === 'vinculado'" class="secao-vinculado">
          <p class="secao-vinculado__texto">
            Este tutor já está na plataforma, e {{ prestador }} tem autorização
            vigente para os animais abaixo. Não há o que cadastrar aqui.
          </p>
          <div class="cartoes">
            <RouterLink
              v-for="animal in autorizados"
              :key="animal.codigo"
              :to="`/clinica/animais/${animal.codigo}`"
              class="cartao-animal"
            >
              <span class="cartao-animal__icone">
                <component :is="iconeDaEspecie(animal.especie)" :size="20" :stroke-width="1.75" />
              </span>
              <span class="cartao-animal__texto">
                <span class="cartao-animal__nome">{{ animal.nome }}</span>
                <span class="cartao-animal__meta">
                  {{ descreverAnimal(animal) }} · {{ animal.tutor }}
                </span>
                <span class="cartao-animal__codigo">{{ animal.codigo }}</span>
              </span>
              <StatusPill
                v-if="animal.situacao"
                :tipo="animal.situacao.tipo"
                :texto="animal.situacao.texto_curto"
              />
              <StatusPill v-else tipo="nao-verificada" texto="sem dados" />
            </RouterLink>
          </div>
        </section>

        <!-- Etapa 2 — o formulário, revelado só depois da barreira (RF12). -->
        <form v-else-if="etapa === 'formulario'" class="formulario" @submit.prevent="cadastrar">
          <p class="formulario__confirmacao">
            <CircleCheck :size="16" :stroke-width="1.75" class="formulario__confirmacao-icone" />
            Nenhum cadastro corresponde a este CPF — siga com o cadastro.
          </p>

          <AppInput
            id="v04-nome"
            v-model="formulario.nome"
            label="Nome completo"
            autocomplete="off"
            :error="primeiroErro('nome')"
          />

          <AppInput
            id="v04-email"
            v-model="formulario.email"
            label="E-mail do tutor"
            type="email"
            autocomplete="off"
            :error="primeiroErro('email')"
            hint="É para este endereço que vai o convite de ativação."
          />

          <!-- O bloco de convite: o que acontece ao concluir, dito antes (RF14). -->
          <div class="bloco-convite">
            <Mail :size="20" :stroke-width="1.75" class="bloco-convite__icone" />
            <p class="bloco-convite__texto">
              Ao concluir, o tutor recebe por e-mail um convite para definir a
              própria senha. Até ativar, ele não recebe lembretes nem pode
              conceder autorizações — o registro clínico vale desde já.
            </p>
          </div>

          <div v-if="erroDeEnvio" class="aviso aviso--erro" role="alert">
            <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
            <div>
              <p class="aviso__titulo">Não conseguimos concluir o cadastro.</p>
              <p class="aviso__texto aviso__texto--solto">{{ erroDeEnvio }}</p>
            </div>
          </div>

          <div class="barra">
            <AppButton type="submit" :loading="enviando">Cadastrar tutor</AppButton>
          </div>
        </form>
      </template>
    </div>

    <SolicitarAutorizacaoModal
      :aberto="solicitando"
      :prestador="prestador"
      :prestador-id="contexto?.prestador?.id"
      :alvo="alvoDaSolicitacao"
      @fechar="solicitando = false"
      @enviada="solicitacao = $event"
    />
  </VetShell>
</template>

<style scoped>
/* Coluna única (§V04): a tela é uma conversa de balcão, não um painel. */
.tela {
  width: 640px;
  max-width: 100%;
  margin: 0 auto;
  padding: var(--space-4) 0 var(--space-12);
}

.cabecalho__sobrelinha {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--brand);
}

.cabecalho__titulo {
  margin: var(--space-1) 0 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  letter-spacing: -.02em;
  color: var(--ink);
}

.cabecalho__texto {
  margin: var(--space-2) 0 0;
  max-width: 65ch;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

/* Etapa do CPF ------------------------------------------------------------- */

.etapa-cpf {
  display: flex;
  align-items: flex-start;
  gap: var(--space-2);
  margin: var(--space-6) 0 0;
}

.etapa-cpf :deep(.app-field) {
  flex: 1;
}

/* Alinha o botão à caixa do campo, abaixo do rótulo de 16 px + 6 de respiro. */
.etapa-cpf__botao {
  margin-top: 22px;
}

.nada-consultado {
  margin: var(--space-4) 0 0;
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

.nada-consultado__texto {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.aviso-de-registro {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  margin: var(--space-6) 0 0;
  padding: var(--space-4);
  background: var(--surface-sunken);
  border: 1px dashed var(--consent);
  border-radius: var(--radius-sm);
}

.aviso-de-registro__icone {
  flex: none;
  color: var(--consent);
}

.aviso-de-registro__texto {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

/* O CPF travado ------------------------------------------------------------ */

.cpf-verificado {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
  margin: var(--space-6) 0 0;
  padding: var(--space-3) var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

.cpf-verificado__rotulo {
  margin: 0;
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
}

.cpf-verificado__numero {
  margin: 0;
  font-family: var(--font-mono);
  font-size: 16px;
  line-height: 24px;
  font-weight: 500;
  font-variant-numeric: tabular-nums;
  color: var(--ink);
}

.cpf-verificado__trocar {
  flex: none;
  padding: var(--space-2) var(--space-3);
  background: none;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  font-family: inherit;
  font-size: 14px;
  font-weight: 600;
  color: var(--ink);
  cursor: pointer;
}

.cpf-verificado__trocar:hover {
  background: var(--surface-sunken);
}

/* Estado crítico — o mesmo desenho de consentimento de V03 ------------------ */

.secao-existente,
.secao-vinculado {
  margin: var(--space-6) 0 0;
}

.consentimento {
  padding: var(--space-6);
  background: var(--surface-sunken);
  border: 1px dashed var(--consent);
  border-radius: var(--radius-md);
}

.consentimento__topo {
  display: flex;
  align-items: center;
  gap: var(--space-3);
}

.consentimento__icone {
  flex: none;
  color: var(--consent);
}

.consentimento__titulo {
  margin: 0;
  max-width: 70ch;
  font-size: 18px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.consentimento__detalhe {
  margin: var(--space-2) 0 0;
  max-width: 70ch;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

/* A etiqueta de espera (V10): o mesmo lugar do botão, o oposto do convite —
   o pedido já foi feito e a vez agora é do tutor. */
.consentimento__etiqueta {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  margin: var(--space-4) 0 0;
  padding: var(--space-2) var(--space-3);
  background: var(--consent-wash);
  border: 1px solid var(--consent);
  border-radius: var(--radius-pill);
  font-size: 13px;
  line-height: 18px;
  font-weight: 600;
  color: var(--consent);
}

.consentimento__registro {
  display: flex;
  align-items: flex-start;
  gap: var(--space-2);
  margin: var(--space-4) 0 0;
  padding: var(--space-3) 0 0;
  border-top: 1px solid var(--border-strong);
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-muted);
}

.consentimento__registro-icone {
  flex: none;
  color: var(--consent);
}

/* Já autorizado ------------------------------------------------------------- */

.secao-vinculado__texto {
  margin: 0 0 var(--space-3);
  max-width: 65ch;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.cartoes {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--space-3);
}

.cartao-animal {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
  color: var(--ink);
}

.cartao-animal:hover {
  border-color: var(--brand-bright);
  color: var(--ink);
}

.cartao-animal__icone {
  display: flex;
  align-items: center;
  justify-content: center;
  flex: none;
  width: 40px;
  height: 40px;
  border-radius: var(--radius-pill);
  background: var(--surface-sunken);
  color: var(--ink-muted);
}

.cartao-animal__texto {
  flex: 1;
  min-width: 0;
}

.cartao-animal__nome {
  display: block;
  font-size: 16px;
  line-height: 24px;
  font-weight: 600;
}

.cartao-animal__meta {
  display: block;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.cartao-animal__codigo {
  display: block;
  font-family: var(--font-mono);
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
}

/* Formulário ---------------------------------------------------------------- */

.formulario {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  margin: var(--space-4) 0 0;
}

.formulario__confirmacao {
  display: flex;
  align-items: flex-start;
  gap: var(--space-2);
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--status-ok, var(--brand));
}

.formulario__confirmacao-icone {
  flex: none;
  margin-top: 2px;
}

.bloco-convite {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-sm);
}

.bloco-convite__icone {
  flex: none;
  color: var(--ink-muted);
}

.bloco-convite__texto {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

/* §V04 — barra de ação fixa no rodapé em celular. `sticky`, como em V07: fixa
   enquanto o formulário passa, sem cobrir a barra de navegação da moldura. */
.barra {
  position: sticky;
  bottom: 0;
  display: flex;
  flex-direction: column;
  padding: var(--space-3) 0;
  background: var(--surface-page);
}

/* Sucesso -------------------------------------------------------------------- */

.sucesso {
  padding: var(--space-12) 0;
  text-align: center;
}

.sucesso__icone {
  color: var(--brand);
}

.sucesso__titulo {
  margin: var(--space-3) 0 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  letter-spacing: -.02em;
  color: var(--ink);
}

.sucesso__texto {
  margin: var(--space-3) auto 0;
  max-width: 55ch;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);
}

/* RF14b — a ressalva é parte do sucesso, não nota de rodapé: o profissional
   precisa dizê-la ao tutor que está à sua frente. */
.sucesso__ressalva {
  margin: var(--space-3) auto 0;
  max-width: 55ch;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.sucesso__acoes {
  display: flex;
  justify-content: center;
  gap: var(--space-3);
  flex-wrap: wrap;
  margin: var(--space-6) 0 0;
}

.sucesso__voltar {
  display: inline-block;
  margin: var(--space-4) 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
  text-decoration: underline;
}

.sucesso__voltar:hover {
  color: var(--ink);
}

/* Botões e avisos — o vocabulário de V03 ------------------------------------ */

.botao {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-2);
  height: 40px;
  padding: 0 var(--space-4);
  border-radius: var(--radius-sm);
  font-family: inherit;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
}

.botao--primario {
  background: var(--brand);
  border: 1px solid var(--brand);
  color: var(--surface-card);
}

.botao--primario:hover {
  background: var(--brand-hover);
  border-color: var(--brand-hover);
  color: var(--surface-card);
}

.botao--secundario {
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  color: var(--ink);
}

.botao--secundario:hover {
  background: var(--surface-sunken);
  color: var(--ink);
}

.botao--consentimento {
  margin: var(--space-4) 0 0;
  background: var(--consent);
  border: 1px solid var(--consent);
  color: var(--surface-card);
}

.botao--consentimento:hover {
  background: #32427A;
  color: var(--surface-card);
}

.aviso {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  padding: var(--space-4);
  border-radius: var(--radius-md);
}

.aviso--erro {
  background: var(--status-late-wash);
  border: 1px solid var(--status-late);
}

.aviso__icone {
  flex: none;
  margin-top: 2px;
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
  margin: var(--space-1) 0 var(--space-4);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.aviso__texto--solto {
  margin-bottom: 0;
}

/* §4.3 — alvo mínimo de toque abaixo de 1024 px. Os 40 px vêm do desenho de
   1440 px e valem só lá; na largura de toque, todo acionável desta tela sobe
   para 44. */
@media (max-width: 1023px) {
  .botao,
  .cpf-verificado__trocar {
    min-height: 44px;
  }
}

/* Esqueleto ----------------------------------------------------------------- */

.esqueleto {
  border-radius: var(--radius-xs);
  background: var(--surface-sunken);
  animation: pulsar 1.2s ease-in-out infinite;
}

.esqueleto--titulo {
  height: 34px;
  width: 40%;
}

.esqueleto--campo {
  height: 48px;
  margin: var(--space-6) 0 0;
}

@keyframes pulsar {
  0%, 100% { opacity: .55; }
  50% { opacity: 1; }
}

.visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip-path: inset(50%);
  white-space: nowrap;
}
</style>
