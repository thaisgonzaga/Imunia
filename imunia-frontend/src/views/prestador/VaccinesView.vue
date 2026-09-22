<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { Lock, Syringe, TriangleAlert, X } from '@lucide/vue'
import ContaShell from '@/components/prestador/ContaShell.vue'
import AppCheckbox from '@/components/base/AppCheckbox.vue'
import AppInput from '@/components/base/AppInput.vue'
import AppSelect from '@/components/base/AppSelect.vue'
import ConfirmDialog from '@/components/base/ConfirmDialog.vue'
import EmptyState from '@/components/base/EmptyState.vue'
import { apiGet, apiPost } from '@/lib/api.js'
import { emNumeros } from '@/lib/datas.js'

/**
 * A04 — as vacinas da clínica (RF23, RN30).
 *
 * A tela mostra dois acervos e escreve num só, e a separação visual é o
 * conteúdo: o catálogo da plataforma vem das diretrizes da WSAVA e vale para
 * todas as clínicas (RN31), e o acervo próprio é o que esta usa e a diretriz
 * não nomeia. Misturá-los numa tabela só, com uma coluna de origem, faria o
 * cadeado parecer um estado transitório em vez do limite que ele é.
 *
 * O bloco de agendamento é a razão de a tela existir. X02 pergunta ao
 * administrador da plataforma dez parâmetros de diretriz, porque é disso que
 * ele responde; aqui se pergunta quantas doses são e de quanto em quanto tempo
 * repetir, que é o que quem atende sabe — e a prévia mostra, em datas, o que a
 * resposta produz na carteira do tutor.
 */
const FORMULARIO_VAZIO = {
  nome_comercial: '',
  fabricante: '',
  agentes_cobertos: '',
  especie_destino: 'cao',
  via_administracao_usual: 'Subcutânea',
  doses_primeira_vez: 1,
  intervalo_semanas: 3,
  repete: true,
  periodicidade_valor: 1,
  periodicidade_unidade: 'anos',
  primeiro_reforco_diferente: false,
  primeiro_reforco_valor: 6,
  primeiro_reforco_unidade: 'meses',
}

const estado = ref(null)
const carregando = ref(true)
const erro = ref('')
const aviso = ref('')

const painelAberto = ref(false)
const editando = ref(null)
const formulario = reactive({ ...FORMULARIO_VAZIO })
const erros = reactive({})
const erroDoPainel = ref('')
const salvando = ref(false)

const previa = ref(null)
const previaErro = ref(false)
let previaAgendada = null

const pendenteDeInativacao = ref(null)
const inativando = ref(false)

const oficiais = computed(() => estado.value?.oficiais ?? [])
const proprias = computed(() => estado.value?.proprias ?? [])
const podeAdministrar = computed(() => estado.value?.pode_administrar ?? false)
const opcoes = computed(() => estado.value?.opcoes ?? {})

/** As listas fechadas vêm do servidor: são as mesmas que a validação aplica. */
function paraSelect(lista) {
  return (lista ?? []).map((item) => ({ value: String(item.valor), label: item.rotulo }))
}

const especies = computed(() => paraSelect(opcoes.value.especies))
const vias = computed(() => paraSelect(opcoes.value.vias))
const intervalos = computed(() => paraSelect(opcoes.value.intervalos_semanas))
const unidades = computed(() => paraSelect(opcoes.value.unidades))

/** P1b só existe com mais de uma dose; P3 só com série e com repetição. */
const pedeIntervalo = computed(() => Number(formulario.doses_primeira_vez) > 1)
const pedePrimeiroReforco = computed(() => pedeIntervalo.value && formulario.repete)

const tituloDoPainel = computed(() =>
  editando.value ? `Editar ${editando.value.nome_comercial}` : 'Adicionar vacina da clínica',
)

function rotuloDaEspecie(especie) {
  return { cao: 'Cão', gato: 'Gato', ambas: 'Cão e gato' }[especie] ?? especie
}

function limparErros() {
  Object.keys(erros).forEach((campo) => delete erros[campo])
}

