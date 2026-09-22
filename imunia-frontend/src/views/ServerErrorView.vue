<script setup>
import { computed } from 'vue'
import { TriangleAlert } from '@lucide/vue'
import ExceptionState from '@/components/base/ExceptionState.vue'
import RoleShell from '@/components/base/RoleShell.vue'
import { painelDe } from '@/lib/areas.js'
import { useSessaoStore } from '@/stores/sessao.js'

/**
 * E03 — falha do sistema. A tela de quando a culpa é nossa.
 *
 * Diz o que aconteceu no mundo ("não conseguimos carregar esta página") e o que
 * fazer em seguida (tentar de novo), nunca o que quebrou por dentro: nem classe
 * de exceção, nem código de estado, nem pilha de chamadas (RNF17, §6.1).
 *
 * O identificador de ocorrência não é código de erro. É o número de protocolo
 * da requisição que falhou, o mesmo que o servidor escreveu em cada linha do seu
 * registro, e existe para uma conversa com o suporte — por isso vem em
 * monoespaçada, em blocos de quatro, legível ao telefone.
 */
const props = defineProps({
  /**
   * Vem do corpo da resposta do servidor. Quando a falha é do próprio
   * aplicativo — um módulo que não carregou, um erro de tela —, não há
   * requisição registrada a que se referir, e o bloco simplesmente não aparece:
   * um número que não existe em registro algum mandaria a pessoa a uma conversa
   * sem começo.
   */
  ocorrencia: { type: String, default: '' },
})

const sessao = useSessaoStore()

const painel = computed(() => painelDe(sessao.usuario))
const rotuloDoPainel = computed(() => (sessao.autenticado ? 'Voltar ao painel' : 'Ir para a entrada'))

/**
 * Recarrega o aplicativo inteiro, e não apenas a tela: a falha pode ter sido no
 * carregamento do próprio SPA, e nesse caso remontar o componente repetiria o
 * mesmo erro sem nunca ir buscar o módulo que faltou.
 */
function tentarDeNovo() {
  window.location.reload()
}
</script>

<template>
  <RoleShell titulo="Falha ao carregar">
    <ExceptionState
      :icone="TriangleAlert"
      tom="falha"
      titulo="Não conseguimos carregar esta página. Tente novamente em instantes."
    >
      <button type="button" class="acao" @click="tentarDeNovo">Tentar de novo</button>
      <RouterLink v-if="painel" :to="painel" class="acao acao--secundaria">{{ rotuloDoPainel }}</RouterLink>

      <template v-if="ocorrencia" #rodape>
        Ocorrência <span class="ocorrencia">{{ ocorrencia }}</span> · informe este identificador ao suporte
      </template>
    </ExceptionState>
  </RoleShell>
</template>

<style scoped>
.ocorrencia {
  font-family: var(--font-mono);
  font-size: 12px;
  font-weight: 500;
  color: var(--ink-muted);
}
</style>
