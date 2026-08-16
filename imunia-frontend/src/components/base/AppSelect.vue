<script setup>
defineProps({
  modelValue: { type: String, default: '' },
  label: { type: String, required: true },
  id: { type: String, required: true },
  options: { type: Array, required: true }, // [{ value, label }]
  placeholder: { type: String, default: 'Selecione' },
  error: { type: String, default: '' },
})

defineEmits(['update:modelValue', 'blur'])
</script>

<template>
  <div class="app-field">
    <label :for="id" class="app-field__label">{{ label }}</label>
    <select
      :id="id"
      class="app-field__select"
      :class="{ 'app-field__select--error': error }"
      :aria-invalid="error ? 'true' : undefined"
      :value="modelValue"
      @change="$emit('update:modelValue', $event.target.value)"
      @blur="$emit('blur', $event)"
    >
      <option value="" disabled>{{ placeholder }}</option>
      <option v-for="option in options" :key="option.value" :value="option.value">
        {{ option.label }}
      </option>
    </select>
    <p v-if="error" class="app-field__error">{{ error }}</p>
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

.app-field__select {
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

.app-field__select:focus-visible {
  border-color: var(--brand-bright);
}

.app-field__select--error {
  border-color: var(--status-late);
}

.app-field__error {
  margin: 6px 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--status-late);
}
</style>
