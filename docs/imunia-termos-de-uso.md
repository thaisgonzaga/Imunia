---
title: "Imunia — Termos de Uso"
subtitle: "Condições de uso da plataforma por tutores, médicos-veterinários e estabelecimentos"
date: "8 de setembro de 2026 — versão 1.0"
lang: pt-BR
---

# Termos de Uso

**Versão 1.0 — em vigor desde 8 de setembro de 2026.**

## Nota preliminar de escopo

Estes Termos descrevem o Imunia **tal como está implementado**. Onde uma funcionalidade não existe, o texto não a promete. Onde o sistema é um protótipo acadêmico, o texto diz que é.

Este arquivo é o documento de referência. O texto exibido ao usuário está em `imunia-frontend/src/views/public/TermsView.vue`, servido em `/termos`, e a versão gravada no aceite vem de `imunia-backend/app/Support/DocumentosLegais.php`. Ao publicar uma versão nova, os três precisam mudar juntos.

Os campos entre colchetes — `[RAZÃO SOCIAL]`, `[CNPJ]`, `[ENDEREÇO]`, `[E-MAIL DE CONTATO]` — só podem ser preenchidos por quem responder juridicamente pela operação. Enquanto o Imunia não tiver pessoa jurídica constituída e não estiver em operação comercial, estes Termos valem como documento de referência do projeto e como texto exibido no protótipo, não como contrato em vigor entre partes determinadas.

Leia antes de aceitar. **As cláusulas que limitam direitos estão em destaque**, como exige o art. 54, § 4º do Código de Defesa do Consumidor.

---

## 1. O que é o Imunia — e o que ele não é

O Imunia é uma plataforma digital de **calendário vacinal e prontuário médico-veterinário** para cães e gatos. Ele permite que tutores acompanhem a vacinação de seus animais, que médicos-veterinários registrem atendimentos e vacinações, e que o histórico de um animal acompanhe-o entre estabelecimentos diferentes, **sempre mediante autorização expressa do tutor**.

**O Imunia é um instrumento de registro. Ele não é, e não pretende ser:**

- um serviço de saúde ou de atendimento veterinário — quem atende é o profissional, não o sistema;
- um substituto da consulta presencial ou do julgamento clínico;
- um emissor de prescrição, de diagnóstico ou de conduta;
- um serviço de urgência ou emergência;
- uma fonte de aconselhamento veterinário ao tutor.

As sugestões de doses e datas apresentadas pelo sistema decorrem de protocolos técnicos de referência aplicados de forma automática. **São sugestões.** A decisão clínica cabe ao médico-veterinário, que pode divergir delas registrando a justificativa.

---

## 2. Definições

**Plataforma** — o sistema Imunia, em suas interfaces web e serviços associados.
**Tutor** — pessoa natural responsável por um ou mais animais cadastrados.
**Estabelecimento** — clínica, hospital veterinário ou profissional autônomo cadastrado como prestador.
**Profissional** — médico-veterinário com inscrição ativa no CRMV, vinculado a um estabelecimento.
**Administrador do estabelecimento** — quem administra a conta do estabelecimento, com ou sem inscrição no CRMV.
**Autorização** — permissão concedida pelo tutor a um estabelecimento determinado, para acessar o histórico de um animal determinado.
**Registro clínico** — atendimento, vacinação ou anexo lançado na plataforma.
**Registro pregresso** — vacinação anterior informada pelo tutor a partir de documento que possua.

---

## 3. Aceite

Ao marcar a caixa de aceite no cadastro, ou ao usar a plataforma, você declara ter lido e concordado com estes Termos e com a [Política de Privacidade](imunia-politica-de-privacidade.md).

O sistema registra a data e a hora do seu aceite. Cada versão destes Termos tem número e data no topo do documento.

---

## 4. Quem pode usar

Para usar a plataforma você deve:

- ter **18 anos ou mais** e capacidade civil plena;
- fornecer informações verdadeiras, exatas e atualizadas;
- ser o legítimo responsável pelos animais que cadastrar, quando for tutor;
- possuir **inscrição ativa no CRMV**, quando atuar como médico-veterinário.

