import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { apiGet } from '@/lib/api.js'

/**
 * Quantos pedidos de acesso esperam resposta do tutor (RF38). Fica em store
 * porque quem desenha o contador é a moldura do ambiente, e não a tela de T13 —
 * o número precisa estar disponível em qualquer lugar do ambiente do tutor,
 * inclusive nos que nada têm a ver com autorizações.
 *
 * A consulta é uma só por carregamento do SPA, como a da sessão: a moldura
 * remonta a cada navegação, e refazer a contagem em toda troca de tela custaria
 * uma requisição por passo de percurso para desenhar um número que quase nunca
 * muda. Quem o muda é T13, e é ela que anuncia o novo valor por `registrar`.
 */
export const useSolicitacoesStore = defineStore('solicitacoes', () => {
  const pendentes = ref(0)
  const consultada = ref(false)

  const temPendencia = computed(() => pendentes.value > 0)

  /**
   * Falha de consulta é tratada como ausência de pendência: um contador que não
   * pôde ser lido não deve anunciar número algum, e muito menos manter o
   * anterior, que passaria a mentir.
   */
  async function carregar({ recarregar = false } = {}) {
    if (consultada.value && !recarregar) return pendentes.value

    try {
      const resposta = await apiGet('/api/solicitacoes/pendentes')
      pendentes.value = resposta.pendentes
    } catch {
      pendentes.value = 0
    } finally {
      consultada.value = true
    }

    return pendentes.value
  }

  function registrar(quantos) {
    pendentes.value = quantos
    consultada.value = true
  }

  return { pendentes, temPendencia, carregar, registrar }
})
