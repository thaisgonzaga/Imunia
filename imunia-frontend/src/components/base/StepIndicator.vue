<script setup>
defineProps({
  current: { type: Number, required: true },
  total: { type: Number, required: true },
  label: { type: String, required: true },
})
</script>

<template>
  <div class="step-indicator">
    <span class="step-indicator__label">Passo {{ current }} de {{ total }} · {{ label }}</span>
    <span class="step-indicator__track">
      <span
        v-for="n in total"
        :key="n"
        class="step-indicator__segment"
        :class="{ 'step-indicator__segment--filled': n <= current }"
      />
    </span>
  </div>
</template>

<style scoped>
.step-indicator {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-4) var(--space-6);
  background: var(--brand-wash);
  border-bottom: 1px solid var(--border-hairline);
}

.step-indicator__label {
  flex: none;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--brand);
}

.step-indicator__track {
  flex: 1;
  display: flex;
  gap: var(--space-1);
}

.step-indicator__segment {
  flex: 1;
  height: 4px;
  border-radius: var(--radius-pill);
  background: var(--border-strong);
}

.step-indicator__segment--filled {
  background: var(--brand);
}

@media (max-width: 767px) {
  .step-indicator {
    flex-direction: column;
    align-items: stretch;
    gap: var(--space-2);
  }
}
</style>
