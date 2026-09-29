/**
 * O caminho de volta depois de entrar: para onde a pessoa ia quando a porta a
 * interrompeu — a rota protegida aberta sem sessão, ou a sessão que expirou no
 * meio do trabalho (RN02). Viaja em `?voltar=` e só vale dentro do próprio
 * aplicativo: um endereço absoluto ali seria um convite a mandar quem acabou
 * de entrar para outro lugar.
 */
export const VOLTAR = 'voltar'

export function caminhoDeRetorno(valor) {
  if (typeof valor !== 'string') return null

  // Só caminho relativo à raiz: `//outro.site` também é absoluto para o
  // navegador. E voltar para a própria porta seria andar em círculo.
  if (!valor.startsWith('/') || valor.startsWith('//') || valor.startsWith('/\\')) return null
  if (valor.startsWith('/entrar')) return null

  return valor
}
