<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { CircleCheck, ClockAlert, Lock, TriangleAlert, UserRoundCheck } from '@lucide/vue'
import ContaShell from '@/components/prestador/ContaShell.vue'
import AppInput from '@/components/base/AppInput.vue'
import AppSelect from '@/components/base/AppSelect.vue'
import ConfirmDialog from '@/components/base/ConfirmDialog.vue'
import EmptyState from '@/components/base/EmptyState.vue'
import { ApiError, apiDelete, apiGet, apiPost } from '@/lib/api.js'
import { somenteDigitos } from '@/lib/masks.js'

/**
 * A03 — equipe do prestador (RF09, RF10).
 *
 * A frase abaixo da tabela e os dois blocos do diálogo de encerramento são o
 * conteúdo da tela, não enfeite: RF10 separa o acesso, que cessa na hora, da
 * autoria, que permanece — e quem clica em "Encerrar vínculo" precisa saber
 * qual das duas coisas está fazendo antes de fazê-la.
 */
const route = useRoute()
const router = useRouter()

const FORMULARIO_VAZIO = { email: '', crmv: '', crmv_uf: 'MG' }

const equipe = ref(null)
const carregando = ref(true)
const erro = ref('')
const aviso = ref('')

const convitePaineAberto = ref(false)
const formulario = reactive({ ...FORMULARIO_VAZIO })
const errosDoConvite = reactive({})
const enviando = ref(false)
const erroDoConvite = ref('')

const reenviando = ref(null)
const pendenteDeEncerramento = ref(null)
const encerrando = ref(false)

const pendenteDeAdministracao = ref(null)
const mudandoAdministracao = ref(false)

const membros = computed(() => equipe.value?.membros ?? [])
const concessao = computed(() => equipe.value?.concessao ?? { pode_conceder: false, restricao: null })
const podeAdministrar = computed(() => equipe.value?.pode_administrar ?? false)

const quemAdministra = computed(() => {
  const nomes = equipe.value?.administrada_por ?? []

  return nomes.length === 0
    ? 'A administração desta conta é de quem a criou.'
    : `A administração desta conta é de ${nomes.join(', ')}.`
})
const ufs = computed(() => (equipe.value?.opcoes?.ufs ?? []).map((uf) => ({ value: uf, label: uf })))

/** O encerrado mais recente, para a frase que explica o que sobreviveu ao desligamento. */
const encerradoRecente = computed(() => membros.value.find((m) => m.situacao === 'encerrado') ?? null)

const alvo = computed(() => pendenteDeEncerramento.value)

/**
 * O conteúdo do diálogo de administração, nas duas direções. Os textos ficam
 * aqui, e não no template, porque o diálogo permanece montado e só alterna
 * `aberto` — o que exige que estas props tenham valor mesmo com ele fechado,
 * sob pena de o foco nunca entrar nele.
 */
const dialogoDaAdministracao = computed(() => {
  const nome = identificar(pendenteDeAdministracao.value?.membro)

  if (pendenteDeAdministracao.value?.concedendo === false) {
    return {
      titulo: `Retirar a administração de ${nome}`,
      acontece: [
        'A pessoa deixa de convidar profissionais, encerrar vínculos e alterar os dados do prestador.',
        'A tela de administração da conta fecha para ela na hora.',
      ],
      naoAcontece: [
        'O vínculo de veterinário permanece: ela continua atendendo e registrando por aqui.',
        'Os registros que assinou continuam no prontuário, com o nome e o CRMV dela.',
      ],
      confirmar: 'Retirar administração',
      cancelar: 'Manter administração',
      variante: 'destrutiva',
    }
  }

  return {
    titulo: `Conceder a administração a ${nome}`,
    acontece: [
      'A pessoa passa a convidar profissionais, encerrar vínculos e alterar os dados do prestador.',
      'O vínculo de veterinário continua como está: administrar é um papel a mais, não outro.',
    ],
    naoAcontece: [
      'O papel administrativo não alcança dado de tutor, animal ou registro clínico.',
      'A concessão não transfere a responsabilidade técnica, que continua com quem consta do cadastro.',
    ],
    confirmar: 'Conceder administração',
    cancelar: 'Cancelar',
    variante: 'primary',
  }
})

function identificar(membro) {
  return membro?.nome ?? membro?.email ?? ''
}

function limparErrosDoConvite() {
  Object.keys(errosDoConvite).forEach((campo) => delete errosDoConvite[campo])
}

