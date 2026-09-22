<script setup>
import { computed, onMounted, ref } from 'vue'
import { CircleCheck, KeyRound, TriangleAlert } from '@lucide/vue'
import TutorShell from '@/components/tutor/TutorShell.vue'
import AccessRequestCard from '@/components/tutor/AccessRequestCard.vue'
import EmptyState from '@/components/base/EmptyState.vue'
import { apiGet, apiPost } from '@/lib/api.js'
import { useSolicitacoesStore } from '@/stores/solicitacoes.js'

/**
 * T13 — solicitações de acesso (RF38).
 *
 * Lista simples, e não agrupamento por animal como T12: lá o tutor administra o
 * que já concedeu e pensa por animal ("quem vê o Théo?"); aqui ele responde a
 * pedidos, e pedido chega um a um, com data. A ordem cronológica é a ordem da
 * resposta.
 *
 * Das duas respostas possíveis, esta tela executa apenas a negativa. "Autorizar"
 * é uma ligação para T11 com o prestador e o animal já resolvidos — a concessão
 * continua saindo de um caminho só, com o código enviado ao e-mail do tutor
 * (RF37), e o percurso permanece dentro dos quatro passos de RNF14.
 */
const solicitacoesPendentes = useSolicitacoesStore()

const dados = ref(null)
const carregando = ref(true)
const erro = ref('')

const recusando = ref(null)
const feito = ref('')
const erroDaAcao = ref('')

async function carregar() {
  carregando.value = true
  erro.value = ''

  try {
    dados.value = await apiGet('/api/solicitacoes')

    // A tela acabou de contar; a moldura não precisa contar de novo.
    solicitacoesPendentes.registrar(dados.value.pendentes)
  } catch (excecao) {
    erro.value = excecao.message
  } finally {
    carregando.value = false
  }
}

onMounted(carregar)

const solicitacoes = computed(() => dados.value?.solicitacoes ?? [])
const pendentes = computed(() => dados.value?.pendentes ?? 0)
const semPedidoAlgum = computed(() => dados.value !== null && solicitacoes.value.length === 0)

/**
 * RF38 — recusar não pede justificativa, não pede código e não pede confirmação
 * em diálogo. Negar acesso não pode custar mais do que concedê-lo, e a recusa
 * não retira do prestador nada que ele já tivesse: o pedido pendente nunca deu
 * acesso a coisa alguma.
 */
async function recusar(solicitacao) {
  recusando.value = solicitacao.id
  erroDaAcao.value = ''
  feito.value = ''

  try {
    const resposta = await apiPost(`/api/solicitacoes/${solicitacao.id}/recusar`)
    feito.value = resposta.message
    await carregar()
  } catch (excecao) {
    erroDaAcao.value = excecao.message
  } finally {
    recusando.value = null
  }
}
</script>

<template>
  <!--
    `amplo` mais a coluna centrada abaixo, como em T01: o limite de 880 px da
    moldura, alinhado à esquerda, deixaria o conteúdo fora do centro da área de
    leitura em telas largas.
  -->
  <TutorShell amplo>
    <div class="pedidos">
      <div class="pedidos__cabecalho">
        <p class="pedidos__sobrelinha">Pedidos de acesso</p>
        <h1 class="pedidos__titulo">
          Quem pediu para ver
          <!-- O contador acompanha o título porque é dele que a moldura tira o
               número da aba: um e outro precisam dizer a mesma coisa. -->
          <span v-if="pendentes > 0" class="pedidos__contador">
            {{ pendentes }}
            <span class="visually-hidden">
              {{ pendentes === 1 ? 'pedido aguardando resposta' : 'pedidos aguardando resposta' }}
            </span>
          </span>
        </h1>
      </div>

      <p v-if="feito" class="aviso aviso--feito" role="status">
        <CircleCheck :size="20" :stroke-width="1.75" class="aviso__icone" />
        {{ feito }}
      </p>

      <div v-if="erroDaAcao" class="aviso aviso--erro" role="alert">
        <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
        <div>
          <p class="aviso__titulo">Não conseguimos recusar o pedido.</p>
          <p class="aviso__texto">{{ erroDaAcao }}</p>
        </div>
      </div>

      <!-- Carregando: esqueleto na forma dos cartões que substitui. -->
      <div v-if="carregando" class="pedidos__lista" aria-busy="true" aria-live="polite">
        <span class="visually-hidden">Carregando os pedidos de acesso.</span>
        <div v-for="linha in 2" :key="linha" class="esqueleto" />
      </div>

      <div v-else-if="erro" class="aviso aviso--erro" role="alert">
        <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
        <div>
          <p class="aviso__titulo">Não conseguimos carregar os pedidos de acesso.</p>
          <p class="aviso__texto">{{ erro }}</p>
          <button type="button" class="botao botao--secundario" @click="carregar">
            Tentar novamente
          </button>
        </div>
      </div>

      <!-- Vazio, e positivo: nenhum pedido não é falta de nada. A tela explica
           o que apareceria aqui e oferece o caminho de quem prefere autorizar
           antes de ser perguntado. -->
      <EmptyState
        v-else-if="semPedidoAlgum"
        :icone="KeyRound"
        titulo="Nenhum pedido no momento"
        descricao="Quando uma clínica quiser ver o histórico de um animal seu, o pedido aparece aqui e você decide. Você também pode autorizar antes, pelo diretório."
        class="pedidos__vazio"
      >
        <RouterLink to="/prestadores" class="botao botao--secundario">
          Autorizar uma clínica
        </RouterLink>
      </EmptyState>

      <div v-else class="pedidos__lista">
        <AccessRequestCard
          v-for="solicitacao in solicitacoes"
          :key="solicitacao.id"
          :solicitacao="solicitacao"
          :class="{ 'pedidos__ocupado': recusando === solicitacao.id }"
          @recusar="recusar"
        />
      </div>
    </div>
  </TutorShell>
