---
title: "Imunia — Política de Privacidade"
subtitle: "Como o sistema trata dados pessoais, com que fundamento e por quanto tempo"
date: "8 de setembro de 2026 — versão 1.0"
lang: pt-BR
---

# Política de Privacidade

**Versão 1.0 — em vigor desde 8 de setembro de 2026.**

## Nota preliminar de escopo

Este documento foi redigido para o sistema Imunia **tal como está implementado**, e não para uma versão idealizada dele. Onde uma funcionalidade ainda não existe, o texto diz que não existe, em vez de prometê-la. Onde a lei assegura um direito que o sistema ainda não automatizou, o texto informa o canal humano pelo qual esse direito é exercido.

Este arquivo é o documento de referência. O texto exibido ao usuário está em `imunia-frontend/src/views/public/PrivacyView.vue`, servido em `/privacidade`, e a versão gravada no aceite vem de `imunia-backend/app/Support/DocumentosLegais.php`. Ao publicar uma versão nova, os três precisam mudar juntos.

Os campos entre colchetes — `[RAZÃO SOCIAL]`, `[CNPJ]`, `[ENDEREÇO]`, `[NOME DO ENCARREGADO]`, `[E-MAIL DO ENCARREGADO]` — só podem ser preenchidos por quem responder juridicamente pela operação. Enquanto o Imunia for um protótipo acadêmico sem pessoa jurídica constituída, esses campos permanecem em aberto, e esta política descreve o tratamento de dados de um sistema em desenvolvimento, não de um serviço em operação comercial.

---

## 1. Quem responde pelos seus dados

O responsável pelo tratamento é **[RAZÃO SOCIAL]**, inscrita no CNPJ sob o nº **[CNPJ]**, com sede em **[ENDEREÇO]**, doravante "Imunia".

### 1.1 Nem todo dado do sistema tem o mesmo responsável

O Imunia é uma plataforma usada por dois grupos distintos: tutores de animais e estabelecimentos veterinários. Isso significa que, para partes diferentes dos dados, o responsável é diferente. Saber quem responde pelo quê é o que permite a você dirigir um pedido à pessoa certa.

| Conjunto de dados | Quem decide sobre o tratamento | Papel do Imunia |
|---|---|---|
| Conta, e-mail, senha, autenticação e sessões | Imunia | **Controlador** |
| Cadastro do tutor e do animal feito pelo próprio tutor | Imunia | **Controlador** |
| Autorizações de acesso, solicitações e registros de acesso | Imunia | **Controlador** |
| Comunicações enviadas pelo sistema (confirmação de e-mail, convites, códigos) | Imunia | **Controlador** |
| Conteúdo clínico — atendimentos, vacinações, anexos e laudos | O estabelecimento veterinário que os registrou | **Operador** |
| Cadastro de tutor e de animal criado por um estabelecimento | O estabelecimento que o criou | **Operador** |
| Dados cadastrais do próprio estabelecimento e de sua equipe | Imunia | **Controlador** |

Quando o Imunia atua como **operador**, ele trata os dados seguindo as instruções do estabelecimento e **não os utiliza para finalidade própria** — não os vende, não os cede, não os usa para publicidade, não os usa para treinar modelos e não os cruza entre estabelecimentos fora do mecanismo de autorização descrito na seção 6.

### 1.2 Encarregado pela proteção de dados

**[NOME DO ENCARREGADO]** — **[E-MAIL DO ENCARREGADO]**

O encarregado é o canal entre você, o Imunia e a Agência Nacional de Proteção de Dados (ANPD). É a ele que você dirige pedidos, dúvidas e reclamações sobre seus dados.

> *Base: LGPD, art. 41, § 1º; Resolução CD/ANPD nº 18/2024, arts. 8º e 9º, que exigem a divulgação da identidade e do contato do encarregado em local de destaque e de fácil acesso.*

---

## 2. A quem esta política se dirige

Esta política descreve o tratamento de dados de quatro grupos de pessoas naturais:

