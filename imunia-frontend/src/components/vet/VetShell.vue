<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import {
  Building2,
  ChevronDown,
  ClipboardPlus,
  ClockAlert,
  FilePenLine,
  History,
  Menu,
  PawPrint,
  Search,
  Stethoscope,
  Syringe,
  UserRoundCheck,
  X,
} from '@lucide/vue'
import { useSessaoStore } from '@/stores/sessao.js'
import { useContextoClinicoStore } from '@/stores/contextoClinico.js'

/**
 * Moldura do ambiente do veterinário (§5.3 do briefing): barra lateral
 * persistente de 240 px em `xl`, reduzida a 72 px só-ícones em `lg` e recolhida
 * em gaveta abaixo disso; cabeçalho com busca global sempre à mão e o botão
 * primário "Registrar"; `ContextBanner` logo abaixo. Em celular, barra inferior
 * de três destinos — Painel · Buscar · Registrar.
 *
 * Densidade alta de propósito: corpo em 14 px e alvos de 32 a 40 px no desktop,
 * porque esta tela é usada de pé, entre um atendimento e outro, e cada linha a
 * mais que cabe na dobra é uma rolagem a menos.
 */
const props = defineProps({
  /** Prestador ativo — o que carimba tudo o que a tela mostra (RF48a). */
  prestador: { type: Object, default: null },
  /** Vínculos de veterinário do profissional, para o alternador de RF09b. */
  vinculos: { type: Array, default: () => [] },
  /** Animais com dose vencida, exibidos como selo ao lado de "Pendências". */
  pendencias: { type: Number, default: 0 },
  /** Título do cabeçalho em celular, onde não há espaço para a barra lateral. */
  titulo: { type: String, default: 'Painel' },
})

const emit = defineEmits(['trocar-prestador'])

const sessao = useSessaoStore()
const contextoClinico = useContextoClinicoStore()
const router = useRouter()

/**
 * A moldura anuncia o contexto que está desenhando. Quem lê é a moldura das
 * telas da conta, que precisa saber qual casca vestir **antes** da resposta do
 * servidor — sem isto, ela desenhava a administrativa e a trocava meio segundo
 * depois, e a barra lateral piscava a cada clique em "Equipe".
 */
watch(
  () => props.prestador,
  (prestador) => contextoClinico.lembrar(prestador, props.vinculos),
  { immediate: true },
)

/**
 * Os quatro destinos do briefing, todos construídos. "Registros" foi o último
 * a ganhar tela: é o livro de produção da clínica, e o único destino da barra
 * cujo âmbito é a autoria (RN40), não a autorização vigente.
 */
const SECOES = [
  { rotulo: 'Painel', destino: '/clinica/painel', icone: Stethoscope },
  { rotulo: 'Pendências', destino: '/clinica/pendencias', icone: ClockAlert, selo: true },
  { rotulo: 'Animais', destino: '/clinica/animais', icone: PawPrint },
  { rotulo: 'Registros', destino: '/clinica/registros', icone: History },
]

/**
 * As duas opções do botão primário. Ambas passam pela escolha do animal (V07a,
 * V08a) porque registro clínico é sempre de um animal determinado: escolher a
 * ação antes do animal é a ordem em que o profissional pensa, mas não a ordem
 * em que o sistema pode gravar.
 */
const REGISTROS = [
  { rotulo: 'Registrar vacinação', destino: '/clinica/registrar/vacinacao', icone: Syringe },
  { rotulo: 'Registrar atendimento', destino: '/clinica/registrar/atendimento', icone: ClipboardPlus },
]

const menuAberto = ref(false)
const registrarAberto = ref(false)
const contextoAberto = ref(false)

const vinculoAtivo = computed(() =>
  props.vinculos.find((vinculo) => vinculo.id === props.prestador?.id),
)

const administraOAtivo = computed(() => Boolean(vinculoAtivo.value?.admin))

