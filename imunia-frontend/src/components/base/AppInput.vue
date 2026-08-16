<script setup>
import { computed, ref } from 'vue'
import { Eye, EyeOff, TriangleAlert } from '@lucide/vue'

const props = defineProps({
  modelValue: { type: String, default: '' },
  label: { type: String, required: true },
  id: { type: String, required: true },
  type: { type: String, default: 'text' },
  inputmode: { type: String, default: undefined },
  placeholder: { type: String, default: undefined },
  error: { type: String, default: '' },
  // Marca o campo como inválido sem mensagem própria — usado quando o erro é
  // do par de campos, e não de um deles (P02).
  invalido: { type: Boolean, default: false },
  hint: { type: String, default: '' },
  mono: { type: Boolean, default: false },
  readonly: { type: Boolean, default: false },
  autocomplete: { type: String, default: undefined },
  // Alternância de visibilidade da senha (P02): quem digita sem ver erra, e
  // errar aqui custa uma tentativa das cinco toleradas.
  revelavel: { type: Boolean, default: false },
})

defineEmits(['update:modelValue', 'blur'])

const revelada = ref(false)

const tipoEfetivo = computed(() => (
  props.revelavel && revelada.value ? 'text' : props.type
))
</script>

<template>
  <div class="app-field">
    <label :for="id" class="app-field__label">{{ label }}</label>
    <div class="app-field__control">
      <input
        :id="id"
        class="app-field__input"
        :class="{
          'app-field__input--mono': mono,
          'app-field__input--error': error || invalido,
          'app-field__input--revelavel': revelavel,
        }"
        :type="tipoEfetivo"
        :inputmode="inputmode"
        :placeholder="placeholder"
        :autocomplete="autocomplete"
        :readonly="readonly"
        :aria-invalid="error || invalido ? 'true' : undefined"
        :value="modelValue"
        @input="$emit('update:modelValue', $event.target.value)"
        @blur="$emit('blur', $event)"
      >
      <button
        v-if="revelavel"
        type="button"
        class="app-field__reveal"
        :aria-label="revelada ? 'Ocultar a senha' : 'Mostrar a senha'"
        :aria-pressed="revelada"
        @click="revelada = !revelada"
      >
        <component :is="revelada ? EyeOff : Eye" :size="20" />
      </button>
    </div>
    <p v-if="error" class="app-field__error">
      <TriangleAlert :size="16" class="app-field__error-icon" />
      <span>{{ error }}</span>
    </p>
    <p v-else-if="hint" class="app-field__hint">{{ hint }}</p>
    <div v-if="$slots.feedback && !error" class="app-field__feedback">
      <slot name="feedback" />
    </div>
  </div>
</template>

<style scoped>
.app-field {
  display: flex;
  flex-direction: column;
}

.app-field__label {
  display: block;
  margin: 0 0 6px;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink);
}

.app-field__control {
  position: relative;
  display: flex;
  align-items: center;
}

.app-field__input {
  width: 100%;
  height: 48px;
  padding: 0 var(--space-3);
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  font-family: var(--font-body);
  font-size: 16px;
  color: var(--ink);
}

.app-field__input:focus-visible {
  border-color: var(--brand-bright);
}

.app-field__input--mono {
  font-family: var(--font-mono);
  font-variant-numeric: tabular-nums;
}

.app-field__input--error {
  border-color: var(--status-late);
}

.app-field__input--revelavel {
  padding-right: var(--space-12);
}

.app-field__reveal {
  position: absolute;
  right: var(--space-1);
  display: flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  background: none;
  border: none;
  border-radius: var(--radius-sm);
  color: var(--ink-muted);
  cursor: pointer;
}

.app-field__reveal:hover {
  color: var(--ink);
}

.app-field__error {
  display: flex;
  align-items: flex-start;
  gap: var(--space-2);
  margin: 6px 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--status-late);
}

.app-field__error-icon {
  flex: none;
  margin-top: 2px;
}

.app-field__hint {
  margin: 6px 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.app-field__feedback {
  margin: 6px 0 0;
}
</style>