</template>

<style scoped>
.pedidos {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  width: 100%;
  max-width: 880px;
  margin: 0 auto;
}

.pedidos__sobrelinha {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--consent);
}

.pedidos__titulo {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  margin: var(--space-1) 0 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  color: var(--ink);
}

.pedidos__contador {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 24px;
  height: 24px;
  padding: 0 var(--space-2);
  border-radius: var(--radius-pill);
  background: var(--consent);
  color: var(--surface-card);
  font-family: var(--font-body);
  font-size: 12px;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.pedidos__lista {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

/* Enquanto a recusa está em curso o cartão não aceita segundo toque: a rota é
   idempotente, mas dois pedidos em voo deixariam a tela contando duas vezes. */
.pedidos__ocupado {
  pointer-events: none;
  opacity: .6;
}

.pedidos__vazio {
  border-color: var(--consent);
}

.pedidos__vazio :deep(.empty-state__icone) {
  color: var(--consent);
}

/* Avisos e botões — mesmo vocabulário de T12. ------------------------------ */

.aviso {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  margin: 0;
  padding: var(--space-4);
  border-radius: var(--radius-md);
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);
}

.aviso__icone {
  flex: none;
  margin-top: 2px;
}

.aviso--feito {
  background: var(--brand-wash);
  border: 1px solid var(--brand);
}

.aviso--feito .aviso__icone {
  color: var(--brand);
}

.aviso--erro {
  background: var(--status-late-wash);
  border: 1px solid var(--status-late);
}

.aviso--erro .aviso__icone {
  color: var(--status-late);
}

.aviso__titulo {
  margin: 0;
  font-weight: 600;
}

.aviso__texto {
  margin: var(--space-1) 0 0;
  color: var(--ink-muted);
}

.aviso--erro .botao {
  margin: var(--space-4) 0 0;
}

.botao {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-2);
  height: 48px;
  padding: 0 var(--space-6);
  border: 1px solid transparent;
  border-radius: var(--radius-sm);
  font-family: var(--font-body);
  font-size: 16px;
  font-weight: 600;
  cursor: pointer;
}

.botao--secundario {
  background: var(--surface-card);
  border-color: var(--border-strong);
  color: var(--ink);
}

.botao--secundario:hover {
  background: var(--surface-sunken);
}

/* Esqueleto de carregamento ------------------------------------------------ */

.esqueleto {
  height: 260px;
  border-radius: var(--radius-md);
  background: var(--surface-sunken);
  animation: pulsar 1.2s ease-in-out infinite;
}

@keyframes pulsar {
  0% { opacity: .55; }
  50% { opacity: 1; }
  100% { opacity: .55; }
}

.visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip-path: inset(50%);
  white-space: nowrap;
}

/* Larguras derivadas ------------------------------------------------------- */

@media (min-width: 1024px) {
  .pedidos__titulo {
    font-size: 36px;
    line-height: 40px;
    letter-spacing: -.02em;
  }
}
</style>
