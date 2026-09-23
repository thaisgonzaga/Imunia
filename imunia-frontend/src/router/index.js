import { createRouter, createWebHistory } from 'vue-router'
import { areaPorNome } from '@/lib/areas.js'
import { relatarFalha } from '@/lib/falha.js'
import { useSessaoStore } from '@/stores/sessao.js'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      name: 'home',
      component: () => import('@/views/public/HomeView.vue'),
    },
    {
      path: '/entrar',
      name: 'login',
      component: () => import('@/views/public/LoginView.vue'),
    },
    {
      path: '/cadastrar-prestador',
      name: 'register-provider',
      component: () => import('@/views/public/RegisterProviderView.vue'),
    },
    {
      path: '/criar-conta',
      name: 'create-tutor-account',
      component: () => import('@/views/public/CreateTutorAccountView.vue'),
    },
    {
      // P10 e P11 — os dois documentos que a caixa de aceite de P05 exige ler
      // antes de marcar. Públicos e sem guarda: quem ainda não tem conta é
      // justamente quem mais precisa deles.
      path: '/termos',
      name: 'terms',
      component: () => import('@/views/public/TermsView.vue'),
    },
    {
      path: '/privacidade',
      name: 'privacy',
      component: () => import('@/views/public/PrivacyView.vue'),
    },
    {
      path: '/recuperar-senha',
      name: 'forgot-password',
      component: () => import('@/views/public/ForgotPasswordView.vue'),
    },
    {
      path: '/redefinir-senha/:token',
      name: 'reset-password',
      component: () => import('@/views/public/ResetPasswordView.vue'),
    },
    {
      path: '/convite/:token',
      name: 'accept-invite',
      component: () => import('@/views/public/AcceptInviteView.vue'),
    },
    {
      // Sem código, a tela pede o do rodapé; com código — o caminho do QR Code
      // impresso —, verifica sozinha (RF47).
      path: '/verificar/:codigo?',
      name: 'verify-document',
      component: () => import('@/views/public/VerifyDocumentView.vue'),
    },
    {
      // Sem token, a tela é a da espera pela confirmação; com token, confirma.
      path: '/verificar-email/:token?',
      name: 'verify-email',
      component: () => import('@/views/public/VerifyEmailView.vue'),
    },
    {
      // T18 — minha conta (RF04, RF06). Sem `area`: é a tela que os quatro
      // ambientes compartilham, e para onde apontam o avatar do cabeçalho e a
      // seção "Conta" de todas as molduras. A moldura desenhada é a de quem
      // chegou, resolvida por `RoleShell`.
      path: '/conta',
      name: 'account',
      component: () => import('@/views/AccountView.vue'),
      meta: { requerAutenticacao: true },
    },
    {
      // T17 — histórico de notificações (RF45), destino do sino do cabeçalho
      // do tutor. Diferente de `/conta`, tem área: o briefing a abre também ao
      // veterinário (RF45a), mas o âmbito dele é outro — autorização vigente,
      // não titularidade — e é fatia própria. Até lá, quem não é tutor recebe
      // E01 com a frase certa, e não uma tela que se monta para ouvir 403.
      path: '/conta/notificacoes/enviadas',
      name: 'tutor-notification-history',
      component: () => import('@/views/tutor/NotificationHistoryView.vue'),
      meta: { requerAutenticacao: true, area: 'tutor' },
    },
    {
      // T01 — primeira tela do tutor autenticado, e destino de `rota_inicial`
      // depois da entrada (P02).
      path: '/inicio',
      name: 'tutor-dashboard',
      component: () => import('@/views/tutor/DashboardView.vue'),
      meta: { requerAutenticacao: true, area: 'tutor' },
    },
    {
      // T02 — relação de animais do tutor (RF16).
      path: '/animais',
      name: 'tutor-animals',
      component: () => import('@/views/tutor/AnimalsView.vue'),
      meta: { requerAutenticacao: true, area: 'tutor' },
    },
    {
      // T10 — diretório de prestadores (RF11). Ponto de partida da autorização,
      // e por isso destino do atalho de T01 e do "Autorizar uma clínica" de
      // T12. O filtro viaja na query (`?nome=`, `?municipio=`, `?uf=`) para que
      // voltar de T11 devolva a mesma lista.
      path: '/prestadores',
      name: 'tutor-providers-directory',
      component: () => import('@/views/tutor/ProvidersDirectoryView.vue'),
      meta: { requerAutenticacao: true, area: 'tutor' },
    },
    {
      // T12 — minhas autorizações (RF41), com revogação (RF39) e renovação
      // (RF40c). Destino da seção "Compartilhamento" da moldura do tutor, e de
      // volta de T10 e T11 com `?prestador=`, que destaca a autorização que o
      // tutor veio ver ou acabou de conceder.
      path: '/autorizacoes',
      name: 'tutor-authorizations',
      component: () => import('@/views/tutor/AuthorizationsView.vue'),
      meta: { requerAutenticacao: true, area: 'tutor' },
    },
    {
      // T11 — conceder autorização (RF36, RF37). Chega de T10 com
      // `?prestador=`, que resolve o primeiro passo e mantém a concessão dentro
      // dos quatro passos contados da tela inicial (RNF14).
      path: '/autorizacoes/nova',
      name: 'tutor-grant-authorization',
      component: () => import('@/views/tutor/GrantAuthorizationView.vue'),
      meta: { requerAutenticacao: true, area: 'tutor' },
    },
    {
      // T13 — solicitações de acesso (RF38). O "Autorizar" daqui sai para T11
      // com `?prestador=` e `?animal=`, que resolvem os dois primeiros passos e
      // levam o tutor direto à confirmação por código.
      path: '/solicitacoes',
      name: 'tutor-access-requests',
      component: () => import('@/views/tutor/AccessRequestsView.vue'),
      meta: { requerAutenticacao: true, area: 'tutor' },
    },
    {
      // T14 — quem acessou meus dados (RF53). O recorte viaja na query
      // (`?animal=`, `?periodo=`, `?prestador=`) porque é assim que o "Ver
      // acessos" de cada cartão de T12 chega aqui já filtrado, e porque o botão
      // de voltar do navegador deve desfazer um filtro em vez de sair da tela.
      path: '/acessos',
      name: 'tutor-access-log',
      component: () => import('@/views/tutor/AccessLogView.vue'),
      meta: { requerAutenticacao: true, area: 'tutor' },
    },
    {
      // T03 — cadastrar animal (RF16, RF17). Declarada antes de
      // `/animais/:codigo`: a rota dinâmica casa com qualquer segmento, e
      // depois dela "novo" seria lido como código de animal.
      path: '/animais/novo',
      name: 'tutor-new-animal',
      component: () => import('@/views/tutor/AnimalCreateView.vue'),
      meta: { requerAutenticacao: true, area: 'tutor' },
    },
    {
      // T04 — perfil do animal (RF16, RF17, RF19).
      path: '/animais/:codigo',
      name: 'tutor-animal-profile',
      component: () => import('@/views/tutor/AnimalProfileView.vue'),
      meta: { requerAutenticacao: true, area: 'tutor' },
    },
    {
      // T04a — editar a identificação do animal (RF16, RN20). Tela própria, e
      // não modal sobre T04: o que ela altera é o cadastro inteiro — nome,
      // espécie e fotografia —, e o endereço precisa poder ser guardado e
      // recarregado como o de T03.
      path: '/animais/:codigo/editar',
      name: 'tutor-edit-animal',
      component: () => import('@/views/tutor/AnimalEditView.vue'),
      meta: { requerAutenticacao: true, area: 'tutor' },
    },
    {
      // T15 — a exportação é modal sobre T04, T05 e T07, não tela própria.
      // O endereço existiu nos botões antes de a fatia ser construída, e um
      // endereço que já circulou não pode virar "página não existe": ele leva
      // ao perfil com o modal aberto.
      path: '/animais/:codigo/exportar',
      redirect: (para) => ({
        path: `/animais/${para.params.codigo}`,
        query: { exportar: '1' },
      }),
    },
    {
      // T05 — carteira de vacinação digital (RF28, RF26).
      path: '/animais/:codigo/carteira',
      name: 'tutor-animal-vaccine-card',
      component: () => import('@/views/tutor/VaccineCardView.vue'),
      meta: { requerAutenticacao: true, area: 'tutor' },
    },
    {
      // T06 — detalhe da vacinação (RF25, RF30).
      path: '/animais/:codigo/vacinas/:id',
      name: 'tutor-vaccine-detail',
      component: () => import('@/views/tutor/VaccineDetailView.vue'),
      meta: { requerAutenticacao: true, area: 'tutor' },
    },
    {
      // T09 — lançar histórico pregresso não verificado (RF29).
      path: '/animais/:codigo/pregresso/novo',
      name: 'tutor-new-past-record',
      component: () => import('@/views/tutor/PastRecordView.vue'),
      meta: { requerAutenticacao: true, area: 'tutor' },
    },
    {
      // T07 — histórico consolidado (RF35, RF52).
      path: '/animais/:codigo/historico',
      name: 'tutor-animal-history',
      component: () => import('@/views/tutor/HistoryView.vue'),
      meta: { requerAutenticacao: true, area: 'tutor' },
    },
    {
      // T08 — detalhe do atendimento (RF31, RF32, RF33). A mesma rota serve ao
      // registro e à sua retificação: são dois atendimentos, e o encadeamento
      // entre eles é navegação de um para o outro (RF33b).
      path: '/animais/:codigo/atendimentos/:id',
      name: 'tutor-appointment-detail',
      component: () => import('@/views/tutor/AppointmentDetailView.vue'),
      meta: { requerAutenticacao: true, area: 'tutor' },
    },
    {
      // V01 — primeira tela do ambiente clínico, e destino de `rota_inicial`
      // de quem tem vínculo de veterinário (P02).
      path: '/clinica/painel',
      name: 'vet-dashboard',
      component: () => import('@/views/vet/DashboardView.vue'),
      meta: { requerAutenticacao: true, area: 'clinica' },
    },
    {
      // V02 — pendências vacinais (RF49). Destino do selo da barra lateral e do
      // indicador de doses vencidas de V01.
      path: '/clinica/pendencias',
      name: 'vet-pending-vaccines',
      component: () => import('@/views/vet/PendenciasView.vue'),
      meta: { requerAutenticacao: true, area: 'clinica' },
    },
    {
      // Relação de animais da clínica — o destino "Animais" da barra lateral
      // (§5.3), sem código de tela no briefing. Convive com `/clinica/animais/
      // :codigo` sem conflito: o roteador pontua o caminho estático acima do
      // dinâmico, e os dois têm profundidades diferentes.
      path: '/clinica/animais',
      name: 'vet-animals',
      component: () => import('@/views/vet/AnimaisView.vue'),
      meta: { requerAutenticacao: true, area: 'clinica' },
    },
    {
      // Relação de registros da clínica — o destino "Registros" da barra
      // lateral (§5.3), sem código de tela no briefing, como `/clinica/
      // animais` e pelo mesmo motivo. O âmbito é o inverso do daquela: decide
      // a autoria (RN40 — a revogação não alcança o que o próprio prestador
      // produziu), não a autorização vigente.
      path: '/clinica/registros',
      name: 'vet-clinical-records',
      component: () => import('@/views/vet/RegistrosView.vue'),
      meta: { requerAutenticacao: true, area: 'clinica' },
    },
    {
      // V03 — buscar animal ou tutor (RF51, RF18, RF13). Porta de entrada do
      // ambiente clínico: a moldura leva a ela pelo atalho `/`, pela barra
      // inferior do celular e pelas duas opções do botão "Registrar", porque
      // registro clínico é sempre de um animal determinado.
      path: '/clinica/buscar',
      name: 'vet-search',
      component: () => import('@/views/vet/BuscarView.vue'),
      meta: { requerAutenticacao: true, area: 'clinica' },
    },
    {
      // V04 — cadastrar tutor no atendimento (RF12, RF13, RF14). O endereço já
      // circulava nos botões de V01 e V03 antes de a fatia existir, como manda
      // o padrão da casa: o caminho definitivo primeiro, a tela depois.
      path: '/clinica/tutores/novo',
      name: 'vet-new-tutor',
      component: () => import('@/views/vet/CadastrarTutorView.vue'),
      meta: { requerAutenticacao: true, area: 'clinica' },
    },
    {
      // V10 — o pedido de autorização (RF38) é modal sobre a tela de origem,
      // não tela própria. O endereço circulou nos botões de V03, V04, V06 e
      // V07a antes de a fatia existir, e um endereço que já circulou não pode
      // virar "página não existe" (mesmo raciocínio de `/animais/:codigo/
      // exportar`): com `?animal=`, leva à ficha com o modal aberto; sem
      // contexto, leva à busca, que é onde o pedido nasce.
      path: '/clinica/autorizacoes/nova',
      redirect: (para) => (para.query.animal
        ? { path: `/clinica/animais/${para.query.animal}`, query: { solicitar: '1' } }
        : { path: '/clinica/buscar' }),
    },
    {
      // V05 — cadastrar animal no atendimento (RF16, RF19, RF20). Declarada
      // antes de `/clinica/animais/:codigo`, como `/animais/novo` do tutor e
      // pelo mesmo motivo: a rota dinâmica leria "novo" como código.
      path: '/clinica/animais/novo',
      name: 'vet-new-animal',
      component: () => import('@/views/vet/CadastrarAnimalView.vue'),
      meta: { requerAutenticacao: true, area: 'clinica' },
    },
    {
      // V05, modo consolidação (RF20b) — o endereço que a tarja "Cadastro
      // preliminar" de V06 sempre prometeu: completar a caracterização do
      // cadastro que o tutor iniciou, nunca criar um segundo (RN19).
      path: '/clinica/animais/:codigo/caracterizar',
      name: 'vet-characterize-animal',
      component: () => import('@/views/vet/CadastrarAnimalView.vue'),
      meta: { requerAutenticacao: true, area: 'clinica' },
    },
    {
      // V07a e V08a — escolher o animal antes de registrar. A ação viaja no
      // caminho, e não em `?registrar=`, porque ela decide a pergunta da tela e
      // a rota de destino: é matéria do endereço, não filtro dele. As duas são
      // uma tela só — o âmbito, o atalho e a recusa por falta de autorização
      // não mudam entre vacinar e atender.
      path: '/clinica/registrar/:acao(vacinacao|atendimento)',
      name: 'vet-choose-animal',
      component: () => import('@/views/vet/EscolherAnimalView.vue'),
      meta: { requerAutenticacao: true, area: 'clinica' },
    },
    {
      // V06 — ficha clínica do animal (RF19, RF35, RF52). Destino de V01, V02 e
      // V03, e o centro de trabalho do veterinário sobre um animal. A aba viaja
      // em `?aba=` para que V07 possa voltar direto à carteira depois de
      // registrar a aplicação.
      path: '/clinica/animais/:codigo',
      name: 'vet-animal-record',
      component: () => import('@/views/vet/FichaAnimalView.vue'),
      meta: { requerAutenticacao: true, area: 'clinica' },
    },
    {
      // T15 pela clínica — modal sobre V06, não tela própria (mesmo raciocínio
      // de `/animais/:codigo/exportar`). O endereço circulou no botão da ficha
      // antes de a fatia existir, e um endereço que já circulou não pode virar
      // "página não existe". O resto da query fica: `?prestador=` é o contexto
      // ativo, e perdê-lo no redirecionamento abriria a ficha de outra clínica.
      path: '/clinica/animais/:codigo/exportar',
      redirect: (para) => ({
        path: `/clinica/animais/${para.params.codigo}`,
        query: { ...para.query, exportar: '1' },
      }),
    },
    {
      // V12 — registrar óbito é modal sobre V06, não tela própria (mesmo
      // raciocínio do exportar acima). O endereço circulou no menu da ficha
      // antes de a fatia existir, e um endereço que já circulou não pode virar
      // "página não existe": ele leva à ficha com o modal aberto. O resto da
      // query fica pelo motivo de sempre — `?prestador=` é o contexto ativo, e
      // o óbito é carimbado no prestador que o registrou (RN27).
      path: '/clinica/animais/:codigo/obito',
      redirect: (para) => ({
        path: `/clinica/animais/${para.params.codigo}`,
        query: { ...para.query, obito: '1' },
      }),
    },
    {
      // V07 — registrar vacinação (RF25, RF26, RF27). O prestador ativo viaja em
      // `?prestador=` desde V06, e não é detalhe: o registro é imutável (RN26) e
      // não há retificação construída ainda, de modo que carimbá-lo com o
      // primeiro vínculo do profissional — em vez do que ele escolheu na faixa
      // de contexto — seria um erro sem conserto.
      path: '/clinica/animais/:codigo/vacinar',
      name: 'vet-register-vaccination',
      component: () => import('@/views/vet/RegistrarVacinacaoView.vue'),
      meta: { requerAutenticacao: true, area: 'clinica' },
    },
    {
      // V08 — registrar atendimento (RF31, RF32, RF34). Mesmo desenho de
      // endereço de V07, e pelo mesmo motivo: o prestador ativo viaja em
      // `?prestador=` desde V06, porque é ele que carimba um registro que RN26
      // torna imutável.
      path: '/clinica/animais/:codigo/atender',
      name: 'vet-register-appointment',
      component: () => import('@/views/vet/RegistrarAtendimentoView.vue'),
      meta: { requerAutenticacao: true, area: 'clinica' },
    },
    {
      // V09 — o registro clínico no ambiente do veterinário, e a retificação
      // (RF33). Duas rotas para a mesma tela, porque o que muda entre um
      // prontuário e uma aplicação de vacina é o conteúdo, não o que a tela faz
      // com ele: mostrar o que ficou gravado, mostrar o encadeamento quando há
      // duas versões (RF33b) e oferecer a correção a quem pode fazê-la (RN27).
      //
      // Não são T06 e T08 com outra moldura: aquelas partem da titularidade do
      // tutor e respondem 403 a quem escreveu o prontuário. É a leitura que a
      // decisão de V08 já anunciava ao mandar o sucesso do registro para a aba
      // Histórico de V06.
      path: '/clinica/animais/:codigo/atendimentos/:id',
      name: 'vet-clinical-record',
      component: () => import('@/views/vet/RegistroClinicoView.vue'),
      meta: { requerAutenticacao: true, area: 'clinica' },
    },
    {
      path: '/clinica/animais/:codigo/vacinas/:id',
      name: 'vet-vaccination-record',
      component: () => import('@/views/vet/RegistroClinicoView.vue'),
      meta: { requerAutenticacao: true, area: 'clinica' },
    },
    {
      // X01 — catálogo de imunobiológicos (RF23). Destino de `rota_inicial`
      // de quem tem o papel `admin_plataforma` (P02), papel global sem
      // vínculo de prestador nem cadastro de tutor.
      path: '/plataforma/catalogo',
      name: 'plataforma-catalogo',
      component: () => import('@/views/plataforma/CatalogoImunobiologicosView.vue'),
      meta: { requerAutenticacao: true, area: 'plataforma' },
    },
    {
      // X02 — versões do cálculo de calendário (RF24). Chega da navegação da
      // plataforma e de X01, pela ligação "Protocolos que usam este
      // imunobiológico". É tela de desktop: o simulador é uma tabela de três
      // colunas, e o briefing (§8.5) dispensa a otimização para celular.
      path: '/plataforma/protocolos',
      name: 'plataforma-protocolos',
      component: () => import('@/views/plataforma/ProtocolosVacinaisView.vue'),
      meta: { requerAutenticacao: true, area: 'plataforma' },
    },
    {
      // A01 — painel administrativo do prestador (RF07, RF08, RF09). Destino de
      // `rota_inicial` de quem tem o papel `admin_prestador` (P02) — o caminho
      // que o cadastro de P03 já apontava e que até aqui caía em E02.
      path: '/prestador',
      name: 'prestador-dashboard',
      component: () => import('@/views/prestador/DashboardView.vue'),
      meta: { requerAutenticacao: true, area: 'prestador' },
    },
    {
      // A02 — dados cadastrais do prestador (RF08). Chega de A01, tanto pela
      // navegação quanto pelas pendências de configuração, que apontam para as
      // âncoras `#endereco` e `#responsavel-tecnico`.
      path: '/prestador/dados',
      name: 'prestador-dados',
      component: () => import('@/views/prestador/ProviderDataView.vue'),
      meta: { requerAutenticacao: true, area: 'prestador' },
    },
    {
      // A03 — equipe do prestador (RF09, RF10). `?convidar=1` abre o modal de
      // convite já na chegada, que é como a pendência "nenhum veterinário
      // ativo" de A01 leva direto à ação que a resolve.
      path: '/prestador/equipe',
      name: 'prestador-equipe',
      component: () => import('@/views/prestador/TeamView.vue'),
      meta: { requerAutenticacao: true, area: 'prestador' },
    },
    {
      // A04 — as vacinas da clínica (RF23, RN30). Convive com A02 e A03 sob o
      // mesmo prefixo e o mesmo papel, e é a primeira tela desta área que toca
      // em cálculo: o que se define aqui vira a data que o tutor vê na
      // carteira (RF26).
      //
      // Cabe sob RN08 porque não alcança tutor, animal nem registro clínico —
      // o item de catálogo é do estabelecimento, e não do paciente.
      path: '/prestador/vacinas',
      name: 'prestador-vacinas',
      component: () => import('@/views/prestador/VaccinesView.vue'),
      meta: { requerAutenticacao: true, area: 'prestador' },
    },
    {
      // E01 — sem permissão. Rota única para todas as áreas: a que foi recusada
      // viaja em `?area=`, e é dela que sai a frase da tela. O endereço tentado
      // não entra na consulta — repeti-lo na barra do navegador não ajudaria
      // quem já não pode abri-lo, e deixaria rastro de um caminho alheio.
      path: '/sem-acesso',
      name: 'forbidden',
      component: () => import('@/views/ForbiddenView.vue'),
    },
    {
      // E02 — cobre também os painéis ainda não construídos, para os quais a
      // autenticação já sabe encaminhar.
      path: '/:caminho(.*)*',
      name: 'not-found',
      component: () => import('@/views/NotFoundView.vue'),
    },
  ],

  /**
   * Navegar para uma página nova começa por ela, não pela altura em que a
   * anterior tinha sido rolada. Só passou a importar com P10 e P11, que são as
   * primeiras telas altas o bastante para que a diferença apareça: sair do
   * rodapé dos termos e chegar ao meio da política de privacidade.
   *
   * Voltar pelo histórico é o caso oposto — quem volta espera reencontrar o
   * ponto de onde saiu, e `posicaoSalva` é justamente esse ponto.
   */
  scrollBehavior(para, de, posicaoSalva) {
    return posicaoSalva ?? { top: 0 }
  },
})