- **Tutores** — pessoas responsáveis por um ou mais animais cadastrados;
- **Médicos-veterinários** — profissionais com inscrição no CRMV vinculados a um estabelecimento;
- **Responsáveis por estabelecimentos** — quem administra a conta de uma clínica, hospital ou atividade autônoma;
- **Terceiros que verificam um documento** — quem recebe um PDF exportado e confere sua autenticidade na página pública de verificação.

O animal **não é titular de dados** para efeito da LGPD, que protege dados de pessoa natural. O tratamento descrito aqui recai sobre as pessoas acima. A seção 5 explica o que decorre disso.

---

## 3. Que dados o sistema coleta

O Imunia coleta apenas o que precisa para funcionar. A lista abaixo é exaustiva em relação ao que o sistema efetivamente armazena hoje.

### 3.1 Dados de conta (todos os perfis)

Nome, endereço de e-mail e senha. A senha nunca é armazenada em texto legível: o sistema guarda apenas o resultado de uma função de derivação criptográfica (bcrypt), que não permite recuperar a senha original.

Também são registrados a data de confirmação do e-mail, a data de ativação da conta e, para retomar seu contexto de trabalho, o último estabelecimento em que você atuou.

### 3.2 Dados de sessão e de acesso

Cada sessão autenticada registra **endereço IP** e **identificação do navegador (user-agent)**, além da data e hora da última atividade. Esse registro é mantido em razão de obrigação legal, explicada na seção 8.

### 3.3 Dados do tutor

**Nome completo** e **CPF**. Também é registrada a data em que os Termos de Uso foram aceitos.

O CPF não é coletado por conveniência: ele é exigência da regulamentação profissional. A Resolução CFMV nº 1.321/2020, art. 3º, VII, determina que os documentos médico-veterinários identifiquem o responsável pelo animal por nome completo, CPF e endereço. No Imunia, o CPF cumpre ainda a função de permitir que um estabelecimento localize um cadastro já existente sem criar duplicidade.

**O sistema não coleta do tutor:** telefone, endereço residencial, CEP, data de nascimento, RG, gênero, foto de perfil ou dados de pagamento. O único canal de contato é o e-mail.

### 3.4 Dados do médico-veterinário e do responsável pelo estabelecimento

Nome, e-mail, **número de inscrição no CRMV e respectiva UF**. O nome e o CRMV do profissional são gravados também dentro de cada registro clínico e de cada vacinação que ele produz, e ali permanecem de forma imutável.

Essa gravação é obrigação regulatória: a Resolução CFMV nº 1.321/2020, art. 9º, VIII, exige que cada procedimento identifique o profissional responsável por nome completo e número de inscrição no CRMV.

### 3.5 Dados do estabelecimento

Razão ou nome, CNPJ, telefone, endereço, CEP, município, UF, e nome, CRMV e UF do responsável técnico. Toda alteração nesses campos é registrada com o valor anterior, o novo valor, quem alterou e quando.

O CNPJ de pessoa jurídica não é dado pessoal. Contudo, quando o estabelecimento é microempreendedor individual ou empresário individual, o cadastro se confunde com o de uma pessoa natural, e o Imunia trata esses dados com a mesma proteção dispensada a dados pessoais.

### 3.6 Dados do animal e conteúdo clínico

Nome, espécie, sexo, raça, pelagem, situação reprodutiva, data de nascimento (exata ou aproximada), microchip, fotografia, e, quando for o caso, data e causa do óbito.

O conteúdo clínico compreende atendimentos (motivo, anamnese, exame físico, hipóteses diagnósticas, diagnóstico, conduta, peso, data de retorno), vacinações (imunobiológico, fabricante, lote, validade, via e sítio de administração, data de aplicação, dose) e anexos (laudos e exames em PDF ou imagem).

Esses são dados **do animal**, mas estão vinculados a um tutor identificado. A seção 5 explica o regime que se aplica a eles.

### 3.7 Dados de autorização e de solicitação de acesso

