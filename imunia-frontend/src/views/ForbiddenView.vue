<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { Lock } from '@lucide/vue'
import ExceptionState from '@/components/base/ExceptionState.vue'
import RoleShell from '@/components/base/RoleShell.vue'
import { explicacaoDaRecusa, painelDe } from '@/lib/areas.js'
import { useSessaoStore } from '@/stores/sessao.js'

/**
 * E01 — sem permissão (RNF09). A tela do impedimento por papel: a página existe,
 * mas pertence a outro perfil do sistema.
 *
 * Duas coisas que E01 deliberadamente **não** é:
 *
 * 1. Não é a garantia de nada. A recusa que vale é a do servidor, em toda
 *    chamada, exiba ou não a interface o comando correspondente (RNF09). Esta
 *    tela é a tradução dessa recusa para linguagem de gente — e o atalho que
 *    evita desenhar uma tela inteira que só saberia falhar.
 *
 * 2. Não é a tela de "sem autorização do tutor". Essa é o estado P2, que já vive
 *    dentro de V03 e de V06: com identificação mínima do animal, explicação e o
 *    caminho de solicitar acesso. Ausência de autorização é estado de tela, não
 *    erro — e mandar o veterinário para uma parede quando ele podia estar
 *    pedindo autorização seria trocar um caminho por um beco.
 */
const route = useRoute()
const sessao = useSessaoStore()

// A área recusada viaja na consulta porque a rota de E01 é a mesma para todas —
// é o que permite dizer de que perfil a página é, em vez de só dizer que não é
// desta pessoa. Os papéis de quem chegou entram na conta para que o conselho
// final só apareça a quem ele serve.
const descricao = computed(() => explicacaoDaRecusa(route.query.area, sessao.usuario?.papeis ?? []))

const painel = computed(() => painelDe(sessao.usuario))
const rotuloDoPainel = computed(() => (sessao.autenticado ? 'Voltar ao painel' : 'Ir para a entrada'))
</script>

<template>
  <RoleShell titulo="Sem acesso">
    <ExceptionState
      :icone="Lock"
      titulo="Você não tem acesso a esta página."
      :descricao="descricao"
    >
      <RouterLink v-if="painel" :to="painel" class="acao">{{ rotuloDoPainel }}</RouterLink>
    </ExceptionState>
  </RoleShell>
</template>