/**
 * Os destinos do prestador ativo (A01-A03), desenhados aqui mesmo: quem atende
 * num estabelecimento pode ver onde trabalha e com quem, e quem também o
 * administra encontra ali as ações. É o mesmo conjunto de telas para os dois —
 * o que muda é o que elas oferecem, e o servidor é quem diz (`pode_administrar`).
 *
 * O primeiro rótulo acompanha o papel: "Painel da conta" para quem administra a
 * conta, "O prestador" para quem só atende nele e não tem conta a administrar
 * ali. Os outros dois nomeiam o que mostram, e servem aos dois casos.
 */
const DESTINOS_DO_PRESTADOR = [
  { rotulo: 'Dados do prestador', destino: '/prestador/dados', icone: FilePenLine },
  { rotulo: 'Equipe', destino: '/prestador/equipe', icone: UserRoundCheck },
  // Sem este destino, quem administra a clínica em que atende nunca acharia
  // A04: a moldura clínica substitui a administrativa para essa pessoa.
  { rotulo: 'Vacinas', destino: '/prestador/vacinas', icone: Syringe },
]

const secoesDoPrestador = computed(() => [
  {
    rotulo: administraOAtivo.value ? 'Painel da conta' : 'O prestador',
    destino: '/prestador',
    icone: Building2,
  },
  ...DESTINOS_DO_PRESTADOR,
])

/** §5.2 — a faixa de contexto existe para quem tem mais de um vínculo. */
const temMaisDeUmVinculo = computed(() => props.vinculos.length > 1)

function fecharMenus() {
  registrarAberto.value = false
  contextoAberto.value = false
}

function trocarPara(id) {
  fecharMenus()
  menuAberto.value = false

  // Clicar no vínculo já ativo não é uma troca: recarregar a tela inteira por
  // um clique de conferência ("estou mesmo na clínica?") só pisca o conteúdo.
  if (id === props.prestador?.id) return

  emit('trocar-prestador', id)
}

/**
 * Um ouvinte só para os dois menus suspensos: clique fora fecha, `Esc` fecha, e
 * `/` leva à busca — o atalho que o cabeçalho anuncia. O atalho é ignorado
 * enquanto se digita, sob pena de a barra virar navegação no meio de uma palavra.
 */
function aoClicarNoDocumento(evento) {
  if (!evento.target.closest('[data-menu]')) fecharMenus()
}

function aoTeclar(evento) {
  if (evento.key === 'Escape') {
    fecharMenus()
    menuAberto.value = false
    return
  }

  if (evento.key !== '/' || evento.metaKey || evento.ctrlKey || evento.altKey) return

  // O alvo nem sempre é elemento — a tecla pode chegar com o documento como
  // alvo, e ali `closest` não existe.
  const alvo = evento.target
  if (alvo instanceof Element && alvo.closest('input, textarea, select, [contenteditable="true"]')) return

  evento.preventDefault()
  router.push('/clinica/buscar')
}

onMounted(() => {
  document.addEventListener('click', aoClicarNoDocumento)
  document.addEventListener('keydown', aoTeclar)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', aoClicarNoDocumento)
  document.removeEventListener('keydown', aoTeclar)
})
</script>