async function carregar(prestadorId) {
  carregando.value = true
  erro.value = ''

  try {
    const consulta = prestadorId ? `?prestador=${prestadorId}` : ''
    equipe.value = await apiGet(`/api/prestador/equipe${consulta}`)
  } catch (excecao) {
    erro.value = excecao.message
  } finally {
    carregando.value = false
  }
}

function abrirConvite() {
  Object.assign(formulario, FORMULARIO_VAZIO)
  limparErrosDoConvite()
  erroDoConvite.value = ''
  convitePaineAberto.value = true
}

function fecharConvite() {
  if (enviando.value) return

  convitePaineAberto.value = false

  // O modal é aberto por `?convidar=1`, vindo da pendência de A01. Fechá-lo sem
  // limpar a consulta faria ele reabrir a cada recarga da página.
  if (route.query.convidar) router.replace('/prestador/equipe')
}

async function convidar() {
  enviando.value = true
  erroDoConvite.value = ''
  limparErrosDoConvite()

  try {
    const resposta = await apiPost(`/api/prestador/equipe?prestador=${equipe.value.prestador.id}`, { ...formulario })

    equipe.value = { ...equipe.value, ...resposta }
    aviso.value = resposta.message
    convitePaineAberto.value = false
    if (route.query.convidar) router.replace('/prestador/equipe')
  } catch (excecao) {
    if (excecao instanceof ApiError && excecao.status === 422) {
      Object.entries(excecao.errors).forEach(([campo, mensagens]) => {
        errosDoConvite[campo] = mensagens[0]
      })
      erroDoConvite.value = excecao.message
    } else {
      erroDoConvite.value = excecao.message
    }
  } finally {
    enviando.value = false
  }
}

async function reenviar(membro) {
  reenviando.value = membro.vinculo
  erro.value = ''

  try {
    const resposta = await apiPost(`/api/prestador/equipe/${membro.vinculo}/reenviar`, {})
    equipe.value = { ...equipe.value, ...resposta }
    aviso.value = resposta.message
  } catch (excecao) {
    erro.value = excecao.message
  } finally {
    reenviando.value = null
  }
}

/**
 * A concessão e a retirada partilham o diálogo, e por isso o alvo guarda também
 * o sentido do gesto: os dois textos de "o que acontece" são opostos, e um
 * diálogo por ação duplicaria a chamada da API para mudar duas frases.
 */
async function mudarAdministracao() {
  const { membro, concedendo } = pendenteDeAdministracao.value
  mudandoAdministracao.value = true
  erro.value = ''

  try {
    const caminho = `/api/prestador/equipe/${membro.vinculo}/administracao`
    const resposta = concedendo ? await apiPost(caminho, {}) : await apiDelete(caminho)

    equipe.value = { ...equipe.value, ...resposta }
    aviso.value = resposta.message
  } catch (excecao) {
    erro.value = excecao.message
  } finally {
    pendenteDeAdministracao.value = null
    mudandoAdministracao.value = false
  }
}

async function encerrar() {
  encerrando.value = true
  erro.value = ''

  try {
    const resposta = await apiDelete(`/api/prestador/equipe/${pendenteDeEncerramento.value.vinculo}`)
    equipe.value = { ...equipe.value, ...resposta }
    aviso.value = resposta.message
    pendenteDeEncerramento.value = null
  } catch (excecao) {
    pendenteDeEncerramento.value = null
    erro.value = excecao.message
  } finally {
    encerrando.value = false
  }
}

watch(
  () => route.query.convidar,
  (valor) => {
    if (valor) abrirConvite()
  },
  { immediate: true },
)

carregar()
</script>