async function carregar(prestadorId) {
  carregando.value = true
  erro.value = ''

  try {
    const consulta = prestadorId ? `?prestador=${prestadorId}` : ''
    estado.value = await apiGet(`/api/prestador/vacinas${consulta}`)
  } catch (excecao) {
    erro.value = excecao.message
  } finally {
    carregando.value = false
  }
}

function abrirCadastro() {
  editando.value = null
  Object.assign(formulario, FORMULARIO_VAZIO)
  limparErros()
  erroDoPainel.value = ''
  painelAberto.value = true
}

function abrirEdicao(item) {
  editando.value = item
  Object.assign(formulario, {
    ...FORMULARIO_VAZIO,
    nome_comercial: item.nome_comercial,
    fabricante: item.fabricante,
    agentes_cobertos: item.agentes_cobertos === 'não informado' ? '' : item.agentes_cobertos,
    especie_destino: item.especie_destino,
    via_administracao_usual: item.via_administracao_usual,
    // As respostas remontadas a partir das colunas: abrir a edição não deve
    // exigir adivinhar o que foi respondido da primeira vez.
    ...(item.agendamento_respostas ?? {}),
  })
  limparErros()
  erroDoPainel.value = ''
  painelAberto.value = true
}

function fecharPainel() {
  if (salvando.value) return

  painelAberto.value = false
  previa.value = null
}

/** O corpo do formulário, com os campos condicionais anulados. */
function corpo() {
  return {
    ...formulario,
    intervalo_semanas: pedeIntervalo.value ? Number(formulario.intervalo_semanas) : null,
    doses_primeira_vez: Number(formulario.doses_primeira_vez),
    periodicidade_valor: formulario.repete ? Number(formulario.periodicidade_valor) : null,
    periodicidade_unidade: formulario.repete ? formulario.periodicidade_unidade : null,
    primeiro_reforco_diferente: pedePrimeiroReforco.value && formulario.primeiro_reforco_diferente,
    primeiro_reforco_valor:
      pedePrimeiroReforco.value && formulario.primeiro_reforco_diferente
        ? Number(formulario.primeiro_reforco_valor)
        : null,
    primeiro_reforco_unidade:
      pedePrimeiroReforco.value && formulario.primeiro_reforco_diferente
        ? formulario.primeiro_reforco_unidade
        : null,
  }
}

/**
 * A prévia vem do servidor porque é o mesmo cálculo da carteira do tutor
 * (RNF01): uma tela que existe para mostrar a data não pode calculá-la por
 * conta própria — estaria conferindo a si mesma.
 */
async function atualizarPrevia() {
  previaErro.value = false

  try {
    const { doses_primeira_vez, intervalo_semanas, repete, periodicidade_valor,
      periodicidade_unidade, primeiro_reforco_diferente, primeiro_reforco_valor,
      primeiro_reforco_unidade } = corpo()

    previa.value = await apiPost('/api/prestador/vacinas/previa', {
      doses_primeira_vez,
      intervalo_semanas,
      repete,
      periodicidade_valor,
      periodicidade_unidade,
      primeiro_reforco_diferente,
      primeiro_reforco_valor,
      primeiro_reforco_unidade,
    })
  } catch {
    // A prévia é informativa: se ela falhar, o formulário continua utilizável e
    // a gravação dirá o que está errado, campo a campo.
    previa.value = null
    previaErro.value = true
  }
}

async function salvar() {
  salvando.value = true
  erroDoPainel.value = ''
  limparErros()

  const prestador = estado.value.prestador.id
  const destino = editando.value
    ? `/api/prestador/vacinas/${editando.value.id}?prestador=${prestador}`
    : `/api/prestador/vacinas?prestador=${prestador}`

  try {
    const resposta = await apiPost(destino, corpo())
    estado.value = { ...estado.value, ...resposta }
    aviso.value = resposta.message
    painelAberto.value = false
    previa.value = null
  } catch (excecao) {
    if (excecao.status === 422 && excecao.errors) {
      Object.entries(excecao.errors).forEach(([campo, mensagens]) => {
        erros[campo] = mensagens[0]
      })
    } else {
      erroDoPainel.value = excecao.message
    }
  } finally {
    salvando.value = false
  }
}

