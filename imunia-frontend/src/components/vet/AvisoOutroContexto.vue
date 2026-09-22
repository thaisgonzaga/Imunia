<script setup>
import { computed } from 'vue'
import { PawPrint } from '@lucide/vue'

/**
 * O aviso de plantel em outro contexto: "Há 1 animal sob autorização vigente
 * em Clínica X — Trocar para lá". É a versão em palavras do selo do alternador,
 * para quem não repara em selo — nasceu do animal recém-autorizado à clínica
 * que ficou invisível para o veterinário no contexto do consultório.
 *
 * Fala só de contagem, nunca de nome ou código de animal: o que está em outro
 * contexto continua fora do âmbito ativo até a troca (RN48). E veste a cor da
 * marca, a mesma da faixa de contexto, porque é dela que este aviso é um
 * prolongamento — não é alerta nem erro.
 */
const props = defineProps({
  /** Prestador ativo — o contexto de que o aviso NÃO fala. */
  prestador: { type: Object, default: null },
  /** Vínculos do payload, com a contagem `animais` de cada um. */
  vinculos: { type: Array, default: () => [] },
})

const emit = defineEmits(['trocar'])

const outros = computed(() =>
  props.vinculos.filter(
    (vinculo) => vinculo.id !== props.prestador?.id && (vinculo.animais ?? 0) > 0,
  ),
)

function frase(vinculo) {
  return vinculo.animais === 1
    ? '1 animal sob autorização vigente'
    : `${vinculo.animais} animais sob autorização vigente`
}
</script>

<template>
  <div v-if="outros.length > 0" class="outro-contexto">
    <p v-for="vinculo in outros" :key="vinculo.id" class="outro-contexto__linha">
      <PawPrint :size="16" :stroke-width="1.75" class="outro-contexto__icone" />
      <span class="outro-contexto__texto">
        Há {{ frase(vinculo) }} em <strong>{{ vinculo.nome }}</strong>.
      </span>
      <button type="button" class="outro-contexto__trocar" @click="emit('trocar', vinculo.id)">
        Trocar para lá
      </button>
    </p>
  </div>
</template>

<style scoped>
.outro-contexto {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.outro-contexto__linha {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: var(--space-2);
  margin: 0;
  padding: var(--space-2) var(--space-3);
  background: var(--brand-wash);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-sm);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

.outro-contexto__icone {
  flex: none;
  color: var(--brand);
}

.outro-contexto__texto {
  flex: 1;
  min-width: 0;
}

/* O alvo de 44 px cabe na linha por margem negativa, como o alternador da
   faixa de contexto — o aviso é uma linha, não um cartão alto. */
.outro-contexto__trocar {
  display: inline-flex;
  align-items: center;
  flex: none;
  height: 44px;
  margin: -8px 0;
  padding: 0 var(--space-1);
  background: none;
  border: 0;
  font-size: 14px;
  font-weight: 600;
  color: var(--brand-bright);
  cursor: pointer;
}

.outro-contexto__trocar:hover {
  color: var(--brand-hover);
}
</style>
