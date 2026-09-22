<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { Dog, Cat, Lock, Syringe, TriangleAlert, X } from '@lucide/vue'
import PlataformaShell from '@/components/plataforma/PlataformaShell.vue'
import AppInput from '@/components/base/AppInput.vue'
import AppSelect from '@/components/base/AppSelect.vue'
import ConfirmDialog from '@/components/base/ConfirmDialog.vue'
import EmptyState from '@/components/base/EmptyState.vue'
import { apiGet, apiPost } from '@/lib/api.js'

/**
 * X01 — catálogo de imunobiológicos (RF23). Tabela em largura total com
 * painel lateral deslizante para criação e edição, sem sair da lista
 * (§8.5 do briefing); inativação, nunca exclusão (RF23b).
 */
const ESPECIES = [
  { value: 'cao', label: 'Cão' },
  { value: 'gato', label: 'Gato' },
  { value: 'ambas', label: 'Cão e gato' },
]

const CLASSIFICACOES = [
  { value: 'essencial', label: 'Essencial' },
  { value: 'nao_essencial', label: 'Não essencial' },
]

const VIAS = [
  { value: 'Subcutânea', label: 'Subcutânea' },
  { value: 'Intramuscular', label: 'Intramuscular' },
]

const FORMULARIO_VAZIO = {
  nome_comercial: '',
  nome_tecnico: '',
  fabricante: '',
  agentes_cobertos: '',
  especie_destino: '',
  classificacao: '',
  via_administracao_usual: '',
}

const itens = ref([])
const carregando = ref(true)
const erro = ref('')

const filtroEspecie = ref('todas')
const filtroClassificacao = ref('')

const painelAberto = ref(false)
const editando = ref(null)
const formulario = reactive({ ...FORMULARIO_VAZIO })
const erros = reactive({})
const salvando = ref(false)
const erroPainel = ref('')

const pendenteDeInativacao = ref(null)
const alternandoSituacao = ref(false)

const itensVisiveis = computed(() => itens.value)

async function carregar() {
  carregando.value = true
  erro.value = ''

  const parametros = new URLSearchParams()
  if (filtroEspecie.value !== 'todas') parametros.set('especie', filtroEspecie.value)
  if (filtroClassificacao.value) parametros.set('classificacao', filtroClassificacao.value)

  try {
    const resposta = await apiGet(`/api/plataforma/catalogo?${parametros}`)
    itens.value = resposta.imunobiologicos
  } catch (excecao) {
    erro.value = excecao.message
  } finally {
    carregando.value = false
  }
}

onMounted(carregar)

function selecionarEspecie(valor) {
  filtroEspecie.value = valor
  carregar()
}

function alternarClassificacao(valor) {
  filtroClassificacao.value = filtroClassificacao.value === valor ? '' : valor
  carregar()
}

function abrirCriacao() {
  editando.value = null
  Object.assign(formulario, FORMULARIO_VAZIO)
  Object.keys(erros).forEach((chave) => delete erros[chave])
  erroPainel.value = ''
  painelAberto.value = true
}

function abrirEdicao(item) {
  editando.value = item
  Object.assign(formulario, {
    nome_comercial: item.nome_comercial,
    nome_tecnico: item.nome_tecnico,
    fabricante: item.fabricante,
    agentes_cobertos: item.agentes_cobertos,
    especie_destino: item.especie_destino,
    classificacao: item.classificacao,
    via_administracao_usual: item.via_administracao_usual,
  })
  Object.keys(erros).forEach((chave) => delete erros[chave])
  erroPainel.value = ''
  painelAberto.value = true
}

function fecharPainel() {
  if (salvando.value) return
  painelAberto.value = false
}

