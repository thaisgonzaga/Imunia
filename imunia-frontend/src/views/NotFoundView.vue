<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { CircleHelp } from '@lucide/vue'
import ExceptionState from '@/components/base/ExceptionState.vue'
import RoleShell from '@/components/base/RoleShell.vue'
import { molduraDe, painelDe } from '@/lib/areas.js'
import { useSessaoStore } from '@/stores/sessao.js'

/**
 * E02 — não encontrado. Responde ao endereço que não existe e ao item que
 * deixou de existir, sem distinguir os dois: a diferença interessa ao sistema,
 * não a quem digitou o endereço (RNF17).
 *
 * É também a resposta às telas que ainda não foram construídas. As molduras dos
 * três ambientes exibem a navegação inteira do desenho aprovado, inclusive os
 * destinos de fatias futuras; até que cheguem, quem os clica cai aqui, com a
 * barra lateral intacta e um caminho de volta.
 */
const route = useRoute()
const sessao = useSessaoStore()

/**
 * A segunda saída depende de onde a pessoa se perdeu. Na clínica, a busca é o
 * ponto de partida de todo trabalho sobre um animal; no ambiente do tutor, a
 * relação de animais faz o mesmo papel. Onde não há um segundo destino que
 * signifique alguma coisa, a tela fica com uma ação só — inventar um botão para
 * preencher a linha do desenho seria pior do que a assimetria.
 */
const SEGUNDA_SAIDA = {
  clinica: { rotulo: 'Buscar', destino: '/clinica/buscar' },
  tutor: { rotulo: 'Meus animais', destino: '/animais' },
}

const area = computed(() => molduraDe(sessao.usuario?.papeis ?? [], route.path))
const segundaSaida = computed(() => (area.value ? SEGUNDA_SAIDA[area.value.nome] ?? null : null))

const painel = computed(() => painelDe(sessao.usuario))
const rotuloDoPainel = computed(() => (sessao.autenticado ? 'Voltar ao painel' : 'Ir para a entrada'))
</script>

<template>
  <RoleShell titulo="Página não encontrada">
    <ExceptionState
      :icone="CircleHelp"
      tom="discreto"
      titulo="Não encontramos esta página."
      descricao="O endereço pode ter mudado, ou o item que você procurava não existe mais."
    >
      <RouterLink v-if="painel" :to="painel" class="acao">{{ rotuloDoPainel }}</RouterLink>
      <RouterLink v-if="segundaSaida" :to="segundaSaida.destino" class="acao acao--secundaria">
        {{ segundaSaida.rotulo }}
      </RouterLink>
    </ExceptionState>
  </RoleShell>
</template>