async function inativar() {
  inativando.value = true
  erro.value = ''

  try {
    const resposta = await apiPost(`/api/prestador/vacinas/${pendenteDeInativacao.value.id}/inativar`)
    estado.value = { ...estado.value, ...resposta }
    aviso.value = resposta.message
  } catch (excecao) {
    erro.value = excecao.message
  } finally {
    pendenteDeInativacao.value = null
    inativando.value = false
  }
}

async function reativar(item) {
  erro.value = ''

  try {
    const resposta = await apiPost(`/api/prestador/vacinas/${item.id}/reativar`)
    estado.value = { ...estado.value, ...resposta }
    aviso.value = resposta.message
  } catch (excecao) {
    erro.value = excecao.message
  }
}

/**
 * A prévia acompanha o bloco de agendamento com um respiro: cada tecla no campo
 * de prazo dispararia uma requisição, e o que importa é o valor em que a pessoa
 * parou.
 */
watch(
  () => [
    painelAberto.value,
    formulario.doses_primeira_vez,
    formulario.intervalo_semanas,
    formulario.repete,
    formulario.periodicidade_valor,
    formulario.periodicidade_unidade,
    formulario.primeiro_reforco_diferente,
    formulario.primeiro_reforco_valor,
    formulario.primeiro_reforco_unidade,
  ],
  () => {
    if (!painelAberto.value) return

    clearTimeout(previaAgendada)
    previaAgendada = setTimeout(atualizarPrevia, 300)
  },
  { immediate: true },
)

carregar()
</script>

