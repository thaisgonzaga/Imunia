/**
 * As quatro áreas do sistema, cada uma com o papel que a abre e o painel que a
 * encabeça — a mesma precedência de `User::rotaInicial()` no servidor, e pela
 * mesma razão: quem acumula papéis (RN05) trabalha a partir de um deles.
 *
 * As telas de exceção (E01–E03) leem esta tabela duas vezes. Uma para saber que
 * moldura desenhar, porque errar uma rota não pode custar a navegação do papel
 * (§8.1); outra para escrever a frase de E01, que muda conforme a área
 * recusada — "esta área pertence a outro perfil" só faz sentido se a tela
 * souber de que perfil está falando.
 */
export const AREAS = [
  {
    nome: 'clinica',
    papel: 'veterinario',
    prefixo: '/clinica',
    painel: '/clinica/painel',
    recusa: 'Esta área é do atendimento clínico e pertence a outro perfil da clínica.',
    // Quem trabalha na clínica tem a quem recorrer; quem só cuida dos próprios
    // animais, não. Ver `explicacaoDaRecusa`.
    temSaidaPelaAdministracao: true,
  },
  {
    nome: 'prestador',
    papel: 'admin_prestador',
    prefixo: '/prestador',
    painel: '/prestador',
    recusa: 'Esta área é da administração da conta do prestador.',
    temSaidaPelaAdministracao: true,
  },
  {
    nome: 'plataforma',
    papel: 'admin_plataforma',
    prefixo: '/plataforma',
    painel: '/plataforma/catalogo',
    recusa: 'Esta área é da administração do Imunia, e não da conta de um prestador.',
  },
  {
    // O ambiente do tutor não tem prefixo próprio: as telas dele são a raiz do
    // aplicativo, e por isso a área é reconhecida pelo papel, nunca pela rota.
    nome: 'tutor',
    papel: 'tutor',
    prefixo: null,
    painel: '/inicio',
    recusa: 'Esta área é de quem tem animais cadastrados no Imunia.',
  },
]

const PADRAO_DE_RECUSA = 'Esta página pertence a outro perfil do sistema.'

const SAIDA_PELA_ADMINISTRACAO = ' Se você precisa dela para trabalhar, fale com quem administra a conta.'

const PAPEIS_DE_PRESTADOR = ['veterinario', 'admin_prestador']

export function areaPorNome(nome) {
  return AREAS.find((area) => area.nome === nome) ?? null
}

/**
 * A área a que um endereço pertence, ou nulo quando o endereço é do ambiente do
 * tutor ou de tela pública — casos em que só o papel de quem chegou responde.
 */
export function areaDoCaminho(caminho) {
  return AREAS.find(
    (area) => area.prefixo && (caminho === area.prefixo || caminho.startsWith(`${area.prefixo}/`)),
  ) ?? null
}

/**
 * A área em que o usuário trabalha, na ordem de precedência do servidor.
 */
export function areaDoUsuario(papeis = []) {
  return AREAS.find((area) => papeis.includes(area.papel)) ?? null
}

/**
 * A moldura correta para uma tela de exceção: a da área do endereço, quando o
 * usuário tem o papel que a abre, e a do seu próprio papel em qualquer outro
 * caso. É o que faz E02 dentro de `/clinica` conservar a barra lateral clínica,
 * e E01 devolver a moldura de quem foi recusado, e não a da área recusada.
 */
export function molduraDe(papeis = [], caminho = '') {
  const daRota = areaDoCaminho(caminho)

  if (daRota && papeis.includes(daRota.papel)) return daRota

  return areaDoUsuario(papeis)
}

/**
 * O painel para onde a ação primária das telas de exceção devolve. A rota vem
 * do servidor junto com a sessão (`rota_inicial`), e não é recalculada aqui: o
 * papel que decide a porta de entrada é o mesmo que decide o que a pessoa pode
 * ver, e essa decisão é de lá.
 *
 * Devolve vazio para a sessão sem ambiente algum — vínculo encerrado e nenhum
 * animal. Para essa pessoa o servidor ainda informa `/inicio`, e oferecer esse
 * caminho seria devolvê-la à mesma página que acabou de ser recusada, num laço
 * de duas telas. A saída dela é sair da conta, e está na moldura.
 */
export function painelDe(usuario) {
  if (!usuario) return '/entrar'

  return areaDoUsuario(usuario.papeis ?? []) ? usuario.rota_inicial ?? '' : ''
}

/**
 * A frase de E01: o que é a área recusada e, quando existe, o que fazer a
 * respeito.
 *
 * O convite a falar com quem administra a conta só vale para quem trabalha num
 * prestador — é ele quem pode receber o papel que falta. Ao tutor que esbarra
 * numa área clínica esse conselho seria pior que silêncio: mandaria pedir a um
 * estranho um acesso que o sistema nunca lhe daria, e sugeriria que os dados
 * clínicos são administrados por quem cuida do animal, quando o desenho inteiro
 * do Imunia diz o contrário.
 *
 * Sem área identificada — recusa vinda do servidor numa tela sem prefixo —, o
 * texto se limita ao que se pode afirmar sem inventar motivo.
 */
export function explicacaoDaRecusa(nomeDaArea, papeis = []) {
  const area = areaPorNome(nomeDaArea)

  if (!area) return PADRAO_DE_RECUSA

  const trabalhaEmPrestador = papeis.some((papel) => PAPEIS_DE_PRESTADOR.includes(papel))

  return area.temSaidaPelaAdministracao && trabalhaEmPrestador
    ? area.recusa + SAIDA_PELA_ADMINISTRACAO
    : area.recusa
}