A plataforma não se destina a menores de 18 anos. Constatado cadastro de menor sem representação legal, a conta será encerrada.

---

## 5. Sua conta

Você é responsável por manter a confidencialidade da sua senha e por tudo o que for feito na plataforma com suas credenciais.

- **Não compartilhe sua conta.** No prontuário veterinário, quem assina o registro é quem responde por ele. Uma conta compartilhada torna a autoria indeterminável e compromete a validade do documento.
- Comunique imediatamente qualquer uso não autorizado pelo canal **[E-MAIL DE CONTATO]**.
- A confirmação do endereço de e-mail é condição para atos sensíveis, como conceder autorização de acesso.
- Ao trocar sua senha, as demais sessões abertas são encerradas.

O sistema **não oferece autenticação em duas etapas para o login**. A verificação por código de seis dígitos existe apenas no ato de conceder autorização de acesso. Escolha uma senha forte e não a reutilize.

---

## 6. Regras aplicáveis ao tutor

### 6.1 O que você controla

O histórico do seu animal não circula sozinho. **Somente você autoriza** um estabelecimento a vê-lo. A autorização:

- é específica por animal e por estabelecimento, nunca genérica;
- exige confirmação por código enviado ao seu e-mail;
- **vale 90 dias**, renováveis;
- pode ser **revogada por você a qualquer momento**, sem justificativa e sem custo.

Você pode consultar, a qualquer tempo, o registro de quem acessou dados dos seus animais, com identificação do estabelecimento, do profissional, da data e da natureza do acesso.

### 6.2 Registro pregresso: o que você lança e o que isso vale

Você pode lançar vacinações anteriores a partir de documentos que possua — a carteira de papel, por exemplo.

> **CLÁUSULA DE DESTAQUE.** O registro pregresso é **declaração sua**, e não ato profissional. Ele é assim identificado no histórico, distinguido dos registros lançados por médico-veterinário. **Não tem valor de atestado de vacinação**, que é privativo do médico-veterinário (Resolução CFMV nº 1.321/2020, art. 4º), e não pode ser apresentado como tal a autoridades, a estabelecimentos ou a terceiros. Informar dado falso pode expor o animal a risco sanitário e você a responsabilidade.

### 6.3 O que você não pode fazer

- Cadastrar animal do qual não seja responsável.
- Usar CPF de terceiro ou informar dados falsos.
- Apresentar registro pregresso como se fosse registro profissional.

### 6.4 Revogar não apaga

> **CLÁUSULA DE DESTAQUE.** Ao revogar uma autorização, o estabelecimento perde o acesso ao histórico produzido por outros — **mas conserva os registros que ele mesmo realizou**. A Resolução CFMV nº 1.321/2020, art. 9º, § 3º, obriga o profissional a arquivar o prontuário por pelo menos cinco anos após o último atendimento, mesmo em caso de óbito do animal. Nem o Imunia nem o estabelecimento podem afastar essa obrigação a seu pedido.

---

## 7. Regras aplicáveis a estabelecimentos e profissionais

### 7.1 Responsabilidade pelo conteúdo clínico

O estabelecimento e o profissional são os **únicos responsáveis** pela veracidade, exatidão, completude e adequação técnica do que registram. O Imunia não revisa, não valida e não audita conteúdo clínico.

Cada registro grava, de forma imutável, o nome e o número de inscrição no CRMV do profissional responsável, como exige a Resolução CFMV nº 1.321/2020, art. 9º, VIII.

### 7.2 Sigilo profissional

O prontuário está protegido por sigilo. Ao usar a plataforma, o estabelecimento e seus profissionais obrigam-se a observar:

- o **art. 154 do Código Penal**, que tipifica como crime revelar segredo obtido em razão da profissão;
- o **art. 11 do Código de Ética do Médico-Veterinário** (Res. CFMV nº 1.138/2016), que veda facilitar o acesso a prontuários, prestar informação sobre paciente ou cliente sem autorização expressa do responsável, e **permitir o uso do cadastro de clientes sem a respectiva autorização**.