<template>
  <ContaShell
    titulo="Equipe"
    :prestador="equipe?.prestador ?? null"
    :vinculos="equipe?.vinculos ?? []"
    :convites-pendentes="equipe?.contagens?.convites_pendentes ?? 0"
    :contexto-clinico="equipe?.contexto_clinico ?? null"
    :atende-aqui="equipe?.atende_aqui ?? false"
    :vinculos-clinicos="equipe?.vinculos_clinicos ?? []"
    @trocar-prestador="carregar"
  >
    <div class="equipe">
      <div class="equipe__cabecalho">
        <div>
          <p class="sobrelinha">Equipe</p>
          <h1 class="titulo">Quem atende nesta clínica</h1>
        </div>
        <button
          v-if="!carregando && !erro && podeAdministrar"
          type="button"
          class="botao botao--primario"
          @click="abrirConvite"
        >
          Convidar veterinário
        </button>
      </div>

      <p v-if="!carregando && !erro && !podeAdministrar" class="equipe__leitura">
        <Lock :size="16" :stroke-width="1.75" />
        <span>Você atende aqui. {{ quemAdministra }}</span>
      </p>

      <p v-if="aviso" class="aviso aviso--sucesso" role="status">{{ aviso }}</p>

      <div v-if="carregando" class="cartao" aria-busy="true" aria-live="polite">
        <span class="visually-hidden">Carregando a equipe.</span>
        <div v-for="n in 4" :key="n" class="esqueleto esqueleto--linha" />
      </div>

      <div v-else-if="erro" class="aviso aviso--erro" role="alert">
        <TriangleAlert :size="20" :stroke-width="1.75" />
        <div>
          <p class="aviso__titulo">Não conseguimos carregar a equipe.</p>
          <p class="aviso__texto">{{ erro }}</p>
          <button type="button" class="botao botao--secundario" @click="carregar()">Tentar novamente</button>
        </div>
      </div>

      <EmptyState
        v-else-if="!membros.length"
        :icone="UserRoundCheck"
        titulo="Nenhum veterinário na equipe"
        descricao="Sua conta administra o cadastro, mas não registra atendimento. Convide ao menos um médico-veterinário para que a clínica possa registrar vacinações."
      >
        <button type="button" class="botao botao--primario" @click="abrirConvite">Convidar veterinário</button>
      </EmptyState>

      <template v-else>
        <table class="tabela">
          <thead>
            <tr>
              <th scope="col">Nome</th>
              <th scope="col">CRMV e UF</th>
              <th scope="col">E-mail</th>
              <th scope="col">Situação</th>
              <th v-if="podeAdministrar" scope="col">Ação</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="membro in membros"
              :key="membro.vinculo"
              :class="{
                'tabela__linha--convite': membro.situacao.startsWith('convite'),
                'tabela__linha--encerrada': membro.situacao === 'encerrado',
              }"
            >
              <td data-rotulo="Nome">
                <span v-if="membro.nome" class="tabela__nome">{{ membro.nome }}</span>
                <span v-else class="tabela__nome tabela__nome--pendente">
                  {{ membro.email }}
                  <span class="tabela__nota">nome definido no primeiro acesso</span>
                </span>
                <!--
                  Quem administra a conta ao lado de quem atende: os dois papéis
                  convivem na mesma pessoa (RN05), e a equipe é onde se vê quem
                  tem qual.
                -->
                <span v-if="membro.administrador" class="tabela__papel">Administra a conta</span>
              </td>
              <td data-rotulo="CRMV e UF" class="mono">{{ membro.crmv ?? '—' }}</td>
              <td data-rotulo="E-mail" class="tabela__discreto">{{ membro.email }}</td>
              <td data-rotulo="Situação">
                <span class="situacao" :class="`situacao--${membro.situacao}`">
                  <CircleCheck v-if="membro.situacao === 'ativo'" :size="14" :stroke-width="1.75" />
                  <ClockAlert v-else-if="membro.situacao.startsWith('convite')" :size="14" :stroke-width="1.75" />
                  {{ membro.situacao_texto }}
                </span>
              </td>
              <td v-if="podeAdministrar" data-rotulo="Ação" class="tabela__acao">
                <button
                  v-if="membro.pode_reenviar"
                  type="button"
                  class="ligacao-botao"
                  :disabled="reenviando === membro.vinculo"
                  @click="reenviar(membro)"
                >
                  {{ reenviando === membro.vinculo ? 'Reenviando…' : 'Reenviar' }}
                </button>
                <button
                  v-if="membro.pode_conceder_administracao"
                  type="button"
                  class="ligacao-botao"
                  @click="pendenteDeAdministracao = { membro, concedendo: true }"
                >
                  Conceder administração
                </button>
                <button
                  v-if="membro.pode_revogar_administracao"
                  type="button"
                  class="ligacao-botao"
                  @click="pendenteDeAdministracao = { membro, concedendo: false }"
                >
                  Retirar administração
                </button>
                <button
                  v-if="membro.pode_encerrar"
                  type="button"
                  class="ligacao-botao ligacao-botao--destrutiva"
                  @click="pendenteDeEncerramento = membro"
                >
                  {{ membro.situacao.startsWith('convite') ? 'Cancelar convite' : 'Encerrar vínculo' }}
                </button>
                <!--
                  RNF09 — ação indisponível não aparece, e a recusa é do servidor.
                  Mas o motivo aparece: botão ausente sem explicação lê-se como
                  defeito, e o administrador procuraria o problema onde não está.

                  O resumo na célula e a frase inteira no `title`: a coluna de
                  150 px não comporta a frase, e deixá-la quebrar dobraria a
                  altura da linha.
                -->
                <span v-if="membro.impedimento" class="tabela__impedimento" :title="membro.impedimento">
                  <span class="tabela__impedimento-curto">{{ membro.impedimento_resumo }}</span>
                  <span class="tabela__impedimento-longo">{{ membro.impedimento }}</span>
                </span>
                <span
                  v-else-if="!membro.pode_encerrar && !membro.pode_reenviar
                    && !membro.pode_conceder_administracao && !membro.pode_revogar_administracao"
                  aria-hidden="true"
                >—</span>
              </td>
            </tr>
          </tbody>
        </table>

        <!--
          A ação ausente explicada uma vez, e não em cada linha: o motivo não é
          do membro, é de quem está olhando a tela (RNF09).
        -->
        <p v-if="podeAdministrar && concessao.restricao" class="equipe__autoria">{{ concessao.restricao }}</p>

        <!-- RF10b e RF10c — o que o encerramento não faz. -->
        <p v-if="encerradoRecente" class="equipe__autoria">
          Os registros de {{ identificar(encerradoRecente) }} continuam no prontuário da clínica, com o
          nome e o CRMV dele: encerrar vínculo remove o acesso, não a autoria.
        </p>

        <aside v-if="podeAdministrar" class="limitacao">
          <Lock :size="16" :stroke-width="1.75" />
          <p>
            Este perfil administra a conta do prestador. Dados de tutores, animais e registros clínicos
            não são acessíveis por ele.
          </p>
        </aside>
      </template>

      <Teleport to="body">
        <div v-if="convitePaineAberto" class="modal__fundo" @click.self="fecharConvite">
          <div class="modal" role="dialog" aria-modal="true" aria-labelledby="convite-titulo">
            <h2 id="convite-titulo" class="modal__titulo">Convidar veterinário</h2>
            <form class="modal__corpo" novalidate @submit.prevent="convidar">
              <AppInput
                id="convite-email"
                v-model="formulario.email"
                label="E-mail"
                type="email"
                placeholder="nome@clinica.com.br"
                :error="errosDoConvite.email"
              />
              <div class="modal__dupla">
                <AppInput
                  id="convite-crmv"
                  v-model="formulario.crmv"
                  label="CRMV"
                  placeholder="00000"
                  inputmode="numeric"
                  mono
                  hint="Apenas o número da inscrição, sem “CRMV” e sem a UF."
                  :error="errosDoConvite.crmv"
                  @update:model-value="formulario.crmv = somenteDigitos($event)"
                />
                <AppSelect
                  id="convite-uf"
                  v-model="formulario.crmv_uf"
                  label="UF"
                  :options="ufs"
                  :error="errosDoConvite.crmv_uf"
                />
              </div>
              <p class="modal__nota">
                Os três campos são obrigatórios: o CRMV informado aqui aparece para conferência quando
                a pessoa aceitar o convite, e acompanha cada registro que ela fizer. O nome e a senha
                são definidos por ela no primeiro acesso.
              </p>
              <p v-if="erroDoConvite" class="modal__erro" role="alert">{{ erroDoConvite }}</p>
              <div class="modal__acoes">
                <button type="button" class="botao botao--secundario" :disabled="enviando" @click="fecharConvite">
                  Cancelar
                </button>
                <button type="submit" class="botao botao--primario" :disabled="enviando">
                  {{ enviando ? 'Enviando…' : 'Enviar convite' }}
                </button>
              </div>
            </form>
          </div>
        </div>
      </Teleport>

      <ConfirmDialog
        :aberto="alvo !== null"
        :titulo="`Encerrar o vínculo de ${identificar(alvo)}`"
        :acontece="[
          'A pessoa perde imediatamente o acesso a este contexto e não consegue mais registrar nem consultar por aqui.',
          'A data do encerramento fica registrada na equipe.',
        ]"
        :nao-acontece="[
          'Os registros que ela produziu continuam íntegros, sob a guarda do prestador, exibindo o nome e o CRMV dela.',
          'A autoria não é removida nem anonimizada: é ela quem responde tecnicamente por aqueles atos.',
        ]"
        rotulo-confirmar="Encerrar vínculo"
        rotulo-cancelar="Manter vínculo"
        variante-confirmar="destrutiva"
        :carregando="encerrando"
        @confirmar="encerrar"
        @cancelar="pendenteDeEncerramento = null"
      />

      <ConfirmDialog
        :aberto="pendenteDeAdministracao !== null"
        :titulo="dialogoDaAdministracao.titulo"
        :acontece="dialogoDaAdministracao.acontece"
        :nao-acontece="dialogoDaAdministracao.naoAcontece"
        :rotulo-confirmar="dialogoDaAdministracao.confirmar"
        :rotulo-cancelar="dialogoDaAdministracao.cancelar"
        :variante-confirmar="dialogoDaAdministracao.variante"
        :carregando="mudandoAdministracao"
        @confirmar="mudarAdministracao"
        @cancelar="pendenteDeAdministracao = null"
      />
    </div>
  </ContaShell>
