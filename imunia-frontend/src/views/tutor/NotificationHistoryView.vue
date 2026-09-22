<script setup>
import { computed, onMounted, ref } from 'vue'
import { Dog, Mail, TriangleAlert } from '@lucide/vue'
import TutorShell from '@/components/tutor/TutorShell.vue'
import EmailVerificationBanner from '@/components/tutor/EmailVerificationBanner.vue'
import NotificationDetailSheet from '@/components/tutor/NotificationDetailSheet.vue'
import EmptyState from '@/components/base/EmptyState.vue'
import { apiGet } from '@/lib/api.js'
import { emHoras, emNumeros } from '@/lib/datas.js'
import { iconeDaSituacao } from '@/lib/notificacoes.js'

/**
 * T17 — histórico de notificações (RF45), o destino do sino do cabeçalho.
 *
 * Responde à pergunta que o arquivo de papel não respondia — já fui avisada, e
 * quando? — com o que falta para agir sobre a resposta: para qual endereço a
 * mensagem foi e se ela chegou. Somente leitura, como o briefing manda: o
 * registro é a fonte de verdade da idempotência de RN43.
 *
 * Tabela a partir de `md` e cartões abaixo, com a mesma marcação: a conversão é
 * de CSS, como na relação de registros da clínica, para que a leitura por
 * leitor de tela seja a de uma tabela nas duas larguras.
 */
const dados = ref(null)
const carregando = ref(true)
const erro = ref('')

const aberta = ref(null)

async function carregar() {
  carregando.value = true
  erro.value = ''

  try {
    dados.value = await apiGet('/api/conta/notificacoes/enviadas')
  } catch (excecao) {
    erro.value = excecao.message
  } finally {
    carregando.value = false
  }
}

onMounted(carregar)

const notificacoes = computed(() => dados.value?.notificacoes ?? [])
const total = computed(() => dados.value?.total ?? 0)
const vazio = computed(() => dados.value !== null && total.value === 0)

const contagem = computed(() => (total.value === 1 ? '1 notificação' : `${total.value} notificações`))

/**
 * O vazio muda de explicação quando o endereço não foi confirmado: ali a
 * ausência não é "ainda não chegou a hora", é "nada vai sair até você
 * confirmar" (RN42) — e dizer a primeira coisa a quem está na segunda a
 * deixaria esperando por um lembrete que não vem.
 */
const descricaoDoVazio = computed(() => (dados.value?.conta.email_verificado === false
  ? 'Enquanto o seu e-mail não for confirmado, o Imunia não envia lembretes. Depois da confirmação, cada lembrete de vacina aparece aqui com a data e a situação de entrega.'
  : 'Os lembretes saem por e-mail antes da data prevista de cada dose, no próprio dia e, se a dose atrasar, mais uma vez. Cada um aparece aqui com a data e a situação de entrega.'))
</script>

