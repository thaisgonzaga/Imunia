<script setup>
import LegalDocument from '@/components/public/LegalDocument.vue'

/**
 * P10 — termos de uso. Par de P11, e destino da outra metade da caixa de aceite
 * de P05.
 *
 * As cláusulas que limitam direito estão marcadas com `legal-destaque`, e não
 * por ênfase editorial: o art. 54, § 4º do Código de Defesa do Consumidor
 * condiciona a validade delas ao destaque. Uma limitação de responsabilidade
 * escondida no meio do parágrafo não é uma limitação discreta — é uma limitação
 * inválida.
 */
</script>

<template>
  <LegalDocument
    titulo="Termos de Uso"
    resumo="Condições de uso da plataforma por tutores, médicos-veterinários e estabelecimentos."
  >
    <div class="legal-destaque">
      <span class="legal-destaque__rotulo">Nota preliminar de escopo</span>
      <p>
        Estes Termos descrevem o Imunia <strong>tal como está implementado</strong>. Onde uma
        funcionalidade não existe, o texto não a promete. Enquanto o Imunia não tiver pessoa
        jurídica constituída e não estiver em operação comercial, estes Termos valem como
        documento de referência do projeto, não como contrato em vigor entre partes
        determinadas.
      </p>
      <p>
        Leia antes de aceitar. <strong>As cláusulas que limitam direitos estão em
        destaque</strong>, como exige o art. 54, § 4º do Código de Defesa do Consumidor.
      </p>
      <p>
        <strong>O que mudou na versão 2.0.</strong> O tutor deixou de se cadastrar sozinho:
        quem cadastra o tutor e o animal é a clínica, no atendimento, e o tutor recebe por
        e-mail um convite para acompanhar o que for registrado. Saiu o compartilhamento
        controlado pelo tutor — não há mais concessão, revogação, renovação nem prazo de 90
        dias de autorização, nem código de confirmação, solicitação de acesso ou diretório de
        estabelecimentos. O histórico do animal passou a ser único: qualquer clínica que o
        atenda, localizando-o por identificador exato, vê o histórico inteiro, e o acesso fica
        registrado no livro de acessos que o tutor consulta. O CPF do tutor deixou de ser
        pedido: o cadastro tem só nome e e-mail.
      </p>
    </div>

    <h2>1. O que é o Imunia — e o que ele não é</h2>
    <p>
      O Imunia é uma plataforma digital de <strong>calendário vacinal e prontuário
      médico-veterinário</strong> para cães e gatos. Ele permite que tutores acompanhem a
      vacinação de seus animais, que médicos-veterinários registrem atendimentos e vacinações,
      e que o histórico de um animal acompanhe-o entre estabelecimentos diferentes,
      <strong>com registro de cada acesso visível ao tutor</strong>.
    </p>
    <p>
      O cadastro do tutor e do animal é feito pelo estabelecimento que os atende. O tutor
      <strong>não precisa fazer nada</strong> para que o atendimento aconteça: ele é convidado
      por e-mail a criar uma senha e acompanhar o que for registrado, se quiser.
    </p>
    <p><strong>O Imunia é um instrumento de registro. Ele não é, e não pretende ser:</strong></p>
    <ul>
      <li>um serviço de saúde ou de atendimento veterinário — quem atende é o profissional, não o sistema;</li>
      <li>um substituto da consulta presencial ou do julgamento clínico;</li>
      <li>um emissor de prescrição, de diagnóstico ou de conduta;</li>
      <li>um serviço de urgência ou emergência;</li>
      <li>uma fonte de aconselhamento veterinário ao tutor.</li>
    </ul>
    <p>
      As sugestões de doses e datas apresentadas pelo sistema decorrem de protocolos técnicos
      de referência aplicados de forma automática. <strong>São sugestões.</strong> A decisão
      clínica cabe ao médico-veterinário, que pode divergir delas registrando a justificativa.
    </p>

    <h2>2. Definições</h2>
    <ul>
      <li><strong>Plataforma</strong> — o sistema Imunia, em suas interfaces web e serviços associados.</li>
      <li><strong>Tutor</strong> — pessoa natural responsável por um ou mais animais cadastrados, cujo cadastro é feito por um estabelecimento.</li>
      <li><strong>Estabelecimento</strong> — clínica, hospital veterinário ou profissional autônomo cadastrado como prestador.</li>
      <li><strong>Profissional</strong> — médico-veterinário com inscrição ativa no CRMV, vinculado a um estabelecimento.</li>
      <li><strong>Convite</strong> — mensagem enviada ao e-mail do tutor quando um estabelecimento cadastra o primeiro animal dele, com um endereço para criar a senha e passar a acompanhar o animal.</li>
      <li><strong>Animal acompanhado</strong> — animal que um estabelecimento cadastrou ou atendeu. O vínculo nasce desses atos, não é concedido nem revogado por ninguém, e não é permissão: serve para formar a lista de pacientes do estabelecimento.</li>
      <li><strong>Livro de acessos</strong> — registro, consultável pelo tutor, de quem acessou dados dele e dos seus animais.</li>
      <li><strong>Registro clínico</strong> — atendimento, vacinação ou anexo lançado na plataforma.</li>
      <li><strong>Registro pregresso</strong> — vacinação anterior informada pelo tutor a partir de documento que possua.</li>
    </ul>

    <h2>3. Aceite</h2>
    <p>
      Ao marcar a caixa de aceite — no cadastro do estabelecimento ou ao aceitar o convite
      para acompanhar seus animais —, ou ao usar a plataforma, você declara ter lido
      e concordado com estes Termos e com a
      <RouterLink to="/privacidade">Política de Privacidade</RouterLink>.
    </p>
    <p>
      O sistema registra a data, a hora e a <strong>versão do documento</strong> que você
      aceitou. A versão vigente está no topo desta página.
    </p>

    <h2>4. Quem pode usar</h2>
    <p>Para usar a plataforma você deve:</p>
    <ul>
      <li>ter <strong>18 anos ou mais</strong> e capacidade civil plena;</li>
      <li>fornecer informações verdadeiras, exatas e atualizadas;</li>
      <li>ser o legítimo responsável pelos animais vinculados ao seu cadastro, quando for tutor;</li>
      <li>possuir <strong>inscrição ativa no CRMV</strong>, quando atuar como médico-veterinário.</li>
    </ul>
    <p>
      A plataforma não se destina a menores de 18 anos. Constatado cadastro de menor sem
      representação legal, a conta será encerrada.
    </p>

    <h2>5. Sua conta</h2>
    <p>
      Você é responsável por manter a confidencialidade da sua senha e por tudo o que for
      feito na plataforma com suas credenciais.
    </p>
    <ul>
      <li>
        <strong>Não compartilhe sua conta.</strong> No prontuário veterinário, quem assina o
        registro é quem responde por ele. Uma conta compartilhada torna a autoria
        indeterminável e compromete a validade do documento.
      </li>
      <li>Comunique imediatamente qualquer uso não autorizado pelo canal de contato.</li>
      <li>
        Como tutor, você recebe por e-mail os lembretes de vacina, de atraso e de retorno dos
        seus animais desde que a clínica cadastra você, mesmo antes de ativar o acesso. Se você
        trocar o e-mail da conta, os lembretes só voltam a sair depois que o endereço novo for
        confirmado.
      </li>
      <li>Ao trocar sua senha, as demais sessões abertas são encerradas.</li>
    </ul>
    <p>
      O sistema <strong>não oferece autenticação em duas etapas para o login</strong>. Escolha
      uma senha forte e não a reutilize.
    </p>

    <h2>6. Regras aplicáveis ao tutor</h2>

    <h3>6.1 Como seu acesso nasce, e o que você acompanha</h3>
    <p>
      Você não se cadastra sozinho. <strong>O estabelecimento que atende seu animal cadastra
      você</strong> — com nome e e-mail — e cadastra o animal. Quando o primeiro animal é
      cadastrado, você recebe um <strong>convite por e-mail</strong>, válido por 7 dias e
      renovável pela própria página, para criar sua senha. Aceitar o convite é opcional:
      <strong>o atendimento não depende disso</strong>.
    </p>
    <p>
      Depois de ativar o acesso, você pode consultar a carteira e o histórico dos seus
      animais, editar a identificação e a foto deles, lançar registro pregresso, exportar
      documentos e consultar os lembretes que recebeu. Você <strong>não registra</strong> atendimento,
      vacinação ou anexo clínico: isso é privativo do médico-veterinário.
    </p>
    <p>
      <strong>O histórico do animal é único.</strong> Qualquer estabelecimento que atenda seu
      animal e o localize por identificador exato — seu e-mail, o código ou QR do animal, ou o
      microchip — vê o histórico inteiro, com a indicação do estabelecimento que produziu cada
      registro. Esse acesso não depende de autorização sua. Em contrapartida, ele
      <strong>fica registrado</strong>: você pode consultar, a qualquer tempo, o livro de
      acessos, com identificação do estabelecimento, do profissional, da data e da natureza do
      acesso, e com a indicação de se aquele estabelecimento acompanha o animal.
    </p>

    <h3>6.2 Registro pregresso: o que você lança e o que isso vale</h3>
    <p>
      Você pode lançar vacinações anteriores a partir de documentos que possua — a carteira de
      papel, por exemplo.
    </p>
    <div class="legal-destaque">
      <span class="legal-destaque__rotulo">Cláusula de destaque</span>
      <p>
        O registro pregresso é <strong>declaração sua</strong>, e não ato profissional. Ele é
        assim identificado no histórico, distinguido dos registros lançados por
        médico-veterinário. <strong>Não tem valor de atestado de vacinação</strong>, que é
        privativo do médico-veterinário (Resolução CFMV nº 1.321/2020, art. 4º), e não pode
        ser apresentado como tal a autoridades, a estabelecimentos ou a terceiros. Informar
        dado falso pode expor o animal a risco sanitário e você a responsabilidade.
      </p>
    </div>

    <h3>6.3 O que você não pode fazer</h3>
    <ul>
      <li>Aceitar convite dirigido a outra pessoa, ou acompanhar animal do qual não seja responsável.</li>
      <li>Informar dados falsos ao estabelecimento ou ao editar a identificação do animal.</li>
      <li>Apresentar registro pregresso como se fosse registro profissional.</li>
    </ul>

    <h3>6.4 O prontuário não depende da sua conta</h3>
    <div class="legal-destaque">
      <span class="legal-destaque__rotulo">Cláusula de destaque</span>
      <p>
        Encerrar sua conta, ou nunca ativá-la, <strong>não apaga os registros clínicos</strong>
        feitos pelos estabelecimentos que atenderam seu animal, nem impede que eles continuem
        disponíveis a quem atender o animal. A Resolução CFMV nº 1.321/2020, art. 9º, § 3º, obriga o profissional a arquivar o
        prontuário por pelo menos cinco anos após o último atendimento, mesmo em caso de óbito
        do animal. Nem o Imunia nem o estabelecimento podem afastar essa obrigação a seu
        pedido.
      </p>
    </div>

    <h2>7. Regras aplicáveis a estabelecimentos e profissionais</h2>

    <h3>7.1 Responsabilidade pelo conteúdo clínico</h3>
    <p>
      O estabelecimento e o profissional são os <strong>únicos responsáveis</strong> pela
      veracidade, exatidão, completude e adequação técnica do que registram. O Imunia não
      revisa, não valida e não audita conteúdo clínico.
    </p>
    <p>
      Cada registro grava, de forma imutável, o nome e o número de inscrição no CRMV do
      profissional responsável, como exige a Resolução CFMV nº 1.321/2020, art. 9º, VIII.
    </p>

    <h3>7.2 Sigilo profissional</h3>
    <p>
      O prontuário está protegido por sigilo. Ao usar a plataforma, o estabelecimento e seus
      profissionais obrigam-se a observar o <strong>art. 154 do Código Penal</strong>, que
      tipifica como crime revelar segredo obtido em razão da profissão, e o
      <strong>art. 11 do Código de Ética do Médico-Veterinário</strong> (Res. CFMV nº
      1.138/2016), que veda facilitar o acesso a prontuários, prestar informação sobre paciente
      ou cliente sem autorização expressa do responsável, e permitir o uso do cadastro de
      clientes sem a respectiva autorização.
    </p>
    <div class="legal-destaque">
      <span class="legal-destaque__rotulo">Cláusula de destaque</span>
      <p>
        É vedado ao estabelecimento usar dados de tutores ou de animais obtidos pela
        plataforma para finalidade estranha ao atendimento — inclusive publicidade,
        prospecção, cessão a terceiros, pesquisa identificável ou cruzamento com bases
        próprias — <strong>sem autorização expressa e específica do titular</strong>. A
        violação é infração ética do profissional, além de tratamento sem base legal.
      </p>
    </div>

    <h3>7.3 Histórico único, acesso registrado</h3>
    <p>
      O estabelecimento alcança qualquer cadastro da plataforma
      <strong>por identificador exato</strong> — e-mail do tutor, código ou QR do animal, ou
      microchip — e vê o histórico inteiro do animal, com a indicação do estabelecimento autor
      de cada registro. A busca por nome alcança apenas os animais que o estabelecimento já
      acompanha.
    </p>
    <p>
      Todo acesso de estabelecimento que ainda não acompanhava o animal, a leitura de registro
      produzido por outro estabelecimento e a exportação de documento que o contenha são
      registrados e <strong>ficam visíveis ao tutor</strong> no livro de acessos. Esse registro
      é condição da exibição: ver implica ficar registrado.
    </p>
    <p>
      Ver não é retificar: só o estabelecimento autor de um registro pode emitir retificação
      sobre ele (seção 8).
    </p>

    <h3>7.4 Equipe</h3>
    <p>
      O estabelecimento é responsável por convidar, manter e encerrar os vínculos de sua
      equipe, e por garantir que cada profissional tenha inscrição ativa no CRMV.
    </p>
    <p>
      Encerrar o vínculo de um profissional <strong>remove seu acesso, não sua autoria</strong>:
      os registros que ele produziu continuam identificados com seu nome e CRMV, como a norma
      profissional exige.
    </p>
    <p>
      Perfis administrativos sem inscrição no CRMV <strong>não acessam dados de tutores, de
      animais ou registros clínicos</strong>. Essa separação é estrutural e não pode ser
      afrouxada por configuração.
    </p>

    <h3>7.5 Papel do Imunia quanto ao conteúdo clínico</h3>
    <p>
      Quanto ao conteúdo clínico registrado pelo estabelecimento, o Imunia atua como
      <strong>operador</strong>, e o estabelecimento como <strong>controlador</strong>. O
      Imunia trata esses dados apenas conforme as instruções do estabelecimento e
      <strong>não os utiliza para finalidade própria</strong>. A distribuição completa de
      papéis está na seção 1 da
      <RouterLink to="/privacidade">Política de Privacidade</RouterLink>.
    </p>

    <h3>7.6 Cadastro do tutor e do animal</h3>
    <p>
      O estabelecimento cadastra o tutor (nome e e-mail) e o animal no atendimento, e
      responde pela veracidade do que informa. Se o e-mail já for de um tutor cadastrado, o
      sistema não cria um segundo cadastro: o animal é vinculado ao tutor existente.
    </p>
    <p>
      Ao cadastrar o primeiro animal de um tutor, o sistema envia ao e-mail informado o
      convite descrito na seção 6.1. <strong>O estabelecimento deve informar o tutor</strong> de
      que usa o Imunia para registrar as informações do animal e de que ele receberá esse
      convite.
    </p>

    <h2>8. Registros são imutáveis; a correção é retificação</h2>
    <div class="legal-destaque">
      <span class="legal-destaque__rotulo">Cláusula de destaque</span>
      <p>
        Atendimentos e vacinações, uma vez lançados,
        <strong>não são editados nem excluídos</strong>. A Resolução CFMV nº 1.321/2020, art.
        2º, VIII, exige prontuário sem rasuras ou emendas, com autenticidade e integridade
        garantidas.
      </p>
      <p>
        Corrigir um registro significa <strong>emitir uma retificação vinculada ao
        original</strong>, com a justificativa da correção. Ambos permanecem visíveis no
        histórico. O erro não desaparece; a correção fica ao lado dele.
      </p>
      <p>
        Isso vale inclusive para pedidos fundados no direito de correção do art. 18, III da
        LGPD, que é atendido por retificação — não por apagamento.
      </p>
    </div>

    <h2>9. Documentos exportados</h2>
    <p>
      A plataforma emite documentos em PDF — carteira de vacinação e histórico — com código de
      verificação e resumo criptográfico que permitem conferir sua autenticidade em página
      pública.
    </p>
    <div class="legal-destaque">
      <span class="legal-destaque__rotulo">Cláusula de destaque</span>
      <p>
        Um documento exportado contém o nome do tutor e os dados do animal, e
        <strong>circula fora do controle da plataforma</strong> depois de baixado. Quem o
        receber verá seu conteúdo. Quem tiver o código de verificação poderá confirmar a
        autenticidade do documento e ver o nome e a espécie do animal — nunca o tutor, o
        conteúdo clínico ou o estabelecimento emissor. A guarda e a distribuição do arquivo
        são responsabilidade de quem o emitiu.
      </p>
    </div>

    <h2>10. Uso aceitável</h2>
    <p>É vedado:</p>
    <ul>
      <li>acessar ou tentar acessar dados de animal que não esteja sendo atendido;</li>
      <li>realizar buscas em massa, raspagem de dados ou extração sistemática de cadastros;</li>
      <li>usar o sistema para fim diverso do cuidado do animal e do registro profissional;</li>
      <li>inserir conteúdo ilícito, ofensivo, ou dado pessoal de terceiro sem base legal;</li>
      <li>inserir dado sensível de pessoa natural sem necessidade clínica;</li>
      <li>burlar, testar ou contornar mecanismos de segurança, limites de tentativa ou controles de acesso;</li>
      <li>fazer engenharia reversa, descompilar ou copiar o software;</li>
      <li>automatizar o uso por robôs ou scripts sem autorização escrita.</li>
    </ul>
    <p>Uso em desacordo com esta seção sujeita a conta a suspensão, na forma da seção 14.</p>

    <h2>11. Propriedade intelectual e titularidade dos dados</h2>
    <p>
      O software, a marca, a interface, o código-fonte e a documentação do Imunia pertencem a
      <span class="legal-campo">[RAZÃO SOCIAL]</span>. Estes Termos concedem licença de uso
      pessoal, não exclusiva, intransferível e revogável — nunca cessão de direitos.
    </p>
    <p>
      <strong>Os dados inseridos na plataforma não pertencem ao Imunia.</strong> O cadastro de
      tutores e animais e o conteúdo clínico permanecem sob titularidade dos respectivos
      titulares e sob controle do estabelecimento que os produziu. O Imunia os hospeda e os
      processa para prestar o serviço, e por nenhuma outra razão.
    </p>

    <h2>12. Disponibilidade</h2>
    <div class="legal-destaque">
      <span class="legal-destaque__rotulo">Cláusula de destaque</span>
      <p>
        O Imunia é fornecido <strong>no estado em que se encontra</strong>. Nesta fase,
        trata-se de sistema em desenvolvimento, sem nível de serviço contratado, sem garantia
        de disponibilidade contínua e sem compromisso de tempo de restabelecimento.
        Manutenções podem ocorrer sem aviso prévio.
      </p>
      <p>
        <strong>Não confie na plataforma como sua única cópia de um registro clínico.</strong>
        O estabelecimento continua obrigado a guardar o prontuário por cinco anos (Res. CFMV nº
        1.321/2020, art. 9º, § 3º) e deve manter meio próprio de preservação, exportando
        periodicamente o que precisar conservar.
      </p>
    </div>
    <p>
      Esta cláusula será revista quando e se a plataforma entrar em operação comercial,
      hipótese em que passará a haver compromisso de disponibilidade declarado.
    </p>

    <h2>13. Limitação de responsabilidade</h2>
    <div class="legal-destaque">
      <span class="legal-destaque__rotulo">Cláusula de destaque — leia com atenção</span>
      <p>O Imunia <strong>não responde</strong> por:</p>
      <ul>
        <li>
          decisões clínicas tomadas por médico-veterinário, ainda que apoiadas em informação
          exibida pela plataforma;
        </li>
        <li>
          veracidade, exatidão ou completude do conteúdo inserido por usuários, inclusive
          registros pregressos lançados por tutores;
        </li>
        <li>
          consequências de acesso ao histórico por estabelecimento que atenda o animal, na
          forma da seção 7.3, ressalvada a responsabilidade do próprio estabelecimento pelo uso
          que fizer dos dados;
        </li>
        <li>uso ou divulgação de documento exportado, depois de baixado;</li>
        <li>
          indisponibilidade decorrente de caso fortuito, força maior, falha de terceiro
          fornecedor de infraestrutura ou interrupção de rede alheia ao seu controle;
        </li>
        <li>danos decorrentes do compartilhamento de credenciais pelo usuário.</li>
      </ul>
      <p><strong>Ressalvas que prevalecem sobre a limitação acima:</strong></p>
      <p>
        <strong>(a)</strong> Nada nesta seção afasta a responsabilidade do Imunia por
        <strong>dolo, culpa grave</strong>, ou por <strong>violação de dever de segurança no
        tratamento de dados pessoais</strong> (LGPD, arts. 42 a 45).
      </p>
      <p>
        <strong>(b)</strong> Perante o <strong>tutor</strong>, que se equipara a consumidor nos
        termos dos arts. 17 e 29 do Código de Defesa do Consumidor, esta limitação
        <strong>não se aplica</strong> na medida em que atenuaria responsabilidade por vício ou
        defeito do serviço — hipótese vedada pelo art. 51, I do CDC.
      </p>
      <p>
        <strong>(c)</strong> Perante o <strong>estabelecimento</strong>, pessoa jurídica que
        contrata a plataforma para sua atividade empresarial, a alocação de riscos aqui
        definida é válida e deve ser observada, nos termos do art. 421-A, II do Código Civil.
      </p>
      <p>
        <strong>(d)</strong> São <strong>nulas</strong>, e não integram estes Termos, quaisquer
        cláusulas que impliquem renúncia antecipada a direito decorrente da natureza do
        contrato (Código Civil, art. 424) ou que coloquem o usuário em desvantagem exagerada
        (CDC, art. 51, IV).
      </p>
    </div>

    <h2>14. Suspensão e encerramento</h2>

    <h3>14.1 Por você</h3>
    <p>
      Você pode encerrar sua conta a qualquer momento, sem custo, escrevendo para o canal de
      contato.
    </p>
    <div class="legal-destaque">
      <span class="legal-destaque__rotulo">Cláusula de destaque</span>
      <p>
        O sistema <strong>ainda não possui tela de encerramento de conta</strong>; o pedido é
        atendido por canal humano. Isso não limita seu direito, apenas o meio de exercê-lo. O
        encerramento <strong>não apaga</strong> registros clínicos sujeitos à guarda
        obrigatória de cinco anos, nem os registros de acesso sujeitos à guarda de seis meses
        do art. 15 do Marco Civil da Internet.
      </p>
    </div>

    <h3>14.2 Pelo Imunia</h3>
    <p>
      A conta pode ser suspensa ou encerrada, <strong>mediante aviso prévio e
      motivação</strong>, em caso de violação destes Termos, fraude, uso que ameace a segurança
      da plataforma ou de terceiros, ou determinação legal ou judicial.
    </p>
    <p>
      Suspensão sem aviso prévio só ocorre quando o aviso for incompatível com a urgência da
      ameaça — hipótese em que a motivação é comunicada em seguida. Você poderá contestar a
      decisão pelo canal de contato, e a suspensão será revista. Antes do encerramento
      definitivo, será concedido prazo razoável para exportação dos dados que você tenha
      direito de conservar.
    </p>

    <h2>15. Alterações destes Termos</h2>
    <p>
      Alterações relevantes serão comunicadas <strong>com antecedência mínima de 30 dias</strong>,
      por e-mail e por aviso na plataforma.
    </p>
    <div class="legal-destaque">
      <span class="legal-destaque__rotulo">Cláusula de destaque</span>
      <p>
        Se você não concordar com a nova versão, poderá <strong>encerrar sua conta sem
        qualquer ônus</strong> antes de sua entrada em vigor. Alterações não retroagem para
        prejudicar situações já constituídas. O Imunia não modifica unilateralmente o conteúdo
        ou a qualidade do serviço já contratado sem essa comunicação e sem esse direito de
        saída, o que seria vedado pelo art. 51, XIII do Código de Defesa do Consumidor.
      </p>
    </div>

    <h2>16. Preço</h2>
    <p>
      A plataforma é <strong>atualmente oferecida sem cobrança</strong>, na condição de sistema
      em desenvolvimento.
    </p>
    <p>
      Caso venha a ser cobrada, o preço, a forma de reajuste por índice objetivo e as condições
      de pagamento serão informados com a antecedência da seção 15, e o uso remunerado
      dependerá de nova manifestação sua.
      <strong>Nenhuma cobrança será feita sem aceite prévio e expresso.</strong>
    </p>

    <h2>17. Proteção de dados</h2>
    <p>
      O tratamento de dados pessoais é descrito na
      <RouterLink to="/privacidade">Política de Privacidade</RouterLink>, que integra estes
      Termos. Em caso de divergência entre os dois documentos quanto a tratamento de dados
      pessoais, <strong>prevalece a Política de Privacidade</strong>.
    </p>

    <h2>18. Disposições finais</h2>
    <p>
      <strong>Independência das cláusulas.</strong> A nulidade de uma cláusula não invalida as
      demais, que permanecem em vigor.
    </p>
    <p>
      <strong>Tolerância não é renúncia.</strong> Deixar de exigir o cumprimento de uma
      obrigação não significa renunciar a ela.
    </p>
    <p>
      <strong>Comunicações.</strong> São feitas para o e-mail cadastrado. Mantenha-o atualizado
      e confirmado.
    </p>
    <p><strong>Lei aplicável.</strong> Estes Termos são regidos pela lei brasileira.</p>
    <p>
      <strong>Foro.</strong> Fica eleito o foro da comarca de
      <span class="legal-campo">[ENDEREÇO]</span> para dirimir controvérsias.
    </p>
    <div class="legal-destaque">
      <span class="legal-destaque__rotulo">Cláusula de destaque</span>
      <p>
        A eleição de foro acima <strong>não se aplica ao tutor</strong>, que poderá demandar no
        foro de seu próprio domicílio (CDC, art. 101, I). Não há cláusula de arbitragem
        compulsória, vedada pelo art. 51, VII do CDC, nem qualquer restrição ao acesso ao Poder
        Judiciário, vedada pelo art. 51, XVII. O foro brasileiro é sempre disponível ao
        aderente, como exige o art. 8º, parágrafo único, II do Marco Civil da Internet.
      </p>
    </div>

    <h2>19. Contato</h2>
    <p>
      <span class="legal-campo">[RAZÃO SOCIAL]</span> — CNPJ
      <span class="legal-campo">[CNPJ]</span> — <span class="legal-campo">[ENDEREÇO]</span><br />
      Contato: <span class="legal-campo">[E-MAIL DE CONTATO]</span>
    </p>
    <p>
      Encarregado pela proteção de dados: ver
      <RouterLink to="/privacidade">Política de Privacidade</RouterLink>, seção 1.2.
    </p>
  </LegalDocument>
</template>