async function salvar() {
  salvando.value = true
  erroPainel.value = ''
  Object.keys(erros).forEach((chave) => delete erros[chave])

  const caminho = editando.value
    ? `/api/plataforma/catalogo/${editando.value.id}`
    : '/api/plataforma/catalogo'

  try {
    const resposta = await apiPost(caminho, { ...formulario })
    const salvo = resposta.imunobiologico

    if (editando.value) {
      const indice = itens.value.findIndex((item) => item.id === salvo.id)
      if (indice !== -1) itens.value[indice] = salvo
    } else {
      itens.value = [salvo, ...itens.value]
    }

    painelAberto.value = false
  } catch (excecao) {
    if (excecao.status === 422) {
      Object.entries(excecao.errors).forEach(([campo, mensagens]) => {
        erros[campo] = mensagens[0]
      })
    } else {
      erroPainel.value = excecao.message
    }
  } finally {
    salvando.value = false
  }
}

function pedirInativacao() {
  pendenteDeInativacao.value = editando.value
}

async function confirmarInativacao() {
  const item = pendenteDeInativacao.value
  alternandoSituacao.value = true

  try {
    const resposta = await apiPost(`/api/plataforma/catalogo/${item.id}/inativar`)
    aplicarSituacao(resposta.imunobiologico)
    pendenteDeInativacao.value = null
    painelAberto.value = false
  } catch (excecao) {
    erroPainel.value = excecao.message
  } finally {
    alternandoSituacao.value = false
  }
}

async function reativar(item) {
  try {
    const resposta = await apiPost(`/api/plataforma/catalogo/${item.id}/reativar`)
    aplicarSituacao(resposta.imunobiologico)
  } catch (excecao) {
    erro.value = excecao.message
  }
}

function aplicarSituacao(atualizado) {
  const indice = itens.value.findIndex((item) => item.id === atualizado.id)
  if (indice !== -1) itens.value[indice] = atualizado
}

function iconeDaEspecie(especie) {
  return especie === 'gato' ? Cat : Dog
}

function rotuloDaEspecie(especie) {
  return ESPECIES.find((opcao) => opcao.value === especie)?.label ?? especie
}
</script>

