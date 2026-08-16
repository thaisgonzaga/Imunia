<script setup>
/**
 * Rodapé das telas públicas. A ligação para a verificação de documento fica
 * aqui, e não só na faixa do meio da página, porque quem chega com um PDF na
 * mão procura o rodapé (RF47).
 */
</script>

<template>
  <footer class="public-footer">
    <div class="public-footer__inner">
      <p class="public-footer__text">Imunia · gestão de saúde animal para cães e gatos</p>
      <nav class="public-footer__links">
        <RouterLink to="/verificar">Verificar documento</RouterLink>
        <RouterLink to="/entrar">Entrar</RouterLink>
        <RouterLink to="/cadastrar-prestador">Sou veterinário</RouterLink>
      </nav>
    </div>
  </footer>
</template>

<style scoped>
.public-footer {
  display: flex;
  justify-content: center;
  padding: var(--space-6) var(--space-4);
  background: var(--surface-card);
  border-top: 1px solid var(--border-hairline);
}

.public-footer__inner {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  width: 100%;
  max-width: 1280px;
}

.public-footer__text {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.public-footer__links {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-4);
  font-size: 14px;
  line-height: 20px;
}

@media (min-width: 768px) {
  .public-footer {
    padding: var(--space-6) var(--space-12);
  }

  .public-footer__inner {
    flex-direction: row;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-6);
  }
}
</style>