<template>
  <ContaShell
    titulo="Vacinas"
    :prestador="estado?.prestador ?? null"
    :vinculos="estado?.vinculos ?? []"
    :convites-pendentes="estado?.equipe?.convites_pendentes ?? 0"
    :contexto-clinico="estado?.contexto_clinico ?? null"
    :atende-aqui="estado?.atende_aqui ?? false"
    :vinculos-clinicos="estado?.vinculos_clinicos ?? []"
    @trocar-prestador="carregar"
  >
    <div class="vacinas">
      <div class="vacinas__cabecalho">
        <div>
          <p class="sobrelinha">Vacinas</p>
          <h1 class="titulo">O que esta clínica aplica</h1>
        </div>
        <button
          v-if="!carregando && !erro && podeAdministrar"
          type="button"
          class="botao botao--primario"
          @click="abrirCadastro"
        >
          Adicionar vacina da clínica
        </button>
      </div>

      <p v-if="!carregando && !erro && !podeAdministrar" class="vacinas__leitura">
        <Lock :size="16" :stroke-width="1.75" />
        <span>Você atende aqui. Quem administra a conta é quem cadastra as vacinas da clínica.</span>
      </p>

      <p v-if="aviso" class="aviso aviso--sucesso" role="status">{{ aviso }}</p>

      <div v-if="carregando" class="cartao" aria-busy="true" aria-live="polite">
        <span class="visually-hidden">Carregando as vacinas.</span>
        <div v-for="n in 5" :key="n" class="esqueleto esqueleto--linha" />
      </div>

      <div v-else-if="erro" class="aviso aviso--erro" role="alert">
        <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
        <div>
          <p class="aviso__titulo">Não conseguimos carregar as vacinas.</p>
          <p class="aviso__texto">{{ erro }}</p>
          <button type="button" class="botao botao--secundario" @click="carregar()">Tentar novamente</button>
        </div>
      </div>

      <template v-else>
        <section class="secao">
          <h2 class="secao__titulo">Vacinas desta clínica</h2>
          <p class="secao__descricao">
            Você define quando o tutor deve ser lembrado, e é esse prazo que entra na carteira do animal.
          </p>

          <EmptyState
            v-if="!proprias.length"
            :icone="Syringe"
            titulo="Nenhuma vacina própria cadastrada"
            descricao="O catálogo da plataforma já cobre as vacinas das diretrizes da WSAVA. Cadastre aqui só o que ele não tem."
          >
            <button v-if="podeAdministrar" type="button" class="botao botao--primario" @click="abrirCadastro">
              Adicionar vacina da clínica
            </button>
          </EmptyState>

          <table v-else class="tabela">
            <thead>
              <tr>
                <th scope="col">Vacina</th>
                <th scope="col">Espécie</th>
                <th scope="col">Fabricante</th>
                <th scope="col">Quando lembrar</th>
                <th v-if="podeAdministrar" scope="col">Ação</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in proprias" :key="item.id" :class="{ 'tabela__linha--encerrada': !item.ativo }">
                <td data-rotulo="Vacina">
                  <span class="tabela__nome">
                    <span class="tabela__nome-comercial">{{ item.nome_comercial }}</span>
                    <span class="tabela__nome-tecnico">{{ item.agentes_cobertos }}</span>
                  </span>
                </td>
                <td data-rotulo="Espécie">
                  <span class="tabela__especie">{{ rotuloDaEspecie(item.especie_destino) }}</span>
                </td>
                <td data-rotulo="Fabricante" class="tabela__muted">{{ item.fabricante }}</td>
                <td data-rotulo="Quando lembrar">
                  <span class="tabela__agendamento">{{ item.agendamento }}</span>
                  <span v-if="!item.ativo" class="etiqueta etiqueta--inativo">inativa</span>
                </td>
                <td v-if="podeAdministrar" data-rotulo="Ação" class="tabela__acao">
                  <button type="button" class="ligacao-botao" @click="abrirEdicao(item)">Editar</button>
                  <button
                    v-if="item.ativo"
                    type="button"
                    class="ligacao-botao ligacao-botao--destrutiva"
                    @click="pendenteDeInativacao = item"
                  >
                    Inativar
                  </button>
                  <button v-else type="button" class="ligacao-botao" @click="reativar(item)">Reativar</button>
                </td>
              </tr>
            </tbody>
          </table>
        </section>

        <section class="secao">
          <h2 class="secao__titulo">Catálogo da plataforma</h2>
          <p class="secao__descricao">
            Mantido a partir das diretrizes da WSAVA e disponível para todas as clínicas. Já aparece no registro de aplicação — não é preciso cadastrar nada disso.
          </p>

          <table class="tabela">
            <thead>
              <tr>
                <th scope="col">Vacina</th>
                <th scope="col">Espécie</th>
                <th scope="col">Classificação</th>
                <th scope="col">Quando lembrar</th>
                <th scope="col">Ação</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in oficiais" :key="item.id">
                <td data-rotulo="Vacina">
                  <span class="tabela__nome">
                    <span class="tabela__nome-comercial">{{ item.nome_comercial }}</span>
                    <span class="tabela__nome-tecnico">{{ item.nome_tecnico }}</span>
                  </span>
                </td>
                <td data-rotulo="Espécie">
                  <span class="tabela__especie">{{ rotuloDaEspecie(item.especie_destino) }}</span>
                </td>
                <td data-rotulo="Classificação">
                  <span class="etiqueta" :class="{ 'etiqueta--essencial': item.classificacao === 'essencial' }">
                    {{ item.classificacao === 'essencial' ? 'essencial' : 'não essencial' }}
                  </span>
                </td>
                <td data-rotulo="Quando lembrar">
                  <span class="tabela__agendamento">{{ item.agendamento }}</span>
                </td>
                <!--
                  O cadeado vem de `bloqueado`, e não de um `v-if` sobre a
                  origem do item: a tela desenha o que o servidor disse, e a
                  recusa continua sendo dele.
                -->
                <td data-rotulo="Ação" class="tabela__acao">
                  <span v-if="item.bloqueado" class="etiqueta etiqueta--inativo">
                    <Lock :size="14" :stroke-width="1.75" />
                    definido pela plataforma
                  </span>
                </td>
              </tr>
            </tbody>
          </table>

          <aside class="limitacao">
            <Lock :size="16" :stroke-width="1.75" />
            <p>
              Os prazos do catálogo da plataforma não são editáveis aqui: o mesmo cálculo tem de
              responder igual em qualquer clínica. Se a sua conduta for outra, a ordem da dose é
              escolhida no momento do registro — o sistema sugere, e quem decide é o veterinário.
            </p>
          </aside>
        </section>
      </template>

      <Teleport to="body">
        <div v-if="painelAberto" class="painel__fundo" @click.self="fecharPainel">
          <div class="painel__folha" role="dialog" aria-modal="true" aria-labelledby="painel-titulo">
            <header class="painel__cabecalho">
              <h2 id="painel-titulo" class="painel__titulo">{{ tituloDoPainel }}</h2>
              <button type="button" class="painel__fechar" aria-label="Fechar" @click="fecharPainel">
                <X :size="20" :stroke-width="1.75" />
              </button>
            </header>

            <form class="painel__corpo" novalidate @submit.prevent="salvar">
              <h3 class="painel__secao">Identificação</h3>

              <AppInput
                id="vacina-nome"
                v-model="formulario.nome_comercial"
                label="Nome da vacina"
                placeholder="Como você a chama no dia a dia"
                :error="erros.nome_comercial"
              />

              <div class="painel__grade">
                <AppInput
                  id="vacina-fabricante"
                  v-model="formulario.fabricante"
                  label="Fabricante"
                  :error="erros.fabricante"
                />
                <AppSelect
                  id="vacina-especie"
                  v-model="formulario.especie_destino"
                  label="Espécie"
                  :options="especies"
                  :error="erros.especie_destino"
                />
              </div>

              <div class="painel__grade">
                <AppInput
                  id="vacina-agentes"
                  v-model="formulario.agentes_cobertos"
                  label="Contra o quê"
                  placeholder="Opcional"
                  :error="erros.agentes_cobertos"
                />
                <AppSelect
                  id="vacina-via"
                  v-model="formulario.via_administracao_usual"
                  label="Via de aplicação"
                  :options="vias"
                  :error="erros.via_administracao_usual"
                />
              </div>

              <h3 class="painel__secao">Quando o tutor deve ser lembrado</h3>

              <div class="painel__grade">
                <AppSelect
                  id="vacina-doses"
                  v-model="formulario.doses_primeira_vez"
                  label="Na primeira vez, quantas doses?"
                  :options="[
                    { value: '1', label: 'Uma só' },
                    { value: '2', label: 'Duas' },
                    { value: '3', label: 'Três' },
                  ]"
                  :error="erros.doses_primeira_vez"
                />
                <AppSelect
                  v-if="pedeIntervalo"
                  id="vacina-intervalo"
                  v-model="formulario.intervalo_semanas"
                  label="Com que intervalo entre elas?"
                  :options="intervalos"
                  :error="erros.intervalo_semanas"
                />
              </div>

              <AppCheckbox id="vacina-repete" v-model="formulario.repete">
                Precisa repetir depois
              </AppCheckbox>

              <div v-if="formulario.repete" class="painel__grade">
                <AppInput
                  id="vacina-periodicidade"
                  v-model="formulario.periodicidade_valor"
                  label="Repete a cada"
                  inputmode="numeric"
                  :error="erros.periodicidade_valor"
                />
                <AppSelect
                  id="vacina-periodicidade-unidade"
                  v-model="formulario.periodicidade_unidade"
                  label="Unidade"
                  :options="unidades"
                  :error="erros.periodicidade_unidade"
                />
              </div>

              <p v-else class="painel__nota">
                Dose única, sem revacinação: o tutor não recebe lembrete de reforço, e a carteira
                mostra a série como concluída.
              </p>

              <template v-if="pedePrimeiroReforco">
                <AppCheckbox id="vacina-reforco-diferente" v-model="formulario.primeiro_reforco_diferente">
                  O primeiro reforço tem prazo diferente
                </AppCheckbox>

                <div v-if="formulario.primeiro_reforco_diferente" class="painel__grade">
                  <AppInput
                    id="vacina-reforco"
                    v-model="formulario.primeiro_reforco_valor"
                    label="Primeiro reforço em"
                    inputmode="numeric"
                    :error="erros.primeiro_reforco_valor"
                  />
                  <AppSelect
                    id="vacina-reforco-unidade"
                    v-model="formulario.primeiro_reforco_unidade"
                    label="Unidade"
                    :options="unidades"
                    :error="erros.primeiro_reforco_unidade"
                  />
                </div>
              </template>

              <!--
                A prévia é a mesma conta que a carteira do tutor faz, e vem do
                servidor por isso (RNF01).
              -->
              <div v-if="previa" class="previa">
                <p class="previa__resumo">{{ previa.resumo }}</p>
                <ol class="previa__lista">
                  <li v-for="(passo, indice) in previa.passos" :key="indice" class="previa__passo">
                    <span class="previa__rotulo">{{ passo.rotulo }}</span>
                    <span class="previa__data">{{ emNumeros(passo.data) ?? 'sem nova dose' }}</span>
                  </li>
                </ol>
                <p class="previa__nota">Se a primeira dose fosse aplicada hoje.</p>
              </div>

              <p v-if="editando" class="painel__aviso">
                <Lock :size="16" :stroke-width="1.75" class="painel__aviso-icone" />
                <span>
                  O prazo novo vale para as aplicações a partir de agora. As datas já calculadas e
                  mostradas aos tutores continuam como estão.
                </span>
              </p>

              <p v-if="erroDoPainel" class="painel__erro" role="alert">{{ erroDoPainel }}</p>
            </form>

            <footer class="painel__rodape">
              <div class="painel__rodape-acoes">
                <button type="button" class="botao botao--secundario" :disabled="salvando" @click="fecharPainel">
                  Cancelar
                </button>
                <button type="button" class="botao botao--primario" :disabled="salvando" @click="salvar">
                  {{ salvando ? 'Salvando…' : 'Salvar' }}
                </button>
              </div>
            </footer>
          </div>
        </div>
      </Teleport>

      <ConfirmDialog
        :aberto="pendenteDeInativacao !== null"
        :titulo="`Inativar ${pendenteDeInativacao?.nome_comercial ?? ''}`"
        :acontece="[
          'A vacina deixa de aparecer no formulário de registro de aplicação desta clínica.',
          'O item continua nesta lista, e pode ser reativado a qualquer momento.',
        ]"
        :nao-acontece="[
          'As aplicações já registradas continuam no prontuário dos animais, com lote, data e quem aplicou.',
          'O calendário delas segue sendo calculado, e os tutores continuam recebendo os lembretes.',
        ]"
        rotulo-confirmar="Inativar vacina"
        rotulo-cancelar="Manter ativa"
        variante-confirmar="destrutiva"
        :carregando="inativando"
        @confirmar="inativar"
        @cancelar="pendenteDeInativacao = null"
      />
    </div>
  </ContaShell>