> **CLÁUSULA DE DESTAQUE.** É vedado ao estabelecimento usar dados de tutores ou de animais obtidos pela plataforma para finalidade estranha ao atendimento — inclusive publicidade, prospecção, cessão a terceiros, pesquisa identificável ou cruzamento com bases próprias — **sem autorização expressa e específica do titular**. A violação é infração ética do profissional, além de tratamento sem base legal.

### 7.3 Acesso condicionado

O estabelecimento **pode solicitar** autorização ao tutor; **não pode concedê-la a si mesmo**. Buscar um cadastro revela apenas sua existência, nunca seu conteúdo.

Toda visualização de histórico produzido por outro estabelecimento é registrada e **fica visível ao tutor**. Esse registro é condição da exibição: ver implica ficar registrado.

Autorização vencida ou revogada encerra o acesso, sem aviso adicional além da notificação de revogação.

### 7.4 Equipe

O estabelecimento é responsável por convidar, manter e encerrar os vínculos de sua equipe, e por garantir que cada profissional tenha inscrição ativa no CRMV.

Encerrar o vínculo de um profissional **remove seu acesso, não sua autoria**: os registros que ele produziu continuam identificados com seu nome e CRMV, como a norma profissional exige.

Perfis administrativos sem inscrição no CRMV **não acessam dados de tutores, de animais ou registros clínicos**. Essa separação é estrutural e não pode ser afrouxada por configuração.

### 7.5 Papel do Imunia quanto ao conteúdo clínico

Quanto ao conteúdo clínico registrado pelo estabelecimento, o Imunia atua como **operador**, e o estabelecimento como **controlador**. O Imunia trata esses dados apenas conforme as instruções do estabelecimento e **não os utiliza para finalidade própria** — não os comercializa, não os cede, não os usa para publicidade e não os cruza entre estabelecimentos fora do mecanismo de autorização do tutor.

A distribuição completa de papéis está na seção 1 da [Política de Privacidade](imunia-politica-de-privacidade.md).

---

## 8. Registros são imutáveis; a correção é retificação

> **CLÁUSULA DE DESTAQUE.** Atendimentos e vacinações, uma vez lançados, **não são editados nem excluídos**. A Resolução CFMV nº 1.321/2020, art. 2º, VIII, exige prontuário sem rasuras ou emendas, com autenticidade e integridade garantidas.
>
> Corrigir um registro significa **emitir uma retificação vinculada ao original**, com a justificativa da correção. Ambos permanecem visíveis no histórico. O erro não desaparece; a correção fica ao lado dele.
>
> Isso vale inclusive para pedidos fundados no direito de correção do art. 18, III da LGPD, que é atendido por retificação — não por apagamento.

---

## 9. Documentos exportados

A plataforma emite documentos em PDF — carteira de vacinação e histórico — com código de verificação e resumo criptográfico que permitem conferir sua autenticidade em página pública.

> **CLÁUSULA DE DESTAQUE.** Um documento exportado contém o nome do tutor e os dados do animal, e **circula fora do controle da plataforma** depois de baixado. Quem o receber verá seu conteúdo. Quem tiver o código de verificação poderá confirmar a autenticidade do documento e ver o nome e a espécie do animal — nunca o tutor, o CPF, o conteúdo clínico ou o estabelecimento emissor. A guarda e a distribuição do arquivo são responsabilidade de quem o emitiu.

---

## 10. Uso aceitável

É vedado:

- acessar ou tentar acessar dados sem autorização vigente;
- realizar buscas em massa, raspagem de dados ou extração sistemática de cadastros;
- usar o sistema para fim diverso do cuidado do animal e do registro profissional;
- inserir conteúdo ilícito, ofensivo, ou dado pessoal de terceiro sem base legal;
- inserir dado sensível de pessoa natural sem necessidade clínica;
- burlar, testar ou contornar mecanismos de segurança, limites de tentativa ou controles de autorização;
- fazer engenharia reversa, descompilar ou copiar o software;
- automatizar o uso por robôs ou scripts sem autorização escrita.

