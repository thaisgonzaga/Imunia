import { ref, toValue } from 'vue'
import { ApiError, apiPost } from '@/lib/api.js'
import { useContagemRegressiva } from '@/lib/contagem.js'

/**
 * Reenvio da confirmação de endereço (RF05c).
 *
 * Duas telas pedem a mesma coisa: a tarja do painel do tutor, onde o pedido
 * interrompe quem veio fazer outra coisa, e a seção de dados pessoais de T18,
 * onde ele fecha o assunto que a própria tela abriu ao dizer que o endereço não
 * está confirmado. O intervalo entre tentativas é do servidor; o que se
 * compartilha aqui é a leitura dele — inclusive a do 429, que não é erro e sim
 * a pausa anunciada em segundos.
 */
export function useReenvioDeConfirmacao(email) {
  const reenviando = ref(false)
  const aviso = ref('')
  const erro = ref('')
  const {
    correndo: emPausa,
    formatado: tempoRestante,
    iniciar: iniciarPausa,
  } = useContagemRegressiva()

  async function reenviar() {
    if (reenviando.value || emPausa.value) return

    reenviando.value = true
    aviso.value = ''
    erro.value = ''

    try {
      const resposta = await apiPost('/api/email/reenviar', { email: toValue(email) })
      aviso.value = resposta.message
      iniciarPausa(60)
    } catch (excecao) {
      if (excecao instanceof ApiError && excecao.status === 429) {
        aviso.value = excecao.message
        iniciarPausa(excecao.data.segundos_restantes)
      } else {
        erro.value = excecao.message
      }
    } finally {
      reenviando.value = false
    }
  }

  return { reenviando, aviso, erro, emPausa, tempoRestante, reenviar }
}
