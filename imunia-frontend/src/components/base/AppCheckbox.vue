<script setup>
defineProps({
  modelValue: { type: Boolean, default: false },
  id: { type: String, required: true },
})

defineEmits(['update:modelValue'])
</script>

<template>
  <label :for="id" class="app-checkbox">
    <input
      :id="id"
      type="checkbox"
      class="app-checkbox__input"
      :checked="modelValue"
      @change="$emit('update:modelValue', $event.target.checked)"
    >
    <span class="app-checkbox__label"><slot /></span>
  </label>
</template>

<style scoped>
.app-checkbox {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  /* Alvo de toque mínimo de 44 px, exigência abaixo de 1024 px. */
  min-height: 44px;
  cursor: pointer;
}

.app-checkbox__input {
  width: 20px;
  height: 20px;
  flex: none;
  accent-color: var(--brand);
  cursor: pointer;
}

.app-checkbox__label {
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);
}
</style>