</template>

<style scoped>
.vacinas {
  width: 100%;
  max-width: 1280px;
  margin: 0 auto;
}

.vacinas__cabecalho {
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

.vacinas__leitura {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: 0 0 var(--space-4);
  color: var(--ink-muted);
  font-size: 14px;
  line-height: 20px;
}

/* Duas seções, e não uma tabela com coluna de origem: a separação é o
   conteúdo. O acervo próprio vem primeiro porque é sobre ele que se age. */
.secao {
  margin: var(--space-6) 0 0;
}

.secao__titulo {
  margin: 0;
  font-family: var(--font-display);
  font-size: 20px;
  line-height: 26px;
  font-weight: 600;
  color: var(--ink);
}

/* Sem limite de medida: as duas descrições cabem numa linha só na largura da
   tela, e é assim que devem ser lidas — a quebra volta sozinha quando não
   couber. */
.secao__descricao {
  margin: var(--space-1) 0 var(--space-3);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

/* `tabela` também não é global: o bloco é o de A03, com o modo cartão abaixo
   de 1024 px e a tabela de verdade acima. A diferença é que aqui as duas
   tabelas não têm o mesmo número de colunas — a de Ação some para quem só
   atende —, e por isso o desktop deixa o próprio elemento distribuir as
   larguras em vez de fixar uma grade. */
.tabela {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
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

/* No cartão, o nome abre a linha e o prazo e a ação fecham — o que identifica
   a vacina vem antes do que se sabe sobre ela. */
.tabela td[data-rotulo='Vacina'] {
  order: 1;
  flex: 1 1 100%;
}

.tabela td[data-rotulo='Quando lembrar'] {
  order: 4;
  flex: 1 1 100%;
}

.tabela td[data-rotulo='Ação'] {
  order: 5;
  flex: 1 1 100%;
}

.tabela td[data-rotulo='Vacina']::before,
.tabela td[data-rotulo='Ação']::before {
  content: none;
}

.tabela__nome {
  display: flex;
  flex-direction: column;
}

.tabela__nome-comercial {
  font-weight: 600;
}

.tabela__nome-tecnico {
  font-size: 12px;
  color: var(--ink-muted);
}

.tabela__muted {
  color: var(--ink-muted);
}

/* "Cão e gato" é um rótulo, não uma frase: quebrá-lo em duas linhas faria a
   linha da tabela crescer para dizer o que cabe numa. */
.tabela__especie {
  white-space: nowrap;
  color: var(--ink-muted);
}

.tabela__agendamento {
  display: block;
  font-size: 13px;
  line-height: 18px;
  color: var(--ink);
}

/* A célula não impõe display próprio: no cartão ela é bloco e na tabela é
   célula, e é o botão que empilha nos dois casos. */
.tabela__acao .ligacao-botao {
  display: block;
  /* `button` centraliza o rótulo por conta própria, e um bloco centralizado
     não começaria no mesmo ponto que o título da coluna. */
  text-align: inherit;
}

/* `botao` também não é global: como `ligacao-botao`, cada tela a declara, e
   estes valores são os de A03 — o primário é o verde da marca com texto
   branco, que é o botão do sistema inteiro. */
.botao {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 40px;
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

/* `ligacao-botao` não é classe global: cada tela que a usa a declara, e A03 é
   de onde estes valores vêm. Os 44 px são o alvo mínimo de toque de §4.3, e a
   faixa de desktop mais abaixo os devolve aos 32 px do desenho de 1440 px. */
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

.tabela__linha--encerrada {
  color: var(--ink-muted);
}

/* A pílula tem altura fixa: `nowrap` é o que garante que ela caiba nela. Sem
   isso, "não essencial" e "definido pela plataforma" quebram em duas linhas
   quando a coluna aperta, e o texto transborda o desenho da etiqueta. */
.etiqueta {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  height: 22px;
  padding: 0 var(--space-2);
  border-radius: var(--radius-pill);
  background: var(--surface-sunken);
  font-size: 12px;
  font-weight: 600;
  white-space: nowrap;
  color: var(--ink-muted);
}

.etiqueta--essencial {
  background: var(--surface-card);
  border: 1px solid var(--brand);
  color: var(--brand);
}

.etiqueta--inativo {
  background: var(--surface-sunken);
  color: var(--ink-muted);
}

.limitacao {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  margin: var(--space-4) 0 0;
  padding: var(--space-3) var(--space-4);
  background: var(--surface-sunken);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
  color: var(--ink-muted);
}

/* Sem limite de medida, como nas descrições de seção: o aviso usa a largura
   que tem e quebra só quando o texto não couber. */
.limitacao p {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
}

/* Estados ---------------------------------------------------------------- */

.cartao {
  margin: var(--space-4) 0 0;
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-sm);
}

.aviso {
  display: flex;
  gap: var(--space-3);
  margin: var(--space-4) 0 0;
  padding: var(--space-4);
  border-radius: var(--radius-sm);
}

.aviso--erro {
  background: var(--status-late-wash);
}

.aviso__icone {
  flex: none;
  color: var(--status-late);
}

.aviso__titulo {
  margin: 0;
  font-weight: 600;
  color: var(--ink);
}

.aviso__texto {
  margin: var(--space-1) 0 var(--space-3);
  color: var(--ink-muted);
}

.visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
}

/* Painel lateral ---------------------------------------------------------- */

.painel__fundo {
  position: fixed;
  inset: 0;
  z-index: 40;
  display: flex;
  justify-content: flex-end;
  background: rgba(20, 35, 31, .32);
}

.painel__folha {
  width: 100%;
  max-width: 480px;
  display: flex;
  flex-direction: column;
  background: var(--surface-card);
  box-shadow: var(--shadow-modal);
}

.painel__cabecalho {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
  padding: var(--space-4) var(--space-6);
  border-bottom: 1px solid var(--border-hairline);
}

.painel__titulo {
  margin: 0;
  font-family: var(--font-display);
  font-size: 22px;
  line-height: 28px;
  font-weight: 600;
  color: var(--ink);
}

.painel__fechar {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  background: none;
  border: none;
  border-radius: var(--radius-sm);
  color: var(--ink-muted);
  cursor: pointer;
}

.painel__corpo {
  flex: 1;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  padding: var(--space-6);
}

.painel__secao {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--brand);
}

.painel__grade {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--space-4);
}

