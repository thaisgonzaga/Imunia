<?php

use App\Http\Controllers\AnimaisDaClinicaController;
use App\Http\Controllers\AnimalController;
use App\Http\Controllers\AtendimentoController;
use App\Http\Controllers\Auth\ContaController;
use App\Http\Controllers\Auth\ConviteController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\AutorizacaoController;
use App\Http\Controllers\BuscaClinicaController;
use App\Http\Controllers\CadastroDeAnimalController;
use App\Http\Controllers\CadastroDeTutorController;
use App\Http\Controllers\CarteiraVacinacaoController;
use App\Http\Controllers\CatalogoImunobiologicosController;
use App\Http\Controllers\DadosPrestadorController;
use App\Http\Controllers\DiretorioPrestadoresController;
use App\Http\Controllers\EquipePrestadorController;
use App\Http\Controllers\EscolhaDeAnimalController;
use App\Http\Controllers\ExportacaoController;
use App\Http\Controllers\ExportacaoPelaClinicaController;
use App\Http\Controllers\FichaClinicaController;
use App\Http\Controllers\HistoricoConsolidadoController;
use App\Http\Controllers\HistoricoDeNotificacoesController;
use App\Http\Controllers\HistoricoPregressoController;
use App\Http\Controllers\LivroDeAcessosController;
use App\Http\Controllers\MinhasAutorizacoesController;
use App\Http\Controllers\PainelPrestadorController;
use App\Http\Controllers\PainelTutorController;
use App\Http\Controllers\PainelVeterinarioController;
use App\Http\Controllers\PedidoDeAutorizacaoController;
use App\Http\Controllers\PendenciasVacinaisController;
use App\Http\Controllers\PrestadorController;
use App\Http\Controllers\ProtocolosVacinaisController;
use App\Http\Controllers\RegistroClinicoController;
use App\Http\Controllers\RegistroDeAtendimentoController;
use App\Http\Controllers\RegistroDeObitoController;
use App\Http\Controllers\RegistroDeVacinacaoController;
use App\Http\Controllers\RegistrosDaClinicaController;
use App\Http\Controllers\SolicitacoesAcessoController;
use App\Http\Controllers\TutorController;
use App\Http\Controllers\VacinasPrestadorController;
use App\Http\Controllers\VerificacaoDocumentoController;
use Illuminate\Support\Facades\Route;

Route::post('/prestadores', [PrestadorController::class, 'store']);
Route::post('/tutores', [TutorController::class, 'store']);

// P09 — verificação pública do documento exportado (RF47). Única rota aberta
// do sistema, ressalva expressa de RN01.
Route::get('/documentos/{codigo}', [VerificacaoDocumentoController::class, 'show']);