<template>
  <PlataformaShell titulo="Administração da plataforma">
    <div class="catalogo">
      <div class="catalogo__topo">
        <div>
          <p class="catalogo__rotulo">Catálogo</p>
          <h1 class="catalogo__titulo">Imunobiológicos</h1>
        </div>
        <button type="button" class="botao botao--primario" @click="abrirCriacao">
          Adicionar imunobiológico
        </button>
      </div>

      <div class="catalogo__filtros">
        <button
          type="button"
          class="pilula"
          :class="{ 'pilula--ativa': filtroEspecie === 'todas' }"
          @click="selecionarEspecie('todas')"
        >
          Todas as espécies
        </button>
        <button
          v-for="opcao in ESPECIES"
          :key="opcao.value"
          type="button"
          class="pilula"
          :class="{ 'pilula--ativa': filtroEspecie === opcao.value }"
          @click="selecionarEspecie(opcao.value)"
        >
          {{ opcao.label }}
        </button>
        <span class="catalogo__separador" aria-hidden="true" />
        <button
          v-for="opcao in CLASSIFICACOES"
          :key="opcao.value"
          type="button"
          class="pilula"
          :class="{ 'pilula--ativa': filtroClassificacao === opcao.value }"
          @click="alternarClassificacao(opcao.value)"
        >
          {{ opcao.label }}
        </button>
        <span v-if="!carregando" class="catalogo__contagem">{{ itensVisiveis.length }} itens</span>
      </div>

      <div v-if="carregando" class="cartao" aria-busy="true" aria-live="polite">
        <span class="visually-hidden">Carregando o catálogo.</span>
        <div class="esqueleto-linha" v-for="linha in 4" :key="linha">
          <div class="esqueleto esqueleto--celula" />
          <div class="esqueleto esqueleto--celula esqueleto--curta" />
          <div class="esqueleto esqueleto--pilula" />
        </div>
      </div>

      <div v-else-if="erro" class="aviso aviso--erro" role="alert">
        <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
        <div>
          <p class="aviso__titulo">Não conseguimos carregar o catálogo.</p>
          <p class="aviso__texto">{{ erro }}</p>
          <button type="button" class="botao botao--secundario" @click="carregar">
            Tentar novamente
          </button>
        </div>
      </div>

      <EmptyState
        v-else-if="itensVisiveis.length === 0"
        :icone="Syringe"
        titulo="O catálogo está vazio"
        descricao="Sem itens no catálogo, nenhum profissional consegue registrar vacinação: é do catálogo que vêm a denominação, a espécie de destino e a via usual de cada aplicação."
      >
        <button type="button" class="botao botao--primario" @click="abrirCriacao">
          Adicionar o primeiro imunobiológico
        </button>
      </EmptyState>

      <template v-else>
        <div class="tabela">
          <div class="tabela__cabecalho">
            <span>Denominação</span>
            <span>Fabricante</span>
            <span>Espécie</span>
            <span>Agentes cobertos</span>
            <span>Classif.</span>
            <span>Via usual</span>
            <span class="tabela__acao-cabecalho">Ação</span>
          </div>
          <div
            v-for="item in itensVisiveis"
            :key="item.id"
            class="tabela__linha"
            :class="{ 'tabela__linha--inativo': !item.ativo }"
          >
            <span class="tabela__nome">
              <span class="tabela__nome-comercial">{{ item.nome_comercial }}</span>
              <span class="tabela__nome-tecnico">{{ item.nome_tecnico }}</span>
            </span>
            <span class="tabela__muted">{{ item.fabricante }}</span>
            <span class="tabela__especie">
              <component :is="iconeDaEspecie(item.especie_destino)" :size="16" :stroke-width="1.75" />
              {{ rotuloDaEspecie(item.especie_destino) }}
            </span>
            <span class="tabela__muted">{{ item.agentes_cobertos }}</span>
            <span>
              <span v-if="item.ativo" class="etiqueta" :class="{ 'etiqueta--essencial': item.classificacao === 'essencial' }">
                {{ item.classificacao === 'essencial' ? 'essencial' : 'não essencial' }}
              </span>
              <span v-else class="etiqueta etiqueta--inativo">
                <Lock :size="14" :stroke-width="1.75" />
                inativo
              </span>
            </span>
            <span class="tabela__muted">{{ item.via_administracao_usual }}</span>
            <span class="tabela__acao">
              <button type="button" class="ligacao" @click="abrirEdicao(item)">Editar</button>
              <button v-if="!item.ativo" type="button" class="ligacao" @click="reativar(item)">Reativar</button>
            </span>
          </div>
        </div>
      </template>
    </div>

    <!-- Painel lateral de criação e edição — tela cheia abaixo de `md`. -->
    <Teleport to="body">
      <div v-if="painelAberto" class="painel__fundo" @click.self="fecharPainel">
        <div class="painel__folha" role="dialog" aria-modal="true" :aria-label="editando ? 'Editar imunobiológico' : 'Adicionar imunobiológico'">
          <div class="painel__cabecalho">
            <span class="painel__titulo">{{ editando ? 'Editar imunobiológico' : 'Adicionar imunobiológico' }}</span>
            <button type="button" class="painel__fechar" aria-label="Fechar" @click="fecharPainel">
              <X :size="20" :stroke-width="1.75" />
            </button>
          </div>

          <form class="painel__corpo" @submit.prevent="salvar">
            <AppInput
              id="cat-com"
              v-model="formulario.nome_comercial"
              label="Denominação comercial"
              :error="erros.nome_comercial"
            />
            <AppInput
              id="cat-tec"
              v-model="formulario.nome_tecnico"
              label="Denominação técnica"
              :error="erros.nome_tecnico"
            />
            <div class="painel__grade">
              <AppInput
                id="cat-fab"
                v-model="formulario.fabricante"
                label="Fabricante"
                :error="erros.fabricante"
              />
              <AppSelect
                id="cat-esp"
                v-model="formulario.especie_destino"
                label="Espécie de destino"
                :options="ESPECIES"
                :error="erros.especie_destino"
              />
            </div>
            <div class="campo">
              <label for="cat-ag" class="campo__rotulo">Agentes cobertos</label>
              <textarea
                id="cat-ag"
                v-model="formulario.agentes_cobertos"
                rows="2"
                class="painel__textarea"
                :class="{ 'painel__textarea--erro': erros.agentes_cobertos }"
              />
              <p v-if="erros.agentes_cobertos" class="campo__erro">{{ erros.agentes_cobertos }}</p>
            </div>
            <div class="painel__grade">
              <AppSelect
                id="cat-cls"
                v-model="formulario.classificacao"
                label="Classificação"
                :options="CLASSIFICACOES"
                :error="erros.classificacao"
              />
              <AppSelect
                id="cat-via"
                v-model="formulario.via_administracao_usual"
                label="Via usual"
                :options="VIAS"
                :error="erros.via_administracao_usual"
              />
            </div>

            <div class="painel__aviso">
              <Lock :size="20" :stroke-width="1.75" class="painel__aviso-icone" />
              <p>Itens do catálogo são inativados, nunca excluídos. A inativação impede novos registros e não afeta os registros anteriores.</p>
            </div>

            <p v-if="erroPainel" class="campo__erro">{{ erroPainel }}</p>
          </form>

          <div class="painel__rodape">
            <!-- Contorno, e não preenchido: a inativação é reversível e não
                 apaga nada (RF23b) — o vermelho cheio é da ação que encerra,
                 e o desenho reserva a ele o "Inativar" do `ConfirmDialog`,
                 depois de a pessoa ler o que acontece. -->
            <button
              v-if="editando && editando.ativo"
              type="button"
              class="botao botao--contorno-destrutivo"
              @click="pedirInativacao"
            >
              Inativar
            </button>
            <span v-else />
            <div class="painel__rodape-acoes">
              <button type="button" class="botao botao--secundario" :disabled="salvando" @click="fecharPainel">
                Cancelar
              </button>
              <button type="button" class="botao botao--primario" :disabled="salvando" @click="salvar">
                <span v-if="salvando" class="botao__girando" aria-hidden="true" />
                {{ salvando ? 'Salvando…' : 'Salvar' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <ConfirmDialog
      :aberto="pendenteDeInativacao !== null"
      titulo="Inativar imunobiológico"
      :acontece="['Este item deixa de poder ser escolhido em novo registro de vacinação.']"
      :nao-acontece="['As aplicações já registradas com ele continuam intactas na carteira dos animais.']"
      rotulo-confirmar="Inativar"
      variante-confirmar="destrutiva"
      :carregando="alternandoSituacao"
      @confirmar="confirmarInativacao"
      @cancelar="pendenteDeInativacao = null"
    />
  </PlataformaShell>
</template>

<style scoped>
.catalogo {
  width: 100%;
  max-width: 1280px;
  margin: 0 auto;
}

.catalogo__topo {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: var(--space-6);
  flex-wrap: wrap;
}

.catalogo__rotulo {
  margin: 0;
  font-size: 13px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--brand);
}

.catalogo__titulo {
  margin: 4px 0 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  color: var(--ink);
}

.catalogo__filtros {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  flex-wrap: wrap;
  margin: var(--space-4) 0 0;
  padding: var(--space-3);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-sm);
}

