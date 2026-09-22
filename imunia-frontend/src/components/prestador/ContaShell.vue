<script setup>
import { computed } from 'vue'
import { useRouter } from 'vue-router'
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

const router = useRouter()
const contexto = useContextoClinicoStore()
const sessao = useSessaoStore()

/** O payload já chegou — antes disso, `atendeAqui` é só o valor inicial. */
const respondido = computed(() => props.prestador !== null)

/**
 * Que moldura vestir enquanto o servidor não respondeu.
 *
 * A escolha não pode esperar a resposta: a casca é a primeira coisa que a tela
 * desenha, e trocá-la depois faz a barra lateral inteira piscar — foi o que
 * acontecia ao clicar em "Painel da conta" vindo da barra da clínica. O que se
 * sabe de imediato é o contexto que a moldura clínica anunciou na tela
 * anterior; na falta dele — primeira tela da sessão, ou recarga da página —
 * vale o papel, que é o palpite mais provável para quem tem ambiente clínico.
 */
const palpite = computed(() => (contexto.prestador
  ? contexto.administraOAtivo
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

/**
 * A moldura não fica vazia esperando: enquanto a resposta não chega, ela veste
 * o contexto lembrado. Só o conteúdo mostra esqueleto — como nas telas do
 * ambiente clínico, onde a barra nunca some entre uma navegação e outra.
 */
const prestadorDaMoldura = computed(() => props.prestador ?? contexto.prestador)

const vinculosDaMoldura = computed(() =>
  (props.vinculosClinicos.length > 0 ? props.vinculosClinicos : contexto.vinculos),
)

/**
 * O alternador da moldura clínica lista todos os vínculos de veterinário, e
 * nem todos são administrados: trocar para um deles não pode recarregar uma
 * tela que o servidor recusaria. Quem administra o destino segue na tela em
 * que está; quem só atende nele vai para o painel da clínica, que é o que
 * aquele contexto tem a oferecer.
 */
function trocarPrestador(id) {
  const destino = vinculosDaMoldura.value.find((vinculo) => vinculo.id === id)

  if (destino && !destino.admin) {
    router.push(`/clinica/painel?prestador=${id}`)

    return
  }

  emit('trocar-prestador', id)
}
</script>

<template>
  <VetShell
    v-if="naMolduraClinica"
    :titulo="titulo"
    :prestador="prestadorDaMoldura"
    :vinculos="vinculosDaMoldura"
    @trocar-prestador="trocarPrestador"
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
