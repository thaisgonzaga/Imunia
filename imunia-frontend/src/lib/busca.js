import { cpfValido, formatarCpf, somenteDigitos } from '@/lib/masks.js'

/**
 * Classificação do termo digitado na busca do veterinário (V03, RF51). É o
 * espelho de App\Support\TermoDeBusca no servidor, pelo mesmo motivo que
 * `cpfValido` espelha a regra de CPF: a tela precisa saber o tipo antes de
 * enviar — para rotular o campo, para escolher o texto do resultado e,
 * sobretudo, para não enviar CPF com dígito verificador errado.
 *
 * Quem decide o que a busca encontra continua sendo o servidor. O que se decide
 * aqui é o que a tela diz enquanto ela não respondeu.
 */
export const CPF = 'cpf'
export const CODIGO = 'codigo'
export const MICROCHIP = 'microchip'
export const NOME = 'nome'

const DIGITOS_DO_MICROCHIP = 15

/** Como o tipo é anunciado ao lado do campo, no rótulo do desenho. */
const ROTULOS = {
  [CPF]: 'CPF',
  [CODIGO]: 'código do animal',
  [MICROCHIP]: 'micro-chip',
  [NOME]: 'nome',
}

export function classificarTermo(termo) {
  const limpo = (termo ?? '').trim().replace(/\s+/g, ' ')
  const compacto = limpo.replace(/[^0-9A-Za-z]/g, '').toUpperCase()
  const digitos = somenteDigitos(limpo)

  // Reconhecido pela forma, e não pelo alfabeto do gerador: quem transcreve do
  // papel troca O por 0, e esse engano precisa cair no código — onde a tela
  // manda conferir os caracteres — e não na busca por nome.
  const blocos = /^IM([0-9A-Z]{4})([0-9A-Z]{4})$/.exec(compacto)
  if (blocos && /[\d-]/.test(limpo)) {
    return { tipo: CODIGO, valor: `IM-${blocos[1]}-${blocos[2]}`, rotulo: ROTULOS[CODIGO] }
  }

  if (digitos !== '' && digitos === compacto) {
    if (digitos.length === 11) {
      return { tipo: CPF, valor: digitos, rotulo: ROTULOS[CPF] }
    }

    if (digitos.length === DIGITOS_DO_MICROCHIP) {
      return { tipo: MICROCHIP, valor: digitos, rotulo: ROTULOS[MICROCHIP] }
    }
  }

  return { tipo: NOME, valor: limpo, rotulo: ROTULOS[NOME] }
}

/**
 * RF13 — o dígito verificador é conferido antes de qualquer consulta, e é isso
 * que sustenta a promessa que a tela faz nesse estado: nada foi consultado e
 * nada foi registrado. O servidor confere de novo; esta conferência existe para
 * que a requisição sequer saia.
 */
export function termoConsultavel(termo) {
  const { tipo, valor } = classificarTermo(termo)

  return valor !== '' && (tipo !== CPF || cpfValido(valor))
}

/**
 * O termo como o resultado o repete. O CPF volta com máscara — foi assim que
 * chegou ao balcão, e é assim que se confere com o documento na mão.
 */
export function termoParaExibicao(termo) {
  const { tipo, valor } = classificarTermo(termo)

  return tipo === CPF ? formatarCpf(valor) : valor
}