</template>

<style scoped>
.equipe {
  width: 100%;
  max-width: 1280px;
  margin: 0 auto;
}

.equipe__cabecalho {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: var(--space-4);
  flex-wrap: wrap;
  margin: 0 0 var(--space-4);
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

.cartao {
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

/*
  Tabela — vira lista de cartões abaixo de 768 px (§5.1 do briefing), pelo
  mesmo desenho de `views/vet/PendenciasView.vue`.
*/
.tabela {
  width: 100%;
  border-collapse: collapse;
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-sm);
  overflow: hidden;
}

.tabela thead {
  display: none;
}

.tabela tr {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-1) var(--space-3);
  padding: var(--space-4);
  border-top: 1px solid var(--border-hairline);
}

.tabela tbody tr:first-child {
  border-top: 0;
}

.tabela td {
  display: block;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

.tabela td::before {
  content: attr(data-rotulo) ': ';
  color: var(--ink-faint);
}

.tabela td[data-rotulo='Nome'] {
  order: 1;
  flex: 1 1 100%;
  font-weight: 600;
}

.tabela td[data-rotulo='Situação'] {
  order: 2;
  flex: 1 1 100%;
}

.tabela td[data-rotulo='CRMV e UF'] {
  order: 3;
}

.tabela td[data-rotulo='E-mail'] {
  order: 4;
  flex: 1 1 100%;
}

.tabela td[data-rotulo='Ação'] {
  order: 5;
  flex: 1 1 100%;
}

.tabela td[data-rotulo='Nome']::before,
.tabela td[data-rotulo='Situação']::before,
.tabela td[data-rotulo='E-mail']::before,
.tabela td[data-rotulo='Ação']::before {
  content: none;
}

.tabela__linha--convite {
  background: var(--consent-wash);
}

/*
  O vínculo encerrado é esmaecido, não escondido: a linha é a prova de que a
  autoria sobreviveu ao desligamento (RF10).
*/
.tabela__linha--encerrada {
  opacity: .62;
}

.tabela__nome {
  display: block;
}

.tabela__nome--pendente {
  font-weight: 400;
  color: var(--ink-muted);
}

.tabela__nota {
  display: block;
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
}

/* Papel, e não situação: fica junto do nome, e não na coluna de Situação, que
   responde outra pergunta — se a pessoa atende hoje. */
.tabela__papel {
  display: inline-block;
  margin-top: 2px;
  padding: 2px var(--space-2);
  border-radius: var(--radius-pill);
  background: var(--brand-wash);
  font-size: 12px;
  line-height: 16px;
  font-weight: 600;
  color: var(--brand);
}

.tabela__discreto {
  color: var(--ink-muted);
}

.mono {
  font-family: var(--font-mono);
  font-size: 13px;
  line-height: 18px;
  font-weight: 500;
}

.tabela__acao {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-3);
}

