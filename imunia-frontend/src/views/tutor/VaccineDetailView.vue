<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ChevronLeft, TriangleAlert } from '@lucide/vue'
import TutorShell from '@/components/tutor/TutorShell.vue'
import BatchSeal from '@/components/tutor/BatchSeal.vue'
import ImmutableNotice from '@/components/base/ImmutableNotice.vue'
import { apiGet } from '@/lib/api.js'

/**
 * T06 — detalhe da vacinação (RF25, RF30). Exibe integralmente uma aplicação
 * já registrada, com toda a rastreabilidade exigida pela norma. Leitura pura:
 * nenhuma edição em nenhuma hipótese (RN26) — o `ImmutableNotice` declara a
 * regra, não a impõe, porque a tela simplesmente não tem ação de escrita.
 */
const route = useRoute()

const detalhe = ref(null)
const carregando = ref(true)
const erro = ref('')

async function carregar() {
  carregando.value = true
  erro.value = ''

  try {
    detalhe.value = await apiGet(`/api/animais/${route.params.codigo}/vacinas/${route.params.id}`)
  } catch (excecao) {
    erro.value = excecao.message
  } finally {
    carregando.value = false
  }
}

onMounted(carregar)
watch(() => [route.params.codigo, route.params.id], carregar)

const animal = computed(() => detalhe.value?.animal ?? null)
const imunobiologico = computed(() => detalhe.value?.imunobiologico ?? null)
const aplicacao = computed(() => detalhe.value?.aplicacao ?? null)
const proximaDose = computed(() => detalhe.value?.proxima_dose ?? null)
const pregresso = computed(() => aplicacao.value?.origem === 'pregresso')

function emNumeros(iso) {
  if (!iso) return null
  return new Intl.DateTimeFormat('pt-BR').format(new Date(`${iso}T00:00:00`))
}

// Lista de definições em duas colunas (§8.6 do briefing): rótulo fixo,
// valor honesto — "não informado" no pregresso, nunca um campo em branco.
const definicoes = computed(() => {
  if (!aplicacao.value) return []

  return [
    { rotulo: 'Imunobiológico', valor: imunobiologico.value?.nome },
    { rotulo: 'Ordem da dose', valor: aplicacao.value.rotulo },
    { rotulo: 'Data da aplicação', valor: emNumeros(aplicacao.value.data), aproximada: aplicacao.value.data_aproximada },
    { rotulo: 'Hora', valor: aplicacao.value.hora, mono: true, apenasProfissional: true },
    { rotulo: 'Fabricante', valor: aplicacao.value.fabricante },
    { rotulo: 'Lote', valor: aplicacao.value.lote, mono: true },
    // Mês e ano, como está impresso no frasco e como o `BatchSeal` já exibe.
    // O servidor guarda uma data completa — o último dia do mês —, mas o dia
    // nunca foi digitado por ninguém: mostrá-lo ao tutor daria a um arredondamento
    // a aparência de um dado lido.
    { rotulo: 'Validade', valor: emNumeros(aplicacao.value.validade)?.slice(3), mono: true },
    { rotulo: 'Via de administração', valor: aplicacao.value.via_administracao },
    // Só o pregresso tem onde ter sido aplicada em texto livre (RF29): na
    // origem profissional o lugar é o prestador, e ele já consta da
    // procedência. A hora faz o caminho inverso — só a aplicação profissional
    // carimba hora exata (RN25).
    { rotulo: 'Onde foi aplicada', valor: aplicacao.value.local_aplicacao, apenasPregresso: true },
    { rotulo: 'Versão do protocolo', valor: aplicacao.value.protocolo_versao, mono: true },
  ].filter((item) => {
    if (item.apenasPregresso) return pregresso.value
    if (item.apenasProfissional) return !pregresso.value

    return true
  })
})
</script>