.catalogo__separador {
  width: 1px;
  height: 20px;
  background: var(--border-hairline);
}

.catalogo__contagem {
  margin-left: auto;
  font-size: 14px;
  color: var(--ink-muted);
  font-variant-numeric: tabular-nums;
}

/* Botões ---------------------------------------------------------------- */

/* Densidade de desktop, como em V01–V06: 40 px e 14 px, e não os 48 px e
   16 px do `AppButton`, que é dimensionado para o polegar do tutor. Esta é
   tela de administração, usada sentada e com cursor (§8.5). */
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

.botao:disabled {
  opacity: .45;
  cursor: not-allowed;
}

.botao--primario {
  background: var(--brand);
  border: 1px solid var(--brand);
  color: var(--surface-card);
}

.botao--primario:hover:not(:disabled) {
  background: var(--brand-hover);
  border-color: var(--brand-hover);
}

.botao--secundario {
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  color: var(--ink);
}

.botao--secundario:hover:not(:disabled) {
  background: var(--surface-sunken);
}

.botao--contorno-destrutivo {
  background: var(--surface-card);
  border: 1px solid var(--status-late);
  color: var(--status-late);
}

.botao--contorno-destrutivo:hover:not(:disabled) {
  background: var(--status-late-wash);
}

.botao__girando {
  width: 14px;
  height: 14px;
  flex: none;
  border: 2px solid rgba(255, 255, 255, .5);
  border-top-color: var(--surface-card);
  border-radius: var(--radius-pill);
  animation: catalogo-girar .6s linear infinite;
}

