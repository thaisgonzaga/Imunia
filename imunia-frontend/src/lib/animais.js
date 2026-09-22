/**
 * Vocabulário de apresentação do animal, compartilhado pelas telas do tutor
 * (T01, T02, T04). O servidor devolve dado; o texto em português é montado
 * aqui, num lugar só, para que "cerca de 18 meses" signifique o mesmo em toda
 * tela em que apareça.
 */

const ESPECIES = {
  cao: 'cão',
  gato: 'gato',
}

export function descreverEspecie(especie) {
  return ESPECIES[especie] ?? especie
}

/**
 * RN14 — a idade é cálculo derivado da data de nascimento, e a natureza da data
 * viaja com ela: "cerca de" não é hesitação de redação, é a informação de que a
 * data foi estimada pelo tutor e ainda não confirmada por veterinário.
 */
export function descreverIdade(meses, exata = false) {
  if (meses === null || meses === undefined) return null

  const texto = meses < 24
    ? `${meses} ${meses === 1 ? 'mês' : 'meses'}`
    : `${Math.floor(meses / 12)} anos`

  return exata ? texto : `cerca de ${texto}`
}

/**
 * Linha de metadados do cartão: espécie · raça · idade, com as partes que se
 * sabem. A raça entra quando a caracterização pelo veterinário existir (RF19);
 * até lá, a linha simplesmente não a menciona — nunca a inventa.
 */
export function descreverAnimal(animal) {
  return [
    descreverEspecie(animal.especie),
    animal.raca ?? null,
    descreverIdade(animal.idade_em_meses, animal.nascimento_exato),
  ]
    .filter(Boolean)
    .join(' · ')
}

/**
 * Enumeração em português para a frase de confirmação positiva do painel:
 * "Théo e Nina estão com as vacinas em dia."
 */
export function enumerarNomes(nomes) {
  if (nomes.length === 0) return ''
  if (nomes.length === 1) return nomes[0]

  return `${nomes.slice(0, -1).join(', ')} e ${nomes[nomes.length - 1]}`
}
