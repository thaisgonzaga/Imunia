<script setup>
import { computed } from 'vue'
import { Eye } from '@lucide/vue'
import { emHoras } from '@/lib/datas.js'

/**
 * `AccessLogRow` (T14, RF53) — uma linha do livro de acessos.
 *
 * Cartão no celular e linha de tabela a partir de `md`, como o desenho pede:
 * a mesma informação, em duas formas. A hora vem em monoespaçada e com
 * `tabular-nums` porque a coluna se lê verticalmente, comparando um horário
 * com o de cima — e é a fonte proporcional que faz essa leitura falhar.
 *
 * Sob o nome da clínica, discreto, vai o vínculo: quando ela acompanha o animal
 * consultado, a linha diz. É contexto, não alerta — a clínica que atende o
 * animal passa a acompanhá-lo sozinha, sem nada a conceder nem a revogar.
 */
const props = defineProps({
  acesso: { type: Object, required: true },
})

const vinculo = computed(() => {
  if (!props.acesso.acompanha) return ''

  const animal = props.acesso.animal

  return animal ? `Acompanha ${animal.nome}` : 'Acompanha um animal seu'
})
</script>

<template>
  <div class="linha">
    <span class="linha__hora">{{ emHoras(acesso.hora) }}</span>

    <span class="linha__prestador">{{ acesso.prestador.nome }}</span>

    <span class="linha__profissional">
      {{ acesso.profissional.nome }}
      <template v-if="acesso.profissional.crmv">
        · <span class="linha__crmv">{{ acesso.profissional.crmv }}</span>
      </template>
    </span>

    <span class="linha__natureza">
      <Eye :size="20" :stroke-width="1.75" class="linha__icone" />
      <!-- A frase inteira no cartão; a forma curta na célula da tabela, onde a
           vizinhança (prestador, profissional, hora) já dá o contexto que a
           frase repetiria em cinco linhas de duas palavras. -->
      <span class="linha__descricao">{{ acesso.descricao }}</span>
      <span class="linha__resumo">{{ acesso.resumo }}</span>
    </span>

    <!-- Filha direta da grade, e não aninhada na natureza, porque em tabela ela
         muda de coluna: o vínculo é da clínica, e é debaixo do nome dela que
         ele se lê — aproveitando a linha que o nome deixa livre. -->
    <span v-if="vinculo" class="linha__vinculo">{{ vinculo }}</span>
  </div>
</template>

<style scoped>
/* Celular: cartão. As partes empilham na ordem em que se leem. */
.linha {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 0 var(--space-3);
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
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

.linha__resumo {
  display: none;
}

.linha__vinculo {
  grid-column: 1 / -1;
  margin: var(--space-1) 0 0;
  padding: 0 0 0 28px;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

/* A partir de md a linha vira linha de tabela, nas colunas do desenho de
   1440 px. O cartão volta abaixo disso, onde 80 px de hora ao lado de três
   textos deixaria cada um com pouco mais de uma palavra por linha. */
@media (min-width: 768px) {
  .linha {
    grid-template-columns: 72px 1.1fr 1.2fr 1.3fr;
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

  /* Duas fileiras por registro: a segunda existe para o vínculo, e as colunas
     que não têm segunda linha atravessam as duas, centradas. */
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

  .linha__vinculo {
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
}
</style>