@keyframes catalogo-girar {
  to { transform: rotate(360deg); }
}

.pilula {
  /* §4.3 — alvo mínimo de 44 px abaixo de 1024 px; os 32 px do desenho valem
     só a partir de `lg`, onde o cursor substitui o dedo. */
  height: 44px;
  padding: 0 var(--space-3);
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-pill);
  font-family: var(--font-body);
  font-size: 14px;
  font-weight: 600;
  color: var(--ink-muted);
  cursor: pointer;
}

.pilula--ativa {
  background: var(--brand-wash);
  border-color: var(--brand);
  color: var(--brand);
}

/* Tabela --------------------------------------------------------------- */

.tabela {
  margin: var(--space-4) 0 0;
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-sm);
  overflow: hidden;
}

.tabela__cabecalho,
.tabela__linha {
  display: grid;
  grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr) minmax(0, .8fr) minmax(0, 1.3fr) minmax(0, 1fr) minmax(0, .9fr) 90px;
  gap: var(--space-3);
  align-items: center;
  padding: var(--space-2) var(--space-4);
}

.tabela__cabecalho {
  background: var(--surface-sunken);
  font-size: 13px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

.tabela__linha {
  min-height: 48px;
  border-top: 1px solid var(--border-hairline);
  font-size: 14px;
  color: var(--ink);
}

.tabela__linha--inativo {
  opacity: .55;
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

.tabela__especie {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  color: var(--ink-muted);
}

.tabela__acao-cabecalho {
  text-align: right;
}

.tabela__acao {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: var(--space-1);
}

.ligacao {
  display: inline-flex;
  align-items: center;
  /* §4.3 — o texto do link tem 14 px, mas a área clicável precisa de 44 px
     abaixo de 1024 px; o preenchimento vertical cria a folga sem aumentar
     a fonte. */
  min-height: 44px;
  background: none;
  border: none;
  padding: 0;
  font-size: 14px;
  font-weight: 600;
  color: var(--brand-bright);
  cursor: pointer;
}

.ligacao:hover {
  color: var(--brand-hover);
}

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

/* Estados ---------------------------------------------------------------- */

.cartao {
  margin: var(--space-4) 0 0;
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-sm);
}

.esqueleto-linha {
  display: flex;
  align-items: center;
  gap: var(--space-4);
  height: 48px;
}

.esqueleto {
  border-radius: var(--radius-xs);
  background: var(--surface-sunken);
  animation: catalogo-shimmer 1.2s ease-in-out infinite;
}

.esqueleto--celula {
  width: 30%;
  height: 16px;
}

.esqueleto--curta {
  width: 14%;
}

.esqueleto--pilula {
  width: 16%;
  height: 22px;
  margin-left: auto;
  border-radius: var(--radius-pill);
}

@keyframes catalogo-shimmer {
  0%, 100% { opacity: .55; }
  50% { opacity: 1; }
}

@media (prefers-reduced-motion: reduce) {
  .esqueleto {
    animation-duration: .001ms;
  }
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

/* O desenho pede 20 px de recuo, valor fora da escala de 4 que §4.3 declara
   fechada ("nada fora dela"). Fica em 24 px, o degrau seguinte — diferença
   invisível ao lado do ganho de não abrir exceção no sistema de espaçamento. */
.painel__cabecalho {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
  padding: var(--space-4) var(--space-6);
  border-bottom: 1px solid var(--border-hairline);
}

.painel__titulo {
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

.painel__grade {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--space-4);
}

/* O rótulo e a mensagem de erro do campo de texto longo, que não é `AppInput`
   e por isso não herda nada dele: as classes `app-field__*` são scoped do
   componente e não existem fora dele. Os valores repetem os de lá de
   propósito — é o mesmo rótulo, e ele não pode parecer outro. */
.campo {
  display: flex;
  flex-direction: column;
}

.campo__rotulo {
  display: block;
  margin: 0 0 6px;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink);
}

.campo__erro {
  margin: 6px 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--status-late);
}