Quem autorizou, qual estabelecimento, quais animais, quando a autorização foi concedida, quando expira e, se for o caso, quando foi revogada. Quando um estabelecimento solicita acesso, registra-se também a mensagem que ele escreveu ao tutor, limitada a 280 caracteres.

O código de seis dígitos enviado por e-mail para confirmar uma autorização **não é armazenado em texto legível** — o sistema guarda apenas seu resumo criptográfico, contra o qual compara o que você digita.

### 3.8 Documentos exportados

Ao emitir um PDF de carteira de vacinação ou de histórico, o sistema registra um código de verificação, um resumo criptográfico do conteúdo, o nome e a espécie do animal no momento da emissão, quem emitiu e quando.

---

## 4. Por que o sistema trata esses dados, e com que base legal

A LGPD exige que todo tratamento tenha uma finalidade específica e uma hipótese legal que o autorize. A tabela abaixo declara ambas, por finalidade.

| Finalidade | Dados envolvidos | Base legal (LGPD) |
|---|---|---|
| Criar e manter sua conta, autenticar o acesso | Nome, e-mail, senha | Art. 7º, V — execução de contrato |
| Identificar o tutor de forma inequívoca e evitar cadastro duplicado | CPF | Art. 7º, II — obrigação regulatória (Res. CFMV 1.321/2020, art. 3º, VII) |
| Manter a carteira de vacinação e o prontuário do animal | Dados do animal e conteúdo clínico | Art. 7º, II e V — obrigação regulatória e execução de contrato |
| Identificar o profissional responsável por cada procedimento | Nome e CRMV | Art. 7º, II — obrigação regulatória (Res. CFMV 1.321/2020, art. 9º, VIII) |
| Permitir a continuidade do histórico entre estabelecimentos | Autorizações e registros de acesso | Art. 7º, V — execução de contrato, mediante autorização expressa do tutor |
| Registrar quem acessou dados de quem, e mostrá-lo ao tutor | Registros de acesso | Art. 7º, II e IX — dever de segurança e prestação de contas |
| Enviar confirmações, convites e códigos de autorização | E-mail | Art. 7º, V — execução de contrato |
| Guardar registros de acesso à aplicação | IP, user-agent, data e hora | Art. 7º, II — obrigação legal (Marco Civil da Internet, art. 15) |
| Preservar registros para defesa em eventual processo | Conforme o caso | Art. 7º, VI e art. 16, I |
| Proteger o sistema contra fraude e uso indevido | Dados de sessão e tentativas de acesso | Art. 7º, IX — legítimo interesse |

Quando o tratamento se apoia em **legítimo interesse**, você pode se opor a ele pelo canal da seção 13. A avaliação que fundamenta esse uso está documentada e é disponibilizada mediante pedido.

> *Sobre a base de "tutela da saúde" (LGPD, arts. 7º, VIII e 11, II, "f"): ela não é invocada nesta política. A lei a restringe a procedimento realizado por profissionais de saúde, serviços de saúde ou autoridade sanitária, categorias que não alcançam a medicina veterinária. O tratamento se funda, portanto, em contrato e em obrigação regulatória.*

---

## 5. Uma palavra sobre dados de saúde animal

Circula a ideia de que o prontuário de um animal seria "dado pessoal sensível". Não é o que a lei diz, e vale explicar por quê — porque a resposta muda o que você pode exigir.

A LGPD define dado sensível, no art. 5º, II, como aquele referente à saúde de **uma pessoa natural**. A saúde registrada no Imunia é a do animal, e o animal não é titular de dados. O prontuário, contudo, **é dado pessoal do tutor**, porque é informação relacionada a uma pessoa identificada: seu nome e seu CPF estão no cadastro a que o histórico se vincula.

Há uma exceção que merece atenção. O art. 11, § 1º da LGPD estende o regime dos dados sensíveis a qualquer tratamento que **revele** dado sensível e possa causar dano ao titular. Um prontuário pode fazer isso — ao registrar uma zoonose transmitida ao tutor, ao identificar um animal de assistência ligado a uma deficiência, ou ao anotar uma condição do tutor como motivo de conduta. Quando isso ocorrer, o registro está sob o regime mais rigoroso, e o Imunia o trata como tal.