// P02 — sessão por cookie httpOnly (RF01, RF02, RNF08).
Route::post('/sessao', [SessionController::class, 'store']);
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/sessao', [SessionController::class, 'show']);
    Route::delete('/sessao', [SessionController::class, 'destroy']);

    // T18 — minha conta (RF04, RF06). Sem área: é a tela que os quatro
    // ambientes compartilham, porque a conta é anterior ao papel.
    Route::get('/conta', [ContaController::class, 'show']);
    Route::post('/conta', [ContaController::class, 'update']);
    Route::post('/conta/senha', [ContaController::class, 'atualizarSenha']);

    // T01 — painel do tutor (RF50). Sob autenticação, como tudo o que não seja
    // a verificação pública de documento (RN01).
    Route::get('/tutor/painel', [PainelTutorController::class, 'show']);

    // V01 — painel do veterinário (RF48). Primeira rota do ambiente clínico: o
    // âmbito é o do prestador ativo (RF48a) e o das autorizações vigentes
    // (RN48), nunca o do usuário autenticado sozinho.
    Route::get('/clinica/painel', [PainelVeterinarioController::class, 'show']);

    // V02 — painel de pendências vacinais (RF49). Mesmo âmbito de V01, e é dele
    // que vem a garantia de RF49c: animal sem autorização vigente não figura no
    // resultado. A exportação (RF49b) repete os filtros da tela para que o
    // arquivo da rechamada seja conferível contra ela.
    Route::get('/clinica/pendencias', [PendenciasVacinaisController::class, 'index']);
    Route::get('/clinica/pendencias/exportar', [PendenciasVacinaisController::class, 'exportar']);

    // Relação de animais da clínica — o destino "Animais" da barra lateral
    // (§5.3), sem código de tela no briefing. Mesmo âmbito de V01 e V02
    // (RN48); a lista navega o plantel, e quem procura um animal determinado
    // continua indo a V03, que é quem sabe responder sobre o que está fora.
    Route::get('/clinica/animais', [AnimaisDaClinicaController::class, 'index']);

    // Relação de registros da clínica — o destino "Registros" da barra
    // lateral (§5.3), sem código de tela no briefing, como a relação de
    // animais. O âmbito é o inverso do dela: decide a autoria (RN40 — a
    // revogação não alcança o que o próprio prestador produziu), não a
    // autorização vigente. É a única listagem do ambiente clínico em que um
    // animal fora do âmbito de RN48 figura — pelos registros que este
    // prestador assinou, e só por eles.
    Route::get('/clinica/registros', [RegistrosDaClinicaController::class, 'index']);

    // V03 — buscar animal ou tutor (RF51, RF18, RF13). Diferente de V01 e V02,
    // esta rota responde também sobre o que está fora do âmbito de autorização
    // — e é justamente por isso que ela responde tão pouco: só a existência do
    // cadastro (RN12), e registrando a consulta em log (RF18b).
    Route::get('/clinica/buscar', [BuscaClinicaController::class, 'index']);

    // V04 — cadastrar tutor no atendimento (RF12, RF13, RF14). A verificação
    // de CPF que precede o formulário não tem rota própria: é a busca de V03,
    // que já responde "novo", "existe fora do âmbito" ou "já autorizado" — e
    // já registra a revelação de existência. Com o mesmo limite de frequência
    // do convite de equipe, porque o sucesso dispara e-mail.
    Route::post('/clinica/tutores', [CadastroDeTutorController::class, 'store'])
        ->middleware('throttle:6,1');

    // V10 — solicitar autorização ao tutor (RF38). A única escrita do ambiente
    // clínico sobre cadastro que não é seu, e ela não escreve acesso: escreve
    // um pedido, que só vira acesso pelo fluxo de T11, com código (RF37). O
    // alvo viaja como termo — o mesmo vocabulário de V03 —, porque a busca não
    // entrega id de cadastro alheio (RN12). Com limite de frequência, porque o
    // sucesso dispara e-mail ao tutor.
    Route::post('/clinica/solicitacoes', [PedidoDeAutorizacaoController::class, 'store'])
        ->middleware('throttle:6,1');

    // V07a e V08a — escolher o animal antes de registrar. Divide com V03 a
    // busca e o âmbito, e acrescenta o atalho dos últimos atendidos; o que a
    // separa daquela rota é o propósito de quem chega, e é ele que decide o que
    // a resposta traz.
    Route::get('/clinica/registrar', [EscolhaDeAnimalController::class, 'index']);

    // V05 — cadastrar animal no atendimento (RF16, RF19, RF20). A criação e a
    // consolidação são o mesmo ato profissional por duas portas: `store` cria
    // com identificação e caracterização de uma vez; `caracterizar` completa o
    // cadastro preliminar do tutor sem jamais criar um segundo (RN19). O tutor
    // do cadastro novo entra por CPF — a chave do balcão —, e o cadastro
    // criado não entra no âmbito do prestador: autorização é ato do tutor.
    Route::post('/clinica/animais', [CadastroDeAnimalController::class, 'store']);
    Route::get('/clinica/animais/{codigo}/caracterizar', [CadastroDeAnimalController::class, 'opcoes']);
    Route::post('/clinica/animais/{codigo}/caracterizar', [CadastroDeAnimalController::class, 'caracterizar']);

    // V06 — ficha clínica do animal (RF19, RF35, RF52). O destino de todo
    // caminho do ambiente clínico que chega a um animal determinado, e a
    // primeira rota que grava acesso por *ver* — e não por buscar. A resposta
    // vem inteira porque as abas trocam sem recarregar, e porque a gravação do
    // log é condição da exibição (RF52b), não um segundo momento dela.
    Route::get('/clinica/animais/{codigo}', [FichaClinicaController::class, 'show']);

    // RF32c — o anexo do ambiente clínico. Rota própria, e não a de T08, porque
    // a verificação é outra: lá o âmbito é a titularidade do tutor; aqui, a
    // autorização vigente do prestador ativo.
    Route::get('/clinica/animais/{codigo}/anexos/{anexo}', [FichaClinicaController::class, 'anexo']);

    // V07 — registrar vacinação (RF25, RF26, RF27). A primeira escrita de
    // registro clínico profissional do sistema.
    //
    // São duas leituras, e não uma, por causa do livro de acessos: `show` abre a
    // tela e grava a linha de RN49 quando há registro de outro prestador a
    // considerar; `previa` recalcula a cada campo alterado e não grava nada.
    // Fundidas, uma vacinação de noventa segundos encheria de linhas repetidas o
    // livro que RF53 promete legível ao tutor.
    //
    // Não há PUT, PATCH nem DELETE, e a ausência é a regra: RN26 torna o
    // registro imutável, e a correção é a retificação vinculada de V09.
    Route::get('/clinica/animais/{codigo}/vacinar', [RegistroDeVacinacaoController::class, 'show']);
    Route::get('/clinica/animais/{codigo}/vacinar/previa', [RegistroDeVacinacaoController::class, 'previa']);
    Route::post('/clinica/animais/{codigo}/vacinar', [RegistroDeVacinacaoController::class, 'store']);

    // V08 — registrar atendimento (RF31, RF32, RF34). O anexo tem rota própria e
    // é enviado **antes** da confirmação, um arquivo por pedido: é o que dá
    // progresso por arquivo e recusa imediata por formato ou tamanho (RN28), e é
    // o que faz o erro de gravação poder prometer que o que já subiu continua
    // lá. Até a confirmação o arquivo é rascunho, sem linha em
    // `anexos_atendimento` — anexo sem registro clínico seria exatamente o que
    // RN28 não admite.
    //
    // Aqui também não há PUT, PATCH nem DELETE de atendimento. O `DELETE` desta
    // lista descarta rascunho de anexo, que ainda não é anexo de coisa alguma.
    Route::get('/clinica/animais/{codigo}/atender', [RegistroDeAtendimentoController::class, 'show']);
    Route::post('/clinica/animais/{codigo}/atender', [RegistroDeAtendimentoController::class, 'store']);
    Route::post('/clinica/animais/{codigo}/atender/anexos', [RegistroDeAtendimentoController::class, 'anexar']);
    Route::delete(
        '/clinica/animais/{codigo}/atender/anexos/{token}',
        [RegistroDeAtendimentoController::class, 'descartarAnexo'],
    );

    // V12 — registrar óbito (RF22). Um POST só: o formulário é modal sobre a
    // ficha, que já carrega tudo o que ele precisa exibir, e não há GET a
    // servir. Também não há PUT, PATCH nem DELETE — RF22c torna o registro
    // permanente, e a correção é a retificação na forma de RF33.
    Route::post('/clinica/animais/{codigo}/obito', [RegistroDeObitoController::class, 'store']);

    // V09 — o registro clínico visto pelo veterinário, e a sua retificação
    // (RF33, RN26, RN27).
    //
    // As duas leituras existem porque T06 e T08 são telas do **tutor**: partem
    // da titularidade e respondem 403 a quem escreveu o prontuário. É o que a
    // decisão de V08 já anotava ao mandar o sucesso para a aba Histórico de V06.
    //
    // As duas escritas são `POST`, e não `PUT` nem `PATCH`, porque não alteram
    // registro algum: cada uma **cria** a versão corrigida, que aponta para a
    // que corrige. Não há aqui, nem em rota alguma do sistema, verbo que
    // sobrescreva ou apague registro clínico confirmado — é RF33a, e a sua
    // prova é esta ausência.
    Route::get(
        '/clinica/animais/{codigo}/atendimentos/{atendimento}',
        [RegistroClinicoController::class, 'atendimento'],
    );
    Route::post(
        '/clinica/animais/{codigo}/atendimentos/{atendimento}/retificar',
        [RegistroClinicoController::class, 'retificarAtendimento'],
    );
    Route::get(
        '/clinica/animais/{codigo}/vacinas/{vacinacao}',
        [RegistroClinicoController::class, 'vacinacao'],
    );
    Route::post(
        '/clinica/animais/{codigo}/vacinas/{vacinacao}/retificar',
        [RegistroClinicoController::class, 'retificarVacinacao'],
    );

    // V06 → T15 — exportar pelo ambiente clínico (RF46 nomeia o veterinário
    // como ator). O mesmo modal e o mesmo documento de T15, por outra porta,
    // porque a verificação é outra: lá, titularidade do tutor (404 para animal
    // alheio, RN12); aqui, autorização vigente do prestador ativo (403 que
    // nomeia o caminho). A emissão cujo recorte leva registro de outro
    // prestador grava a linha de RN49 antes de existir, e o download reverifica
    // a autorização a cada pedido, como o anexo de RF32c.
    Route::post(
        '/clinica/animais/{codigo}/exportacoes',
        [ExportacaoPelaClinicaController::class, 'store'],
    );
    Route::get(
        '/clinica/animais/{codigo}/exportacoes/{emissao}/documento',
        [ExportacaoPelaClinicaController::class, 'documento'],
    );

    // T10 — diretório de prestadores (RF11). Mesmo caminho do cadastro público
    // de prestador (P03), verbo diferente e propósito oposto: aquele cria o
    // estabelecimento sem sessão alguma, este lista para o tutor autenticado o
    // que há de público a respeito dos já cadastrados (RF11a).
    Route::get('/prestadores', [DiretorioPrestadoresController::class, 'index']);

    // T11 — conceder autorização (RF36, RF37). Três rotas para os três atos do
    // fluxo: o que a tela precisa saber, o pedido do código e a confirmação
    // que efetivamente concede. Nada é autorizado antes da última.
    Route::get('/autorizacoes/nova', [AutorizacaoController::class, 'opcoes']);
    Route::post('/autorizacoes/confirmacoes', [AutorizacaoController::class, 'store']);
    Route::post('/autorizacoes/confirmacoes/{confirmacao}', [AutorizacaoController::class, 'confirmar']);
    Route::post(
        '/autorizacoes/confirmacoes/{confirmacao}/reenviar',
        [AutorizacaoController::class, 'reenviar'],
    );

    // T12 — minhas autorizações (RF41). A relação e os dois atos que ela
    // oferece: revogar (RF39) e renovar (RF40c). Nenhum deles pede código —
    // desfazer não pode custar mais do que fazer, e a renovação repousa sobre o
    // consentimento já manifestado uma vez.
    Route::get('/autorizacoes', [MinhasAutorizacoesController::class, 'index']);
    Route::delete('/autorizacoes/{autorizacao}', [MinhasAutorizacoesController::class, 'destroy']);
    Route::post('/autorizacoes/{autorizacao}/renovar', [MinhasAutorizacoesController::class, 'renovar']);

    // T13 — solicitações de acesso (RF38). Nenhuma rota daqui concede coisa
    // alguma: o "Autorizar" da tela vai para o fluxo de T11, com código, e o
    // que este controlador oferece é a leitura dos pedidos e a recusa deles.
    // O contador tem rota própria porque a moldura do tutor o consulta em toda
    // entrada no ambiente.
    Route::get('/solicitacoes', [SolicitacoesAcessoController::class, 'index']);
    Route::get('/solicitacoes/pendentes', [SolicitacoesAcessoController::class, 'pendentes']);
    Route::post('/solicitacoes/{solicitacao}/recusar', [SolicitacoesAcessoController::class, 'recusar']);

    // T14 — quem acessou meus dados (RF53). Só leitura: o log é imutável
    // (RF52a), e o titular dos dados não é exceção à regra — é a razão dela.
    // A revogação que cada linha oferece (RF53b) é a rota de T12, e não uma
    // segunda: o efeito é o mesmo e o texto que o tutor lê antes de confirmar
    // também tem de ser (RF39d).
    Route::get('/acessos', [LivroDeAcessosController::class, 'index']);

    // T17 — histórico de notificações (RF45). O destino do sino do cabeçalho
    // do tutor. Só leitura: o registro é a fonte de verdade de RN43.
    Route::get('/conta/notificacoes/enviadas', [HistoricoDeNotificacoesController::class, 'index']);

    // T02 — relação de animais do tutor (RF16).
    Route::get('/animais', [AnimalController::class, 'index']);

    // T03 — cadastrar animal (RF16, RF17, RF20a). A única escrita do tutor
    // sobre o próprio animal: identificação, e nada de caracterização (RN18).
    Route::post('/animais', [AnimalController::class, 'store']);

    // T04 — perfil do animal (RF16, RF17, RF19).
    Route::get('/animais/{codigo}', [AnimalController::class, 'show']);

    // RF16b, RN20 — a fotografia sobe por rota própria, e o mesmo verbo a
    // substitui a qualquer tempo.
    Route::post('/animais/{codigo}/foto', [AnimalController::class, 'foto']);

    // T05 — carteira de vacinação digital (RF28, RF26).
    Route::get('/animais/{codigo}/carteira', [CarteiraVacinacaoController::class, 'show']);

    // T06 — detalhe da vacinação (RF25, RF30).
    Route::get('/animais/{codigo}/vacinas/{vacinacao}', [CarteiraVacinacaoController::class, 'aplicacao']);

    // T09 — histórico pregresso não verificado (RF29). A primeira escrita de
    // registro clínico do sistema, e a única que não é ato clínico (RN25).
    Route::get('/animais/{codigo}/pregresso', [HistoricoPregressoController::class, 'opcoes']);
    Route::post('/animais/{codigo}/pregresso', [HistoricoPregressoController::class, 'store']);

    // T07 — histórico consolidado (RF35, RF52).
    Route::get('/animais/{codigo}/historico', [HistoricoConsolidadoController::class, 'show']);

    // T15 — exportar o histórico em PDF verificável (RF46). A emissão cria a
    // linha que a verificação pública de P09 consulta (RF47); o download
    // entrega o arquivo gravado na emissão, nunca uma segunda geração.
    Route::post('/animais/{codigo}/exportacoes', [ExportacaoController::class, 'store']);
    Route::get(
        '/animais/{codigo}/exportacoes/{emissao}/documento',
        [ExportacaoController::class, 'documento'],
    );

    // T08 — detalhe do atendimento (RF31, RF32, RF33).
    Route::get('/animais/{codigo}/atendimentos/{atendimento}', [AtendimentoController::class, 'show']);

    // RF32c — o anexo tem rota própria, sob a mesma autenticação, porque o
    // arquivo não é alcançável por endereço direto do armazenamento.
    Route::get(
        '/animais/{codigo}/atendimentos/{atendimento}/anexos/{anexo}',
        [AtendimentoController::class, 'anexo'],
    );

    // A01 — painel administrativo do prestador (RF07, RF08, RF09). O caminho é
    // `/prestador/painel`, e não `/prestador`, pela mesma simetria de
    // `/tutor/painel` e `/clinica/painel` — e porque `/prestadores`, no plural,
    // já é o diretório de T10 e o cadastro de P03.
    //
    // Nada sob este prefixo alcança tutor, animal ou registro clínico: RN08
    // separa a administração da conta do acesso ao dado, e a separação é o
    // argumento de projeto destas três telas.
    Route::get('/prestador/painel', [PainelPrestadorController::class, 'show']);

    // A02 — dados cadastrais do prestador (RF08). Escrita por POST, como toda
    // atualização do sistema.
    Route::get('/prestador/dados', [DadosPrestadorController::class, 'show']);
    Route::post('/prestador/dados', [DadosPrestadorController::class, 'update']);

    // A03 — equipe do prestador (RF09, RF10). O convite e o reenvio disparam
    // e-mail, e por isso são os únicos desta fatia com limite de frequência: a
    // tela de equipe não pode virar um disparador.
    //
    // O encerramento responde a `DELETE` embora não remova linha alguma, pelo
    // mesmo motivo de `DELETE /autorizacoes/{autorizacao}`: o que se encerra é
    // a relação, e o registro que a comprova permanece (RF10b).
    Route::get('/prestador/equipe', [EquipePrestadorController::class, 'index']);
    Route::post('/prestador/equipe', [EquipePrestadorController::class, 'store'])
        ->middleware('throttle:6,1');
    Route::post('/prestador/equipe/{vinculo}/reenviar', [EquipePrestadorController::class, 'reenviar'])
        ->middleware('throttle:6,1');
    Route::delete('/prestador/equipe/{vinculo}', [EquipePrestadorController::class, 'encerrar']);

    // A administração da conta concedida a quem já atende aqui. O par
    // `POST`/`DELETE` sobre o mesmo caminho é deliberado: o que se cria e se
    // encerra é o vínculo administrativo, um recurso — e não um campo do
    // profissional, que um `PATCH` no vínculo sugeriria.
    Route::post('/prestador/equipe/{vinculo}/administracao', [EquipePrestadorController::class, 'conceder']);
    Route::delete('/prestador/equipe/{vinculo}/administracao', [EquipePrestadorController::class, 'revogar']);

    // A04 — as vacinas da clínica (RF23, RN30). O catálogo da plataforma
    // aparece aqui em leitura travada, e o que a clínica cadastra fica sob o
    // seu `prestador_id`: invisível às demais e fora do alcance de X01.
    //
    // Não há `DELETE`, pelo mesmo motivo de X01 (RF23b): inativa-se, nunca se
    // exclui — a aplicação já registrada aponta para o item, e o histórico do
    // animal não é apagável por decisão administrativa.
    //
    // A prévia responde a `POST` e não grava nada, como o simulador de X02: o
    // verbo está aqui pelo tamanho da entrada, não por efeito.
    Route::get('/prestador/vacinas', [VacinasPrestadorController::class, 'index']);
    Route::post('/prestador/vacinas/previa', [VacinasPrestadorController::class, 'previa']);
    Route::post('/prestador/vacinas', [VacinasPrestadorController::class, 'store']);
    Route::post('/prestador/vacinas/{imunobiologico}', [VacinasPrestadorController::class, 'update']);
    Route::post('/prestador/vacinas/{imunobiologico}/inativar', [VacinasPrestadorController::class, 'inativar']);
    Route::post('/prestador/vacinas/{imunobiologico}/reativar', [VacinasPrestadorController::class, 'reativar']);

    // X01 — catálogo de imunobiológicos (RF23). Ator é a administração da
    // plataforma, papel global sem vínculo de prestador — a checagem de
    // `admin_plataforma` fica na FormRequest e no controlador, e não nesta
    // rota, porque a leitura (`index`) não passa por FormRequest alguma.
    Route::get('/plataforma/catalogo', [CatalogoImunobiologicosController::class, 'index']);
    Route::post('/plataforma/catalogo', [CatalogoImunobiologicosController::class, 'store']);
    Route::post('/plataforma/catalogo/{imunobiologico}', [CatalogoImunobiologicosController::class, 'update']);
    Route::post(
        '/plataforma/catalogo/{imunobiologico}/inativar',
        [CatalogoImunobiologicosController::class, 'inativar'],
    );
    Route::post(
        '/plataforma/catalogo/{imunobiologico}/reativar',
        [CatalogoImunobiologicosController::class, 'reativar'],
    );

    // X02 — versões do cálculo de calendário (RF24). Mesma porta de X01, e a
    // rota que responde à pergunta previsível da banca: quando a diretriz muda,
    // publica-se uma versão, e o código fica onde está. A simulação não grava
    // nada — responde a POST pelo tamanho das entradas, não por efeito.
    Route::get('/plataforma/protocolos', [ProtocolosVacinaisController::class, 'index']);
    Route::post('/plataforma/protocolos/simular', [ProtocolosVacinaisController::class, 'simular']);
    Route::post('/plataforma/protocolos/versoes', [ProtocolosVacinaisController::class, 'store']);
    Route::post(
        '/plataforma/protocolos/versoes/{versao}/parametros',
        [ProtocolosVacinaisController::class, 'salvarParametros'],
    );
    Route::post(
        '/plataforma/protocolos/versoes/{versao}/publicar',
        [ProtocolosVacinaisController::class, 'publicar'],
    );
    Route::delete('/plataforma/protocolos/versoes/{versao}', [ProtocolosVacinaisController::class, 'descartar']);
});

// P05 e P06 — redefinição de senha esquecida (RF03).
Route::post('/senha/recuperar', [PasswordResetController::class, 'store']);
Route::get('/senha/redefinir/{token}', [PasswordResetController::class, 'show']);
Route::post('/senha/redefinir', [PasswordResetController::class, 'update']);

// P08 — confirmação do endereço de e-mail (RF05).
Route::post('/email/verificar/{token}', [EmailVerificationController::class, 'update']);
Route::post('/email/reenviar', [EmailVerificationController::class, 'store']);

// P07 — ativação de acesso por convite (RF09, RF14).
Route::get('/convites/{token}', [ConviteController::class, 'show']);
Route::post('/convites/{token}', [ConviteController::class, 'store']);
Route::post('/convites/{token}/reenviar', [ConviteController::class, 'reenviar'])
    ->middleware('throttle:3,10');