.painel__textarea {
  width: 100%;
  padding: var(--space-2) var(--space-3);
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  font-family: var(--font-body);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
  resize: vertical;
}

.painel__textarea:focus-visible {
  border-color: var(--brand-bright);
}

/* Mesma densidade dos botões, pela mesma razão: `AppInput` e `AppSelect` são
   dimensionados para o polegar do tutor (48 px, 16 px), e o desenho desta
   tela de administração pede 40 px e 14 px. Abaixo de 1024 px eles voltam ao
   tamanho original — que é o alvo de toque de §4.3 e, nos 16 px, o que evita
   o zoom automático do iOS ao focar o campo. */
.painel__corpo :deep(.app-field__input),
.painel__corpo :deep(.app-field__select) {
  height: 40px;
  font-size: 14px;
}

.painel__textarea--erro {
  border-color: var(--status-late);
}

.painel__aviso {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  padding: var(--space-3);
  background: var(--surface-sunken);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
}

.painel__aviso p {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

.painel__aviso-icone {
  flex: none;
  color: var(--ink-muted);
}

.painel__rodape {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
  padding: var(--space-4) var(--space-6);
  border-top: 1px solid var(--border-hairline);
}

.painel__rodape-acoes {
  display: flex;
  gap: var(--space-2);
}

@media (max-width: 767px) {
  .tabela__cabecalho {
    display: none;
  }

  .tabela__linha {
    grid-template-columns: 1fr;
    gap: var(--space-1);
    padding: var(--space-3) var(--space-4);
  }

  .tabela__acao {
    flex-direction: row;
    align-items: center;
    gap: var(--space-3);
  }

  .painel__folha {
    max-width: none;
  }
}

/* §4.3 — alvo mínimo de 44 px em qualquer largura abaixo de 1024, como em
   V06: os 40 px do desenho de 1440 px valem para o cursor, não para o dedo. */
@media (max-width: 1023px) {
  .botao {
    min-height: 44px;
  }

  .painel__corpo :deep(.app-field__input),
  .painel__corpo :deep(.app-field__select) {
    height: 48px;
    font-size: 16px;
  }

  .painel__textarea {
    font-size: 16px;
    line-height: 24px;
  }
}

/* §4.3 — a partir daqui o cursor substitui o dedo: as pílulas voltam aos
   32 px do desenho de 1440 px, e não aos 44 px que a faixa abaixo de
   1024 px exige. */
@media (min-width: 1024px) {
  .pilula {
    height: 32px;
  }

  .ligacao {
    min-height: 0;
  }
}
</style>
