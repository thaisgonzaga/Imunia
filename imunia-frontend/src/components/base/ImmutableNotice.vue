<script setup>
import { computed } from 'vue'
import { Lock } from '@lucide/vue'

/**
 * `ImmutableNotice` (§5.2 do briefing) — bloco com ícone de cadeado que
 * declara a imutabilidade do registro clínico (decisoes.md §3.4, RN26).
 * Três variantes: `formulario`, exibida antes da confirmação num fluxo de
 * registro (fatia futura); `informativa`, no topo de uma tela de leitura como
 * T06; e `encadeada`, quando o registro lido já tem retificação de um lado ou
 * do outro (T08) — a mesma regra, no tempo verbal de cada momento.
 */
const props = defineProps({
  variante: {
    type: String,
    default: 'informativa',
    validator: (v) => ['formulario', 'informativa', 'encadeada'].includes(v),
  },
})

const TEXTOS = {
  formulario: 'Depois de confirmado, este registro não pode ser alterado nem excluído. Correções entram como retificação vinculada, e as duas versões ficam visíveis.',
  informativa: 'Este é um registro clínico imutável: não pode ser alterado nem excluído. Uma eventual correção entraria como retificação vinculada, com as duas versões visíveis.',
  // Aqui a regra já não é hipótese: ela aconteceu, e o que o tutor precisa
  // saber é que a correção não apagou nada.
  encadeada: 'Nada foi apagado: a versão original continua no sistema como foi confirmada, e a retificação fica ligada a ela nos dois sentidos.',
}

const texto = computed(() => TEXTOS[props.variante])
</script>

<template>
  <div class="immutable-notice">
    <Lock :size="18" :stroke-width="1.75" class="immutable-notice__icone" />
    <p class="immutable-notice__texto">{{ texto }}</p>
  </div>
</template>

<style scoped>
.immutable-notice {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
  background: var(--surface-sunken);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

.immutable-notice__icone {
  flex: none;
  margin-top: 2px;
  color: var(--ink-muted);
}

.immutable-notice__texto {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}
</style>