Uso em desacordo com esta seção sujeita a conta a suspensão, na forma da seção 14.

---

## 11. Propriedade intelectual e titularidade dos dados

O software, a marca, a interface, o código-fonte e a documentação do Imunia pertencem a **[RAZÃO SOCIAL]**. Estes Termos concedem licença de uso pessoal, não exclusiva, intransferível e revogável — nunca cessão de direitos.

**Os dados inseridos na plataforma não pertencem ao Imunia.** O cadastro de tutores e animais e o conteúdo clínico permanecem sob titularidade dos respectivos titulares e sob controle do estabelecimento que os produziu. O Imunia os hospeda e os processa para prestar o serviço, e por nenhuma outra razão.

---

## 12. Disponibilidade

> **CLÁUSULA DE DESTAQUE.** O Imunia é fornecido **no estado em que se encontra**. Nesta fase, trata-se de sistema em desenvolvimento, sem nível de serviço contratado, sem garantia de disponibilidade contínua e sem compromisso de tempo de restabelecimento. Manutenções podem ocorrer sem aviso prévio.
>
> **Não confie na plataforma como sua única cópia de um registro clínico.** O estabelecimento continua obrigado a guardar o prontuário por cinco anos (Res. CFMV nº 1.321/2020, art. 9º, § 3º) e deve manter meio próprio de preservação, exportando periodicamente o que precisar conservar.

Esta cláusula será revista quando e se a plataforma entrar em operação comercial, hipótese em que passará a haver compromisso de disponibilidade declarado.

---

## 13. Limitação de responsabilidade

> **CLÁUSULA DE DESTAQUE — LEIA COM ATENÇÃO.**
>
> O Imunia **não responde** por:
>
> - decisões clínicas tomadas por médico-veterinário, ainda que apoiadas em informação exibida pela plataforma;
> - veracidade, exatidão ou completude do conteúdo inserido por usuários, inclusive registros pregressos lançados por tutores;
> - consequências de autorização concedida pelo tutor a um estabelecimento;
> - uso ou divulgação de documento exportado, depois de baixado;
> - indisponibilidade decorrente de caso fortuito, força maior, falha de terceiro fornecedor de infraestrutura ou interrupção de rede alheia ao seu controle;
> - danos decorrentes do compartilhamento de credenciais pelo usuário.
>
> **Ressalvas que prevalecem sobre a limitação acima:**
>
> **(a)** Nada nesta seção afasta a responsabilidade do Imunia por **dolo, culpa grave**, ou por **violação de dever de segurança no tratamento de dados pessoais** (LGPD, arts. 42 a 45).
>
> **(b)** Perante o **tutor**, que se equipara a consumidor nos termos dos arts. 17 e 29 do Código de Defesa do Consumidor, esta limitação **não se aplica** na medida em que atenuaria responsabilidade por vício ou defeito do serviço — hipótese vedada pelo art. 51, I do CDC.
>
> **(c)** Perante o **estabelecimento**, pessoa jurídica que contrata a plataforma para sua atividade empresarial, a alocação de riscos aqui definida é válida e deve ser observada, nos termos do art. 421-A, II do Código Civil.
>
> **(d)** São **nulas**, e não integram estes Termos, quaisquer cláusulas que impliquem renúncia antecipada a direito decorrente da natureza do contrato (Código Civil, art. 424) ou que coloquem o usuário em desvantagem exagerada (CDC, art. 51, IV).

---

## 14. Suspensão e encerramento

### 14.1 Por você

Você pode encerrar sua conta a qualquer momento, sem custo, escrevendo para **[E-MAIL DE CONTATO]**.

> **CLÁUSULA DE DESTAQUE.** O sistema **ainda não possui tela de encerramento de conta**; o pedido é atendido por canal humano. Isso não limita seu direito, apenas o meio de exercê-lo. O encerramento **não apaga** registros clínicos sujeitos à guarda obrigatória de cinco anos, nem os registros de acesso sujeitos à guarda de seis meses do art. 15 do Marco Civil da Internet.

