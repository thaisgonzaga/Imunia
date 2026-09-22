/**
 * Formatação de data em português. O servidor manda sempre ISO (`Y-m-d`) e é
 * aqui que ela vira texto para o tutor — nunca no servidor, que não sabe em
 * que tela o valor vai aparecer.
 */

function comoData(iso) {
  return new Date(`${iso}T00:00:00`)
}

/** 26/12/2025 — a forma que o tutor lê num documento. */
export function emNumeros(iso) {
  if (!iso) return null

  return new Intl.DateTimeFormat('pt-BR').format(comoData(iso))
}

/** dezembro de 2025 — cabeçalho de mês da linha do tempo (T07). */
export function emMesEAno(iso) {
  if (!iso) return null

  return new Intl.DateTimeFormat('pt-BR', { month: 'long', year: 'numeric' }).format(comoData(iso))
}

/** qua., 12 de novembro de 2025 — o cabeçalho do prontuário (T08). */
export function porExtenso(iso) {
  if (!iso) return null

  return new Intl.DateTimeFormat('pt-BR', {
    weekday: 'short',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }).format(comoData(iso))
}

/**
 * Terça, 18 de novembro de 2025 — o cabeçalho de dia da auditoria (T14).
 *
 * O dia da semana entra porque a pergunta do tutor diante do livro de acessos
 * costuma ser "isso foi no dia em que levei o Théo à clínica?", e a data em
 * números sozinha não a responde. O "-feira" sai porque o cabeçalho se repete
 * a cada grupo e o sufixo não acrescenta nada em quatro dos sete dias.
 */
export function porDiaDaSemana(iso) {
  if (!iso) return null

  const texto = new Intl.DateTimeFormat('pt-BR', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }).format(comoData(iso))

  const semSufixo = texto.replace('-feira', '')

  return semSufixo.charAt(0).toUpperCase() + semSufixo.slice(1)
}

/**
 * 16h20 — a hora como se escreve em português. O servidor manda `16:20`, que é
 * o formato do dado; esta é a forma de lê-lo.
 */
export function emHoras(hora) {
  if (!hora) return null

  return hora.replace(':', 'h')
}

/**
 * Só o ano. É o que se pode afirmar de uma data aproximada (RN25): o registro
 * pregresso diz "2024", não "01/06/2024", porque a segunda forma afirmaria uma
 * precisão que ninguém tem.
 */
export function apenasAno(iso) {
  if (!iso) return null

  return String(comoData(iso).getFullYear())
}

/**
 * A data de um registro clínico como ele pode ser afirmado: completa quando a
 * data é exata, degradada para o ano quando é aproximada.
 */
export function dataDeRegistro(iso, aproximada) {
  return aproximada ? apenasAno(iso) : emNumeros(iso)
}
