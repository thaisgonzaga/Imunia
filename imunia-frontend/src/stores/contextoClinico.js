import { ref } from 'vue'
import { defineStore } from 'pinia'

/**
 * O contexto clínico em que a moldura está desenhada — lembrado entre telas.
 *
 * Existe por causa de um defeito de percurso, e não de dado: as telas da conta
 * (A01–A03) só sabem qual moldura vestir depois que o payload chega, e até lá
 * desenhavam a administrativa para depois trocá-la pela clínica. Quem clicava
 * em "Equipe" na barra da clínica via a barra inteira ser substituída e voltar
 * meio segundo depois.
 *
 * O que se guarda é o que a moldura clínica já tinha em mãos na tela anterior:
 * o prestador ativo e os vínculos com a marca de quem os administra. Não é
 * cache de resposta — a tela seguinte continua consultando o servidor e
 * substitui isto pelo que ele responder; é só o que se sabia até a resposta
 * chegar, para que a casca não pisque no caminho.
 */
export const useContextoClinicoStore = defineStore('contextoClinico', () => {
  const prestador = ref(null)
  const vinculos = ref([])

  /**
   * A moldura desenhada na tela anterior — `'clinica'` ou `'administrativa'`,
   * e nada antes da primeira. É o palpite das telas da conta: entre uma e
   * outra muda o conteúdo, não o contexto, e quem estava numa casca segue nela.
   */
  const moldura = ref(null)

  function lembrar(novoPrestador, novosVinculos) {
    if (!novoPrestador) return

    prestador.value = novoPrestador
    vinculos.value = novosVinculos ?? []
    moldura.value = 'clinica'
  }

  function lembrarMoldura(clinica) {
    moldura.value = clinica ? 'clinica' : 'administrativa'
  }

  /**
   * Fim da sessão: o que se lembrava é de outra pessoa para quem entrar em
   * seguida na mesma aba — e a moldura o desenharia antes da primeira resposta.
   */
  function esquecer() {
    prestador.value = null
    vinculos.value = []
    moldura.value = null
  }

  return { prestador, vinculos, moldura, lembrar, lembrarMoldura, esquecer }
})
