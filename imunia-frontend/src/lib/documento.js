/**
 * Código de verificação da exportação em PDF (RF47, RN47): identificador
 * próprio de cada emissão, impresso no rodapé do documento e embutido no QR
 * Code. São 16 caracteres hexadecimais, lidos em quatro grupos de quatro.
 */
const COMPRIMENTO_CODIGO = 16

export function normalizarCodigoDocumento(valor) {
  return (valor ?? '')
    .toUpperCase()
    .replace(/[^0-9A-F]/g, '')
    .slice(0, COMPRIMENTO_CODIGO)
}

export function formatarCodigoDocumento(valor) {
  return normalizarCodigoDocumento(valor).replace(/(.{4})(?=.)/g, '$1 ')
}

/**
 * Confere apenas o formato, antes de submeter: quem digitou errado descobre
 * aqui, sem gastar uma tentativa da rota pública, que é protegida contra
 * enumeração (RF47c).
 */
export function codigoDocumentoValido(valor) {
  return normalizarCodigoDocumento(valor).length === COMPRIMENTO_CODIGO
}

export { COMPRIMENTO_CODIGO }
