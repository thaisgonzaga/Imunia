<script setup>
/**
 * Estado de exceção (§8.1 do briefing, E01–E03): o `EmptyState` em escala
 * maior — ícone de 40 px, título, uma frase e ação de retorno —, centrado na
 * área de conteúdo da moldura que o envolve.
 *
 * Não é o estado de erro de carregamento de uma tela (§6.1, item 3), que vive
 * dentro da tela que falhou e mantém tudo o mais no lugar. Este componente é
 * para quando não há tela: a página não existe, não é sua, ou não pôde ser
 * montada.
 */
defineProps({
  icone: { type: [Object, Function], required: true },
  titulo: { type: String, required: true },
  descricao: { type: String, default: '' },
  /**
   * A cor do ícone. "neutro" é o impedimento (E01), "discreto" é a ausência
   * (E02) e "falha" é o vermelho que o sistema reserva ao que deu errado — e
   * que nenhuma das outras duas telas usa, porque nem toda parede é um defeito.
   */
  tom: {
    type: String,
    default: 'neutro',
    validator: (valor) => ['neutro', 'discreto', 'falha'].includes(valor),
  },
})
</script>

<template>
  <div class="exception-state">
    <div class="exception-state__bloco">
      <component
        :is="icone"
        :size="40"
        :stroke-width="1.75"
        class="exception-state__icone"
        :class="`exception-state__icone--${tom}`"
      />

      <h1 class="exception-state__titulo">{{ titulo }}</h1>
      <p v-if="descricao" class="exception-state__descricao">{{ descricao }}</p>

      <!-- As ações são links de navegação e, em E03, um botão de nova
           tentativa. O componente estiliza os dois pela classe `acao` para que
           tenham exatamente a mesma altura e o mesmo peso, como no desenho. -->
      <div class="exception-state__acoes"><slot /></div>

      <div v-if="$slots.rodape" class="exception-state__rodape">
        <slot name="rodape" />
      </div>
    </div>
  </div>
</template>

<style scoped>
.exception-state {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 360px;
  padding: var(--space-24) var(--space-6);
}

.exception-state__bloco {
  max-width: 60ch;
  text-align: center;
}

.exception-state__icone--neutro {
  color: var(--ink-muted);
}

.exception-state__icone--discreto {
  color: var(--unverified);
}

.exception-state__icone--falha {
  color: var(--status-late);
}

.exception-state__titulo {
  margin: var(--space-4) 0 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  letter-spacing: -.01em;
  color: var(--ink);
  text-wrap: pretty;
}

.exception-state__descricao {
  margin: var(--space-2) 0 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
  text-wrap: pretty;
}

.exception-state__acoes:not(:empty) {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: center;
  gap: var(--space-3);
  margin: var(--space-6) 0 0;
}

.exception-state__acoes :deep(.acao) {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 40px;
  padding: 0 var(--space-4);
  background: var(--brand);
  border: 1px solid var(--brand);
  border-radius: var(--radius-sm);
  font-family: var(--font-body);
  font-size: 14px;
  font-weight: 600;
  color: #FFFFFF;
  cursor: pointer;
  transition: background-color 120ms cubic-bezier(.2, 0, 0, 1), border-color 120ms cubic-bezier(.2, 0, 0, 1);
}

.exception-state__acoes :deep(.acao:hover) {
  background: var(--brand-hover);
  border-color: var(--brand-hover);
  color: #FFFFFF;
}

.exception-state__acoes :deep(.acao--secundaria) {
  background: var(--surface-card);
  border-color: var(--border-strong);
  color: var(--ink);
}

.exception-state__acoes :deep(.acao--secundaria:hover) {
  background: var(--surface-sunken);
  border-color: var(--border-strong);
  color: var(--ink);
}

.exception-state__rodape {
  margin: var(--space-6) 0 0;
  padding: var(--space-3) 0 0;
  border-top: 1px solid var(--border-hairline);
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
}

/* Coluna única em todas as larguras, e alvos de 48 px onde o dedo é o
   apontador: a densidade de 40 px do desenho é do desktop. */
@media (max-width: 767px) {
  .exception-state {
    min-height: 0;
    padding: var(--space-12) var(--space-4);
  }

  .exception-state__titulo {
    font-size: 24px;
    line-height: 30px;
  }

  .exception-state__acoes:not(:empty) {
    flex-direction: column;
    align-items: stretch;
  }

  .exception-state__acoes :deep(.acao) {
    height: 48px;
    font-size: 16px;
  }
}
</style>
