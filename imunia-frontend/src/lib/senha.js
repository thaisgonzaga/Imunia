/**
 * Política de senha de RN04, na mesma granularidade exibida ao usuário como
 * lista de critérios verificáveis. É espelho da regra `App\Rules\SenhaForte`
 * do servidor: aqui serve para orientar quem digita, e nunca para decidir —
 * quem decide é o servidor.
 */
export function criteriosDeSenha(senha = '') {
  return [
    { rotulo: 'Ao menos 10 caracteres', atendido: senha.length >= 10 },
    { rotulo: 'Uma letra maiúscula', atendido: /\p{Lu}/u.test(senha) },
    { rotulo: 'Um número ou símbolo', atendido: /[0-9]|[^\p{L}\p{N}]/u.test(senha) },
  ]
}

export function senhaForte(senha = '') {
  return criteriosDeSenha(senha).every((criterio) => criterio.atendido)
}
