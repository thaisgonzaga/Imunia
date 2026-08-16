function somenteDigitos(valor) {
  return (valor ?? '').replace(/\D/g, '')
}

export function formatarDocumento(valor) {
  const digitos = somenteDigitos(valor).slice(0, 14)

  if (digitos.length <= 11) {
    return digitos
      .replace(/^(\d{3})(\d)/, '$1.$2')
      .replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3')
      .replace(/\.(\d{3})(\d{1,2})$/, '.$1-$2')
  }

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
 * (App\Rules\DocumentoInscricaoValido), para dar retorno imediato no campo.
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

export { somenteDigitos }
