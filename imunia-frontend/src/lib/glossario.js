/**
 * As notas explicativas dos termos técnicos do prontuário (T08), em linguagem
 * simples. O prontuário é escrito pelo veterinário, para o veterinário — e é
 * lido pelo tutor, que tem direito de entendê-lo sem precisar de tradutor.
 *
 * O critério é estreito de propósito: recebe nota o termo que pertence ao
 * vocabulário técnico, não o que o tutor já lê sem dificuldade. "Motivo da
 * consulta" e "Diagnóstico" ficam de fora porque explicá-los seria condescender
 * com quem lê; "anamnese" e "conduta" entram porque ninguém as encontra fora de
 * um consultório.
 *
 * A nota explica o termo — nunca reescreve o que o veterinário registrou. O
 * texto clínico é exibido como foi escrito, e a nota fica ao lado dele.
 */
const NOTAS = {
  anamnese: (animal) =>
    `Anamnese: o relato do que você contou ao veterinário sobre o que vem acontecendo com ${animal}.`,

  hipoteses_diagnosticas: () =>
    'Hipóteses diagnósticas: as possibilidades que o veterinário considerou antes de ter certeza. '
    + 'Os exames servem justamente para confirmar uma delas e descartar as outras.',

  conduta: (animal) =>
    `Conduta: o tratamento indicado para ${animal} e o que cabe a você fazer em casa até o retorno.`,
}

/**
 * A nota do termo, com o nome do animal no lugar certo, ou nulo quando o termo
 * não precisa de explicação.
 *
 * @param {string} chave chave da seção do prontuário
 * @param {string} animal nome do animal, para a frase falar dele
 * @returns {string|null}
 */
export function notaDoTermo(chave, animal) {
  const nota = NOTAS[chave]

  return nota ? nota(animal) : null
}
