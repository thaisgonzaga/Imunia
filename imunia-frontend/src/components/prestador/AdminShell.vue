<script setup>
import { computed, ref } from 'vue'
import { Building2, FilePenLine, Info, Lock, Menu, Stethoscope, Syringe, UserRoundCheck, X } from '@lucide/vue'
import { useSessaoStore } from '@/stores/sessao.js'

/**
 * Moldura da administração do prestador (§8.4 do briefing: A01, A02, A03).
 *
 * O bloco ao pé da barra lateral é o elemento de projeto desta moldura, e por
 * isso é permanente e não some em largura alguma: ele declara o que este perfil
 * não alcança (RN08). As três telas são enxutas porque o papel é limitado, e a
 * moldura é o que impede que a escassez seja lida como tela inacabada.
 */
defineProps({
  titulo: { type: String, default: 'Administração' },
  prestador: { type: Object, default: null },
  vinculos: { type: Array, default: () => [] },
  convitesPendentes: { type: Number, default: 0 },
  /**
   * O prestador em que a pessoa atende, quando não é o que ela administra. Vem
   * do servidor porque é ele que guarda o contexto ativo — e é a única maneira
   * de a tela explicar por que abriu numa conta que não é a do ambiente
   * clínico de onde se veio.
   */
  contextoClinico: { type: Object, default: null },
})

const emit = defineEmits(['trocar-prestador'])

const SECOES = [
  { rotulo: 'Painel', destino: '/prestador', icone: Building2, exato: true },
  { rotulo: 'Dados do prestador', destino: '/prestador/dados', icone: FilePenLine },
  { rotulo: 'Equipe', destino: '/prestador/equipe', icone: UserRoundCheck, selo: true },
  { rotulo: 'Vacinas', destino: '/prestador/vacinas', icone: Syringe },
]

const LIMITACAO =
  'Este perfil administra a conta do prestador. Dados de tutores, animais e registros clínicos '
  + 'não são acessíveis por ele.'

const sessao = useSessaoStore()
const menuAberto = ref(false)

/**
 * A volta do alternador de papel: quem funda a clínica é também veterinário
 * (P03) e precisa do caminho de retorno ao ambiente de registro. Para a
 * administradora sem CRMV — o caso que esta moldura defende — o item não
 * existe, coerente com o bloco de limitação logo abaixo.
 */
const ehVeterinario = computed(() =>
  (sessao.usuario?.papeis ?? []).includes('veterinario'),
)
</script>

