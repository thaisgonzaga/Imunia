<script setup>
import { onErrorCaptured } from 'vue'
import { useRouter } from 'vue-router'
import ServerErrorView from '@/views/ServerErrorView.vue'
import { esquecerFalha, falhaAtual, ocorrenciaDaFalha, relatarFalha } from '@/lib/falha.js'

/**
 * A raiz do aplicativo é também a última rede: o que nenhuma tela tratou para
 * aqui, e vira E03 em vez de uma página em branco — o pior desfecho possível,
 * porque não diz o que houve nem oferece saída.
 */
onErrorCaptured((excecao) => {
  relatarFalha(excecao)

  // A propagação para por aqui: acima desta linha só há o registro no console,
  // que `relatarFalha` já fez.
  return false
})

// Sair da tela de exceção é navegar para outro lugar, e a falha da tela
// anterior deixa de valer no instante em que a nova rota se resolve.
useRouter().afterEach(() => esquecerFalha())
</script>

<template>
  <ServerErrorView v-if="falhaAtual" :ocorrencia="ocorrenciaDaFalha" />
  <RouterView v-else />
</template>
