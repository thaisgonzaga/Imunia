<script setup>
import LegalDocument from '@/components/public/LegalDocument.vue'

/**
 * P11 — política de privacidade. A caixa de aceite de P05 aponta para cá desde a
 * primeira versão daquela tela; até agora o destino não existia, e o clique
 * caía em "não encontrado" (E02).
 *
 * O texto de referência está em `docs/imunia-politica-de-privacidade.md`, e as duas
 * cópias precisam andar juntas. Os campos entre colchetes ficam visíveis de
 * propósito: enquanto não houver pessoa jurídica constituída, inventar razão
 * social seria fabricar identidade que não existe.
 */
</script>

<template>
  <LegalDocument
    titulo="Política de Privacidade"
    resumo="Como o Imunia trata dados pessoais, com que fundamento e por quanto tempo."
  >
    <div class="legal-destaque">
      <span class="legal-destaque__rotulo">Nota preliminar de escopo</span>
      <p>
        Esta política descreve o Imunia <strong>tal como está implementado</strong>. Onde uma
        funcionalidade ainda não existe, o texto diz que não existe, em vez de prometê-la.
        Onde a lei assegura um direito que o sistema ainda não automatizou, o texto informa
        o canal humano pelo qual esse direito é exercido.
      </p>
      <p>
        Os campos entre colchetes só podem ser preenchidos por quem responder juridicamente
        pela operação. Enquanto o Imunia for um protótipo acadêmico sem pessoa jurídica
        constituída, esta política descreve o tratamento de dados de um sistema em
        desenvolvimento, não de um serviço em operação comercial.
      </p>
    </div>

    <h2>1. Quem responde pelos seus dados</h2>
    <p>
      O responsável pelo tratamento é <span class="legal-campo">[RAZÃO SOCIAL]</span>,
      inscrita no CNPJ sob o nº <span class="legal-campo">[CNPJ]</span>, com sede em
      <span class="legal-campo">[ENDEREÇO]</span>, doravante "Imunia".
    </p>

    <h3>1.1 Nem todo dado do sistema tem o mesmo responsável</h3>
    <p>
      O Imunia é usado por dois grupos distintos: tutores de animais e estabelecimentos
      veterinários. Para partes diferentes dos dados, o responsável é diferente. Saber quem
      responde pelo quê é o que permite a você dirigir um pedido à pessoa certa.
    </p>
    <div class="legal-tabela">
      <table>
        <thead>
          <tr>
            <th>Conjunto de dados</th>
            <th>Quem decide</th>
            <th>Papel do Imunia</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>Conta, e-mail, senha, autenticação e sessões</td>
            <td>Imunia</td>
            <td><strong>Controlador</strong></td>
          </tr>
          <tr>
            <td>Cadastro do tutor e do animal feito pelo próprio tutor</td>
            <td>Imunia</td>
            <td><strong>Controlador</strong></td>
          </tr>
          <tr>
            <td>Autorizações, solicitações e registros de acesso</td>
            <td>Imunia</td>
            <td><strong>Controlador</strong></td>
          </tr>
          <tr>
            <td>Comunicações enviadas pelo sistema</td>
            <td>Imunia</td>
            <td><strong>Controlador</strong></td>
          </tr>
          <tr>
            <td>Conteúdo clínico — atendimentos, vacinações, anexos e laudos</td>
            <td>O estabelecimento que registrou</td>
            <td><strong>Operador</strong></td>
          </tr>
          <tr>
            <td>Cadastro de tutor e de animal criado por um estabelecimento</td>
            <td>O estabelecimento que criou</td>
            <td><strong>Operador</strong></td>
          </tr>
          <tr>
            <td>Dados cadastrais do estabelecimento e de sua equipe</td>
            <td>Imunia</td>
            <td><strong>Controlador</strong></td>
          </tr>
        </tbody>
      </table>
    </div>
    <p>
      Quando o Imunia atua como <strong>operador</strong>, trata os dados seguindo as
      instruções do estabelecimento e <strong>não os utiliza para finalidade própria</strong>
      — não os vende, não os cede, não os usa para publicidade, não os usa para treinar
      modelos e não os cruza entre estabelecimentos fora do mecanismo de autorização
      descrito na seção 6.
    </p>

    <h3>1.2 Encarregado pela proteção de dados</h3>
    <p>
      <span class="legal-campo">[NOME DO ENCARREGADO]</span> —
      <span class="legal-campo">[E-MAIL DO ENCARREGADO]</span>
    </p>
    <p>
      O encarregado é o canal entre você, o Imunia e a Agência Nacional de Proteção de Dados
      (ANPD). É a ele que você dirige pedidos, dúvidas e reclamações sobre seus dados.
    </p>
    <div class="legal-nota">
      <p>
        Base: LGPD, art. 41, § 1º; Resolução CD/ANPD nº 18/2024, arts. 8º e 9º, que exigem a
        divulgação da identidade e do contato do encarregado em local de destaque e de fácil
        acesso.
      </p>
    </div>

    <h2>2. A quem esta política se dirige</h2>
    <ul>
      <li><strong>Tutores</strong> — pessoas responsáveis por animais cadastrados;</li>
      <li>
        <strong>Médicos-veterinários</strong> — profissionais com inscrição no CRMV
        vinculados a um estabelecimento;
      </li>
      <li>
        <strong>Responsáveis por estabelecimentos</strong> — quem administra a conta de uma
        clínica, hospital ou atividade autônoma;
      </li>
      <li>
        <strong>Terceiros que verificam um documento</strong> — quem recebe um PDF exportado
        e confere sua autenticidade na página pública de verificação.
      </li>
    </ul>
    <p>
      O animal <strong>não é titular de dados</strong> para efeito da LGPD, que protege dados
      de pessoa natural. O tratamento descrito aqui recai sobre as pessoas acima. A seção 5
      explica o que decorre disso.
    </p>

    <h2>3. Que dados o sistema coleta</h2>
    <p>
      O Imunia coleta apenas o que precisa para funcionar. A lista abaixo é exaustiva em
      relação ao que o sistema efetivamente armazena hoje.
    </p>

    <h3>3.1 Dados de conta (todos os perfis)</h3>
    <p>
      Nome, endereço de e-mail e senha. A senha nunca é armazenada em texto legível: o
      sistema guarda apenas o resultado de uma função de derivação criptográfica, que não
      permite recuperar a senha original. Também são registrados a data de confirmação do
      e-mail, a data de ativação da conta e, para retomar seu contexto de trabalho, o último
      estabelecimento em que você atuou.
    </p>

    <h3>3.2 Dados de sessão e de acesso</h3>
    <p>
      Cada sessão autenticada registra <strong>endereço IP</strong> e
      <strong>identificação do navegador</strong>, além da data e hora da última atividade.
      Esse registro é mantido em razão de obrigação legal, explicada na seção 8.
    </p>

    <h3>3.3 Dados do tutor</h3>
    <p><strong>Nome completo</strong> e <strong>CPF</strong>, mais a data e a versão dos termos aceitos.</p>
    <p>
      O CPF não é coletado por conveniência: é exigência da regulamentação profissional. A
      Resolução CFMV nº 1.321/2020, art. 3º, VII, determina que os documentos
      médico-veterinários identifiquem o responsável pelo animal por nome completo, CPF e
      endereço. No Imunia, o CPF cumpre ainda a função de permitir que um estabelecimento
      localize um cadastro já existente sem criar duplicidade.
    </p>
    <p>
      <strong>O sistema não coleta do tutor:</strong> telefone, endereço residencial, CEP,
      data de nascimento, RG, gênero, foto de perfil ou dados de pagamento. O único canal de
      contato é o e-mail.
    </p>

    <h3>3.4 Dados do médico-veterinário e do responsável pelo estabelecimento</h3>
    <p>
      Nome, e-mail, <strong>número de inscrição no CRMV e respectiva UF</strong>. O nome e o
      CRMV do profissional são gravados também dentro de cada registro clínico e de cada
      vacinação que ele produz, e ali permanecem de forma imutável — obrigação da Resolução
      CFMV nº 1.321/2020, art. 9º, VIII.
    </p>

    <h3>3.5 Dados do estabelecimento</h3>
    <p>
      Razão ou nome, CNPJ, telefone, endereço, CEP, município, UF, e nome, CRMV e UF do
      responsável técnico. Toda alteração nesses campos é registrada com o valor anterior, o
      novo valor, quem alterou e quando.
    </p>
    <p>
      O CNPJ de pessoa jurídica não é dado pessoal. Contudo, quando o estabelecimento é
      microempreendedor individual ou empresário individual, o cadastro se confunde com o de
      uma pessoa natural, e o Imunia o trata com a mesma proteção dispensada a dados
      pessoais.
    </p>

    <h3>3.6 Dados do animal e conteúdo clínico</h3>
    <p>
      Nome, espécie, sexo, raça, pelagem, situação reprodutiva, data de nascimento (exata ou
      aproximada), microchip, fotografia e, quando for o caso, data e causa do óbito.
    </p>
    <p>
      O conteúdo clínico compreende atendimentos (motivo, anamnese, exame físico, hipóteses
      diagnósticas, diagnóstico, conduta, peso, data de retorno), vacinações (imunobiológico,
      fabricante, lote, validade, via e sítio de administração, data de aplicação, dose) e
      anexos (laudos e exames em PDF ou imagem).
    </p>

    <h3>3.7 Dados de autorização e de solicitação de acesso</h3>
    <p>
      Quem autorizou, qual estabelecimento, quais animais, quando a autorização foi
      concedida, quando expira e, se for o caso, quando foi revogada. Quando um
      estabelecimento solicita acesso, registra-se também a mensagem que ele escreveu ao
      tutor, limitada a 280 caracteres.
    </p>
    <p>
      O código de seis dígitos enviado por e-mail para confirmar uma autorização
      <strong>não é armazenado em texto legível</strong> — o sistema guarda apenas seu resumo
      criptográfico, contra o qual compara o que você digita.
    </p>

    <h3>3.8 Documentos exportados</h3>
    <p>
      Ao emitir um PDF de carteira de vacinação ou de histórico, o sistema registra um código
      de verificação, um resumo criptográfico do conteúdo, o nome e a espécie do animal no
      momento da emissão, quem emitiu e quando.
    </p>

    <h2>4. Por que o sistema trata esses dados, e com que base legal</h2>
    <div class="legal-tabela">
      <table>
        <thead>
          <tr>
            <th>Finalidade</th>
            <th>Dados</th>
            <th>Base legal (LGPD)</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>Criar e manter sua conta, autenticar o acesso</td>
            <td>Nome, e-mail, senha</td>
            <td>Art. 7º, V — execução de contrato</td>
          </tr>
          <tr>
            <td>Identificar o tutor e evitar cadastro duplicado</td>
            <td>CPF</td>
            <td>Art. 7º, II — obrigação regulatória (Res. CFMV 1.321/2020, art. 3º, VII)</td>
          </tr>
          <tr>
            <td>Manter a carteira de vacinação e o prontuário</td>
            <td>Dados do animal e conteúdo clínico</td>
            <td>Art. 7º, II e V</td>
          </tr>
          <tr>
            <td>Identificar o profissional de cada procedimento</td>
            <td>Nome e CRMV</td>
            <td>Art. 7º, II — obrigação regulatória (Res. CFMV 1.321/2020, art. 9º, VIII)</td>
          </tr>
          <tr>
            <td>Permitir a continuidade do histórico entre estabelecimentos</td>
            <td>Autorizações e registros de acesso</td>
            <td>Art. 7º, V — mediante autorização expressa do tutor</td>
          </tr>
          <tr>
            <td>Registrar quem acessou dados de quem, e mostrá-lo ao tutor</td>
            <td>Registros de acesso</td>
            <td>Art. 7º, II e IX — segurança e prestação de contas</td>
          </tr>
          <tr>
            <td>Enviar confirmações, convites e códigos de autorização</td>
            <td>E-mail</td>
            <td>Art. 7º, V</td>
          </tr>
          <tr>
            <td>Guardar registros de acesso à aplicação</td>
            <td>IP, navegador, data e hora</td>
            <td>Art. 7º, II — Marco Civil da Internet, art. 15</td>
          </tr>
          <tr>
            <td>Preservar registros para defesa em eventual processo</td>
            <td>Conforme o caso</td>
            <td>Art. 7º, VI e art. 16, I</td>
          </tr>
          <tr>
            <td>Proteger o sistema contra fraude e uso indevido</td>
            <td>Dados de sessão e tentativas de acesso</td>
            <td>Art. 7º, IX — legítimo interesse</td>
          </tr>
        </tbody>
      </table>
    </div>
    <p>
      Quando o tratamento se apoia em <strong>legítimo interesse</strong>, você pode se opor
      a ele pelo canal da seção 13. A avaliação que fundamenta esse uso está documentada e é
      disponibilizada mediante pedido.
    </p>
    <div class="legal-nota">
      <p>
        Sobre a base de "tutela da saúde" (LGPD, arts. 7º, VIII e 11, II, "f"): ela não é
        invocada nesta política. A lei a restringe a procedimento realizado por profissionais de
        saúde, serviços de saúde ou autoridade sanitária, categorias que não alcançam a
        medicina veterinária. O tratamento se funda, portanto, em contrato e em obrigação
        regulatória.
      </p>
    </div>

    <h2>5. Uma palavra sobre dados de saúde animal</h2>
    <p>
      Circula a ideia de que o prontuário de um animal seria "dado pessoal sensível". Não é o
      que a lei diz, e vale explicar por quê — porque a resposta muda o que você pode exigir.
    </p>
    <p>
      A LGPD define dado sensível, no art. 5º, II, como aquele referente à saúde de
      <strong>uma pessoa natural</strong>. A saúde registrada no Imunia é a do animal, e o
      animal não é titular de dados. O prontuário, contudo,
      <strong>é dado pessoal do tutor</strong>, porque é informação relacionada a uma pessoa
      identificada: seu nome e seu CPF estão no cadastro a que o histórico se vincula.
    </p>
    <p>
      Há uma exceção que merece atenção. O art. 11, § 1º da LGPD estende o regime dos dados
      sensíveis a qualquer tratamento que <strong>revele</strong> dado sensível e possa causar
      dano ao titular. Um prontuário pode fazer isso — ao registrar uma zoonose transmitida
      ao tutor, ao identificar um animal de assistência ligado a uma deficiência, ou ao
      anotar uma condição do tutor como motivo de conduta. Quando isso ocorrer, o registro
      está sob o regime mais rigoroso, e o Imunia o trata como tal.
    </p>
    <p>
      <strong>A postura adotada é conservadora:</strong> o sistema aplica a dados clínicos as
      mesmas salvaguardas devidas a dados sensíveis — acesso condicionado a autorização
      expressa, registro de cada consulta, imutabilidade dos registros, minimização do que se
      exibe — independentemente da classificação. Além disso, o prontuário veterinário está
      protegido por <strong>sigilo profissional</strong>, cuja violação é crime (Código Penal,
      art. 154) e infração ética (Res. CFMV nº 1.138/2016, art. 11).
    </p>

    <h2>6. Com quem os dados são compartilhados</h2>

    <h3>6.1 Entre estabelecimentos, e só com autorização do tutor</h3>
    <p>
      Este é o núcleo do sistema, e a regra é simples:
      <strong>
        nenhum estabelecimento vê o histórico produzido por outro sem que o tutor tenha
        autorizado, nominalmente, aquele estabelecimento e aquele animal.
      </strong>
    </p>
    <ul>
      <li>
        A autorização é concedida <strong>exclusivamente pelo tutor</strong>. Um
        estabelecimento pode solicitá-la, nunca concedê-la a si mesmo.
      </li>
      <li>
        Ela é específica por <strong>animal</strong> e por <strong>estabelecimento</strong>.
        Não existe autorização geral, nem autorização a um veterinário como pessoa física.
      </li>
      <li>
        Para concedê-la, o tutor confirma um <strong>código de seis dígitos</strong> enviado
        ao seu e-mail. Após cinco tentativas erradas, a confirmação é bloqueada por trinta
        minutos e o tutor é avisado.
      </li>
      <li>A autorização <strong>vale 90 dias</strong> e pode ser renovada.</li>
      <li>
        O tutor pode <strong>revogar a qualquer momento</strong>, e o estabelecimento é
        notificado da revogação.
      </li>
      <li>
        Antes de exibir a um profissional qualquer histórico produzido por outro
        estabelecimento, o sistema avisa que aquela visualização será registrada e ficará
        visível ao tutor.
      </li>
    </ul>
    <p>
      <strong>A revogação não apaga registros.</strong> O estabelecimento perde o acesso ao
      histórico alheio, mas os atendimentos e as vacinações que ele próprio realizou
      permanecem sob sua guarda. Isso não é escolha de produto: a Resolução CFMV nº
      1.321/2020, art. 9º, § 3º, obriga o profissional a arquivar o prontuário por pelo menos
      cinco anos após o último atendimento, mesmo em caso de óbito do animal.
    </p>

    <h3>6.2 Perfis administrativos não alcançam dados clínicos</h3>
    <p>
      Quem administra a conta de um estabelecimento sem ser médico-veterinário
      <strong>não tem acesso a dado algum de tutor, de animal ou de registro clínico</strong>.
      A separação é estrutural no sistema, não uma configuração que se possa afrouxar.
    </p>

    <h3>6.3 Documento exportado e verificação pública</h3>
    <p>
      Um PDF exportado contém o nome do tutor, os dados do animal e o conteúdo escolhido — e,
      uma vez baixado, <strong>circula fora do controle do sistema</strong>.
    </p>
    <p>
      A página pública de verificação, acessível a quem tiver o código impresso no documento,
      revela apenas se o documento é autêntico, a data de emissão, o
      <strong>nome e a espécie do animal</strong> e um resumo abreviado. Ela não revela o
      tutor, o CPF, o conteúdo clínico nem o estabelecimento emissor.
    </p>

    <h3>6.4 Terceiros</h3>
    <p>
      O Imunia <strong>não vende, não aluga e não cede dados pessoais</strong>. Não há na
      plataforma serviços de analytics, publicidade, rastreamento comportamental, mapas, chat
      de suporte ou gateway de pagamento.
    </p>
    <div class="legal-tabela">
      <table>
        <thead>
          <tr>
            <th>Terceiro</th>
            <th>Para quê</th>
            <th>Que dados alcança</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>Provedor de hospedagem e banco de dados</td>
            <td>Executar o sistema</td>
            <td>Todos, em repouso e em trânsito</td>
          </tr>
          <tr>
            <td>Provedor de envio de e-mail</td>
            <td>Entregar confirmações, convites e códigos</td>
            <td>Nome, e-mail e o conteúdo da mensagem</td>
          </tr>
          <tr>
            <td>Google Fonts</td>
            <td>Carregar as fontes tipográficas da interface</td>
            <td>Endereço IP e dados do navegador, ao carregar a página</td>
          </tr>
        </tbody>
      </table>
    </div>
    <p>
      Quando esses fornecedores atuarem como operadores, o farão sob contrato que os obriga a
      tratar os dados apenas conforme instruções, com dever de segurança e de sigilo.
    </p>

    <h2>7. O registro de acessos: você vê quem viu</h2>
    <p>O Imunia mantém um <strong>livro de acessos</strong> consultável pelo tutor, que registra de forma imutável:</p>
    <ul>
      <li>buscas realizadas por CPF, por código do animal, por microchip ou por nome;</li>
      <li>consulta a ficha de animal sem autorização vigente;</li>
      <li>visualização de histórico produzido por outro estabelecimento;</li>
      <li>exportação de documento que contenha registro alheio;</li>
      <li>alertas de duplicidade de cadastro.</li>
    </ul>
    <p>
      Cada entrada mostra o estabelecimento, o profissional (nome e CRMV), o animal, a
      natureza do acesso, a data e a hora, e qual autorização estava vigente naquele momento.
    </p>
    <p>
      Esse registro cumpre, por interface e em tempo real, o direito do art. 18, VII da LGPD
      — saber com quem seus dados foram compartilhados — sem que seja preciso pedir.
    </p>

    <h2>8. Por quanto tempo os dados são guardados</h2>
    <div class="legal-tabela">
      <table>
        <thead>
          <tr>
            <th>Dado</th>
            <th>Prazo</th>
            <th>Fundamento</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>Prontuário e vacinações</td>
            <td><strong>5 anos</strong> após o último atendimento, inclusive em caso de óbito</td>
            <td>Res. CFMV nº 1.321/2020, art. 9º, § 3º</td>
          </tr>
          <tr>
            <td>Registros de acesso à aplicação</td>
            <td><strong>6 meses</strong>, sob sigilo</td>
            <td>Marco Civil da Internet, art. 15</td>
          </tr>
          <tr>
            <td>Registros de incidentes de segurança</td>
            <td><strong>5 anos</strong></td>
            <td>Res. CD/ANPD nº 15/2024, art. 10</td>
          </tr>
          <tr>
            <td>Conta e cadastro</td>
            <td>Enquanto a conta existir, e depois pelos prazos de prescrição</td>
            <td>LGPD, art. 16, I</td>
          </tr>
          <tr>
            <td>Autorizações e livro de acessos</td>
            <td>Enquanto necessários à prestação de contas e à defesa de direitos</td>
            <td>LGPD, art. 7º, VI e art. 16, I</td>
          </tr>
          <tr>
            <td>Códigos de autorização e tokens de convite</td>
            <td>5 minutos, 24 horas ou 7 dias, conforme o tipo</td>
            <td>Minimização — LGPD, art. 6º, III</td>
          </tr>
        </tbody>
      </table>
    </div>
    <p>
      Findos os prazos, os dados são eliminados ou anonimizados, salvo quando a lei impuser
      guarda mais longa.
    </p>
    <div class="legal-destaque">
      <span class="legal-destaque__rotulo">Declaração honesta sobre o estado atual</span>
      <p>
        O sistema <strong>ainda não possui rotina automatizada de expurgo</strong>. Os prazos
        acima são a política declarada; sua execução, hoje, depende de procedimento manual.
        Esta é uma limitação conhecida, registrada como pendência de implementação.
      </p>
    </div>

    <h2>9. Transferência internacional de dados</h2>
    <p>
      O carregamento das fontes tipográficas da interface é feito a partir dos servidores do
      Google, o que expõe o endereço IP do visitante a servidores possivelmente localizados
      fora do Brasil. É a <strong>única requisição a domínio de terceiro</strong> feita pela
      interface. Nenhum dado de conta, de animal ou de prontuário é transferido nessa
      operação.
    </p>
    <p>Caso a infraestrutura do Imunia venha a ser hospedada fora do Brasil, aplicam-se:</p>
    <ul>
      <li>
        para a União Europeia e os países do Espaço Econômico Europeu, o reconhecimento de
        grau adequado de proteção, que dispensa mecanismo adicional (LGPD, art. 33, I;
        Resolução CD/ANPD nº 32, de 26 de janeiro de 2026);
      </li>
      <li>
        para os demais países, as <strong>cláusulas-padrão contratuais</strong> do Anexo II da
        Resolução CD/ANPD nº 19/2024, adotadas integralmente e sem alteração.
      </li>
    </ul>

    <h2>10. Cookies e o que fica no seu dispositivo</h2>
    <p>
      O Imunia <strong>não usa cookies de publicidade, de analytics ou de rastreamento</strong>.
      Por isso não há banner de consentimento de cookies: não há nada a consentir além do
      estritamente necessário para o sistema funcionar.
    </p>
    <div class="legal-tabela">
      <table>
        <thead>
          <tr>
            <th>O que</th>
            <th>Para quê</th>
            <th>Onde</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>Cookie de sessão</td>
            <td>Manter você autenticado. Não é legível por scripts da página.</td>
            <td>Navegador</td>
          </tr>
          <tr>
            <td>Cookie <code>XSRF-TOKEN</code></td>
            <td>Impedir que outro site execute ações em seu nome</td>
            <td>Navegador</td>
          </tr>
          <tr>
            <td>Rascunho da concessão de autorização</td>
            <td>Preservar sua escolha durante o fluxo</td>
            <td>Apagado ao fechar a aba</td>
          </tr>
          <tr>
            <td>Rascunho de atendimento</td>
            <td>Evitar que o veterinário perca o texto digitado</td>
            <td>Dispositivo do profissional</td>
          </tr>
        </tbody>
      </table>
    </div>
    <div class="legal-destaque">
      <span class="legal-destaque__rotulo">Atenção ao último item</span>
      <p>
        O rascunho de atendimento guarda conteúdo clínico
        <strong>no próprio dispositivo do veterinário</strong>, até que o registro seja
        concluído. Em computador compartilhado, encerre a sessão e conclua ou descarte os
        rascunhos abertos.
      </p>
    </div>

    <h2>11. Segurança</h2>
    <p>
      As medidas adotadas incluem: transmissão cifrada, senhas armazenadas apenas como
      resultado de função de derivação criptográfica, tokens e códigos de autorização
      guardados apenas como resumo criptográfico, limitação de tentativas de acesso e de
      pedidos de autorização, invalidação das demais sessões ao trocar a senha, proteção
      contra requisições forjadas de outros sites, anexos servidos por rota autorizada e
      nunca por endereço direto, e registro imutável de acessos.
    </p>
    <p>Nenhum sistema é imune. As medidas acima reduzem risco; não o eliminam.</p>
    <div class="legal-destaque">
      <span class="legal-destaque__rotulo">Limitação conhecida</span>
      <p>
        As fotografias de animais são armazenadas em área de arquivos servida publicamente.
        Quem conhecer o endereço de uma fotografia poderá acessá-la sem autenticação. Isso não
        expõe prontuário, nome de tutor ou CPF, mas é uma diferença real em relação ao
        tratamento dado aos anexos clínicos, que exigem autenticação e autorização.
      </p>
    </div>

    <h2>12. Incidentes de segurança</h2>
    <p>
      Se ocorrer incidente que possa acarretar risco ou dano relevante, o Imunia comunicará
      <strong>à ANPD e aos titulares afetados em até 3 (três) dias úteis</strong>, contados do
      conhecimento de que o incidente atingiu dados pessoais, informando o que ocorreu, quais
      dados foram atingidos, os riscos envolvidos e as medidas adotadas.
    </p>
    <p>
      Todo incidente é registrado e mantido por, no mínimo, cinco anos, ainda que não tenha
      exigido comunicação.
    </p>
    <div class="legal-nota">
      <p>
        Base: LGPD, art. 48; Resolução CD/ANPD nº 15/2024, arts. 5º, 6º, 9º e 10.
        Registre-se que dados protegidos por sigilo profissional — como o prontuário —
        figuram entre os critérios de risco relevante do art. 5º, V daquela resolução.
      </p>
    </div>

    <h2>13. Seus direitos, e como exercê-los</h2>
    <p>A LGPD assegura a você, no art. 18:</p>
    <ol>
      <li><strong>confirmação</strong> de que existe tratamento dos seus dados;</li>
      <li><strong>acesso</strong> aos dados;</li>
      <li><strong>correção</strong> de dados incompletos, inexatos ou desatualizados;</li>
      <li>
        <strong>anonimização, bloqueio ou eliminação</strong> de dados desnecessários,
        excessivos ou tratados em desconformidade com a lei;
      </li>
      <li><strong>portabilidade</strong> a outro fornecedor, conforme regulamentação da ANPD;</li>
      <li><strong>eliminação</strong> dos dados tratados com base em consentimento;</li>
      <li><strong>informação</strong> sobre com quem seus dados foram compartilhados;</li>
      <li>
        <strong>informação</strong> sobre a possibilidade de não consentir e sobre as
        consequências da negativa;
      </li>
      <li><strong>revogação do consentimento</strong>, a qualquer momento.</li>
    </ol>
    <p>
      Você pode ainda <strong>opor-se</strong> a tratamento fundado em dispensa de
      consentimento, quando houver descumprimento da lei (art. 18, § 2º).
    </p>

    <h3>Como pedir</h3>
    <p>
      Escreva para <span class="legal-campo">[E-MAIL DO ENCARREGADO]</span>. O atendimento é
      <strong>gratuito</strong>. Pediremos elementos que confirmem sua identidade — não para
      dificultar, mas porque entregar seus dados a quem se passe por você seria o pior
      desfecho possível.
    </p>
    <p>
      <strong>Prazo:</strong> a confirmação de existência de tratamento e o acesso aos dados
      são prestados em <strong>até 15 dias</strong> (LGPD, art. 19, II). Em formato
      simplificado, imediatamente. Para os demais pedidos, responderemos no menor prazo
      praticável, informando desde logo o que for possível.
    </p>

    <h3>Três limites que você deve conhecer de antemão</h3>
    <div class="legal-destaque">
      <span class="legal-destaque__rotulo">Limites do direito de correção e de eliminação</span>
      <p>
        <strong>Registros clínicos não são apagados nem editados.</strong> A Resolução CFMV nº
        1.321/2020, art. 2º, VIII, exige prontuário sem rasuras ou emendas. No Imunia,
        corrigir um registro significa <strong>emitir uma retificação vinculada ao registro
        original</strong>, que passa a constar do histórico ao lado dele. O erro fica visível,
        e a correção também. Isso atende ao direito de correção do art. 18, III de forma
        compatível com a norma profissional — mas não produz o apagamento que você talvez
        esperasse.
      </p>
      <p>
        <strong>A guarda de cinco anos prevalece sobre o pedido de eliminação.</strong> O art.
        16, I da LGPD ressalva a conservação necessária ao cumprimento de obrigação legal ou
        regulatória.
      </p>
      <p>
        <strong>
          O sistema ainda não oferece autoatendimento para exclusão de conta nem para download
          completo dos seus dados.
        </strong>
        As telas correspondentes não foram implementadas. Isso <strong>não suprime seus
        direitos</strong>: eles são exercidos pelo canal do encarregado, com atendimento
        humano. É uma limitação de interface, não de direito.
      </p>
    </div>

    <h3>Se não ficarmos entendidos</h3>
    <p>
      Você pode peticionar à <strong>ANPD</strong> (LGPD, art. 18, § 1º) e recorrer aos
      <strong>órgãos de defesa do consumidor</strong> (art. 18, § 8º), independentemente de
      nos ter procurado antes.
    </p>

    <h2>14. Crianças e adolescentes</h2>
    <p>
      O Imunia <strong>não se destina a menores de 18 anos</strong>. O cadastro exige a
      declaração de capacidade civil prevista nos Termos de Uso, e o sistema não coleta idade,
      série escolar, geolocalização, conteúdo gerado por usuário de acesso público, nem
      qualquer dado que caracterize serviço atrativo ou dirigido ao público infantojuvenil.
    </p>
    <p>
      Se tomarmos conhecimento de cadastro de menor sem representação, a conta será encerrada
      e os dados eliminados, ressalvado o que a lei obrigue a conservar.
    </p>
    <div class="legal-nota">
      <p>
        Referência: LGPD, art. 14; Lei nº 15.211/2025 (ECA Digital), art. 1º, cujo escopo
        alcança serviços direcionados a crianças e adolescentes ou de acesso provável por eles
        — hipóteses em que o Imunia não se enquadra.
      </p>
    </div>

    <h2>15. Decisões tomadas por algoritmo</h2>
    <p>
      O sistema <strong>calcula automaticamente as próximas doses vacinais</strong> a partir de
      protocolos técnicos de referência, da espécie e do histórico do animal, e sinaliza doses
      previstas, próximas e atrasadas.
    </p>
    <p>
      Esse cálculo é uma <strong>sugestão técnica, não uma decisão sobre você</strong>. Ele não
      define preço, não concede nem nega acesso, não avalia crédito e não produz efeito
      jurídico sobre nenhuma pessoa. A conduta é sempre do médico-veterinário, que pode
      divergir do cálculo registrando a justificativa.
    </p>
    <p>
      Cada dose prevista exibe, na interface, a regra que a produziu e a versão do protocolo
      aplicado. Não há elaboração de perfil comportamental, pontuação de usuários ou
      publicidade dirigida.
    </p>

    <h2>16. Alterações nesta política</h2>
    <p>
      Esta política pode mudar quando o sistema mudar. Alterações relevantes — sobretudo em
      finalidades, bases legais, prazos ou compartilhamentos — serão comunicadas com destaque
      e com antecedência razoável, conforme o art. 8º, § 6º da LGPD.
    </p>
    <p>
      Cada versão tem número e data no topo do documento, e é a versão vigente no momento do
      seu aceite que fica registrada na sua conta.
    </p>

    <h2>17. Contato</h2>
    <p>
      Encarregado pela proteção de dados:
      <span class="legal-campo">[NOME DO ENCARREGADO]</span> —
      <span class="legal-campo">[E-MAIL DO ENCARREGADO]</span><br />
      Controlador: <span class="legal-campo">[RAZÃO SOCIAL]</span>, CNPJ
      <span class="legal-campo">[CNPJ]</span>, <span class="legal-campo">[ENDEREÇO]</span>
    </p>
    <p>
      Agência Nacional de Proteção de Dados — ANPD:
      <a href="https://www.gov.br/anpd" target="_blank" rel="noopener">gov.br/anpd</a>
    </p>
    <p>
      Documento relacionado: <RouterLink to="/termos">Termos de Uso</RouterLink>.
    </p>
  </LegalDocument>
</template>