### 14.2 Pelo Imunia

A conta pode ser suspensa ou encerrada, **mediante aviso prévio e motivação**, em caso de violação destes Termos, fraude, uso que ameace a segurança da plataforma ou de terceiros, ou determinação legal ou judicial.

Suspensão sem aviso prévio só ocorre quando o aviso for incompatível com a urgência da ameaça — hipótese em que a motivação é comunicada em seguida. Você poderá contestar a decisão pelo canal de contato, e a suspensão será revista.

Antes do encerramento definitivo, será concedido prazo razoável para exportação dos dados que você tenha direito de conservar.

---

## 15. Alterações destes Termos

Alterações relevantes serão comunicadas **com antecedência mínima de 30 dias**, por e-mail e por aviso na plataforma.

> **CLÁUSULA DE DESTAQUE.** Se você não concordar com a nova versão, poderá **encerrar sua conta sem qualquer ônus** antes de sua entrada em vigor. Alterações não retroagem para prejudicar situações já constituídas. O Imunia não modifica unilateralmente o conteúdo ou a qualidade do serviço já contratado sem essa comunicação e sem esse direito de saída, o que seria vedado pelo art. 51, XIII do Código de Defesa do Consumidor.

---

## 16. Preço

A plataforma é **atualmente oferecida sem cobrança**, na condição de sistema em desenvolvimento.

Caso venha a ser cobrada, o preço, a forma de reajuste por índice objetivo e as condições de pagamento serão informados com a antecedência da seção 15, e o uso remunerado dependerá de nova manifestação sua. **Nenhuma cobrança será feita sem aceite prévio e expresso.**

---

## 17. Proteção de dados

O tratamento de dados pessoais é descrito na [Política de Privacidade](imunia-politica-de-privacidade.md), que integra estes Termos.

Em caso de divergência entre os dois documentos quanto a tratamento de dados pessoais, **prevalece a Política de Privacidade**.

---

## 18. Disposições finais

**Independência das cláusulas.** A nulidade de uma cláusula não invalida as demais, que permanecem em vigor.

**Tolerância não é renúncia.** Deixar de exigir o cumprimento de uma obrigação não significa renunciar a ela.

**Comunicações.** São feitas para o e-mail cadastrado. Mantenha-o atualizado e confirmado.

**Lei aplicável.** Estes Termos são regidos pela lei brasileira.

**Foro.** Fica eleito o foro da comarca de **[ENDEREÇO]** para dirimir controvérsias.

> **CLÁUSULA DE DESTAQUE.** A eleição de foro acima **não se aplica ao tutor**, que poderá demandar no foro de seu próprio domicílio (CDC, art. 101, I). Não há cláusula de arbitragem compulsória, vedada pelo art. 51, VII do CDC, nem qualquer restrição ao acesso ao Poder Judiciário, vedada pelo art. 51, XVII. O foro brasileiro é sempre disponível ao aderente, como exige o art. 8º, parágrafo único, II do Marco Civil da Internet.

---

## 19. Contato

**[RAZÃO SOCIAL]** — CNPJ **[CNPJ]** — **[ENDEREÇO]**
Contato: **[E-MAIL DE CONTATO]**
Encarregado pela proteção de dados: ver [Política de Privacidade](imunia-politica-de-privacidade.md), seção 1.2.

---

### Normas citadas

- Lei nº 13.709/2018 (LGPD), arts. 18, 42 a 45
- Lei nº 8.078/1990 (Código de Defesa do Consumidor), arts. 17, 29, 51, 54 e 101
- Lei nº 10.406/2002 (Código Civil), arts. 421-A, 423 e 424
- Lei nº 12.965/2014 (Marco Civil da Internet), arts. 8º e 15
- Decreto-Lei nº 2.848/1940 (Código Penal), art. 154
- Resolução CFMV nº 1.321/2020, alterada pela Resolução CFMV nº 1.653/2025
- Resolução CFMV nº 1.138/2016 — Código de Ética do Médico-Veterinário
