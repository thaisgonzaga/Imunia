<script setup>
import { computed } from 'vue'
import { Eye, ShieldAlert } from '@lucide/vue'
import { emHoras, emNumeros } from '@/lib/datas.js'

/**
 * `AccessLogRow` (T14, RF53) — uma linha do livro de acessos.
 *
 * Cartão no celular e linha de tabela a partir de `md`, como o desenho pede:
 * a mesma informação, em duas formas. A hora vem em monoespaçada e com
 * `tabular-nums` porque a coluna se lê verticalmente, comparando um horário
 * com o de cima — e é a fonte proporcional que faz essa leitura falhar.
 *
 * A distinção entre acesso sob autorização e acesso sem nenhuma é o conteúdo
 * mais importante da linha (RF52c). Ela não fica só na cor: o texto diz, e o
 * ícone muda de desenho, porque cor sozinha não é portadora de significado
 * (§4.1 dos tokens).
 */
const props = defineProps({
  acesso: { type: Object, required: true },
})

defineEmits(['revogar'])

const semAutorizacao = computed(() => props.acesso.autorizacao === null)

/**
 * RF53 pede "sob qual autorização", e não "se havia alguma": a autorização é
 * nomeada pela data em que o tutor a concedeu, que é o ato de vontade dele e o
 * único identificador que significa alguma coisa para quem lê.
 *
 * O que veio depois entra junto quando muda a leitura do fato: acesso sob
 * autorização que o tutor revogou em seguida foi legítimo quando aconteceu, e
 * confundi-lo com invasão seria erro grave na direção contrária.
 */
const procedencia = computed(() => {
  if (semAutorizacao.value) {
    return 'Sem autorização sua — a consulta ficou registrada'
  }

  const desde = emNumeros(props.acesso.autorizacao.concedida_em)

  return {
    revogada: `Autorização de ${desde}, revogada por você depois`,
    expirada: `Autorização de ${desde}, encerrada depois`,
  }[props.acesso.autorizacao.situacao] ?? `Autorização de ${desde}`
})

/**
 * O nome do que se revoga. Quem navega por leitor de tela ouve o botão fora do
 * contexto da linha, e "Revogar acesso" sozinho, numa tela com vários acessos
 * da mesma semana, não diz qual clínica perde o quê.
 */
const alcance = computed(() => {
  const animal = props.acesso.animal

  return animal === null
    ? `de ${props.acesso.prestador.nome}`
    : `de ${props.acesso.prestador.nome} ao histórico de ${animal.nome}`
})
</script>

<template>
  <div class="linha" :class="{ 'linha--sem-autorizacao': semAutorizacao }">
    <span class="linha__hora">{{ emHoras(acesso.hora) }}</span>

    <span class="linha__prestador">{{ acesso.prestador.nome }}</span>

    <span class="linha__profissional">
      {{ acesso.profissional.nome }}
      <template v-if="acesso.profissional.crmv">
        · <span class="linha__crmv">{{ acesso.profissional.crmv }}</span>
      </template>
    </span>

    <span class="linha__natureza">
      <component
        :is="semAutorizacao ? ShieldAlert : Eye"
        :size="20"
        :stroke-width="1.75"
        class="linha__icone"
      />
      <!-- A frase inteira no cartão; a forma curta na célula da tabela, onde a
           vizinhança (prestador, profissional, hora) já dá o contexto que a
           frase repetiria em cinco linhas de duas palavras. -->
      <span class="linha__descricao">{{ acesso.descricao }}</span>
      <span class="linha__resumo">{{ acesso.resumo }}</span>
    </span>

    <!-- Filha direta da grade, e não aninhada na natureza, porque em tabela ela
         muda de coluna: a autorização é do prestador, e é debaixo do nome dele
         que ela se lê — aproveitando a linha que o nome deixa livre em vez de
         empilhar uma quarta linha na célula mais estreita. -->
    <span class="linha__procedencia">{{ procedencia }}</span>

    <span class="linha__acao">
      <button
        v-if="acesso.revogavel"
        type="button"
        class="linha__revogar"
        @click="$emit('revogar', acesso)"
      >
        <span aria-hidden="true">Revogar acesso desta clínica</span>
        <span class="visually-hidden">Revogar acesso {{ alcance }}</span>
      </button>
    </span>
  </div>
</template>