<template>
  <div class="vet-shell">
    <aside class="vet-shell__lateral">
      <RouterLink to="/clinica/painel" class="vet-shell__marca">
        <span class="vet-shell__marca-completa">Imunia</span>
        <span class="vet-shell__marca-curta" aria-hidden="true">I</span>
      </RouterLink>

      <nav class="vet-shell__nav" aria-label="Seções da clínica">
        <RouterLink
          v-for="secao in SECOES"
          :key="secao.destino"
          :to="secao.destino"
          class="vet-shell__item"
        >
          <component :is="secao.icone" :size="20" :stroke-width="1.75" class="vet-shell__item-icone" />
          <span class="vet-shell__item-rotulo">{{ secao.rotulo }}</span>
          <span v-if="secao.selo && pendencias > 0" class="vet-shell__selo">{{ pendencias }}</span>
        </RouterLink>
      </nav>

      <nav
        v-if="prestador"
        class="vet-shell__nav vet-shell__ambiente"
        :aria-label="administraOAtivo ? 'Administração da conta' : 'O prestador'"
      >
        <RouterLink
          v-for="destino in secoesDoPrestador"
          :key="destino.destino"
          :to="destino.destino"
          class="vet-shell__item"
        >
          <component :is="destino.icone" :size="20" :stroke-width="1.75" class="vet-shell__item-icone" />
          <span class="vet-shell__item-rotulo">{{ destino.rotulo }}</span>
        </RouterLink>
      </nav>

      <!-- Os vínculos ficam à vista mesmo sem o alternador aberto: saber em que
           estabelecimento se está é parte de saber o que se está vendo. E cada
           um é a própria troca — o mesmo gesto da gaveta do celular, sem passar
           pelo alternador da faixa. -->
      <div v-if="prestador" class="vet-shell__vinculos">
        <p class="vet-shell__vinculos-rotulo">Seus vínculos</p>
        <button
          v-for="vinculo in vinculos"
          :key="vinculo.id"
          type="button"
          class="vet-shell__vinculo vet-shell__vinculo--botao"
          :class="{ 'vet-shell__vinculo--ativo': vinculo.id === prestador.id }"
          :aria-current="vinculo.id === prestador.id"
          @click="trocarPara(vinculo.id)"
        >
          {{ vinculo.nome }}
        </button>
      </div>
    </aside>

    <div class="vet-shell__coluna">
      <header class="vet-shell__cabecalho">
        <button
          type="button"
          class="vet-shell__alvo vet-shell__gaveta-botao"
          aria-label="Abrir o menu"
          :aria-expanded="menuAberto"
          @click="menuAberto = true"
        >
          <Menu :size="24" :stroke-width="1.75" />
        </button>

        <p class="vet-shell__titulo">{{ titulo }}</p>

        <!-- A busca é a porta de entrada de V03, e não um campo que filtra esta
             tela: por isso é uma ligação com cara de campo, e não um <input>
             que não buscaria nada onde está.
             A própria V03 ocupa esta faixa com o campo de verdade (§8.3: o
             campo "desloca-se para o topo após a primeira consulta"), e é só
             para ela que a fenda existe. -->
        <div class="vet-shell__busca-faixa">
          <slot name="busca">
            <RouterLink to="/clinica/buscar" class="vet-shell__busca">
              <PawPrint :size="16" :stroke-width="1.75" class="vet-shell__busca-icone" />
              <span>Buscar animal, tutor ou código</span>
              <kbd class="vet-shell__atalho">/</kbd>
            </RouterLink>
          </slot>
        </div>

        <div class="vet-shell__registrar" data-menu>
          <button
            type="button"
            class="vet-shell__botao-registrar"
            :aria-expanded="registrarAberto"
            aria-haspopup="menu"
            @click="registrarAberto = !registrarAberto; contextoAberto = false"
          >
            Registrar
            <ChevronDown :size="16" :stroke-width="1.75" />
          </button>

          <div v-if="registrarAberto" class="vet-shell__menu" role="menu">
            <RouterLink
              v-for="opcao in REGISTROS"
              :key="opcao.destino"
              :to="opcao.destino"
              class="vet-shell__menu-item"
              role="menuitem"
              @click="fecharMenus"
            >
              <component :is="opcao.icone" :size="16" :stroke-width="1.75" />
              {{ opcao.rotulo }}
            </RouterLink>
          </div>
        </div>

        <RouterLink to="/conta" class="vet-shell__alvo vet-shell__conta" aria-label="Minha conta">
          <span class="vet-shell__avatar" aria-hidden="true">{{ sessao.iniciais }}</span>
        </RouterLink>
      </header>

      <!-- RF09b — nunca ocultável, e presente sempre que houver entre o que
           alternar. Com um vínculo só, a faixa não informaria nada que o
           cabeçalho e a barra lateral já não digam. -->
      <div v-if="prestador && temMaisDeUmVinculo" class="vet-shell__contexto" data-menu>
        <Building2 :size="16" :stroke-width="1.75" class="vet-shell__contexto-icone" />
        <p class="vet-shell__contexto-texto">
          <span class="vet-shell__contexto-prefixo">Contexto ativo: </span>
          <strong>{{ prestador.nome }}</strong>
        </p>

        <button
          type="button"
          class="vet-shell__trocar"
          :aria-expanded="contextoAberto"
          aria-haspopup="menu"
          @click="contextoAberto = !contextoAberto; registrarAberto = false"
        >
          <span class="vet-shell__trocar-completo">Trocar prestador</span>
          <span class="vet-shell__trocar-curto" aria-hidden="true">Trocar</span>
        </button>

        <div v-if="contextoAberto" class="vet-shell__menu vet-shell__menu--contexto" role="menu">
          <button
            v-for="vinculo in vinculos"
            :key="vinculo.id"
            type="button"
            class="vet-shell__menu-item"
            role="menuitem"
            :aria-current="vinculo.id === prestador.id"
            @click="trocarPara(vinculo.id)"
          >
            <Building2 :size="16" :stroke-width="1.75" />
            {{ vinculo.nome }}
          </button>
        </div>
      </div>

      <main class="vet-shell__conteudo">
        <div class="vet-shell__limite">
          <slot />
        </div>
      </main>
    </div>

    <!-- Gaveta: a barra lateral abaixo de 1024 px. -->
    <div v-if="menuAberto" class="vet-shell__cortina" @click="menuAberto = false" />
    <div v-if="menuAberto" class="vet-shell__gaveta" role="dialog" aria-label="Seções da clínica">
      <div class="vet-shell__gaveta-topo">
        <span class="vet-shell__marca-completa">Imunia</span>
        <button type="button" class="vet-shell__alvo" aria-label="Fechar o menu" @click="menuAberto = false">
          <X :size="24" :stroke-width="1.75" />
        </button>
      </div>

      <nav class="vet-shell__nav" aria-label="Seções da clínica">
        <RouterLink
          v-for="secao in SECOES"
          :key="secao.destino"
          :to="secao.destino"
          class="vet-shell__item"
          @click="menuAberto = false"
        >
          <component :is="secao.icone" :size="20" :stroke-width="1.75" class="vet-shell__item-icone" />
          <span class="vet-shell__item-rotulo">{{ secao.rotulo }}</span>
          <span v-if="secao.selo && pendencias > 0" class="vet-shell__selo">{{ pendencias }}</span>
        </RouterLink>
      </nav>

      <nav
        v-if="prestador"
        class="vet-shell__nav vet-shell__ambiente"
        :aria-label="administraOAtivo ? 'Administração da conta' : 'O prestador'"
      >
        <RouterLink
          v-for="destino in secoesDoPrestador"
          :key="destino.destino"
          :to="destino.destino"
          class="vet-shell__item"
          @click="menuAberto = false"
        >
          <component :is="destino.icone" :size="20" :stroke-width="1.75" class="vet-shell__item-icone" />
          <span class="vet-shell__item-rotulo">{{ destino.rotulo }}</span>
        </RouterLink>
      </nav>

      <div v-if="prestador" class="vet-shell__vinculos">
        <p class="vet-shell__vinculos-rotulo">Seus vínculos</p>
        <button
          v-for="vinculo in vinculos"
          :key="vinculo.id"
          type="button"
          class="vet-shell__vinculo vet-shell__vinculo--botao"
          :class="{ 'vet-shell__vinculo--ativo': vinculo.id === prestador.id }"
          @click="trocarPara(vinculo.id)"
        >
          {{ vinculo.nome }}
        </button>
      </div>
    </div>

    <nav class="vet-shell__abas" aria-label="Seções da clínica">
      <RouterLink to="/clinica/painel" class="vet-shell__aba">
        <Stethoscope :size="20" :stroke-width="1.75" />
        <span>Painel</span>
      </RouterLink>
      <RouterLink to="/clinica/buscar" class="vet-shell__aba">
        <Search :size="20" :stroke-width="1.75" />
        <span>Buscar</span>
      </RouterLink>
      <RouterLink to="/clinica/registrar/vacinacao" class="vet-shell__aba">
        <ClipboardPlus :size="20" :stroke-width="1.75" />
        <span>Registrar</span>
      </RouterLink>
    </nav>
  </div>
