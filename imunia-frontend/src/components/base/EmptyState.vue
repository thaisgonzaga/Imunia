<script setup>
/**
 * Estado vazio (§5.1 do briefing): ícone, título, uma frase que explica o
 * porquê do vazio e — sempre — uma ação. Vazio nunca é tela morta.
 */
defineProps({
  icone: { type: [Object, Function], required: true },
  titulo: { type: String, required: true },
  descricao: { type: String, default: '' },
})
</script>

<template>
  <div class="empty-state">
    <component :is="icone" :size="32" :stroke-width="1.75" class="empty-state__icone" />
    <p class="empty-state__titulo">{{ titulo }}</p>
    <p v-if="descricao" class="empty-state__descricao">{{ descricao }}</p>
    <div class="empty-state__acao"><slot /></div>
  </div>
</template>

<style scoped>
.empty-state {
  padding: var(--space-8) var(--space-6);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
  text-align: center;
}

.empty-state__icone {
  color: var(--ink-faint);
}

.empty-state__titulo {
  margin: var(--space-3) 0 0;
  font-size: 18px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.empty-state__descricao {
  margin: var(--space-2) 0 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
}

/* As ações vêm por slot e são elementos inline: o compilador do Vue condensa o
   espaço entre duas tags, e dois botões lado a lado saíam encostados. O gap é
   do bloco, para que nenhuma tela precise se lembrar disso. */
.empty-state__acao:not(:empty) {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-3);
  flex-wrap: wrap;
  margin: var(--space-6) 0 0;
}
</style>