**A postura adotada é conservadora:** o sistema aplica a dados clínicos as mesmas salvaguardas devidas a dados sensíveis — acesso condicionado a autorização expressa, registro de cada consulta, imutabilidade dos registros, minimização do que se exibe — independentemente da classificação. Além disso, o prontuário veterinário está protegido por **sigilo profissional**, cuja violação é crime (Código Penal, art. 154) e infração ética (Res. CFMV nº 1.138/2016, art. 11).

---

## 6. Com quem os dados são compartilhados

### 6.1 Entre estabelecimentos, e só com autorização do tutor

Este é o núcleo do sistema, e a regra é simples: **nenhum estabelecimento vê o histórico produzido por outro sem que o tutor tenha autorizado, nominalmente, aquele estabelecimento e aquele animal.**

Como funciona:

- A autorização é concedida **exclusivamente pelo tutor**. Um estabelecimento pode solicitá-la, nunca concedê-la a si mesmo.
- Ela é específica por **animal** e por **estabelecimento**. Não existe autorização geral, nem autorização a um veterinário como pessoa física.
- Para concedê-la, o tutor confirma um **código de seis dígitos** enviado ao seu e-mail. Após cinco tentativas erradas, a confirmação é bloqueada por trinta minutos e o tutor é avisado.
- A autorização **vale 90 dias** e pode ser renovada. Vencida, o acesso cessa.
- O tutor pode **revogar a qualquer momento**, e o estabelecimento é notificado da revogação.
- Antes de exibir a um profissional qualquer histórico produzido por outro estabelecimento, o sistema avisa que aquela visualização será registrada e ficará visível ao tutor.

**A revogação não apaga registros.** O estabelecimento perde o acesso ao histórico alheio, mas os atendimentos e as vacinações que ele próprio realizou permanecem sob sua guarda. Isso não é escolha de produto: a Resolução CFMV nº 1.321/2020, art. 9º, § 3º, obriga o profissional a arquivar o prontuário por pelo menos cinco anos após o último atendimento, mesmo em caso de óbito do animal.

### 6.2 Perfis administrativos não alcançam dados clínicos

Quem administra a conta de um estabelecimento sem ser médico-veterinário **não tem acesso a dado algum de tutor, de animal ou de registro clínico**. A separação é estrutural no sistema, não uma configuração que se possa afrouxar.

### 6.3 Documento exportado e verificação pública

Um PDF exportado contém o nome do tutor, os dados do animal e o conteúdo escolhido — e, uma vez baixado, **circula fora do controle do sistema**. Quem o receber verá o que ele contém.

A página pública de verificação, acessível a quem tiver o código impresso no documento, revela apenas se o documento é autêntico, a data de emissão, o **nome e a espécie do animal** e um resumo abreviado. Ela não revela o tutor, o CPF, o conteúdo clínico nem o estabelecimento emissor.

### 6.4 Terceiros

O Imunia **não vende, não aluga e não cede dados pessoais**. Não há na plataforma serviços de analytics, publicidade, rastreamento comportamental, mapas, chat de suporte ou gateway de pagamento.

Os terceiros efetivamente envolvidos são:

| Terceiro | Para quê | Que dados alcança |
|---|---|---|
| Provedor de hospedagem e banco de dados | Executar o sistema | Todos, em repouso e em trânsito |
| Provedor de envio de e-mail | Entregar confirmações, convites e códigos | Nome, e-mail e o conteúdo da mensagem |
| Google Fonts | Carregar as fontes tipográficas da interface | Endereço IP e dados do navegador, ao carregar a página |

Quando esses fornecedores atuarem como operadores, o farão sob contrato que os obriga a tratar os dados apenas conforme instruções, com dever de segurança e de sigilo.

---

## 7. O registro de acessos: você vê quem viu

