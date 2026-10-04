<script setup>
import { computed, watchEffect } from 'vue'
import AdminShell from '@/components/prestador/AdminShell.vue'
import VetShell from '@/components/vet/VetShell.vue'
import { useContextoClinicoStore } from '@/stores/contextoClinico.js'
import { useSessaoStore } from '@/stores/sessao.js'

/**
 * A moldura das três telas da conta (A01, A02, A03) — que é uma ou outra,
 * conforme quem está olhando.
 *
 * Quem administra a conta **em que atende** permanece na moldura clínica: a
 * barra lateral do ambiente clínico já desenha "Painel da conta", "Dados do
 * prestador" e "Equipe" para esse caso, e trocar a barra inteira ao clicar num
 * deles fazia a navegação parecer que tinha saltado para outro sistema —
 * inclusive oferecendo um "Ambiente clínico" de volta para onde a pessoa nunca
 * saiu. O conteúdo muda; a casca, não.
 *
 * Quem **não** atende ali continua na moldura administrativa: a administradora
 * sem CRMV não tem ambiente clínico a vestir, e é essa moldura que carrega o
 * bloco de limitação de RN08 — o que este perfil não alcança é argumento de
 * projeto, não redundância.
 */
const props = defineProps({
  titulo: { type: String, default: 'Administração' },
  prestador: { type: Object, default: null },
  /** Vínculos administrados — o alternador da moldura administrativa. */
  vinculos: { type: Array, default: () => [] },
  convitesPendentes: { type: Number, default: 0 },
  contextoClinico: { type: Object, default: null },
  /** Se a pessoa também é veterinária no prestador administrado. */
  atendeAqui: { type: Boolean, default: false },
  /** Vínculos de veterinário, para o alternador da moldura clínica. */
  vinculosClinicos: { type: Array, default: () => [] },
})

const emit = defineEmits(['trocar-prestador'])

const contexto = useContextoClinicoStore()
const sessao = useSessaoStore()

/** O payload já chegou — antes disso, `atendeAqui` é só o valor inicial. */
const respondido = computed(() => props.prestador !== null)

/**
 * Que moldura vestir enquanto o servidor não respondeu.
 *
 * A escolha não pode esperar a resposta: a casca é a primeira coisa que a tela
 * desenha, e trocá-la depois faz a barra lateral inteira piscar e o cabeçalho
 * trocar de texto. O que se sabe de imediato é a moldura da tela anterior: as
 * telas da conta falam do estabelecimento em que a pessoa está, e quem chega
 * da barra da clínica atende nele — administrando ou não. Na falta dela —
 * primeira tela da sessão, ou recarga da página — vale o papel, que é o
 * palpite mais provável para quem tem ambiente clínico.
 */
const palpite = computed(() => (contexto.moldura
  ? contexto.moldura === 'clinica'
  : (sessao.usuario?.papeis ?? []).includes('veterinario')))

/**
 * A moldura clínica veste o contexto ativo, e por isso não serve quando a tela
 * administra outro prestador: a barra diria "Dr. Lucas" enquanto o ambiente
 * clínico continua na clínica de onde a pessoa veio. Nesse caso vale a moldura
 * administrativa, que é onde a divergência é explicada e tem o caminho de volta.
 */
const naMolduraClinica = computed(() => (respondido.value
  ? props.atendeAqui && props.contextoClinico === null
  : palpite.value))

/** A decisão do servidor vira o palpite da próxima tela da conta. */
watchEffect(() => {
  if (respondido.value) contexto.lembrarMoldura(naMolduraClinica.value)
})
</script>

<template>
  <!-- O alternador recarrega a própria tela para qualquer vínculo, administrado
       ou não: as leituras da conta respondem a quem atende no estabelecimento,
       e só as ações dependem de administrá-lo. Mandar quem só atende para o
       painel da clínica tirava a pessoa da tela em que estava.
       Enquanto a resposta não chega, a própria moldura clínica veste o
       contexto lembrado — só o conteúdo mostra esqueleto. -->
  <VetShell
    v-if="naMolduraClinica"
    :titulo="titulo"
    :prestador="prestador"
    :vinculos="vinculosClinicos"
    @trocar-prestador="emit('trocar-prestador', $event)"
  >
    <slot />
  </VetShell>

  <AdminShell
    v-else
    :titulo="titulo"
    :prestador="prestador"
    :vinculos="vinculos"
    :convites-pendentes="convitesPendentes"
    :contexto-clinico="contextoClinico"
    @trocar-prestador="emit('trocar-prestador', $event)"
  >
    <slot />
  </AdminShell>
</template>
