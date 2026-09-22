<script setup>
import { ClockAlert } from '@lucide/vue'
import { useReenvioDeConfirmacao } from '@/lib/confirmacaoDeEmail.js'

/**
 * RF05b — tarja persistente de endereço não confirmado. Persistente porque o
 * lembrete é o que traz Helena de volta ao sistema: sem endereço confirmado,
 * as doses vencem em silêncio, e esse é justamente o problema que o Imunia se
 * propõe a resolver (P2). Some sozinha quando a confirmação acontece.
 */
const props = defineProps({
  email: { type: String, required: true },
})

// O pedido e a pausa entre tentativas são os mesmos de T18 (RF05c).
const {
  reenviando,
  aviso,
  erro,
  emPausa,
  tempoRestante,
  reenviar,
} = useReenvioDeConfirmacao(() => props.email)
</script>

<template>
  <div class="verificacao" role="status">
    <ClockAlert :size="20" :stroke-width="1.75" class="verificacao__icone" />

    <div class="verificacao__corpo">
      <p class="verificacao__titulo">Confirme seu e-mail para receber lembretes das próximas doses.</p>
      <p class="verificacao__detalhe">Enviamos o link para {{ email }}.</p>

      <p v-if="aviso" class="verificacao__detalhe" aria-live="polite">{{ aviso }}</p>
      <p v-if="erro" class="verificacao__erro" aria-live="polite">{{ erro }}</p>

      <button
        type="button"
        class="verificacao__acao"
        :disabled="reenviando || emPausa"
        @click="reenviar"
      >
        <template v-if="emPausa">Reenviar em {{ tempoRestante }}</template>
        <template v-else-if="reenviando">Reenviando…</template>
        <template v-else>Reenviar o e-mail de confirmação</template>
      </button>
    </div>
  </div>
</template>

<style scoped>
.verificacao {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
  background: var(--surface-card);
  border-top: 1px solid var(--status-due);
  border-bottom: 1px solid var(--status-due);
}

.verificacao__icone {
  flex: none;
  margin-top: 2px;
  color: var(--status-due-text);
}

.verificacao__corpo {
  flex: 1;
  min-width: 0;
}

.verificacao__titulo {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);
}

.verificacao__detalhe {
  margin: var(--space-1) 0 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
  overflow-wrap: anywhere;
}

.verificacao__erro {
  margin: var(--space-1) 0 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--status-late);
}

.verificacao__acao {
  width: 100%;
  height: 48px;
  margin: var(--space-3) 0 0;
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  font-size: 16px;
  font-weight: 600;
  color: var(--ink);
  cursor: pointer;
}

.verificacao__acao:hover:not(:disabled) {
  background: var(--surface-sunken);
}

.verificacao__acao:disabled {
  opacity: .45;
  cursor: not-allowed;
}

@media (min-width: 768px) {
  .verificacao {
    padding: var(--space-3) var(--space-6);
  }

  .verificacao__acao {
    width: auto;
    padding: 0 var(--space-6);
  }
}

@media (min-width: 1024px) {
  .verificacao {
    padding: var(--space-3) var(--space-8);
  }
}
</style>