<template>
  <!-- `amplo` mais a coluna centrada abaixo, como em T14: o limite de 880 px
       da moldura, alinhado à esquerda, deixaria a tabela fora do centro. -->
  <TutorShell amplo>
    <template #aviso>
      <EmailVerificationBanner
        v-if="dados && !dados.conta.email_verificado"
        :email="dados.conta.email"
      />
    </template>

    <div class="notificacoes">
      <div>
        <p class="notificacoes__sobrelinha">Minha conta</p>
        <h1 class="notificacoes__titulo">Histórico de notificações</h1>
        <p class="notificacoes__apoio">
          Cada lembrete de vacina que o Imunia envia fica registrado aqui: quando saiu, para qual
          endereço e se chegou.
        </p>
      </div>

      <div v-if="carregando" class="cartao" aria-busy="true" aria-live="polite">
        <span class="visually-hidden">Carregando o seu histórico de notificações.</span>
        <div v-for="linha in 3" :key="linha" class="esqueleto" />
      </div>

      <div v-else-if="erro" class="aviso aviso--erro" role="alert">
        <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
        <div>
          <p class="aviso__titulo">Não conseguimos carregar o seu histórico de notificações.</p>
          <p class="aviso__texto">{{ erro }}</p>
          <button type="button" class="botao botao--secundario" @click="carregar">
            Tentar novamente
          </button>
        </div>
      </div>

      <EmptyState
        v-else-if="vazio"
        :icone="Mail"
        titulo="Nenhum lembrete enviado ainda"
        :descricao="descricaoDoVazio"
      >
        <RouterLink to="/animais" class="botao botao--secundario">
          <Dog :size="20" :stroke-width="1.75" />
          Ver meus animais
        </RouterLink>
      </EmptyState>

      <template v-else>
        <p class="notificacoes__contagem" aria-live="polite">{{ contagem }}</p>

        <div class="cartao">
          <table class="tabela">
            <caption class="visually-hidden">
              Notificações enviadas, da mais recente para a mais antiga
            </caption>
            <thead>
              <tr>
                <th scope="col" aria-sort="descending">Data e hora ↓</th>
                <th scope="col">Tipo</th>
                <th scope="col">Animal</th>
                <th scope="col">Destinatário</th>
                <th scope="col" class="tabela__situacao">Situação</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="notificacao in notificacoes"
                :key="notificacao.id"
                :class="{ 'tabela__linha--falhou': notificacao.situacao === 'falhou' }"
              >
                <td data-rotulo="Data e hora" class="tabela__numeros">
                  {{ emNumeros(notificacao.data) }}
                  <span class="tabela__hora">{{ emHoras(notificacao.hora) }}</span>
                </td>
                <td data-rotulo="Tipo">
                  <!-- A linha inteira é o alvo; o botão fica na célula que dá
                       nome ao item, e o nome acessível completa o que a célula
                       sozinha não diz. -->
                  <button type="button" class="tabela__abrir" @click="aberta = notificacao">
                    {{ notificacao.tipo_rotulo }}
                    <span class="visually-hidden">
                      sobre {{ notificacao.animal.nome }}, enviada em
                      {{ emNumeros(notificacao.data) }}. Ver detalhes
                    </span>
                  </button>
                </td>
                <td data-rotulo="Animal">{{ notificacao.animal.nome }}</td>
                <td data-rotulo="Destinatário" class="tabela__discreto">
                  {{ notificacao.destinatario }}
                </td>
                <td data-rotulo="Situação" class="tabela__situacao">
                  <span class="situacao" :class="`situacao--${notificacao.situacao}`">
                    <component
                      :is="iconeDaSituacao(notificacao.situacao)"
                      :size="14"
                      :stroke-width="1.75"
                    />
                    {{ notificacao.situacao_rotulo }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </div>

    <NotificationDetailSheet
      :aberto="aberta !== null"
      :notificacao="aberta"
      @fechar="aberta = null"
    />
  </TutorShell>
</template>

<style scoped>
.notificacoes {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  width: 100%;
  max-width: 880px;
  margin: 0 auto;
}

.notificacoes__sobrelinha {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

.notificacoes__titulo {
  margin: var(--space-1) 0 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  color: var(--ink);
}

.notificacoes__apoio {
  margin: var(--space-2) 0 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
}

.notificacoes__contagem {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
  font-variant-numeric: tabular-nums;
}

.cartao {
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
  overflow: hidden;
}

/* Tabela — vira lista de cartões abaixo de 768 px (§5.1). ------------------- */

.tabela {
  width: 100%;
  border-collapse: collapse;
}

.tabela thead {
  display: none;
}

/* No cartão, o tipo encabeça; a data flutua no canto; animal e situação vêm
   na linha de baixo, e o destinatário fecha com o rótulo que o dado sozinho
   não daria. */
.tabela tr {
  position: relative;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-1) var(--space-3);
  padding: var(--space-4);
  border-top: 1px solid var(--border-hairline);
}

.tabela tbody tr:first-child {
  border-top: 0;
}

.tabela tbody tr:hover {
  background: var(--surface-sunken);
}

.tabela td {
  display: block;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);
}

.tabela td[data-rotulo='Tipo'] {
  order: 1;
  flex: 1 1 100%;
  padding-right: 104px;
}

.tabela td[data-rotulo='Data e hora'] {
  order: 2;
  position: absolute;
  top: var(--space-4);
  right: var(--space-4);
  font-size: 14px;
  color: var(--ink-muted);
  text-align: right;
}

.tabela td[data-rotulo='Animal'] {
  order: 3;
}

.tabela td[data-rotulo='Situação'] {
  order: 4;
}

.tabela td[data-rotulo='Destinatário'] {
  order: 5;
  flex: 1 1 100%;
  font-size: 14px;
  line-height: 20px;
  /* Domínio longo quebra dentro da célula, em vez de invadir a vizinha: o
     endereço não tem espaço onde quebrar sozinho. */
  overflow-wrap: anywhere;
}

.tabela td[data-rotulo='Destinatário']::before {
  content: 'Para: ';
}