<style scoped>
/* Celular: cartão. As cinco partes empilham na ordem em que se leem. */
.linha {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 0 var(--space-3);
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

.linha--sem-autorizacao {
  border-color: var(--status-late);
}

.linha__hora {
  grid-column: 1;
  align-self: baseline;
  font-family: var(--font-mono);
  font-size: 13px;
  line-height: 18px;
  font-weight: 500;
  color: var(--ink);
  font-variant-numeric: tabular-nums;
}

.linha__prestador {
  grid-column: 2;
  min-width: 0;
  font-size: 18px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.linha__profissional {
  grid-column: 2;
  margin: var(--space-1) 0 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
}

.linha__crmv {
  font-family: var(--font-mono);
  font-size: 14px;
  font-weight: 500;
}

.linha__natureza {
  grid-column: 1 / -1;
  display: flex;
  align-items: flex-start;
  gap: var(--space-2);
  margin: var(--space-2) 0 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);
}

.linha__icone {
  flex: none;
  margin-top: 2px;
  color: var(--consent);
}

.linha--sem-autorizacao .linha__icone {
  color: var(--status-late);
}

.linha__resumo {
  display: none;
}

.linha__procedencia {
  grid-column: 1 / -1;
  margin: var(--space-1) 0 0;
  padding: 0 0 0 28px;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.linha--sem-autorizacao .linha__procedencia {
  color: var(--status-late);
  font-weight: 600;
}

.linha__acao {
  grid-column: 1 / -1;
}

.linha__revogar {
  display: block;
  width: 100%;
  height: 48px;
  margin: var(--space-3) 0 0;
  background: var(--surface-card);
  border: 1px solid var(--status-late);
  border-radius: var(--radius-sm);
  font-family: var(--font-body);
  font-size: 16px;
  font-weight: 600;
  color: var(--status-late);
  cursor: pointer;
}

.linha__revogar:hover {
  background: var(--status-late-wash);
}

.visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip-path: inset(50%);
  white-space: nowrap;
}

/* A partir de md a linha vira linha de tabela, nas colunas do desenho de
   1440 px. O cartão volta abaixo disso, onde 80 px de hora ao lado de três
   textos deixaria cada um com pouco mais de uma palavra por linha. */
@media (min-width: 768px) {
  .linha {
    grid-template-columns: 72px 1.1fr 1.2fr 1.3fr 140px;
    grid-template-rows: auto auto;
    align-items: center;
    gap: 0 var(--space-4);
    min-height: 48px;
    padding: var(--space-2) var(--space-4);
    border: none;
    border-top: 1px solid var(--border-hairline);
    border-radius: 0;
    font-size: 14px;
    line-height: 20px;
  }

  .linha--sem-autorizacao {
    border-color: var(--border-hairline);
    background: var(--status-late-wash);
  }

  /* Duas fileiras por registro: a segunda existe para a autorização, e as
     colunas que não têm segunda linha atravessam as duas, centradas. */
  .linha__hora {
    grid-area: 1 / 1 / 3 / 2;
    align-self: center;
  }

  .linha__prestador {
    grid-area: 1 / 2 / 2 / 3;
    align-self: end;
    font-size: 14px;
    line-height: 20px;
    font-weight: 600;
  }

  .linha__procedencia {
    grid-area: 2 / 2 / 3 / 3;
    align-self: start;
    margin: 0;
    padding: 0;
    font-size: 13px;
    line-height: 18px;
  }

  .linha__profissional {
    grid-area: 1 / 3 / 3 / 4;
    margin: 0;
    font-size: 14px;
    line-height: 20px;
  }

  .linha__natureza {
    grid-area: 1 / 4 / 3 / 5;
    margin: 0;
  }

  .linha__acao {
    grid-area: 1 / 5 / 3 / 6;
    margin: 0;
  }

  .linha__crmv {
    font-size: 13px;
  }

  .linha__natureza {
    align-items: flex-start;
    font-size: 14px;
    line-height: 20px;
  }

  .linha__icone {
    width: 16px;
    height: 16px;
    margin-top: 2px;
  }

  .linha__descricao {
    display: none;
  }

  .linha__resumo {
    display: inline;
  }

  .linha__procedencia {
    font-size: 13px;
    line-height: 18px;
  }

  .linha__acao {
    text-align: right;
  }

  /* Já em tabela, mas ainda em tela que se toca: o alvo continua com os 44 px
     mínimos de §4.3 do briefing, e é a linha que cresce para acomodá-lo — não
     o alvo que encolhe para caber na linha. */
  .linha__revogar {
    width: auto;
    height: auto;
    min-height: 44px;
    margin: 0;
    padding: 0 var(--space-3);
    font-size: 14px;
  }
}

/* No desenho de 1440 px a revogação é ligação de texto: a partir daqui há
   ponteiro, o alvo mínimo deixa de reger, e a linha volta aos 48 px. */
@media (min-width: 1024px) {
  .linha__revogar {
    display: inline;
    min-height: 0;
    padding: 0;
    border: none;
    background: none;
    text-decoration: underline;
  }

  .linha__revogar:hover {
    background: none;
    color: #8C2A23;
  }
}
</style>