<template>
  <!--
    `amplo` mais a coluna centrada abaixo, como em T01. Aqui a largura de
    leitura é a de 640 px do desenho, e não a de 880: o que muda é só o
    alinhamento, que passa a ser o centro da área de conteúdo.
  -->
  <TutorShell amplo>
    <div class="detalhe">
      <RouterLink :to="`/animais/${route.params.codigo}/carteira`" class="detalhe__voltar">
        <ChevronLeft :size="20" :stroke-width="1.75" />
        Voltar para a carteira
      </RouterLink>

      <!-- Carregando: esqueleto na forma exata do conteúdo que substitui. -->
      <div v-if="carregando" class="detalhe__conteudo" aria-busy="true" aria-live="polite">
        <span class="visually-hidden">Carregando o detalhe da vacinação.</span>
        <div class="esqueleto esqueleto--selo" />
        <div class="esqueleto esqueleto--bloco" />
      </div>

      <div v-else-if="erro" class="aviso aviso--erro" role="alert">
        <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
        <div>
          <p class="aviso__titulo">Não conseguimos carregar este registro.</p>
          <p class="aviso__texto">{{ erro }}</p>
          <button type="button" class="botao botao--secundario" @click="carregar">
            Tentar novamente
          </button>
        </div>
      </div>

      <div v-else class="detalhe__conteudo">
        <div>
          <p class="detalhe__sobrelinha">Detalhe da vacinação · {{ animal.nome }}</p>
          <h1 class="detalhe__titulo">{{ imunobiologico.nome }}</h1>
        </div>

        <BatchSeal :aplicacao="aplicacao" class="detalhe__selo" />

        <section class="cartao">
          <h2 class="cartao__rotulo">Dados completos</h2>
          <dl class="lista-definicoes">
            <div v-for="item in definicoes" :key="item.rotulo" class="lista-definicoes__item">
              <dt class="lista-definicoes__rotulo">{{ item.rotulo }}</dt>
              <dd
                class="lista-definicoes__valor"
                :class="{ 'lista-definicoes__valor--mono': item.mono, 'lista-definicoes__valor--ausente': !item.valor }"
              >
                {{ item.valor ?? 'não informado' }}<span v-if="item.valor && item.aproximada"> (aproximada)</span>
              </dd>
            </div>
          </dl>
        </section>

        <section v-if="proximaDose" class="cartao">
          <h2 class="cartao__rotulo">Próxima dose</h2>
          <p class="proxima-dose__data">previsto para {{ emNumeros(proximaDose.prevista_para) }}</p>
          <p class="proxima-dose__regra">{{ proximaDose.regra_texto }}</p>
        </section>

        <section v-else-if="pregresso" class="cartao cartao--info">
          <p class="cartao__texto">
            Sem data exata registrada para esta aplicação — não é possível calcular a próxima dose a partir dela.
          </p>
        </section>

        <ImmutableNotice />
      </div>
    </div>
  </TutorShell>
</template>

<style scoped>
.detalhe {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  width: 100%;
  max-width: 640px;
  margin: 0 auto;
}

.detalhe__voltar {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  color: var(--ink-muted);
  font-size: 14px;
  font-weight: 600;
}

.detalhe__voltar:hover {
  color: var(--ink);
}

.detalhe__conteudo {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.detalhe__sobrelinha {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--brand-bright);
}

.detalhe__titulo {
  margin: var(--space-1) 0 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  color: var(--ink);
}

.cartao {
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

.cartao--info {
  background: var(--surface-sunken);
}

.cartao__rotulo {
  margin: 0 0 var(--space-3);
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

.cartao__texto {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

/* Lista de definições ------------------------------------------------- */

.lista-definicoes {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--space-4);
  margin: 0;
}

.lista-definicoes__item {
  padding-top: var(--space-3);
  border-top: 1px solid var(--border-hairline);
}

.lista-definicoes__item:first-child {
  padding-top: 0;
  border-top: none;
}

.lista-definicoes__rotulo {
  margin: 0 0 var(--space-1);
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .04em;
  text-transform: uppercase;
  color: var(--ink-faint);
}

.lista-definicoes__valor {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);
}

.lista-definicoes__valor--mono {
  font-family: var(--font-mono);
  font-variant-numeric: tabular-nums;
}

.lista-definicoes__valor--ausente {
  color: var(--ink-faint);
}

/* Próxima dose ---------------------------------------------------------- */

.proxima-dose__data {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
  font-variant-numeric: tabular-nums;
}

.proxima-dose__regra {
  margin: var(--space-1) 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

/* Avisos e esqueleto — mesmo vocabulário das demais telas do tutor. ------ */

.botao {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-2);
  height: 48px;
  padding: 0 var(--space-6);
  border-radius: var(--radius-sm);
  font-size: 16px;
  font-weight: 600;
  cursor: pointer;
  width: auto;
}

.botao--secundario {
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  color: var(--ink);
}

.botao--secundario:hover {
  background: var(--surface-sunken);
}

.aviso {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  padding: var(--space-4);
  border-radius: var(--radius-md);
}

.aviso__icone {
  flex: none;
  margin-top: 2px;
  color: var(--status-late);
}

.aviso--erro {
  background: var(--status-late-wash);
  border: 1px solid var(--status-late);
}

.aviso__titulo {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.aviso__texto {
  margin: var(--space-1) 0 0 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
}

.aviso--erro .botao {
  margin: var(--space-4) 0 0;
}

.esqueleto {
  border-radius: var(--radius-xs);
  background: var(--surface-sunken);
  animation: pulsar 1.2s ease-in-out infinite;
}

.esqueleto--selo {
  height: 220px;
  border-radius: var(--radius-md);
}

.esqueleto--bloco {
  height: 160px;
  border-radius: var(--radius-md);
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

@media (min-width: 768px) {
  .lista-definicoes {
    grid-template-columns: 1fr 1fr;
    gap: var(--space-4) var(--space-6);
  }
}
</style>