</template>

<style scoped>
.vet-shell {
  display: flex;
  min-height: 100vh;
  background: var(--surface-page);
  font-size: 14px;
  line-height: 20px;
}

.vet-shell__coluna {
  flex: 1;
  min-width: 0;
}

/* Cabeçalho ---------------------------------------------------------------- */

.vet-shell__cabecalho {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  height: 56px;
  padding: 0 var(--space-4);
  background: var(--surface-card);
  border-bottom: 1px solid var(--border-hairline);
}

.vet-shell__titulo {
  flex: 1;
  min-width: 0;
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

/* A faixa reserva o espaço e responde pela responsividade; o que a ocupa —
   ligação por padrão, campo em V03 — só precisa preenchê-la. */
.vet-shell__busca-faixa {
  display: none;
  flex: 1;
  min-width: 0;
}

.vet-shell__busca {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  height: 36px;
  padding: 0 var(--space-3);
  background: var(--surface-sunken);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  color: var(--ink-faint);
}

.vet-shell__busca:hover {
  border-color: var(--brand-bright);
  color: var(--ink-muted);
}

.vet-shell__busca-icone {
  flex: none;
  color: var(--ink-muted);
}

.vet-shell__atalho {
  margin-left: auto;
  font-family: var(--font-mono);
  font-size: 12px;
  color: var(--ink-faint);
}

.vet-shell__botao-registrar {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  height: 36px;
  padding: 0 14px;
  background: var(--brand);
  border: 1px solid var(--brand);
  border-radius: var(--radius-sm);
  font-size: 14px;
  font-weight: 600;
  color: var(--surface-card);
  cursor: pointer;
}

.vet-shell__botao-registrar:hover {
  background: var(--brand-hover);
  border-color: var(--brand-hover);
}

.vet-shell__registrar {
  position: relative;
  flex: none;
}

.vet-shell__menu {
  position: absolute;
  top: calc(100% + var(--space-2));
  right: 0;
  z-index: 20;
  min-width: 232px;
  padding: var(--space-1);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-sm);
  box-shadow: var(--shadow-overlay);
}

