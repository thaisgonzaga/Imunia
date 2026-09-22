import { computed, ref } from 'vue'

/**
 * A falha que impediu a tela de existir — a que leva a E03.
 *
 * É um estado de módulo, e não uma store: quem a anuncia é o roteador e o
 * tratador de erros do Vue, os dois fora de componente, e o que se guarda aqui
 * não é dado do sistema, é o fato de a última tentativa de desenhar alguma
 * coisa ter terminado em nada.
 *
 * Não confundir com o erro de carregamento de uma tela (§6.1, item 3), que cada
 * view trata por conta própria e mostra sem derrubar o resto: ali a moldura, os
 * dados já carregados e as outras seções continuam de pé.
 */
const falha = ref(null)

export const falhaAtual = computed(() => falha.value)

/**
 * O número de protocolo da requisição que falhou, quando a falha veio do
 * servidor. Falha do próprio aplicativo não tem ocorrência registrada.
 */
export const ocorrenciaDaFalha = computed(() => falha.value?.ocorrencia ?? '')

export function relatarFalha(excecao) {
  falha.value = excecao ?? new Error('Falha sem detalhe.')

  // A tela de exceção esconde o defeito de quem está usando o sistema, não de
  // quem o desenvolve: o console continua recebendo a exceção inteira.
  console.error('[Imunia] Falha não tratada:', excecao)
}

export function esquecerFalha() {
  falha.value = null
}