O Imunia mantém um **livro de acessos** consultável pelo tutor, que registra de forma imutável:

- buscas realizadas por CPF, por código do animal, por microchip ou por nome;
- consulta a ficha de animal sem autorização vigente;
- visualização de histórico produzido por outro estabelecimento;
- exportação de documento que contenha registro alheio;
- alertas de duplicidade de cadastro.

Cada entrada mostra o estabelecimento, o profissional (nome e CRMV), o animal, a natureza do acesso, a data e a hora, e qual autorização estava vigente naquele momento.

Esse registro cumpre, por interface e em tempo real, o direito do art. 18, VII da LGPD — saber com quem seus dados foram compartilhados — sem que seja preciso pedir.

---

## 8. Por quanto tempo os dados são guardados

| Dado | Prazo | Fundamento |
|---|---|---|
| Prontuário e vacinações | **5 anos** após o último atendimento, inclusive em caso de óbito | Res. CFMV nº 1.321/2020, art. 9º, § 3º |
| Registros de acesso à aplicação (IP, data e hora) | **6 meses**, sob sigilo | Marco Civil da Internet, art. 15 |
| Registros de incidentes de segurança | **5 anos** | Res. CD/ANPD nº 15/2024, art. 10 |
| Conta e cadastro | Enquanto a conta existir, e depois pelos prazos de prescrição aplicáveis | LGPD, art. 16, I |
| Autorizações e livro de acessos | Enquanto necessários à prestação de contas e à defesa de direitos | LGPD, art. 7º, VI e art. 16, I |
| Códigos de autorização e tokens de convite | Expiram em 5 minutos (código), 24 horas (confirmação de e-mail) ou 7 dias (convite) | Minimização — LGPD, art. 6º, III |

Findos os prazos, os dados são eliminados ou anonimizados, salvo quando a lei impuser guarda mais longa.

**Declaração honesta sobre o estado atual:** o sistema **ainda não possui rotina automatizada de expurgo**. Os prazos acima são a política declarada; sua execução, hoje, depende de procedimento manual. Esta é uma limitação conhecida, registrada como pendência de implementação.

---

## 9. Transferência internacional de dados

O carregamento das fontes tipográficas da interface é feito a partir dos servidores do Google (`fonts.googleapis.com` e `fonts.gstatic.com`), o que expõe o endereço IP do visitante a servidores possivelmente localizados fora do Brasil. É a **única requisição a domínio de terceiro** feita pela interface.

Nenhum dado de conta, de animal ou de prontuário é transferido nessa operação.

Caso a infraestrutura do Imunia venha a ser hospedada fora do Brasil, aplicam-se:

- para a União Europeia e os países do Espaço Econômico Europeu, o reconhecimento de grau adequado de proteção, que dispensa mecanismo adicional (LGPD, art. 33, I; Resolução CD/ANPD nº 32, de 26 de janeiro de 2026);
- para os demais países, as **cláusulas-padrão contratuais** do Anexo II da Resolução CD/ANPD nº 19/2024, adotadas integralmente e sem alteração.

---

## 10. Cookies e o que fica no seu dispositivo

O Imunia **não usa cookies de publicidade, de analytics ou de rastreamento**. Por isso não há banner de consentimento de cookies: não há nada a consentir além do estritamente necessário para o sistema funcionar.

| O que | Para quê | Onde |
|---|---|---|
| Cookie de sessão | Manter você autenticado. Não é legível por scripts da página. | Navegador |
| Cookie `XSRF-TOKEN` | Impedir que outro site execute ações em seu nome | Navegador |
| Rascunho da concessão de autorização | Preservar sua escolha de estabelecimento e animais durante o fluxo | `sessionStorage`, apagado ao fechar a aba |
| Rascunho de atendimento | Evitar que o veterinário perca o texto digitado se a página recarregar | `localStorage` do dispositivo |

**Atenção ao último item.** O rascunho de atendimento guarda conteúdo clínico **no próprio dispositivo do veterinário**, até que o registro seja concluído. Em computador compartilhado, encerre a sessão e conclua ou descarte os rascunhos abertos.

