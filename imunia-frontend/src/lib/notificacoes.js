import { MailCheck, MailQuestionMark, MailX } from '@lucide/vue'

/**
 * O ícone de cada situação de envio (T17, RF45). Mora aqui porque a tabela e a
 * folha de detalhe desenham o mesmo selo, e um envelope diferente em cada lugar
 * faria a mesma mensagem parecer ter duas situações.
 *
 * O desenho muda junto com a cor — envelope com visto, com interrogação, com
 * xis — porque cor sozinha não é portadora de significado (§4.1 dos tokens).
 */
const ICONES = {
  entregue: MailCheck,
  sem_confirmacao: MailQuestionMark,
  falhou: MailX,
}

export function iconeDaSituacao(situacao) {
  return ICONES[situacao] ?? MailQuestionMark
}
