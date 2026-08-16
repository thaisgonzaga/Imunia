import { computed, onUnmounted, ref } from 'vue'

/**
 * Contagem regressiva usada nas pausas anunciadas ao usuário: bloqueio por
 * tentativas (P02), limite de pedidos de recuperação (P05) e intervalo entre
 * reenvios da confirmação (P08). O tempo vem sempre do servidor — a tela
 * apenas o exibe.
 */
export function useContagemRegressiva() {
  const restante = ref(0)
  let intervalo = null

  function parar() {
    if (intervalo !== null) {
      clearInterval(intervalo)
      intervalo = null
    }
  }

  function iniciar(segundos) {
    parar()
    restante.value = Math.max(0, Math.ceil(segundos ?? 0))

    if (restante.value === 0) return

    intervalo = setInterval(() => {
      restante.value -= 1
      if (restante.value <= 0) parar()
    }, 1000)
  }

  const correndo = computed(() => restante.value > 0)

  const formatado = computed(() => {
    const minutos = Math.floor(restante.value / 60)
    const segundos = restante.value % 60

    return `${minutos}:${String(segundos).padStart(2, '0')}`
  })

  onUnmounted(parar)

  return { restante, correndo, formatado, iniciar, parar }
}