.vet-shell__menu-item {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  width: 100%;
  padding: var(--space-2) var(--space-3);
  background: none;
  border: 0;
  border-radius: var(--radius-xs);
  font-size: 14px;
  line-height: 20px;
  text-align: left;
  color: var(--ink);
  cursor: pointer;
}

.vet-shell__menu-item:hover {
  background: var(--surface-sunken);
  color: var(--ink);
}

.vet-shell__menu-item[aria-current='true'] {
  color: var(--brand);
  font-weight: 600;
}

.vet-shell__alvo {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: none;
  width: 44px;
  height: 44px;
  background: none;
  border: 0;
  color: var(--ink);
  cursor: pointer;
}

.vet-shell__avatar {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: var(--radius-pill);
  background: var(--surface-sunken);
  font-size: 12px;
  font-weight: 600;
  color: var(--ink-muted);
}

/* Faixa de contexto -------------------------------------------------------- */

/* 32 px exatos (§5.2): a faixa é permanente e disputa altura com o conteúdo.
   O alvo de 44 px do alternador cabe dentro dela por margem negativa. */
.vet-shell__contexto {
  position: relative;
  display: flex;
  align-items: center;
  gap: var(--space-2);
  height: 32px;
  padding: 0 var(--space-4);
  background: var(--brand-wash);
  border-bottom: 1px solid var(--border-hairline);
}

.vet-shell__contexto-icone {
  flex: none;
  color: var(--brand);
}

.vet-shell__contexto-texto {
  flex: 1;
  min-width: 0;
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  color: var(--ink);
}