.tabela__impedimento {
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
}

/* No cartão do celular há largura para a frase inteira; na célula, não. */
.tabela__impedimento-curto {
  display: none;
}

.situacao {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 22px;
  padding: 0 10px;
  border-radius: var(--radius-pill);
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  font-size: 12px;
  font-weight: 600;
  color: var(--ink-muted);
  font-variant-numeric: tabular-nums;
}

.situacao--ativo {
  border-color: var(--status-ok);
  color: var(--status-ok);
}

.situacao--convite_pendente,
.situacao--convite_expirado {
  border-color: var(--consent);
  color: var(--consent);
}

.situacao--encerrado {
  background: var(--surface-sunken);
  border-color: transparent;
}

.ligacao-botao {
  min-height: 44px;
  padding: 0;
  background: none;
  border: 0;
  font-family: var(--font-body);
  font-size: 14px;
  font-weight: 600;
  color: var(--brand-bright);
  cursor: pointer;
}

.ligacao-botao:hover {
  color: var(--brand-hover);
}

.ligacao-botao--destrutiva {
  color: var(--status-late);
}

.ligacao-botao:disabled {
  opacity: .6;
  cursor: progress;
}

.equipe__leitura {
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

.equipe__leitura svg {
  flex: none;
}

.equipe__autoria {
  margin: var(--space-3) 0 0;
  max-width: 75ch;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.limitacao {
  display: flex;
  align-items: flex-start;
  gap: var(--space-2);
  margin: var(--space-4) 0 0;
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

.aviso {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  margin: 0 0 var(--space-4);
  padding: var(--space-4);
  border-radius: var(--radius-sm);
  font-size: 14px;
  line-height: 20px;
}

.aviso--erro {
  background: var(--status-late-wash);
  border: 1px solid var(--status-late);
  color: var(--status-late);
}

.aviso--sucesso {
  display: block;
  background: var(--brand-wash);
  border: 1px solid var(--brand);
  color: var(--ink);
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

.modal__fundo {
  position: fixed;
  inset: 0;
  z-index: 40;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  padding: var(--space-4);
  background: rgba(20, 35, 31, .32);
}

.modal {
  width: 100%;
  max-width: 460px;
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-modal);
  overflow: hidden;
}

.modal__titulo {
  margin: 0;
  padding: var(--space-4);
  border-bottom: 1px solid var(--border-hairline);
  font-family: var(--font-display);
  font-size: 22px;
  line-height: 28px;
  font-weight: 600;
  color: var(--ink);
}

.modal__corpo {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  padding: var(--space-4);
}

.modal__dupla {
  display: grid;
  grid-template-columns: 1fr 100px;
  gap: var(--space-4);
}

.modal__nota {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.modal__erro {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--status-late);
}

.modal__acoes {
  display: flex;
  justify-content: flex-end;
  gap: var(--space-3);
  padding: var(--space-4) 0 0;
  border-top: 1px solid var(--border-hairline);
}

.esqueleto {
  border-radius: var(--radius-xs);
  background: var(--surface-sunken);
  animation: esqueleto 1.2s ease-in-out infinite;
}

.esqueleto--linha {
  height: 48px;
}

.esqueleto--linha + .esqueleto--linha {
  margin-top: var(--space-2);
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
  .modal__fundo {
    align-items: center;
  }
}

@media (min-width: 1024px) {
  .titulo {
    font-size: 28px;
  }

  .tabela thead {
    display: table-header-group;
  }

  .tabela thead tr,
  .tabela tbody tr {
    display: grid;
    grid-template-columns: 1.4fr 1fr 1.4fr 1fr 150px;
    gap: var(--space-4);
    align-items: center;
    padding: var(--space-2) var(--space-4);
  }

  .tabela thead tr {
    padding: var(--space-3) var(--space-4);
    background: var(--surface-sunken);
    border-top: 0;
  }

  .tabela th {
    font-size: 13px;
    line-height: 16px;
    font-weight: 600;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: var(--ink-muted);
    text-align: left;
  }

  .tabela th:last-child,
  .tabela td[data-rotulo='Ação'] {
    text-align: right;
    justify-content: flex-end;
  }

  /*
    Sem isto a ordem de empilhamento do modo cartão sobrevive ao grid, e cada
    coluna cai sob o cabeçalho da vizinha.
  */
  .tabela td {
    order: 0;
    flex: none;
  }

  .tabela td::before {
    content: none;
  }

  .tabela td[data-rotulo='Ação'] {
    flex-direction: column;
    align-items: flex-end;
    gap: var(--space-1);
  }

  .ligacao-botao {
    min-height: 32px;
  }

  .tabela__impedimento {
    text-align: right;
  }

  .tabela__impedimento-curto {
    display: inline;
  }

  .tabela__impedimento-longo {
    display: none;
  }
}
</style>
