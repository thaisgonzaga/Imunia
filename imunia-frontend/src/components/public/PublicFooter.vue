<script setup>
import { VERSAO } from '@/lib/documentosLegais.js'

/**
 * Rodapé das telas públicas. A ligação para a verificação de documento fica
 * aqui, e não só na faixa do meio da página, porque quem chega com um PDF na
 * mão procura o rodapé (RF47).
 *
 * Os documentos legais ficam numa faixa própria, e não junto de "Entrar" e
 * "Sou veterinário", porque não são a mesma coisa: aqueles convidam a agir,
 * estes precisam estar sempre encontráveis sem disputar a atenção com o
 * convite. É também o único ponto do sistema em que quem ainda não tem conta
 * — e portanto nunca viu a caixa de aceite — alcança os dois textos.
 *
 * A versão aparece aqui porque é a que ficará gravada no aceite de quem se
 * cadastrar agora: quem quiser conferir o que aceitou não deveria ter de abrir
 * o documento para descobrir qual é.
 */
</script>

<template>
  <footer class="public-footer">
    <div class="public-footer__inner">
      <div class="public-footer__principal">
        <p class="public-footer__text">Imunia · gestão de saúde animal para cães e gatos</p>
        <nav class="public-footer__links">
          <RouterLink to="/verificar">Verificar documento</RouterLink>
          <RouterLink to="/entrar">Entrar</RouterLink>
          <RouterLink to="/cadastrar-prestador">Sou veterinário</RouterLink>
        </nav>
      </div>

      <div class="public-footer__legal">
        <nav class="public-footer__links">
          <RouterLink to="/termos">Termos de uso</RouterLink>
          <RouterLink to="/privacidade">Política de privacidade</RouterLink>
        </nav>
        <p class="public-footer__versao">Versão {{ VERSAO }}</p>
      </div>
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

.public-footer__principal {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

/* A régua separa o que convida a agir do que precisa estar sempre à mão. */
.public-footer__legal {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  padding-top: var(--space-4);
  border-top: 1px solid var(--border-hairline);
}

.public-footer__versao {
  margin: 0;
  font-family: var(--font-mono);
  font-size: 13px;
  line-height: 20px;
  color: var(--ink-faint);
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

  /* As duas faixas continuam empilhadas; o que vira linha é cada uma delas,
     com a marca e a versão à esquerda e os links à direita. */
  .public-footer__principal,
  .public-footer__legal {
    flex-direction: row;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-6);
  }

  /* A versão vem antes dos links na leitura, e depois deles no eixo: à
     esquerda, sob a marca, é onde ela acompanha o nome do produto. */
  .public-footer__legal .public-footer__versao {
    order: -1;
  }
}
</style>