/**
 * RN01 — nenhum dado do sistema é alcançável sem autenticação. A verificação do
 * servidor continua sendo a que vale; esta apenas evita que a tela protegida
 * chegue a ser desenhada para quem já não tem sessão.
 *
 * A segunda parte faz o mesmo com o papel (RNF09): quem não tem o papel que
 * abre a área vai para E01 em vez de assistir a uma tela montar-se para pedir
 * ao servidor dados que ele vai recusar, e terminar num aviso de erro que não
 * explica coisa alguma. A recusa continua sendo a do servidor — esta é apenas a
 * sua tradução para quem está olhando.
 */
router.beforeEach(async (para) => {
  if (!para.meta.requerAutenticacao) return true

  const sessao = useSessaoStore()
  await sessao.carregar()

  if (!sessao.autenticado) return { name: 'login' }

  const area = areaPorNome(para.meta.area)
  if (area && !(sessao.usuario.papeis ?? []).includes(area.papel)) {
    return { name: 'forbidden', query: { area: area.nome }, replace: true }
  }

  return true
})

/**
 * E03 — falha do sistema, na versão em que ela impede a própria navegação: o
 * módulo da tela que não chegou (rede caída no meio de um `import()`, versão
 * nova publicada enquanto a aba estava aberta). O erro sobe para `App.vue`, que
 * troca o conteúdo pela tela de exceção sem mexer no endereço — o "Tentar de
 * novo" precisa recarregar esta rota, e não outra.
 */
router.onError((excecao) => {
  relatarFalha(excecao)
})

export default router