.vet-shell__contexto-prefixo {
  display: none;
}

.vet-shell__trocar {
  display: inline-flex;
  align-items: center;
  flex: none;
  height: 44px;
  margin: -6px 0;
  padding: 0 var(--space-1);
  background: none;
  border: 0;
  font-size: 14px;
  font-weight: 600;
  color: var(--brand-bright);
  cursor: pointer;
}

.vet-shell__trocar:hover {
  color: var(--brand-hover);
}

.vet-shell__trocar-completo {
  display: none;
}

.vet-shell__menu--contexto {
  top: 100%;
  right: var(--space-4);
}

/* Conteúdo ----------------------------------------------------------------- */

.vet-shell__conteudo {
  /* A barra de abas é fixa: o conteúdo reserva a altura dela. */
  padding: var(--space-4) var(--space-4) calc(56px + var(--space-6));
}

/* Barra lateral e gaveta --------------------------------------------------- */

.vet-shell__lateral {
  display: none;
}

.vet-shell__cortina {
  position: fixed;
  inset: 0;
  z-index: 30;
  background: rgba(20, 35, 31, .45);
}

.vet-shell__gaveta {
  position: fixed;
  inset: 0 auto 0 0;
  z-index: 31;
  width: 264px;
  max-width: 82vw;
  padding: var(--space-3);
  overflow-y: auto;
  background: var(--surface-card);
  border-right: 1px solid var(--border-hairline);
}

.vet-shell__gaveta-topo {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin: 0 0 var(--space-4);
}

.vet-shell__marca {
  display: block;
  font-family: var(--font-display);
  font-size: 22px;
  line-height: 28px;
  font-weight: 600;
  color: var(--brand);
}

.vet-shell__marca-completa {
  font-family: var(--font-display);
  font-size: 22px;
  line-height: 28px;
  font-weight: 600;
  color: var(--brand);
}

.vet-shell__nav {
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
}

.vet-shell__item {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-2);
  border-radius: var(--radius-sm);
  color: var(--ink-muted);
  font-size: 14px;
}

.vet-shell__item:hover {
  background: var(--surface-sunken);
  color: var(--ink);
}

.vet-shell__item.router-link-active {
  background: var(--brand-wash);
  color: var(--brand);
  font-weight: 600;
}

.vet-shell__item-icone {
  flex: none;
}

.vet-shell__item-rotulo {
  flex: 1;
  min-width: 0;
}

/* Ambiente é mudança de moldura, não seção da clínica: o filete separa os dois
   grupos para que a barra não some cinco destinos num bloco só. */
.vet-shell__ambiente {
  margin-top: var(--space-4);
  padding-top: var(--space-3);
  border-top: 1px solid var(--border-hairline);
}

