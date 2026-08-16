<script setup>
import { Check } from '@lucide/vue'

/**
 * Moldura de duas colunas das telas públicas de criação de conta (P03, P04):
 * painel da marca à esquerda, formulário à direita ocupando a página inteira.
 * Difere da `AuthPage` por propósito — ali o usuário já decidiu entrar e só
 * precisa do cartão; aqui ainda está decidindo, e a coluna da esquerda é o
 * argumento. Abaixo de 1024 px o painel sai e sobra o cabeçalho de 56 px,
 * porque a coluna dupla não cabe sem espremer o formulário.
 */
defineProps({
  titulo: { type: String, required: true },
  subtitulo: { type: String, default: '' },
  itens: { type: Array, default: () => [] },
  rodape: { type: String, default: '' },
  acaoRotulo: { type: String, default: '' },
  acaoDestino: { type: String, default: '' },
  // P04 é assistente de três passos com campos lado a lado — a coluna de
  // 400 px que serve ao formulário de uma coluna só (P03) fica apertada.
  largo: { type: Boolean, default: false },
})
</script>

<template>
  <div class="auth-split">
    <header class="auth-split__header">
      <RouterLink to="/" class="auth-split__header-brand">Imunia</RouterLink>
      <RouterLink v-if="acaoRotulo" :to="acaoDestino" class="auth-split__header-action">
        {{ acaoRotulo }}
      </RouterLink>
    </header>

    <!-- Argumento de venda, não conteúdo do formulário: fora da ordem de leitura
         de quem usa leitor de tela para preencher o cadastro. -->
    <aside class="auth-split__panel">
      <RouterLink to="/" class="auth-split__brand">Imunia</RouterLink>

      <div class="auth-split__pitch">
        <h2 class="auth-split__title">{{ titulo }}</h2>
        <p v-if="subtitulo" class="auth-split__subtitle">{{ subtitulo }}</p>

        <ul v-if="itens.length" class="auth-split__list">
          <li v-for="item in itens" :key="item" class="auth-split__item">
            <span class="auth-split__check" aria-hidden="true"><Check :size="14" /></span>
            {{ item }}
          </li>
        </ul>
      </div>

      <p v-if="rodape" class="auth-split__footnote">{{ rodape }}</p>
    </aside>

    <main class="auth-split__main">
      <div class="auth-split__column" :class="{ 'auth-split__column--largo': largo }">
        <slot />
      </div>
    </main>
  </div>
</template>

<style scoped>
.auth-split {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
  background: var(--surface-card);
}

.auth-split__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
  height: 56px;
  padding: 0 var(--space-4);
  background: var(--surface-card);
  border-bottom: 1px solid var(--border-hairline);
}

.auth-split__header-brand {
  display: inline-flex;
  align-items: center;
  height: 44px;
  font-family: var(--font-display);
  font-size: 22px;
  line-height: 28px;
  font-weight: 600;
  color: var(--brand);
}

.auth-split__header-action {
  display: inline-flex;
  align-items: center;
  height: 44px;
  padding: 0 var(--space-2);
  font-size: 14px;
  line-height: 20px;
  font-weight: 600;
}

.auth-split__panel {
  display: none;
}

.auth-split__main {
  display: flex;
  flex: 1;
  justify-content: center;
  padding: var(--space-4);
}

.auth-split__column {
  width: 100%;
  max-width: 400px;
}

.auth-split__column--largo {
  max-width: 640px;
}

@media (min-width: 768px) {
  .auth-split__main {
    align-items: center;
    padding: var(--space-12) var(--space-6);
  }
}

@media (min-width: 1024px) {
  .auth-split {
    display: grid;
    grid-template-columns: 1fr 1fr;
  }

  .auth-split__header {
    display: none;
  }

  .auth-split__panel {
    position: sticky;
    top: 0;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    height: 100vh;
    padding: var(--space-12);
    /* O sistema é orientado a borda, não a sombra — a profundidade aqui vem do
       próprio par de verdes da marca, sem hexadecimal novo. */
    background:
      radial-gradient(120% 80% at 15% 0%, rgba(255, 255, 255, .07) 0%, rgba(255, 255, 255, 0) 60%),
      linear-gradient(165deg, var(--brand) 0%, var(--brand-hover) 100%);
    color: #FFFFFF;
  }

  .auth-split__brand {
    align-self: flex-start;
    font-family: var(--font-display);
    font-size: 22px;
    line-height: 28px;
    font-weight: 600;
    color: #FFFFFF;
  }

  .auth-split__brand:hover {
    color: #FFFFFF;
  }

  .auth-split__pitch {
    max-width: 460px;
    padding: var(--space-12) 0;
  }

  .auth-split__title {
    margin: 0;
    font-family: var(--font-display);
    font-size: 36px;
    line-height: 40px;
    font-weight: 600;
    letter-spacing: -.02em;
    text-wrap: pretty;
  }

  .auth-split__subtitle {
    margin: var(--space-4) 0 0;
    font-size: 16px;
    line-height: 24px;
    color: rgba(255, 255, 255, .82);
    text-wrap: pretty;
  }

  .auth-split__list {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    margin: var(--space-8) 0 0;
    padding: 0;
    list-style: none;
  }

  .auth-split__item {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    font-size: 16px;
    line-height: 24px;
    color: rgba(255, 255, 255, .92);
  }

  .auth-split__check {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    border-radius: var(--radius-pill);
    background: rgba(255, 255, 255, .14);
  }

  .auth-split__footnote {
    margin: 0;
    font-size: 14px;
    line-height: 20px;
    color: rgba(255, 255, 255, .72);
  }

  .auth-split__main {
    padding: var(--space-12);
  }
}

@media (min-width: 1440px) {
  .auth-split__panel {
    padding: var(--space-16);
  }
}
</style>