.painel__nota {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.painel__aviso {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  margin: 0;
  padding: var(--space-3);
  background: var(--surface-sunken);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

.painel__aviso-icone {
  flex: none;
  color: var(--ink-muted);
}

.painel__erro {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--status-late);
}

.painel__rodape {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: var(--space-3);
  padding: var(--space-4) var(--space-6);
  border-top: 1px solid var(--border-hairline);
}

.painel__rodape-acoes {
  display: flex;
  gap: var(--space-2);
}

.painel__corpo :deep(.app-field__input),
.painel__corpo :deep(.app-field__select) {
  height: 40px;
  font-size: 14px;
}

/* Prévia ------------------------------------------------------------------ */

.previa {
  padding: var(--space-4);
  background: var(--surface-sunken);
  border-radius: var(--radius-sm);
}

.previa__resumo {
  margin: 0 0 var(--space-3);
  font-size: 14px;
  line-height: 20px;
  font-weight: 600;
  color: var(--ink);
}

.previa__lista {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.previa__passo {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: var(--space-3);
  font-size: 14px;
  line-height: 20px;
}

.previa__rotulo {
  color: var(--ink-muted);
}

.previa__data {
  font-variant-numeric: tabular-nums;
  color: var(--ink);
}

.previa__nota {
  margin: var(--space-3) 0 0;
  font-size: 13px;
  line-height: 18px;
  color: var(--ink-muted);
}

@media (max-width: 767px) {
  .painel__folha {
    max-width: none;
  }

  .painel__grade {
    grid-template-columns: 1fr;
  }
}

/* §4.3 — alvo mínimo de 44 px abaixo de 1024: os 40 px valem para o cursor. */
@media (max-width: 1023px) {
  .botao {
    min-height: 44px;
  }

  .painel__corpo :deep(.app-field__input),
  .painel__corpo :deep(.app-field__select) {
    height: 48px;
    font-size: 16px;
  }
}

/* A partir daqui o cursor substitui o dedo, e a ação da tabela volta à
   densidade do desenho de 1440 px — como em A03. É também onde o cartão volta
   a ser tabela: o cabeçalho reaparece e o rótulo repetido em cada célula sai. */
@media (min-width: 1024px) {
  .ligacao-botao {
    min-height: 32px;
  }

  .tabela thead {
    display: table-header-group;
  }

  .tabela tr {
    display: table-row;
    padding: 0;
    border-top: 0;
  }

  .tabela thead tr {
    background: var(--surface-sunken);
  }

  /* Coluna inteira centrada, título e conteúdo — inclusive a última, que em
     A03 encosta na direita. */
  .tabela th {
    padding: var(--space-3) var(--space-4);
    font-size: 13px;
    line-height: 16px;
    font-weight: 600;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: var(--ink-muted);
    text-align: center;
    white-space: nowrap;
  }

  .tabela td {
    display: table-cell;
    padding: var(--space-3) var(--space-4);
    border-top: 1px solid var(--border-hairline);
    vertical-align: middle;
    text-align: center;
  }

  .tabela td::before {
    content: none;
  }

  /* Dois conteúdos montam a própria caixa e por isso não obedecem ao
     `text-align` da célula: o nome é flex e centraliza pelo eixo que usa, e o
     botão é bloco de largura própria, que só a margem automática move. Sem
     isto a coluna fica com o título no centro e o dado na esquerda. */
  .tabela__nome {
    align-items: center;
  }

  .tabela__acao .ligacao-botao {
    margin-inline: auto;
  }
}
</style>
