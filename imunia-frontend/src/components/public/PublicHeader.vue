<script setup>
/**
 * Cabeçalho das telas públicas (P01 e, adiante, P09). Abaixo de 768 px resta
 * apenas "Entrar": o convite a criar conta já está no herói, e dois alvos
 * disputando a mesma faixa de 56 px encolheriam ambos abaixo dos 44 px
 * exigidos em celular.
 */
</script>

<template>
  <header class="public-header">
    <RouterLink to="/" class="public-header__brand">Imunia</RouterLink>
    <nav class="public-header__actions">
      <RouterLink to="/entrar" class="public-header__link">Entrar</RouterLink>
      <RouterLink to="/criar-conta" class="public-header__cta">Criar conta</RouterLink>
    </nav>
  </header>
</template>

<style scoped>
.public-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-4);
  height: 56px;
  padding: 0 var(--space-4);
  background: var(--surface-card);
  border-bottom: 1px solid var(--border-hairline);
}

.public-header__brand {
  display: inline-flex;
  align-items: center;
  height: 44px;
  font-family: var(--font-display);
  font-size: 22px;
  line-height: 28px;
  font-weight: 600;
  color: var(--brand);
}

.public-header__actions {
  display: flex;
  align-items: center;
  gap: var(--space-3);
}

.public-header__link {
  display: inline-flex;
  align-items: center;
  height: 44px;
  padding: 0 var(--space-2);
  font-size: 14px;
  line-height: 20px;
  font-weight: 600;
}

.public-header__cta {
  display: none;
}

@media (min-width: 768px) {
  .public-header {
    justify-content: center;
    gap: var(--space-6);
    padding: 0 var(--space-12);
  }

  .public-header__brand {
    flex: 1;
    max-width: 1280px;
  }

  /* Acima de 768 px os dois alvos cabem lado a lado com folga. */
  .public-header__cta {
    display: inline-flex;
    align-items: center;
    height: 36px;
    padding: 0 14px;
    background: var(--brand);
    border: 1px solid var(--brand);
    border-radius: var(--radius-sm);
    font-size: 14px;
    line-height: 20px;
    font-weight: 600;
    color: var(--surface-card);
  }

  .public-header__cta:hover {
    background: var(--brand-hover);
    border-color: var(--brand-hover);
    color: var(--surface-card);
  }

  .public-header__link {
    height: 36px;
    padding: 0 var(--space-3);
  }
}
</style>
