<script setup>
import PublicFooter from '@/components/public/PublicFooter.vue'
import PublicHeader from '@/components/public/PublicHeader.vue'
import { VERSAO, VIGENTE_DESDE, VIGENTE_DESDE_POR_EXTENSO } from '@/lib/documentosLegais.js'

/**
 * Moldura compartilhada por P10 (termos de uso) e P11 (política de privacidade).
 *
 * As duas telas são o mesmo objeto — documento longo, público, lido uma vez e
 * consultado depois —, e o que muda entre elas é só o texto. Deixar a
 * tipografia aqui é o que impede que uma delas evolua e a outra fique para
 * trás, o que num par de documentos que se citam mutuamente seria visível.
 *
 * A versão aparece no topo porque o aceite grava a versão: sem ela à vista,
 * quem quisesse conferir o que aceitou não teria como.
 */
defineProps({
  titulo: { type: String, required: true },
  resumo: { type: String, required: true },
})
</script>

<template>
  <div class="legal">
    <PublicHeader />

    <main class="legal__main">
      <article class="legal__doc">
        <header class="legal__head">
          <h1 class="legal__title">{{ titulo }}</h1>
          <p class="legal__lead">{{ resumo }}</p>
          <p class="legal__version">
            Versão {{ VERSAO }} — em vigor desde
            <time :datetime="VIGENTE_DESDE">{{ VIGENTE_DESDE_POR_EXTENSO }}</time>
          </p>
        </header>

        <div class="legal__body">
          <slot />
        </div>
      </article>
    </main>

    <!-- O rodapé é o caminho de um documento para o outro: os dois se citam o
         tempo todo, e quem chegou ao fim de um costuma querer o outro. -->
    <PublicFooter />
  </div>
</template>

<style scoped>
.legal {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
  background: var(--surface-page);
}

/* O respiro final encolheu quando o rodapé entrou: antes ele era o fim da
   página e precisava de fôlego; agora o rodapé é que fecha. */
.legal__main {
  display: flex;
  flex: 1;
  justify-content: center;
  padding: var(--space-8) var(--space-4) var(--space-16);
}

/* A medida de leitura é generosa — bem acima dos 75ch que o briefing fixa para
   o `ConsentNotice` —, mas existe: são quatro mil palavras, e a linha de 1280 px
   passaria de 150 caracteres, que é onde o olho perde o começo da linha
   seguinte.

   Ela vive no documento inteiro, e não em cada bloco: é o que faz o
   `justify-content` do `main` centralizá-lo. Presa ao `__head` e ao `__body`, a
   medida encolhia o texto mas deixava o artigo com os 1280 px do trilho, e o
   documento ficava encostado na margem esquerda de uma faixa vazia. */
.legal__doc {
  width: 100%;
  max-width: 80ch;
}

.legal__head {
  padding-bottom: var(--space-6);
  border-bottom: 1px solid var(--border-hairline);
}

.legal__title {
  margin: 0;
  font-family: var(--font-display);
  font-size: 32px;
  line-height: 40px;
  font-weight: 600;
  color: var(--ink);
}

.legal__lead {
  margin: var(--space-3) 0 0;
  font-size: 18px;
  line-height: 28px;
  color: var(--ink-muted);
}

.legal__version {
  margin: var(--space-4) 0 0;
  font-family: var(--font-mono);
  font-size: 13px;
  line-height: 20px;
  color: var(--ink-faint);
}

/* Tipografia do corpo. O texto vem por slot em HTML simples, sem classes, para
   que o documento se leia como documento no arquivo-fonte também — quem for
   revisar a cláusula não deveria ter de atravessar marcação para chegar nela. */
.legal__body :deep(h2) {
  margin: var(--space-12) 0 var(--space-4);
  font-family: var(--font-display);
  font-size: 24px;
  line-height: 32px;
  font-weight: 600;
  color: var(--ink);
}

.legal__body :deep(h3) {
  margin: var(--space-8) 0 var(--space-3);
  font-size: 18px;
  line-height: 26px;
  font-weight: 600;
  color: var(--ink);
}

.legal__body :deep(p),
.legal__body :deep(li) {
  font-size: 16px;
  line-height: 26px;
  color: var(--ink);
}

.legal__body :deep(p) {
  margin: var(--space-4) 0;
}

.legal__body :deep(ul),
.legal__body :deep(ol) {
  margin: var(--space-4) 0;
  padding-left: var(--space-6);
}

.legal__body :deep(li) {
  margin: var(--space-2) 0;
}

.legal__body :deep(strong) {
  font-weight: 600;
}

.legal__body :deep(a) {
  color: var(--brand);
  text-decoration: underline;
  text-underline-offset: 2px;
}

/* Cláusula que limita direito: destacada e imediatamente compreensível, como
   exige o art. 54, § 4º do Código de Defesa do Consumidor. O destaque não é
   ornamento — é requisito de validade da cláusula. */
.legal__body :deep(.legal-destaque) {
  margin: var(--space-6) 0;
  padding: var(--space-4);
  background: var(--consent-wash);
  border: 1px solid var(--consent);
  border-radius: var(--radius-sm);
}

.legal__body :deep(.legal-destaque__rotulo) {
  display: block;
  margin-bottom: var(--space-2);
  font-size: 13px;
  line-height: 20px;
  font-weight: 600;
  letter-spacing: .04em;
  text-transform: uppercase;
  color: var(--consent);
}

.legal__body :deep(.legal-destaque p) {
  margin: var(--space-2) 0 0;
}

.legal__body :deep(.legal-destaque > p:first-of-type) {
  margin-top: 0;
}

/* Nota de fundamento legal: presente para quem quiser conferir, discreta para
   quem não quiser. */
.legal__body :deep(.legal-nota) {
  margin: var(--space-4) 0;
  padding-left: var(--space-4);
  border-left: 2px solid var(--border-strong);
  font-size: 14px;
  line-height: 22px;
  color: var(--ink-muted);
}

.legal__body :deep(.legal-nota p) {
  margin: 0;
  font-size: 14px;
  line-height: 22px;
  color: var(--ink-muted);
}

/* As tabelas de finalidade e de prazo são densas e não cabem em celular.
   Rolagem própria, para que a página nunca role na horizontal. */
.legal__body :deep(.legal-tabela) {
  overflow-x: auto;
  margin: var(--space-4) 0;
}

.legal__body :deep(table) {
  width: 100%;
  border-collapse: collapse;
  font-size: 15px;
  line-height: 22px;
}

.legal__body :deep(th),
.legal__body :deep(td) {
  padding: var(--space-3);
  text-align: left;
  vertical-align: top;
  border-bottom: 1px solid var(--border-hairline);
  color: var(--ink);
}

.legal__body :deep(th) {
  font-weight: 600;
  white-space: nowrap;
  color: var(--ink-muted);
  background: var(--surface-sunken);
}

.legal__body :deep(.legal-campo) {
  padding: 1px var(--space-1);
  background: var(--surface-sunken);
  border-radius: var(--radius-xs);
  font-family: var(--font-mono);
  font-size: 14px;
  color: var(--ink-muted);
}

@media (min-width: 768px) {
  .legal__main {
    padding: var(--space-12) var(--space-12) var(--space-16);
  }

  .legal__title {
    font-size: 40px;
    line-height: 48px;
  }
}
</style>
