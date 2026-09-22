<script setup>
import { onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { Bell, Dog, KeyRound, PawPrint, UserRound } from '@lucide/vue'
import { useSessaoStore } from '@/stores/sessao.js'
import { useSolicitacoesStore } from '@/stores/solicitacoes.js'

/**
 * Moldura do ambiente do tutor (§5.3 do briefing): cabeçalho de 56 px, abas
 * inferiores no celular e barra lateral de 240 px a partir de 1024 px, com o
 * conteúdo limitado a 880 px. As abas ficam fixas no rodapé porque o painel é
 * feito para ser lido com uma das mãos, de pé, na recepção da clínica.
 */
defineProps({
  /**
   * O limite de 880 px existe para texto corrido em linha legível. Estados que
   * não são leitura — o vazio de um painel, por exemplo — ficariam encostados
   * na esquerda com uma faixa vazia à direita; `amplo` devolve a largura
   * inteira da coluna, e o respiro passa a ser o mesmo dos dois lados.
   */
  amplo: { type: Boolean, default: false },
})

const route = useRoute()
const sessao = useSessaoStore()
const solicitacoes = useSolicitacoesStore()

// RF38 — o contador da aba. A consulta é uma só por carregamento do SPA: a
// store guarda o número, e T13 o corrige quando o tutor responde a um pedido.
onMounted(() => solicitacoes.carregar())

/**
 * Os quatro destinos são os do briefing, e desde a fatia de T18 os quatro têm
 * tela. O sino do cabeçalho leva a T17 (`/conta/notificacoes/enviadas`), que
 * fica sob a seção "Conta" — é por `familia` que o destaque continua lá.
 *
 * `familia` existe porque a seção não é a rota: "Compartilhamento" abrange o
 * diretório, a concessão, as autorizações, os pedidos e a auditoria, e o
 * `router-link-active` sozinho apagaria o destaque em quatro dessas cinco
 * telas — o tutor deixaria de saber em que parte do aplicativo está.
 */
const SECOES = [
  {
    rotulo: 'Início',
    abreviado: 'Início',
    destino: '/inicio',
    icone: PawPrint,
    familia: ['/inicio'],
  },
  {
    rotulo: 'Animais',
    abreviado: 'Animais',
    destino: '/animais',
    icone: Dog,
    familia: ['/animais'],
  },
  {
    rotulo: 'Compartilhamento',
    abreviado: 'Compartilhar',
    destino: '/autorizacoes',
    icone: KeyRound,
    familia: ['/autorizacoes', '/solicitacoes', '/prestadores', '/acessos'],
    contador: true,
  },
  {
    rotulo: 'Conta',
    abreviado: 'Conta',
    destino: '/conta',
    icone: UserRound,
    familia: ['/conta'],
  },
]

function ativa(secao) {
  return secao.familia.some((raiz) => route.path === raiz || route.path.startsWith(`${raiz}/`))
}
</script>

<template>
  <div class="tutor-shell">
    <aside class="tutor-shell__lateral">
      <RouterLink to="/inicio" class="tutor-shell__marca tutor-shell__marca--lateral">Imunia</RouterLink>
      <nav class="tutor-shell__lateral-nav" aria-label="Seções do Imunia">
        <RouterLink
          v-for="secao in SECOES"
          :key="secao.destino"
          :to="secao.destino"
          class="tutor-shell__item"
          :class="{ 'tutor-shell__item--ativa': ativa(secao) }"
        >
          <component :is="secao.icone" :size="20" :stroke-width="1.75" />
          {{ secao.rotulo }}
          <span
            v-if="secao.contador && solicitacoes.temPendencia"
            class="tutor-shell__selo tutor-shell__selo--lateral"
          >
            <span aria-hidden="true">{{ solicitacoes.pendentes }}</span>
            <span class="tutor-shell__oculto">
              {{ solicitacoes.pendentes }}
              {{ solicitacoes.pendentes === 1 ? 'pedido de acesso' : 'pedidos de acesso' }}
              aguardando resposta
            </span>
          </span>
        </RouterLink>
      </nav>
    </aside>

    <div class="tutor-shell__coluna">
      <header class="tutor-shell__cabecalho">
        <RouterLink to="/inicio" class="tutor-shell__marca">Imunia</RouterLink>

        <div class="tutor-shell__conta">
          <RouterLink
            to="/conta/notificacoes/enviadas"
            class="tutor-shell__alvo"
            aria-label="Notificações"
          >
            <Bell :size="20" :stroke-width="1.75" />
          </RouterLink>
          <RouterLink to="/conta" class="tutor-shell__alvo" aria-label="Minha conta">
            <span class="tutor-shell__avatar" aria-hidden="true">{{ sessao.iniciais }}</span>
          </RouterLink>
        </div>
      </header>

      <!-- Tarja de largura total, entre o cabeçalho e o conteúdo: é onde entra
           o aviso de e-mail ainda não confirmado (RF05). -->
      <slot name="aviso" />

      <main class="tutor-shell__conteudo">
        <div class="tutor-shell__limite" :class="{ 'tutor-shell__limite--amplo': amplo }">
          <slot />
        </div>
      </main>
    </div>

    <nav class="tutor-shell__abas" aria-label="Seções do Imunia">
      <RouterLink
        v-for="secao in SECOES"
        :key="secao.destino"
        :to="secao.destino"
        class="tutor-shell__aba"
        :class="{ 'tutor-shell__aba--ativa': ativa(secao) }"
      >
        <span class="tutor-shell__aba-icone">
          <component :is="secao.icone" :size="20" :stroke-width="1.75" />
          <span v-if="secao.contador && solicitacoes.temPendencia" class="tutor-shell__selo">
            <span aria-hidden="true">{{ solicitacoes.pendentes }}</span>
            <span class="tutor-shell__oculto">
              {{ solicitacoes.pendentes }}
              {{ solicitacoes.pendentes === 1 ? 'pedido de acesso' : 'pedidos de acesso' }}
              aguardando resposta
            </span>
          </span>
        </span>
        <span class="tutor-shell__aba-rotulo">{{ secao.abreviado }}</span>
      </RouterLink>
    </nav>
  </div>
</template>

<style scoped>
.tutor-shell {
  display: flex;
  min-height: 100vh;
  background: var(--surface-page);
}

.tutor-shell__coluna {
  flex: 1;
  min-width: 0;
}

.tutor-shell__cabecalho {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
  height: 56px;
  padding: 0 var(--space-4);
  background: var(--surface-card);
  border-bottom: 1px solid var(--border-hairline);
}

.tutor-shell__marca {
  font-family: var(--font-display);
  font-size: 18px;
  line-height: 24px;
  font-weight: 600;
  color: var(--brand);
}

.tutor-shell__conta {
  display: flex;
  align-items: center;
  gap: var(--space-2);
}

/* Alvos de 44 px mesmo com ícone de 20: o briefing fixa o mínimo abaixo de
   1024 px, e o cabeçalho é o lugar onde o dedo mais erra. */
.tutor-shell__alvo {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  color: var(--ink-muted);
}

.tutor-shell__alvo:hover {
  color: var(--ink);
}

.tutor-shell__avatar {
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

.tutor-shell__conteudo {
  /* A barra de abas é fixa: o conteúdo reserva a altura dela para que o último
     bloco da página não fique embaixo do rodapé. */
  padding: var(--space-4) var(--space-4) calc(56px + var(--space-6));
}

.tutor-shell__abas {
  position: fixed;
  inset: auto 0 0;
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  background: var(--surface-card);
  border-top: 1px solid var(--border-hairline);
}

.tutor-shell__aba {
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

.tutor-shell__aba--ativa,
.tutor-shell__aba:hover {
  color: var(--brand);
}

.tutor-shell__aba--ativa .tutor-shell__aba-rotulo {
  font-weight: 600;
}

.tutor-shell__aba-icone {
  position: relative;
  display: inline-flex;
}

/* O selo de pendência (RF38). Índigo de consentimento, como tudo o que fala de
   autorização, e com o número escrito — um ponto sozinho diria que há algo, e
   não quantos. */
.tutor-shell__selo {
  position: absolute;
  top: -6px;
  left: 12px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  border-radius: var(--radius-pill);
  background: var(--consent);
  color: var(--surface-card);
  font-size: 11px;
  line-height: 18px;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.tutor-shell__oculto {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip-path: inset(50%);
  white-space: nowrap;
}

.tutor-shell__lateral {
  display: none;
}

@media (min-width: 768px) {
  .tutor-shell__cabecalho {
    padding: 0 var(--space-6);
  }

  .tutor-shell__conteudo {
    padding: var(--space-6) var(--space-6) calc(56px + var(--space-8));
  }
}

@media (min-width: 1024px) {
  /* Presa à janela: esticada à altura do conteúdo, a navegação rolava junto e
     trocar de seção no fim do histórico pedia voltar ao topo. */
  .tutor-shell__lateral {
    position: sticky;
    top: 0;
    display: block;
    flex: none;
    width: 240px;
    height: 100vh;
    overflow-y: auto;
    padding: var(--space-4) var(--space-3);
    background: var(--surface-card);
    border-right: 1px solid var(--border-hairline);
  }

  .tutor-shell__marca--lateral {
    display: block;
    padding: 0 var(--space-2) var(--space-6);
    font-size: 22px;
    line-height: 28px;
  }

  .tutor-shell__lateral-nav {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
  }

  .tutor-shell__item {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    padding: 10px var(--space-2);
    border-radius: var(--radius-sm);
    color: var(--ink-muted);
    font-size: 16px;
  }

  .tutor-shell__item:hover {
    background: var(--surface-sunken);
    color: var(--ink);
  }

  .tutor-shell__item--ativa {
    background: var(--brand-wash);
    color: var(--brand);
    font-weight: 600;
  }

  /* Na barra lateral o selo acompanha o rótulo, no fim da linha: há largura
     para ele, e sobre o ícone de 20 px ficaria apertado contra o texto. */
  .tutor-shell__selo--lateral {
    position: static;
    margin-left: auto;
  }

  /* A marca já está na barra lateral; no cabeçalho restam sino e avatar. */
  .tutor-shell__cabecalho {
    justify-content: flex-end;
    padding: 0 var(--space-8);
  }

  .tutor-shell__cabecalho .tutor-shell__marca {
    display: none;
  }

  .tutor-shell__conteudo {
    padding: var(--space-8);
  }

  .tutor-shell__limite {
    max-width: 880px;
  }

  .tutor-shell__limite--amplo {
    max-width: none;
  }

  .tutor-shell__abas {
    display: none;
  }
}
</style>
