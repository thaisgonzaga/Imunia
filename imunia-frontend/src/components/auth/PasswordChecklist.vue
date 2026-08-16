<script setup>
import { computed } from 'vue'
import { CircleCheck, CircleHelp } from '@lucide/vue'
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
</script>

<template>
  <ul class="password-checklist">
    <li
      v-for="criterio in criterios"
      :key="criterio.rotulo"
      :class="{ 'password-checklist__item--met': criterio.atendido }"
    >
      <component :is="criterio.atendido ? CircleCheck : CircleHelp" :size="16" />
      {{ criterio.rotulo }}
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

.password-checklist__item--met {
  color: var(--ink);
}

.password-checklist__item--met svg {
  color: var(--brand);
}
</style>
