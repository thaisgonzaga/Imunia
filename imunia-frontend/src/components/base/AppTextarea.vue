<script setup>
/**
 * Campo de texto longo, irmão de `AppInput` — mesma API, mesmo rótulo, mesma
 * dica e mesmo erro, para que um formulário que mistura os dois não tenha duas
 * gramáticas de campo.
 *
 * Nasceu com V07 (observação da aplicação e justificativa de conduta
 * divergente, RF27b) e serve a V08, que é quase só texto longo.
 */
import { nextTick, onMounted, ref, watch } from 'vue'
import { TriangleAlert } from '@lucide/vue'

const props = defineProps({
  modelValue: { type: String, default: '' },
  label: { type: String, required: true },
  id: { type: String, required: true },
  linhas: { type: Number, default: 2 },
  placeholder: { type: String, default: undefined },
  error: { type: String, default: '' },
  hint: { type: String, default: '' },
  readonly: { type: Boolean, default: false },

  /**
   * V08 — a caixa cresce com o texto, em vez de rolar por dentro. Opcional
   * porque só faz sentido onde o texto é o registro: numa observação de duas
   * linhas, a barra de rolagem interna nunca aparece, e o ajuste a cada tecla
   * seria trabalho por nada.
   */
  autoExpansivel: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'blur'])

const campo = ref(null)

function ajustarAltura() {
  if (!props.autoExpansivel || campo.value === null) return

  // Zerar antes de medir é o que permite a caixa **encolher** quando o texto
  // some: `scrollHeight` de um elemento com altura fixa nunca é menor que ela.
  campo.value.style.height = 'auto'
  campo.value.style.height = `${campo.value.scrollHeight}px`
}

function aoDigitar(evento) {
  emit('update:modelValue', evento.target.value)
  ajustarAltura()
}

onMounted(ajustarAltura)

// O rascunho restaurado chega pelo `modelValue`, sem passar por tecla alguma:
// sem este observador, a anamnese de dez linhas voltaria dentro de uma caixa
// de duas.
watch(() => props.modelValue, () => nextTick(ajustarAltura))
</script>

<template>
  <div class="app-textarea">
    <label :for="id" class="app-textarea__label">{{ label }}</label>
    <textarea
      :id="id"
      ref="campo"
      class="app-textarea__campo"
      :class="{ 'app-textarea__campo--error': error }"
      :rows="linhas"
      :placeholder="placeholder"
      :readonly="readonly"
      :aria-invalid="error ? 'true' : undefined"
      :value="modelValue"
      @input="aoDigitar"
      @blur="$emit('blur', $event)"
    />
    <p v-if="error" class="app-textarea__error">
      <TriangleAlert :size="16" class="app-textarea__error-icon" />
      <span>{{ error }}</span>
    </p>
    <p v-else-if="hint" class="app-textarea__hint">{{ hint }}</p>
  </div>
</template>

<style scoped>
.app-textarea {
  display: flex;
  flex-direction: column;
}

.app-textarea__label {
  display: block;
  margin: 0 0 6px;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink);
}

.app-textarea__campo {
  width: 100%;
  padding: var(--space-2) var(--space-3);
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  font-family: var(--font-body);
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);

  /* O texto clínico cresce; a caixa acompanha, e o profissional decide até
     onde. Sem isso, uma anamnese de dez linhas se lê por uma fresta. */
  resize: vertical;
  min-height: 72px;
}

.app-textarea__campo:focus-visible {
  border-color: var(--brand-bright);
}

.app-textarea__campo--error {
  border-color: var(--status-late);
}

.app-textarea__error {
  display: flex;
  align-items: flex-start;
  gap: var(--space-2);
  margin: 6px 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--status-late);
}

.app-textarea__error-icon {
  flex: none;
  margin-top: 2px;
}

.app-textarea__hint {
  margin: 6px 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

@media (min-width: 1024px) {
  .app-textarea__campo {
    font-size: 14px;
    line-height: 20px;
    min-height: 60px;
  }
}
</style>
