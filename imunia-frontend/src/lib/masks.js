function somenteDigitos(valor) {
  return (valor ?? '').replace(/\D/g, '')
}

/**
 * O CNPJ do prestador (RF07): `00.000.000/0000-00`.
 *
 * A máscara é uma só porque o documento é um só — os três tipos de RF07a se
 * inscrevem como pessoa jurídica, o autônomo inclusive, já que o
 * médico-veterinário não pode ser microempreendedor individual.
 */
export function formatarCnpj(valor) {
  const digitos = somenteDigitos(valor).slice(0, 14)

  return digitos
    .replace(/^(\d{2})(\d)/, '$1.$2')
    .replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
    .replace(/\.(\d{3})(\d)/, '.$1/$2')
    .replace(/(\d{4})(\d{1,2})$/, '$1-$2')
}

export function formatarTelefone(valor) {
  const digitos = somenteDigitos(valor).slice(0, 11)

  if (digitos.length <= 10) {
    return digitos
      .replace(/^(\d{2})(\d)/, '($1) $2')
      .replace(/(\d{4})(\d{1,4})$/, '$1-$2')
  }

  return digitos
    .replace(/^(\d{2})(\d)/, '($1) $2')
    .replace(/(\d{5})(\d{1,4})$/, '$1-$2')
}

export function formatarCpf(valor) {
  const digitos = somenteDigitos(valor).slice(0, 11)

  return digitos
    .replace(/^(\d{3})(\d)/, '$1.$2')
    .replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3')
    .replace(/\.(\d{3})(\d{1,2})$/, '.$1-$2')
}

/**
 * Mesmo algoritmo de dígito verificador usado no backend
 * (App\Rules\CpfValido), para dar retorno imediato no campo.
 */
export function cpfValido(valor) {
  const cpf = somenteDigitos(valor)
  if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) return false

  for (let posicaoDigito = 9; posicaoDigito <= 10; posicaoDigito++) {
    let soma = 0
    for (let i = 0; i < posicaoDigito; i++) {
      soma += Number(cpf[i]) * (posicaoDigito + 1 - i)
    }
    const resto = (soma * 10) % 11
    const digitoEsperado = resto === 10 ? 0 : resto
    if (Number(cpf[posicaoDigito]) !== digitoEsperado) return false
  }

  return true
}

/**
 * Espelho de `App\Rules\CnpjValido`, pelo mesmo motivo do CPF: dizer no campo
 * que o número não fecha, antes de o formulário inteiro ser enviado.
 *
 * Aqui a conferência local vale ainda mais do que a conveniência, porque o
 * servidor responde 422 tanto para o CNPJ malformado quanto para o já
 * cadastrado — e a tela, que só enxerga o campo em erro, anunciava "já existe
 * um estabelecimento" para quem tinha apenas errado um dígito.
 */
export function cnpjValido(valor) {
  const cnpj = somenteDigitos(valor)
  if (cnpj.length !== 14 || /^(\d)\1{13}$/.test(cnpj)) return false

  const pesos = [
    [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2],
    [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2],
  ]

  return pesos.every((linha, indice) => {
    const soma = linha.reduce((total, peso, i) => total + Number(cnpj[i]) * peso, 0)
    const resto = soma % 11
    const digitoEsperado = resto < 2 ? 0 : 11 - resto

    return Number(cnpj[12 + indice]) === digitoEsperado
  })
}

/**
 * Validade de frasco, em mês e ano (V07, RF25a). O rótulo do imunobiológico
 * traz "04/2027" e é assim que o profissional confere, com o frasco na mão —
 * pedir um dia obrigaria a inventá-lo.
 *
 * A barra entra sozinha depois do segundo dígito, para que o campo aceite
 * teclado numérico no celular sem exigir a tecla que ele não tem.
 */
export function formatarMesAno(valor) {
  const digitos = somenteDigitos(valor).slice(0, 6)

  if (digitos.length <= 2) return digitos

  return `${digitos.slice(0, 2)}/${digitos.slice(2)}`
}

/**
 * A data aproximada de T09 e de T03 (RF29, RN25): `aaaa`, ou `mm/aaaa` quando
 * o tutor lembra o mês. O campo aceita os dois, e é isso que o distingue de
 * `formatarMesAno` — ali a barra pode entrar sempre, aqui ela só pode entrar
 * quando o que está sendo escrito não pode mais ser um ano.
 *
 * Dois sinais decidem isso sem perguntar nada a quem digita: nenhum ano começa
 * em zero, e nenhum passa de quatro dígitos. "2024" fica intacto; "042024"
 * vira "04/2024" já no terceiro dígito.
 */
export function formatarDataAproximada(valor) {
  const bruto = valor ?? ''

  // A barra digitada manda, e o mês fica com os dígitos que o tutor lhe deu:
  // "6/2024" é aceito pelo servidor, e completá-lo para "06" seria corrigir
  // quem não errou.
  if (bruto.includes('/')) {
    const [mes, ano = ''] = bruto.split('/')

    return `${somenteDigitos(mes).slice(0, 2)}/${somenteDigitos(ano).slice(0, 4)}`
  }

  const digitos = somenteDigitos(bruto).slice(0, 6)

  if (!digitos.startsWith('0') && digitos.length <= 4) return digitos

  return digitos.length <= 2 ? digitos : `${digitos.slice(0, 2)}/${digitos.slice(2)}`
}

/**
 * A data completa de V05 (RF19, RN14): `dd/mm/aaaa`, exigida quando o
 * veterinário marca o nascimento como exato. As barras entram sozinhas, pelo
 * mesmo motivo de `formatarMesAno` — teclado numérico sem a tecla de barra.
 */
export function formatarDataCompleta(valor) {
  const digitos = somenteDigitos(valor).slice(0, 8)

  if (digitos.length <= 2) return digitos
  if (digitos.length <= 4) return `${digitos.slice(0, 2)}/${digitos.slice(2)}`

  return `${digitos.slice(0, 2)}/${digitos.slice(2, 4)}/${digitos.slice(4)}`
}

export { somenteDigitos }
