<script setup>
import { computed } from 'vue'
import { FilePenLine } from '@lucide/vue'
import { emNumeros } from '@/lib/datas.js'

/**
 * A tarja de encadeamento entre um registro clínico e a sua retificação
 * (RF33b). Aparece nos dois sentidos: no registro corrigido, apontando para a
 * correção; na correção, apontando de volta para o original.
 *
 * Fica antes do conteúdo, e não depois, porque quem abre um registro retificado
 * precisa saber disso antes de ler — e não depois de já ter lido um diagnóstico
 * que foi corrigido três dias depois.
 */
const props = defineProps({
  variante: {
    type: String,
    required: true,
    validator: (v) => ['retificado', 'retificacao'].includes(v),
  },
  em: { type: String, required: true },
  destino: { type: String, required: true },

  /**
   * Qual registro clínico é este. Nasceu com o atendimento (T08) e ganhou a
   * aplicação de vacina em V09, quando a retificação passou a valer para as
   * duas espécies (RF33). As frases mudam com a concordância, e não só com a
   * palavra: "este atendimento foi retificado", "esta aplicação foi
   * retificada".
   */
  registro: {
    type: String,
    default: 'atendimento',
    validator: (v) => ['atendimento', 'aplicacao'].includes(v),
  },
})

const texto = computed(() => {
  const quando = emNumeros(props.em)

  if (props.registro === 'aplicacao') {
    return props.variante === 'retificado'
      ? `Esta aplicação foi retificada em ${quando}. As duas versões continuam visíveis.`
      : `Este registro corrige a aplicação de ${quando}, que continua visível como foi confirmada.`
  }

  return props.variante === 'retificado'
    ? `Este atendimento foi retificado em ${quando}. As duas versões continuam visíveis.`
    : `Este registro corrige o atendimento de ${quando}, que continua visível como foi confirmado.`
})

const acao = computed(() => (props.variante === 'retificado'
  ? 'Ver a retificação'
  : 'Ver o registro original'))
</script>

<template>
  <div class="tarja">
    <FilePenLine :size="20" :stroke-width="1.75" class="tarja__icone" />
    <div class="tarja__conteudo">
      <p class="tarja__texto">{{ texto }}</p>
      <RouterLink :to="destino" class="tarja__acao">{{ acao }}</RouterLink>
    </div>
  </div>
</template>

<style scoped>
.tarja {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
  background: var(--surface-card);
  border-top: 1px solid var(--status-due);
  border-bottom: 1px solid var(--status-due);
}

.tarja__icone {
  flex: none;
  margin-top: 2px;
  color: var(--status-due-text);
}

.tarja__conteudo {
  flex: 1;
  min-width: 0;
}

.tarja__texto {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);
  text-wrap: pretty;
}

/* Alvo de 48 px: a ligação entre as duas versões é o caminho que o tutor mais
   provavelmente vai tomar nesta tela, e não pode ser o mais difícil de acertar. */
.tarja__acao {
  display: inline-flex;
  align-items: center;
  height: 48px;
  font-size: 16px;
  font-weight: 600;
}
</style>