.tabela__hora {
  display: block;
}

.tabela__abrir {
  padding: 0;
  background: none;
  border: 0;
  font-family: var(--font-body);
  font-size: 16px;
  line-height: 24px;
  font-weight: 600;
  text-align: left;
  color: var(--ink);
  cursor: pointer;
}

/* A linha inteira é o alvo, sem que a semântica da tabela se perca. */
.tabela__abrir::after {
  content: '';
  position: absolute;
  inset: 0;
}

.tabela__abrir:focus-visible {
  outline: none;
}

.tabela__abrir:focus-visible::after {
  outline: 2px solid var(--brand);
  outline-offset: -2px;
}

.tabela td.tabela__discreto {
  color: var(--ink-muted);
}

.tabela__numeros {
  font-variant-numeric: tabular-nums;
}

/* A falha é o estado que pede atenção: a linha ganha o fundo de alerta, e o
   selo continua dizendo em palavras o que a cor apenas reforça. */
.tabela__linha--falhou {
  background: var(--status-late-wash);
}

.tabela tbody .tabela__linha--falhou:hover {
  background: var(--status-late-wash);
}

/* Situação — mesmo selo da folha de detalhe. ------------------------------- */

.situacao {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 22px;
  padding: 0 10px;
  white-space: nowrap;
  border-radius: var(--radius-pill);
  background: var(--surface-card);
  font-size: 12px;
  line-height: 16px;
  font-weight: 600;
}

.situacao--entregue {
  border: 1px solid var(--status-ok);
  color: var(--status-ok);
}

.situacao--sem_confirmacao {
  border: 1px solid var(--border-strong);
  color: var(--ink-muted);
}

.situacao--falhou {
  border: 1px solid var(--status-late);
  color: var(--status-late);
}

/* Avisos e botões — mesmo vocabulário de T14. ------------------------------ */

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
  text-decoration: none;
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
  height: 72px;
  margin: var(--space-3);
  border-radius: var(--radius-sm);
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

@media (min-width: 768px) {
  .tabela thead {
    display: table-header-group;
  }

  .tabela th {
    padding: var(--space-3) 0;
    font-size: 13px;
    line-height: 16px;
    font-weight: 600;
    letter-spacing: .06em;
    text-transform: uppercase;
    text-align: left;
    white-space: nowrap;
    color: var(--ink-muted);
  }

  /* Cabeçalho e linhas repetem a mesma grade — `minmax(0, …fr)` pela lição da
     relação de animais (decisoes.md §9.15): com `fr` seco, cada grade resolve
     a própria largura de coluna e a célula sai de baixo do seu título. */
  .tabela thead tr,
  .tabela tbody tr {
    display: grid;
    grid-template-columns:
      minmax(0, 1.3fr) minmax(0, 1.6fr) minmax(0, .8fr) minmax(0, 1.6fr) 148px;
    gap: var(--space-4);
    padding: 0 20px;
  }

  .tabela thead tr {
    background: var(--surface-sunken);
  }

  .tabela tbody tr {
    align-items: center;
    min-height: 48px;
    padding-top: var(--space-2);
    padding-bottom: var(--space-2);
  }

  /* `order` também vale em grid: sem zerá-lo, a ordem do cartão sobrevive e
     cada célula cai sob o cabeçalho da vizinha (a lição registrada em V02). */
  .tabela td[data-rotulo] {
    order: 0;
    position: static;
    flex: none;
    padding-right: 0;
    font-size: 14px;
    line-height: 20px;
    text-align: left;
  }

  /* Data e hora numa linha quando cabem; quando não cabem (768 px), a hora
     desce inteira e alinhada, sem o recuo que uma margem deixaria. */
  .tabela td[data-rotulo='Data e hora'] {
    display: flex;
    flex-wrap: wrap;
    column-gap: var(--space-2);
    color: var(--ink);
  }

  .tabela td[data-rotulo='Destinatário']::before {
    content: none;
  }

  .tabela__hora {
    color: var(--ink-muted);
  }

  .tabela__abrir {
    font-size: 14px;
    line-height: 20px;
  }

  /* A coluna de situação inteira centrada, título e selo. */
  .tabela th.tabela__situacao,
  .tabela td.tabela__situacao {
    text-align: center;
  }
}

@media (min-width: 1024px) {
  .notificacoes__titulo {
    font-size: 36px;
    line-height: 40px;
    letter-spacing: -.02em;
  }
}
</style>
