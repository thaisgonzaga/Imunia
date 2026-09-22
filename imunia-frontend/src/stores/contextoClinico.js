import { computed, ref } from 'vue'
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

  /** Se o prestador ativo é uma conta que a pessoa também administra. */
  const administraOAtivo = computed(() =>
    Boolean(vinculos.value.find((vinculo) => vinculo.id === prestador.value?.id)?.admin),
  )

  function lembrar(novoPrestador, novosVinculos) {
    if (!novoPrestador) return

    prestador.value = novoPrestador
    vinculos.value = novosVinculos ?? []
  }

  return { prestador, vinculos, administraOAtivo, lembrar }
})