<template>
  <div class="admin-shell">
    <aside class="admin-shell__lateral">
      <div class="admin-shell__topo">
        <div class="admin-shell__marca">Imunia</div>
        <nav class="admin-shell__nav" aria-label="Administração do prestador">
          <RouterLink
            v-for="secao in SECOES"
            :key="secao.destino"
            :to="secao.destino"
            class="admin-shell__item"
            :class="{ 'admin-shell__item--exato': secao.exato }"
          >
            <span class="admin-shell__rotulo">
              <component :is="secao.icone" :size="20" :stroke-width="1.75" />
              <span>{{ secao.rotulo }}</span>
            </span>
            <span v-if="secao.selo && convitesPendentes > 0" class="admin-shell__selo">
              {{ convitesPendentes }}
              <span class="visually-hidden">
                {{ convitesPendentes === 1 ? 'convite pendente' : 'convites pendentes' }}
              </span>
            </span>
          </RouterLink>
        </nav>

        <nav v-if="ehVeterinario" class="admin-shell__nav admin-shell__ambiente" aria-label="Trocar de ambiente">
          <RouterLink to="/clinica/painel" class="admin-shell__item">
            <span class="admin-shell__rotulo">
              <Stethoscope :size="20" :stroke-width="1.75" />
              <span>Ambiente clínico</span>
            </span>
          </RouterLink>
        </nav>
      </div>

      <div class="admin-shell__limitacao-caixa">
        <div class="admin-shell__limitacao">
          <Lock :size="16" :stroke-width="1.75" />
          <p>{{ LIMITACAO }}</p>
        </div>
      </div>
    </aside>

    <div class="admin-shell__coluna">
      <header class="admin-shell__cabecalho">
        <button
          type="button"
          class="admin-shell__gaveta-botao"
          aria-label="Abrir o menu"
          :aria-expanded="menuAberto"
          @click="menuAberto = true"
        >
          <Menu :size="24" :stroke-width="1.75" />
        </button>
        <p class="admin-shell__titulo">
          {{ titulo }}<span v-if="prestador"> · {{ prestador.nome }}</span>
        </p>
        <span class="admin-shell__avatar" aria-hidden="true">{{ sessao.iniciais }}</span>
      </header>

      <!--
        Quem atende numa clínica que não administra chega aqui pela própria
        conta, e não pela clínica de onde veio. Sem esta linha a tela parecia
        ter desfeito a troca de contexto — o painel abria no consultório sem
        dizer por quê, e o caminho de volta não estava à vista.
      -->
      <div v-if="contextoClinico" class="admin-shell__divergencia">
        <Info :size="16" :stroke-width="1.75" />
        <p>
          Você atende em <strong>{{ contextoClinico.nome }}</strong>, mas não administra essa conta.
          Aqui você administra <strong>{{ prestador?.nome }}</strong>.
        </p>
        <RouterLink to="/clinica/painel" class="admin-shell__voltar">Voltar ao ambiente clínico</RouterLink>
      </div>

      <!--
        RF09b — a faixa de contexto só existe quando há entre o que alternar.
        Quem administra uma conta só não tem escolha a fazer, e a faixa seria
        um enfeite que ocupa 32 px em toda tela.
      -->
      <div v-if="vinculos.length > 1" class="admin-shell__contexto">
        <span>Contexto ativo: <strong>{{ prestador?.nome }}</strong></span>
        <label class="admin-shell__troca">
          <span class="visually-hidden">Trocar prestador</span>
          <select :value="prestador?.id" @change="emit('trocar-prestador', Number($event.target.value))">
            <option v-for="vinculo in vinculos" :key="vinculo.id" :value="vinculo.id">
              {{ vinculo.nome }}
            </option>
          </select>
        </label>
      </div>

      <main class="admin-shell__conteudo">
        <slot />
      </main>
    </div>

    <div v-if="menuAberto" class="admin-shell__cortina" @click="menuAberto = false" />
    <div v-if="menuAberto" class="admin-shell__gaveta" role="dialog" aria-label="Administração do prestador">
      <div class="admin-shell__gaveta-topo">
        <span class="admin-shell__marca">Imunia</span>
        <button type="button" class="admin-shell__gaveta-botao" aria-label="Fechar o menu" @click="menuAberto = false">
          <X :size="24" :stroke-width="1.75" />
        </button>
      </div>
      <nav class="admin-shell__nav" aria-label="Administração do prestador">
        <RouterLink
          v-for="secao in SECOES"
          :key="secao.destino"
          :to="secao.destino"
          class="admin-shell__item"
          :class="{ 'admin-shell__item--exato': secao.exato }"
          @click="menuAberto = false"
        >
          <span class="admin-shell__rotulo">
            <component :is="secao.icone" :size="20" :stroke-width="1.75" />
            <span>{{ secao.rotulo }}</span>
          </span>
          <span v-if="secao.selo && convitesPendentes > 0" class="admin-shell__selo">{{ convitesPendentes }}</span>
        </RouterLink>
      </nav>
      <nav v-if="ehVeterinario" class="admin-shell__nav admin-shell__ambiente" aria-label="Trocar de ambiente">
        <RouterLink to="/clinica/painel" class="admin-shell__item" @click="menuAberto = false">
          <span class="admin-shell__rotulo">
            <Stethoscope :size="20" :stroke-width="1.75" />
            <span>Ambiente clínico</span>
          </span>
        </RouterLink>
      </nav>
      <div class="admin-shell__limitacao-caixa admin-shell__limitacao-caixa--gaveta">
        <div class="admin-shell__limitacao">
          <Lock :size="16" :stroke-width="1.75" />
          <p>{{ LIMITACAO }}</p>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.admin-shell {
  display: flex;
  min-height: 100vh;
  background: var(--surface-page);
}

.admin-shell__lateral {
  display: none;
  flex: none;
  width: 240px;
  flex-direction: column;
  background: var(--surface-card);
  border-right: 1px solid var(--border-hairline);
}

.admin-shell__topo {
  padding: var(--space-4) var(--space-3) 0;
}

.admin-shell__marca {
  padding: 0 var(--space-2) var(--space-6);
  font-family: var(--font-display);
  font-size: 22px;
  line-height: 28px;
  font-weight: 600;
  color: var(--brand);
}

.admin-shell__nav {
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
}

.admin-shell__item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
  padding: var(--space-2);
  border-radius: var(--radius-sm);
  font-size: 14px;
  font-weight: 600;
  color: var(--ink-muted);
}

.admin-shell__rotulo {
  display: inline-flex;
  align-items: center;
  gap: var(--space-3);
}

.admin-shell__item:hover {
  background: var(--surface-sunken);
}

.admin-shell__item.router-link-active {
  background: var(--brand-wash);
  color: var(--brand);
}

/*
  "Painel" é `/prestador`, prefixo de todas as outras: sem isto o item ficaria
  aceso também em Dados e em Equipe, e a barra deixaria de dizer onde se está.
*/
.admin-shell__item--exato.router-link-active:not(.router-link-exact-active) {
  background: none;
  color: var(--ink-muted);
}