---

## 11. Segurança

As medidas adotadas incluem: transmissão cifrada, senhas armazenadas apenas como resultado de função de derivação criptográfica, tokens e códigos de autorização guardados apenas como resumo criptográfico, limitação de tentativas de acesso e de pedidos de autorização, invalidação das demais sessões ao trocar a senha, proteção contra requisições forjadas de outros sites, anexos servidos por rota autorizada e nunca por endereço direto, e registro imutável de acessos.

Nenhum sistema é imune. As medidas acima reduzem risco; não o eliminam.

**Limitação conhecida:** as fotografias de animais são armazenadas em área de arquivos servida publicamente. Quem conhecer o endereço de uma fotografia poderá acessá-la sem autenticação. Isso não expõe prontuário, nome de tutor ou CPF, mas é uma diferença real em relação ao tratamento dado aos anexos clínicos, que exigem autenticação e autorização.

---

## 12. Incidentes de segurança

Se ocorrer incidente que possa acarretar risco ou dano relevante, o Imunia comunicará **à ANPD e aos titulares afetados em até 3 (três) dias úteis**, contados do conhecimento de que o incidente atingiu dados pessoais, informando o que ocorreu, quais dados foram atingidos, os riscos envolvidos e as medidas adotadas.

Todo incidente é registrado e mantido por, no mínimo, cinco anos, ainda que não tenha exigido comunicação.

> *Base: LGPD, art. 48; Resolução CD/ANPD nº 15/2024, arts. 5º, 6º, 9º e 10. Registre-se que dados protegidos por sigilo profissional — como o prontuário — figuram entre os critérios de risco relevante do art. 5º, V daquela resolução.*

---

## 13. Seus direitos, e como exercê-los

A LGPD assegura a você, no art. 18:

1. **confirmação** de que existe tratamento dos seus dados;
2. **acesso** aos dados;
3. **correção** de dados incompletos, inexatos ou desatualizados;
4. **anonimização, bloqueio ou eliminação** de dados desnecessários, excessivos ou tratados em desconformidade com a lei;
5. **portabilidade** a outro fornecedor, conforme regulamentação da ANPD;
6. **eliminação** dos dados tratados com base em consentimento;
7. **informação** sobre com quem seus dados foram compartilhados;
8. **informação** sobre a possibilidade de não consentir e sobre as consequências da negativa;
9. **revogação do consentimento**, a qualquer momento.

Você pode ainda **opor-se** a tratamento fundado em dispensa de consentimento, quando houver descumprimento da lei (art. 18, § 2º).

### Como pedir

Escreva para **[E-MAIL DO ENCARREGADO]**. O atendimento é **gratuito**. Pediremos elementos que confirmem sua identidade — não para dificultar, mas porque entregar seus dados a quem se passe por você seria o pior desfecho possível.

**Prazo:** a confirmação de existência de tratamento e o acesso aos dados são prestados em **até 15 dias** (LGPD, art. 19, II). Em formato simplificado, imediatamente. Para os demais pedidos, responderemos no menor prazo praticável, informando desde logo o que for possível.

### Três limites que você deve conhecer de antemão

**Registros clínicos não são apagados nem editados.** A Resolução CFMV nº 1.321/2020, art. 2º, VIII, exige prontuário sem rasuras ou emendas, com autenticidade e integridade garantidas. No Imunia, corrigir um registro significa **emitir uma retificação vinculada ao registro original**, que passa a constar do histórico ao lado dele. O erro fica visível, e a correção também. Isso atende ao direito de correção do art. 18, III de forma compatível com a norma profissional — mas não produz o apagamento que você talvez esperasse.

**A guarda de cinco anos prevalece sobre o pedido de eliminação.** O art. 16, I da LGPD ressalva a conservação necessária ao cumprimento de obrigação legal ou regulatória. Enquanto correr o prazo do art. 9º, § 3º da Res. CFMV 1.321/2020, o prontuário será conservado ainda que você peça sua exclusão.

