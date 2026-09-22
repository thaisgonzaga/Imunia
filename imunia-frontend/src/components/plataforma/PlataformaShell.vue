<script setup>
import { ref } from 'vue'
import { Building2, CalendarClock, Menu, Syringe, X } from '@lucide/vue'
import { useSessaoStore } from '@/stores/sessao.js'

/**
 * Moldura da administração da plataforma (§8.5 do briefing: X01, X02).
 * Bloco separado da experiência dos usuários finais — barra lateral de
 * 240 px com os três destinos previstos no mockup (Catálogo, Protocolos,
 * Prestadores), recolhida a gaveta abaixo de `lg`. "Prestadores" ainda não
 * tem tela própria (a administração de prestadores da plataforma é fatia
 * futura) — o destino existe porque a navegação do desenho aprovado não some
 * enquanto a tela não chega; até lá, o roteador responde com E02, a resposta
 * correta para uma rota que ainda não existe.
 */
defineProps({
  titulo: { type: String, default: 'Administração da plataforma' },
})

const SECOES = [
  { rotulo: 'Catálogo', destino: '/plataforma/catalogo', icone: Syringe },
  { rotulo: 'Protocolos', destino: '/plataforma/protocolos', icone: CalendarClock },
  { rotulo: 'Prestadores', destino: '/plataforma/prestadores', icone: Building2 },
]

const sessao = useSessaoStore()
const menuAberto = ref(false)
</script>

<template>
  <div class="plataforma-shell">
    <aside class="plataforma-shell__lateral">
      <div class="plataforma-shell__marca">Imunia</div>
      <nav class="plataforma-shell__nav" aria-label="Administração da plataforma">
        <RouterLink v-for="secao in SECOES" :key="secao.destino" :to="secao.destino" class="plataforma-shell__item">
          <component :is="secao.icone" :size="20" :stroke-width="1.75" />
          <span>{{ secao.rotulo }}</span>
        </RouterLink>
      </nav>
    </aside>

    <div class="plataforma-shell__coluna">
      <header class="plataforma-shell__cabecalho">
        <button
          type="button"
          class="plataforma-shell__gaveta-botao"
          aria-label="Abrir o menu"
          :aria-expanded="menuAberto"
          @click="menuAberto = true"
        >
          <Menu :size="24" :stroke-width="1.75" />
        </button>
        <p class="plataforma-shell__titulo">{{ titulo }}</p>
        <span class="plataforma-shell__avatar" aria-hidden="true">{{ sessao.iniciais }}</span>
      </header>

      <main class="plataforma-shell__conteudo">
        <slot />
      </main>
    </div>

    <div v-if="menuAberto" class="plataforma-shell__cortina" @click="menuAberto = false" />
    <div v-if="menuAberto" class="plataforma-shell__gaveta" role="dialog" aria-label="Administração da plataforma">
      <div class="plataforma-shell__gaveta-topo">
        <span class="plataforma-shell__marca">Imunia</span>
        <button type="button" class="plataforma-shell__gaveta-botao" aria-label="Fechar o menu" @click="menuAberto = false">
          <X :size="24" :stroke-width="1.75" />
        </button>
      </div>
      <nav class="plataforma-shell__nav" aria-label="Administração da plataforma">
        <RouterLink
          v-for="secao in SECOES"
          :key="secao.destino"
          :to="secao.destino"
          class="plataforma-shell__item"
          @click="menuAberto = false"
        >
          <component :is="secao.icone" :size="20" :stroke-width="1.75" />
          <span>{{ secao.rotulo }}</span>
        </RouterLink>
      </nav>
    </div>
  </div>
</template>

<style scoped>
.plataforma-shell {
  display: flex;
  min-height: 100vh;
  background: var(--surface-page);
}

.plataforma-shell__lateral {
  display: none;
  flex: none;
  width: 240px;
  flex-direction: column;
  padding: var(--space-4) var(--space-3);
  background: var(--surface-card);
  border-right: 1px solid var(--border-hairline);
}

.plataforma-shell__marca {
  padding: 0 var(--space-2) var(--space-6);
  font-family: var(--font-display);
  font-size: 22px;
  line-height: 28px;
  font-weight: 600;
  color: var(--brand);
}

.plataforma-shell__nav {
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
}

.plataforma-shell__item {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-2);
  border-radius: var(--radius-sm);
  font-size: 14px;
  font-weight: 600;
  color: var(--ink-muted);
}

.plataforma-shell__item:hover {
  background: var(--surface-sunken);
}

.plataforma-shell__item.router-link-active {
  background: var(--brand-wash);
  color: var(--brand);
}

.plataforma-shell__coluna {
  flex: 1;
  min-width: 0;
}

.plataforma-shell__cabecalho {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  height: 56px;
  padding: 0 var(--space-4);
  background: var(--surface-card);
  border-bottom: 1px solid var(--border-hairline);
}

.plataforma-shell__gaveta-botao {
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

.plataforma-shell__titulo {
  flex: 1;
  min-width: 0;
  margin: 0;
  font-size: 14px;
  color: var(--ink-muted);
}

.plataforma-shell__avatar {
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

.plataforma-shell__conteudo {
  padding: var(--space-4);
}

.plataforma-shell__cortina {
  position: fixed;
  inset: 0;
  z-index: 30;
  background: rgba(20, 35, 31, .32);
}

.plataforma-shell__gaveta {
  position: fixed;
  inset: 0 25% 0 0;
  z-index: 31;
  display: flex;
  flex-direction: column;
  padding: var(--space-4) var(--space-3);
  background: var(--surface-card);
}

.plataforma-shell__gaveta-topo {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 var(--space-2) var(--space-6);
}

@media (min-width: 1024px) {
  /* Presa à janela: esticada à altura do conteúdo, a navegação rolava junto e
     trocar de tela no fim de uma lista longa pedia voltar ao topo. */
  .plataforma-shell__lateral {
    position: sticky;
    top: 0;
    display: flex;
    height: 100vh;
    overflow-y: auto;
  }

  .plataforma-shell__gaveta-botao {
    display: none;
  }

  .plataforma-shell__cortina,
  .plataforma-shell__gaveta {
    display: none;
  }

  .plataforma-shell__conteudo {
    padding: var(--space-6);
  }
}
</style>