/* Ambiente é mudança de moldura, não seção da administração: o filete separa
   os dois grupos, como na moldura do veterinário. */
.admin-shell__ambiente {
  margin-top: var(--space-4);
  padding-top: var(--space-3);
  border-top: 1px solid var(--border-hairline);
}

.admin-shell__selo {
  display: inline-flex;
  align-items: center;
  height: 22px;
  padding: 0 10px;
  border-radius: var(--radius-pill);
  background: var(--consent-wash);
  color: var(--consent);
  font-size: 12px;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.admin-shell__limitacao-caixa {
  margin-top: auto;
  padding: var(--space-3);
  border-top: 1px solid var(--border-hairline);
}

.admin-shell__limitacao-caixa--gaveta {
  border-top: 0;
}

.admin-shell__limitacao {
  display: flex;
  align-items: flex-start;
  gap: var(--space-2);
  padding: var(--space-3);
  background: var(--surface-sunken);
  border-radius: var(--radius-sm);
  color: var(--ink-muted);
}

.admin-shell__limitacao svg {
  flex: none;
  margin-top: 2px;
}

.admin-shell__limitacao p {
  margin: 0;
  font-size: 12px;
  line-height: 16px;
}

.admin-shell__coluna {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.admin-shell__cabecalho {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  height: 56px;
  padding: 0 var(--space-4);
  background: var(--surface-card);
  border-bottom: 1px solid var(--border-hairline);
}

.admin-shell__gaveta-botao {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  margin: 0 0 0 calc(-1 * var(--space-2));
  background: none;
  border: none;
  border-radius: var(--radius-sm);
  color: var(--ink);
  cursor: pointer;
}

.admin-shell__titulo {
  flex: 1;
  min-width: 0;
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.admin-shell__avatar {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  flex: none;
  border-radius: var(--radius-pill);
  background: var(--surface-sunken);
  font-size: 12px;
  font-weight: 600;
  color: var(--ink-muted);
}

/* Sóbria de propósito: não é erro nem alerta, é a explicação de onde se está.
   A cor da marca fica com a faixa de contexto, logo abaixo, que é ação. */
.admin-shell__divergencia {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: var(--space-2);
  padding: var(--space-2) var(--space-4);
  background: var(--surface-sunken);
  border-bottom: 1px solid var(--border-hairline);
  color: var(--ink-muted);
}

.admin-shell__divergencia svg {
  flex: none;
}

.admin-shell__divergencia p {
  flex: 1;
  min-width: 0;
  margin: 0;
  font-size: 13px;
  line-height: 16px;
}

.admin-shell__divergencia strong {
  color: var(--ink);
}

/* O alvo de 44 px cabe na linha por margem negativa, como no aviso de outro
   contexto do ambiente clínico. */
.admin-shell__voltar {
  display: inline-flex;
  align-items: center;
  flex: none;
  height: 44px;
  margin: -14px 0;
  font-size: 13px;
  font-weight: 600;
  color: var(--brand-bright);
}

.admin-shell__voltar:hover {
  color: var(--brand-hover);
}

.admin-shell__contexto {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
  flex-wrap: wrap;
  padding: var(--space-2) var(--space-4);
  background: var(--brand-wash);
  font-size: 13px;
  line-height: 16px;
  color: var(--brand);
}

.admin-shell__troca select {
  height: 44px;
  padding: 0 var(--space-2);
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  font-family: var(--font-body);
  font-size: 14px;
  color: var(--ink);
}

.admin-shell__conteudo {
  flex: 1;
  min-width: 0;
  padding: var(--space-4);
}

.admin-shell__cortina {
  position: fixed;
  inset: 0;
  z-index: 30;
  background: rgba(20, 35, 31, .32);
}

.admin-shell__gaveta {
  position: fixed;
  inset: 0 25% 0 0;
  z-index: 31;
  display: flex;
  flex-direction: column;
  padding: var(--space-4) var(--space-3);
  background: var(--surface-card);
}

.admin-shell__gaveta-topo {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 var(--space-2) var(--space-6);
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

@media (min-width: 1024px) {
  /* Presa à janela: esticada à altura do conteúdo, a navegação rolava junto e
     trocar de tela no fim de uma lista longa pedia voltar ao topo. De quebra, o
     bloco de limitação fica sempre à vista, no pé da janela e não da página. */
  .admin-shell__lateral {
    position: sticky;
    top: 0;
    display: flex;
    height: 100vh;
    overflow-y: auto;
  }

  .admin-shell__gaveta-botao {
    display: none;
  }

  .admin-shell__cortina,
  .admin-shell__gaveta {
    display: none;
  }

  .admin-shell__troca select {
    height: 32px;
  }

  .admin-shell__conteudo {
    padding: var(--space-6);
  }
}
</style>