**O sistema ainda não oferece autoatendimento para exclusão de conta nem para download completo dos seus dados.** As telas correspondentes não foram implementadas. Isso **não suprime seus direitos**: eles são exercidos pelo canal do encarregado, com atendimento humano. É uma limitação de interface, não de direito, e está registrada como pendência.

### Se não ficarmos entendidos

Você pode peticionar à **ANPD** (LGPD, art. 18, § 1º) e recorrer aos **órgãos de defesa do consumidor** (art. 18, § 8º), independentemente de nos ter procurado antes.

---

## 14. Crianças e adolescentes

O Imunia **não se destina a menores de 18 anos**. O cadastro exige a declaração de capacidade civil prevista nos Termos de Uso, e o sistema não coleta idade, série escolar, geolocalização, conteúdo gerado por usuário de acesso público, nem qualquer dado que caracterize serviço atrativo ou dirigido ao público infantojuvenil.

Se tomarmos conhecimento de cadastro de menor sem representação, a conta será encerrada e os dados eliminados, ressalvado o que a lei obrigue a conservar.

> *Referência: LGPD, art. 14; Lei nº 15.211/2025 (ECA Digital), art. 1º, cujo escopo alcança serviços direcionados a crianças e adolescentes ou de acesso provável por eles — hipóteses em que o Imunia não se enquadra.*

---

## 15. Decisões tomadas por algoritmo

O sistema **calcula automaticamente as próximas doses vacinais** a partir de protocolos técnicos de referência, da espécie e do histórico do animal, e sinaliza doses previstas, próximas e atrasadas.

Esse cálculo é uma **sugestão técnica, não uma decisão sobre você**. Ele não define preço, não concede nem nega acesso, não avalia crédito e não produz efeito jurídico sobre nenhuma pessoa. A conduta é sempre do médico-veterinário, que pode divergir do cálculo registrando a justificativa.

Cada dose prevista exibe, na interface, a regra que a produziu e a versão do protocolo aplicado. Não há elaboração de perfil comportamental, pontuação de usuários ou publicidade dirigida.

---

## 16. Alterações nesta política

Esta política pode mudar quando o sistema mudar. Alterações relevantes — sobretudo em finalidades, bases legais, prazos ou compartilhamentos — serão comunicadas com destaque e com antecedência razoável, conforme o art. 8º, § 6º da LGPD.

Cada versão tem número e data no topo do documento. As versões anteriores permanecem disponíveis para consulta.

---

## 17. Contato

Encarregado pela proteção de dados: **[NOME DO ENCARREGADO]** — **[E-MAIL DO ENCARREGADO]**
Controlador: **[RAZÃO SOCIAL]**, CNPJ **[CNPJ]**, **[ENDEREÇO]**

Agência Nacional de Proteção de Dados — ANPD: <https://www.gov.br/anpd>

---

### Normas citadas

- Lei nº 13.709/2018 (LGPD), com as alterações das Leis nº 13.853/2019, 14.010/2020, 14.460/2022, 15.352/2026 e 15.452/2026
- Lei nº 12.965/2014 (Marco Civil da Internet), art. 15
- Lei nº 8.078/1990 (Código de Defesa do Consumidor)
- Decreto-Lei nº 2.848/1940 (Código Penal), art. 154
- Resolução CD/ANPD nº 15, de 24 de abril de 2024 — comunicação de incidentes
- Resolução CD/ANPD nº 18, de 16 de julho de 2024 — atuação do encarregado
- Resolução CD/ANPD nº 19, de 23 de agosto de 2024 — transferência internacional
- Resolução CD/ANPD nº 32, de 26 de janeiro de 2026 — adequação da União Europeia
- Resolução CFMV nº 1.321/2020, alterada pela Resolução CFMV nº 1.653/2025 — documentos médico-veterinários
- Resolução CFMV nº 1.138/2016 — Código de Ética do Médico-Veterinário
