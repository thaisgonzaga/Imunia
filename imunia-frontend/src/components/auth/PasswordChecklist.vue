<script setup>
import { computed } from 'vue'
import { CircleCheck, CircleHelp, CircleX } from '@lucide/vue'
import { criteriosDeSenha } from '@/lib/senha.js'

/**
 * Critérios de senha de RN04 marcados conforme o usuário digita. Os mesmos
 * três critérios valem no autocadastro (P03), na redefinição (P06) e na
 * ativação por convite (P07) — e o servidor os cobra de novo em todos.
 */
const props = defineProps({
  senha: { type: String, default: '' },
})

const criterios = computed(() => criteriosDeSenha(props.senha))

// Com o campo vazio os critérios ainda são só orientação; a partir do primeiro
// caractere, o que faltar passa a ser apontado em vermelho.
const digitando = computed(() => props.senha.length > 0)

function situacao(criterio) {
  if (criterio.atendido) return 'atendido'
  return digitando.value ? 'pendente' : 'neutro'
}

const icones = { atendido: CircleCheck, pendente: CircleX, neutro: CircleHelp }
</script>

<template>
  <ul class="password-checklist">
    <li
      v-for="criterio in criterios"
      :key="criterio.rotulo"
      :class="`password-checklist__item--${situacao(criterio)}`"
    >
      <component :is="icones[situacao(criterio)]" :size="16" aria-hidden="true" />
      {{ criterio.rotulo }}
      <span v-if="digitando" class="visually-hidden">
        {{ criterio.atendido ? '(atendido)' : '(pendente)' }}
      </span>
    </li>
  </ul>
</template>

<style scoped>
.password-checklist {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin: var(--space-3) 0 0;
  padding: 0;
  list-style: none;
}

.password-checklist li {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.password-checklist li svg {
  flex: none;
  color: var(--unverified);
}

/* Os modificadores repetem o bloco para superar `.password-checklist li`, que
   sozinho já é mais específico que uma classe de item. */
.password-checklist .password-checklist__item--atendido {
  color: var(--ink);
}

.password-checklist .password-checklist__item--atendido svg {
  color: var(--brand);
}

.password-checklist .password-checklist__item--pendente,
.password-checklist .password-checklist__item--pendente svg {
  color: var(--status-late);
}

.visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
}
</style>