.vet-shell__selo {
  display: inline-flex;
  align-items: center;
  flex: none;
  height: 22px;
  padding: 0 10px;
  border-radius: var(--radius-pill);
  background: var(--status-late-wash);
  color: var(--status-late);
  font-size: 12px;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.vet-shell__vinculos {
  margin: var(--space-6) 0 0;
  padding: var(--space-3) var(--space-2) 0;
  border-top: 1px solid var(--border-hairline);
}

.vet-shell__vinculos-rotulo {
  margin: 0;
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
}

.vet-shell__vinculo {
  display: block;
  margin: var(--space-2) 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.vet-shell__vinculo--ativo {
  font-weight: 600;
  color: var(--brand);
}

.vet-shell__vinculo--botao {
  width: 100%;
  padding: 0;
  background: none;
  border: 0;
  text-align: left;
  cursor: pointer;
}

.vet-shell__vinculo--botao:hover {
  color: var(--ink);
}

.vet-shell__vinculo--botao.vet-shell__vinculo--ativo:hover {
  color: var(--brand);
}

/* Barra inferior ----------------------------------------------------------- */

.vet-shell__abas {
  position: fixed;
  inset: auto 0 0;
  z-index: 10;
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  background: var(--surface-card);
  border-top: 1px solid var(--border-hairline);
}

.vet-shell__aba {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: var(--space-1);
  height: 56px;
  color: var(--ink-muted);
  font-size: 12px;
  line-height: 16px;
}

.vet-shell__aba.router-link-active,
.vet-shell__aba:hover {
  color: var(--brand);
}

/* Larguras derivadas ------------------------------------------------------- */

@media (min-width: 768px) {
  .vet-shell__titulo {
    display: none;
  }

  /* Margens automáticas dos dois lados do par busca + Registrar o centralizam
     no espaço restante; o avatar fica à direita com um respiro da borda. */
  .vet-shell__busca-faixa {
    display: block;
    max-width: 480px;
    margin-left: auto;
  }

  .vet-shell__registrar {
    margin-right: auto;
  }

  .vet-shell__conta {
    margin-right: var(--space-4);
  }

  .vet-shell__contexto-prefixo {
    display: inline;
  }

  .vet-shell__trocar-completo {
    display: inline;
  }

  .vet-shell__trocar-curto {
    display: none;
  }

  .vet-shell__conteudo {
    padding: var(--space-6) var(--space-6) var(--space-8);
  }

  .vet-shell__abas {
    display: none;
  }
}

@media (min-width: 1024px) {
  /* Presa à janela: esticada à altura do conteúdo, a navegação rolava junto e
     trocar de seção no fim de uma ficha longa pedia voltar ao topo. */
  .vet-shell__lateral {
    position: sticky;
    top: 0;
    display: flex;
    flex-direction: column;
    flex: none;
    width: 72px;
    height: 100vh;
    overflow-y: auto;
    padding: var(--space-4) 0;
    background: var(--surface-card);
    border-right: 1px solid var(--border-hairline);
  }

  .vet-shell__lateral .vet-shell__marca {
    align-self: center;
    margin: 0 0 var(--space-2);
  }

  .vet-shell__lateral .vet-shell__marca-completa,
  .vet-shell__lateral .vet-shell__item-rotulo,
  .vet-shell__lateral .vet-shell__selo,
  .vet-shell__lateral .vet-shell__vinculos {
    display: none;
  }

  .vet-shell__lateral .vet-shell__nav {
    align-items: center;
    gap: var(--space-2);
  }

  .vet-shell__lateral .vet-shell__item {
    justify-content: center;
    width: 40px;
    height: 40px;
    padding: 0;
  }

  .vet-shell__gaveta-botao {
    display: none;
  }

  /* Contrapeso do avatar (44px + respiro de 16px): sem ele, o par busca +
     Registrar centraliza no espaço que sobra, 36px à esquerda do centro real. */
  .vet-shell__cabecalho::before {
    content: '';
    flex: none;
    width: 60px;
  }
}

@media (min-width: 1280px) {
  .vet-shell__lateral {
    width: 240px;
    padding: var(--space-4) var(--space-3);
  }

  .vet-shell__lateral .vet-shell__marca {
    align-self: stretch;
    padding: 0 var(--space-2) var(--space-6);
    margin: 0;
  }

  .vet-shell__lateral .vet-shell__marca-completa,
  .vet-shell__lateral .vet-shell__item-rotulo,
  .vet-shell__lateral .vet-shell__selo,
  .vet-shell__lateral .vet-shell__vinculos {
    display: revert;
  }

  .vet-shell__lateral .vet-shell__selo {
    display: inline-flex;
  }

  .vet-shell__marca-curta {
    display: none;
  }

  .vet-shell__lateral .vet-shell__nav {
    align-items: stretch;
    gap: var(--space-1);
  }

  .vet-shell__lateral .vet-shell__item {
    justify-content: flex-start;
    width: auto;
    height: auto;
    padding: var(--space-2);
  }

  .vet-shell__cabecalho,
  .vet-shell__contexto {
    padding-left: var(--space-6);
    padding-right: var(--space-6);
  }

  .vet-shell__menu--contexto {
    right: var(--space-6);
  }
}
</style>
