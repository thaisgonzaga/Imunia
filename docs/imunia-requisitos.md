---
title: "Imunia --- Documento de Requisitos"
subtitle: "Etapa 3 do Trabalho de Conclusão de Curso: engenharia de requisitos"
date: "Agosto de 2026 --- versão 1.3"
lang: pt-BR
---

# 1. Nota preliminar

## 1.1 Finalidade deste documento

Este documento consolida a especificação de requisitos do sistema **Imunia**, derivada das decisões de projeto (Etapa 1) e da caracterização do problema (Etapa 2). Cumpre três funções distintas, que convém não confundir:

1. **Função de engenharia.** Define o que deve ser construído, em granularidade suficiente para orientar a modelagem (Etapa 5) e a implementação (Etapa 7).
2. **Função acadêmica.** Fornece o conteúdo do Capítulo 3 (Metodologia) e parte do Capítulo 4 (Resultados) da monografia, além de demonstrar à banca que o desenvolvimento foi precedido de elicitação sistemática, e não de intuição.
3. **Função de delimitação.** Registra explicitamente o que **não** será construído, protegendo o trabalho contra a expectativa implícita de completude funcional.

## 1.2 Processo de elicitação adotado

Requisitos de sistemas acadêmicos frequentemente aparecem sem que se declare de onde vieram. Aqui, cada requisito possui origem rastreável a uma das quatro fontes abaixo, e essa rastreabilidade deve constar do texto da monografia.

| Fonte | Técnica | Produto |
|---|---|---|
| Cenário de aplicação (Etapa 2, §3) | *Scenario-based design* (CARROLL, 2000) | Problemas P1 a P8 e requisitos deles decorrentes |
| Normas profissionais | Análise documental | Resoluções CFMV nº 1.321/2020 e nº 1.653/2025 — requisitos de conformidade |
| Diretrizes técnicas do domínio | Análise documental | Diretrizes WSAVA — parametrização do calendário vacinal |
| Análise de concorrentes | *Benchmarking* funcional | NuvemVet, LoopVet e Petfolio — requisitos de paridade e de diferenciação |
| Legislação de proteção de dados | Análise documental | Lei nº 13.709/2018 (LGPD) — requisitos de consentimento, acesso e segurança |

**Lacuna metodológica remanescente.** Não houve, até este momento, elicitação junto a usuários reais. A entrevista semiestruturada recomendada na Etapa 2 (§1.2) permanece a intervenção de maior retorno disponível: aplicada a um único médico-veterinário atuante, converteria este documento de *especificação derivada da literatura* em *especificação validada por profissional do domínio*. O roteiro da entrevista pode ser construído diretamente a partir das seções 4 e 6 deste documento, submetendo cada regra de negócio à confirmação do entrevistado.

## 1.3 Convenções de identificação e classificação

| Prefixo | Categoria | Definição operacional |
|---|---|---|
| RF | Requisito funcional | O que o sistema faz — função observável, com ator e resultado |
| RNF | Requisito não funcional | Como o sistema se comporta — qualidade mensurável, com critério de verificação |
| RN | Regra de negócio | Restrição do domínio, verdadeira independentemente de haver software |

A distinção entre RF e RN é fonte recorrente de imprecisão em monografias. Adota-se aqui o critério clássico: a regra de negócio existiria ainda que o sistema não fosse construído — "a dose final da série primária deve ser aplicada com dezesseis semanas de idade ou mais" é verdade da medicina veterinária, não do software. O requisito funcional é a resposta do sistema a essa regra — "o sistema calcula a data prevista da próxima dose". Uma mesma regra pode sustentar vários requisitos, e um requisito pode implementar várias regras.

**Escala de prioridade.**

| Nível | Significado | Consequência |
|---|---|---|
| Essencial | Sem ele, o sistema não resolve o problema enunciado | Compõe o escopo mínimo demonstrável (§8) |
| Importante | Agrega valor substantivo; sua ausência degrada a solução | Implementado se o cronograma permitir |
| Desejável | Refinamento ou conveniência | Candidato natural a Trabalhos Futuros |

**Campos de cada requisito funcional.** Ator responsável, prioridade, origem (problema do cenário ou seção do documento de decisões), regras de negócio associadas e critérios de aceitação verificáveis. Os critérios de aceitação foram redigidos de modo a poderem ser convertidos diretamente em casos de teste automatizado, o que os torna úteis tanto para a implementação quanto para o capítulo de Resultados.

## 1.4 Correspondência com a numeração provisória da Etapa 2

A matriz de rastreabilidade do documento de estudo de caso empregou numeração provisória, agora substituída pela consolidada. A tabela abaixo evita inconsistência entre os documentos e deve ser observada ao redigir a monografia.

| Numeração provisória (Etapa 2) | Numeração consolidada | Requisito |
|---|---|---|
| RF01 | RF16, RF17 | Cadastro global do animal com código único |
| RF02 | RF25 | Registro de lote, validade, fabricante, via e CRMV do aplicador |
| RF03 | RF26 | Cálculo automático do calendário vacinal |
| RF04 | RF28 | Carteira de vacinação digital |
| RF05 | RF42 | Lembretes automáticos por correio eletrônico |
| RF06 | RF31, RF33 | Prontuário imutável com retificação |
| RF07 | RF36 | Autorização nominal concedida pelo tutor |
| RF08 | RF52 | Registro de acesso ao histórico de outro prestador |
| RF09 | RF46, RF47 | Exportação em PDF com verificação por QR Code |
| RF10 | RF29 | Histórico pregresso não verificado |
| RF12 | RF34 | Retorno programado |
| RF13 | RF49 | Painel de pendências vacinais |
| RF14 | RF39 | Revogação de autorização |

## 1.5 Registro de alterações

**Versão 1.1.** Três ajustes decorrentes de revisão do autor:

1. Esclarecimento da distinção entre **papel** e **pessoa** (§3.1), que estava implícita e gerava a impressão equivocada de que o prestador e o médico-veterinário seriam necessariamente pessoas distintas.
2. Reformulação da matriz de permissões (§3.2), que empregava o símbolo "—" com significado ambíguo. A matriz passou a admitir apenas "Sim" e "Não", com coluna própria para o âmbito de cada permissão.
3. Incorporação do **autocadastro do tutor** e da **repartição dos campos do animal** entre tutor e médico-veterinário, o que produziu dois requisitos novos (RF19 redefinido e RF20) e quatro regras novas (RN17 a RN20).

Em consequência das inclusões, os requisitos anteriormente numerados de RF20 a RF54 passaram a RF21 a RF55, e as regras de RN17 a RN47 passaram a RN21 a RN51. Documentos derivados da versão 1.0 devem ser conferidos contra a numeração atual.

**Versão 1.2.** Restrição do papel `admin_prestador` à administração da conta. Nas versões anteriores, o papel acumulava atribuições cadastrais — cadastro de tutores e animais, consulta a históricos, painel de pendências — atribuídas à recepção do estabelecimento. A revisão eliminou essas atribuições: **nenhum dado de tutor, de animal ou de registro clínico é acessível ao papel administrativo**. A numeração não foi alterada; foram ajustados os atores de quinze requisitos, a matriz de permissões (§3.2), a regra RN08 e as personas (§2).

**Versão 1.3.** Explicitação da **unicidade da conta** e do caminho pelo qual os papéis se acumulam sobre ela. A acumulação já constava de §3.1, que lista entre os casos previstos "Dr. Marcelo, na condição de tutor do próprio cão", mas nenhum requisito descrevia como o segundo papel passa a existir — lacuna que, na implementação, obrigava a mesma pessoa a manter dois endereços de correio eletrônico para ser tutor e veterinário. Dois ajustes decorrem disso:

1. **RF12** passou a admitir **três** origens para o cadastro do tutor, com o acréscimo do papel a conta existente, e ganhou os critérios (e) e (f), que fixam a unicidade do endereço e as condições do acréscimo.
2. **RN05** foi reescrita para declarar a conta única por endereço, incluir o papel `tutor` entre os acumuláveis e exigir sessão autenticada do titular para o acréscimo de qualquer papel.
3. **RF01** passou a prever a indicação do papel na entrada, com o critério (a) reformulado — o destino é o painel do papel indicado, e a precedência anterior fica reservada à entrada sem indicação — e o critério (e) acrescentado, que impede a indicação de virar condição de acesso.

A numeração não foi alterada, e nenhum requisito foi acrescentado ou removido.

---

# 2. Personas

## 2.1 Nota sobre o uso de personas

As personas abaixo não são invenção livre: derivam dos atores do cenário construído na Etapa 2, e cada uma corresponde a um papel efetivamente previsto no modelo de autorização do sistema. Sob o enquadramento do *scenario-based design*, personas são instrumento reconhecido de elicitação e de comunicação de requisitos, não elemento decorativo. Cabe, contudo, a mesma advertência da etapa anterior: são construções do autor, e o texto da monografia deve nomeá-las como tais.

O valor analítico das personas neste projeto está concentrado em um ponto específico — elas tornam evidente **onde passa a fronteira de quem pode dizer algo sobre o animal**. Helena identifica os seus animais e nada mais registra; Dr. Marcelo e Dra. Larissa caracterizam, examinam e prescrevem; Camila, que opera o balcão da mesma clínica em que Dr. Marcelo atende, não é usuária do sistema. Essa fronteira, que poderia parecer burocrática, é a tradução em software da responsabilidade técnica exigida pela norma profissional.

## 2.2 Helena Ramos — a tutora

| Atributo | Descrição |
|---|---|
| Idade e ocupação | 34 anos, professora da rede municipal |
| Papel no sistema | `tutor` |
| Contexto | Tutora de Théo (cão, adotado filhote em 2025) e Nina (gata, adotada adulta, sem histórico conhecido) |
| Competência digital | Alta para aplicativos de banco e mensagens; baixa organização de documentos em papel |
| Dispositivo predominante | Telefone celular |
| Frequência de uso | Baixa e episódica — consultas pontuais e resposta a lembretes |

**Objetivos.** Não perder doses; saber, sem depender da memória, o que cada animal já recebeu; poder entregar o histórico a um profissional novo sem precisar explicá-lo.

**Frustrações.** Já perdeu uma carteira de vacinação; já pagou por doses provavelmente desnecessárias; já foi obrigada a servir de intermediária técnica entre dois veterinários, papel para o qual não tem qualificação.

**Implicação de projeto.** Helena pode chegar ao sistema por conta própria, antes de qualquer clínica: cadastra-se, registra Théo e Nina com nome, espécie e fotografia, e lança o histórico pregresso de que dispõe. O que ela **não** faz é caracterizar o animal — raça, pelagem, peso e situação reprodutiva são preenchidos pelo veterinário no primeiro atendimento. A distinção é deliberada e sustenta o diferencial do sistema: o tutor identifica o seu animal, o profissional o descreve. Helena é, ademais, usuária de baixa frequência e alta dependência de notificação. A interface do tutor precisa ser compreensível sem treinamento e operável no celular, e o sistema não pode pressupor que ela retorne espontaneamente — é o lembrete que traz Helena de volta, não o contrário. Ela é, também, a única pessoa autorizada a decidir quem vê o histórico dos seus animais, e essa decisão precisa ser tomável em poucos passos, sob o balcão da clínica, com o animal no colo.

## 2.3 Dr. Marcelo Antunes — o médico-veterinário

| Atributo | Descrição |
|---|---|
| Idade e experiência | 47 anos, dezoito de profissão |
| Papel no sistema | `veterinario` e, por ser responsável técnico, também `admin_prestador` |
| Volume de trabalho | Média de catorze atendimentos por dia |
| Competência digital | Média; usa sistemas quando são mais rápidos que o papel, e os abandona quando não são |
| Dispositivo predominante | Computador do consultório |
| Frequência de uso | Intensiva, em janelas curtas entre atendimentos |

**Objetivos.** Decidir com informação completa; cumprir a exigência normativa de registro sem que isso consuma o tempo da consulta; saber quais animais sob seus cuidados estão com reforço vencido.

**Frustrações.** Depende do relato do tutor e da carteira apresentada; repete exames por não ter acesso ao anterior; sabe que a informação de que precisa existe em algum lugar do arquivo de papel, e que extraí-la é inviável.

**Implicação de projeto.** O registro clínico compete com o tempo do atendimento. Cada campo obrigatório a mais é um incentivo ao abandono do sistema, e cada campo obrigatório a menos é uma lacuna de conformidade — a tensão entre RN21 e a usabilidade do formulário de vacinação é real e deve ser resolvida por interface (valores padrão, leitura de lote por seleção, repetição do último aplicador), não pela flexibilização da regra.

## 2.4 Camila Duarte — a persona negativa

| Atributo | Descrição |
|---|---|
| Idade e ocupação | 29 anos, recepcionista da Clínica Vet Amigo |
| Papel no sistema | **Nenhum** — não é usuária |
| Competência digital | Alta; é quem opera de fato os demais sistemas da clínica |

A técnica de personas admite a **persona negativa**: a descrição explícita de quem o sistema deliberadamente *não* atende. Camila é a persona negativa do Imunia, e registrá-la como tal é mais honesto — e academicamente mais defensável — do que simplesmente omiti-la.

**Por que não é usuária.** Camila não possui CRMV. Toda informação sobre o animal, no Imunia, é informação de origem profissional: se a recepção pudesse cadastrar animais, transcrever dados ou consultar históricos, o diferencial central do sistema — enunciado na Etapa 1 (§2.3) como inversão da responsabilidade sobre o dado clínico — seria verdadeiro apenas no discurso. A clínica contrata a plataforma para que **o médico-veterinário** faça o controle; não para transferir esse controle ao balcão.

**Consequência que precisa ser assumida.** A rechamada ativa por telefone, executada hoje por Camila de memória e por amostragem, não passa a ser executada por ela dentro do sistema. Ela se torna atribuição da equipe clínica, apoiada no painel de pendências (RF49), cujo resultado é exportável. Trata-se de decisão de projeto com custo operacional real para o estabelecimento, e a monografia ganha ao declará-la em vez de contorná-la: o Imunia optou pela integridade da origem do dado, ao preço de não atender ao pessoal administrativo da clínica. A criação de um papel operacional restrito — capaz de ver nome, contato e vacina vencida, e nada mais — permanece registrada como pendência (§10) e candidata natural a Trabalhos Futuros.

**Implicação sobre o papel `admin_prestador`.** Removida a recepção, o papel administrativo deixa de corresponder a um funcionário não clínico e passa a ser atribuição do responsável técnico ou de outro médico-veterinário da equipe. Ele não se confunde com o papel `veterinario` porque nem todo profissional vinculado deve poder convidar usuários, encerrar vínculos ou alterar os dados do estabelecimento — é segregação de funções entre pares, não entre categorias profissionais.

## 2.5 Dra. Larissa Freitas — a profissional autônoma

| Atributo | Descrição |
|---|---|
| Idade e atuação | 33 anos, atendimento domiciliar em município de pequeno porte |
| Papel no sistema | `admin_prestador` **e** `veterinario` sobre o mesmo prestador |
| Registro clínico atual | Anotações em aplicativo de notas do telefone |
| Dispositivo predominante | Telefone celular, em deslocamento |

**Objetivos.** Ter registro defensável do que aplicou e prescreveu, sem carregar arquivo físico; acessar o histórico do animal na casa do tutor.

**Implicação de projeto.** Larissa é a justificativa da generalização adotada na Etapa 1 (§3.1): o inquilino é o **prestador**, não a clínica. Modelada como prestador de um único integrante, ela ocupa lugar próprio no modelo em vez de ser um caso residual. Impõe, ainda, dois requisitos concretos: acumulação de papéis pelo mesmo usuário (RN05) e uso do registro clínico em tela pequena, fora do consultório.

## 2.6 Atores externos considerados

Dois participantes do domínio afetam o sistema sem serem seus usuários, e convém declará-lo:

- **O profissional não cadastrado na plataforma**, que recebe do tutor uma exportação em PDF. Não autentica, não registra, não consulta a base — interage exclusivamente com a rota pública de verificação (RF47). É o destinatário de toda a mitigação prevista para a limitação de adesão parcial.
- **O serviço público de campanha antirrábica**, que produz informação clínica relevante e permanece fora do alcance do sistema. É a origem da perda por não captura descrita em P5, mitigada — não resolvida — pelo histórico pregresso não verificado (RF29).

---

# 3. Atores e papéis do sistema

## 3.1 Definição dos papéis

| Papel | Quem é | Vínculo |
|---|---|---|
| `tutor` | Pessoa física responsável por um ou mais animais | Global; não pertence a prestador algum |
| `veterinario` | Médico-veterinário com CRMV ativo | Vinculado a um ou mais prestadores |
| `admin_prestador` | Responsável pela conta do prestador na plataforma | Vinculado a um prestador |
| *(não autenticado)* | Terceiro que recebe documento exportado | Nenhum; acesso restrito à rota de verificação |


### Papel não é pessoa

Esta distinção precisa ficar explícita no texto da monografia, sob pena de leitura equivocada: os três papéis descrevem **atribuições**, não indivíduos. Uma mesma pessoa pode acumular papéis, e o sistema atribui a ela a união das permissões correspondentes (RN05).

| Situação real | Papéis atribuídos ao usuário |
|---|---|
| Dra. Larissa, médica-veterinária autônoma | `admin_prestador` **e** `veterinario`, sobre o mesmo prestador |
| Dr. Marcelo, responsável técnico da Clínica Vet Amigo | `admin_prestador` **e** `veterinario` |
| Demais médicos-veterinários da equipe da clínica | `veterinario` |
| Dr. Marcelo, na condição de tutor do próprio cão | `veterinario` no prestador **e** `tutor` sobre os seus animais, sem que um papel confira privilégio ao outro |
| Recepção e pessoal administrativo do estabelecimento | Nenhum — não são usuários do sistema (§2.4) |

O ponto que provavelmente causou a dúvida é o do profissional autônomo, e ele merece formulação precisa. **Prestador é entidade, não pessoa**: é o inquilino da arquitetura multi-inquilino, a unidade sob a qual os registros clínicos são carimbados e a quem o tutor concede autorização. **Veterinário é pessoa**: é quem possui CRMV, assina o ato clínico e responde tecnicamente por ele.

Na Clínica Vet Amigo, entidade e pessoa não coincidem — a clínica é o prestador, e Dr. Marcelo é um entre os profissionais que nela atuam. No atendimento domiciliar da Dra. Larissa, entidade e pessoa coincidem: ela é, ao mesmo tempo, o prestador e o único veterinário. O sistema não a obriga a criar duas contas nem a fingir ser duas pessoas; ela possui **uma conta com dois papéis**, sobre um prestador de um único integrante. É exatamente a generalização adotada na Etapa 1 (§3.1), e é o que evita que o veterinário autônomo fique sem lugar no modelo.

Resta a pergunta inversa: se o responsável técnico acumula os dois papéis, por que separá-los? Não por diferença de categoria profissional — ambos os papéis são exercidos por médicos-veterinários —, mas por **segregação de funções entre pares**:

1. **Administrar a conta não é ato clínico.** Convidar profissionais, encerrar vínculos e alterar os dados do estabelecimento são atribuições de gestão do prestador. Não há razão para que todo veterinário contratado possa remover colegas do sistema ou alterar a identificação da clínica que consta dos documentos emitidos.
2. **O papel administrativo não alcança dado algum de tutor, animal ou registro clínico.** Esta é a consequência mais importante da revisão da versão 1.2, e a garantia que dela decorre é forte o bastante para figurar no Capítulo 4: **toda informação sobre o animal existente na plataforma foi lançada por um médico-veterinário identificado ou pelo próprio tutor, jamais por pessoal administrativo**. A afirmação é verificável na matriz da seção seguinte, e é o que distingue o Imunia dos sistemas de gestão analisados, nos quais a recepção alimenta o cadastro clínico.

## 3.2 Matriz de permissões

Na versão 1.0 deste documento, algumas células traziam o símbolo "—", com o sentido de "não se aplica a este papel" — por exemplo, a linha de visualização do histórico do **próprio** animal, que descreve a posição do tutor e não tem correspondente na posição do veterinário. A notação era ambígua e podia ser lida como negativa, o que seria falso: o tutor visualiza integralmente o histórico dos seus animais, e o veterinário visualiza o histórico dos animais que lhe foram autorizados. A matriz foi refeita para admitir apenas **Sim** e **Não**, com o âmbito de cada permissão declarado em coluna própria.

Cada célula responde a uma única pergunta: *este papel pode executar esta operação, dentro do âmbito indicado?*

**Quadro 1 — Permissões por papel**

| Operação | Âmbito da permissão | Tutor | Veterinário | Admin. da conta | Não autenticado |
|---|---|:---:|:---:|:---:|:---:|
| Visualizar histórico clínico do animal | Tutor: seus animais. Veterinário: animais com autorização vigente | Sim | Sim | Não | Não |
| Cadastrar animal (identificação: nome, espécie e fotografia) | Tutor: seus animais. Veterinário: animais em atendimento | Sim | Sim | Não | Não |
| Completar a caracterização do animal (raça, pelagem, peso, situação reprodutiva, micro-chip) | Animais com autorização vigente | Não | Sim | Não | Não |
| Registrar vacinação | Animais com autorização vigente | Não | Sim | Não | Não |
| Registrar atendimento e prontuário | Animais com autorização vigente | Não | Sim | Não | Não |
| Retificar registro clínico | Exclusivamente os de sua própria autoria | Não | Sim | Não | Não |
| Editar ou excluir registro de outro profissional | Nenhum | Não | Não | Não | Não |
| Lançar histórico pregresso não verificado | Tutor: seus animais. Veterinário: animais autorizados | Sim | Sim | Não | Não |
| Cadastrar tutor | Tutor: autocadastro. Veterinário: no atendimento | Sim | Sim | Não | Não |
| Conceder e revogar autorização de acesso | Exclusivamente sobre os seus animais | Sim | Não | Não | Não |
| Solicitar autorização ao tutor | Animais em atendimento no prestador | Não | Sim | Não | Não |
| Consultar painel de pendências vacinais | Animais sob autorização vigente do prestador | Não | Sim | Não | Não |
| Consultar o registro de acessos ao histórico | Exclusivamente sobre os seus animais | Sim | Não | Não | Não |
| Gerenciar usuários e dados do prestador | O próprio prestador | Não | Não | Sim | Não |
| Cadastrar vacina no acervo próprio da clínica e definir o agendamento dela | O próprio prestador | Não | Não | Sim | Não |
| Editar ou inativar item do catálogo mantido pela plataforma | Nenhum | Não | Não | Não | Não |
| Exportar histórico em PDF verificável | Conforme o âmbito de visualização | Sim | Sim | Não | Não |
| Verificar autenticidade de documento exportado | Rota pública, sem exposição de conteúdo | Sim | Sim | Sim | Sim |

Cinco leituras dessa matriz merecem registro no texto da monografia, por constituírem o diferencial do sistema em relação aos concorrentes analisados:

1. **A coluna do tutor não contém nenhuma permissão de escrita clínica.** Ele cadastra o animal e o identifica; quem o caracteriza e quem registra o ato é o profissional. É a inversão de responsabilidade sobre o dado, enunciada na Etapa 1 (§2.3).
2. **A linha de edição de registro alheio é negativa para todos os papéis, sem exceção.** Não existe usuário privilegiado capaz de alterar o registro de outro profissional — nem mesmo quem administra a conta do prestador.
3. **A concessão de autorização é exclusiva do tutor.** Prestadores podem solicitar; apenas o titular concede.
4. **O catálogo mantido pela plataforma é negativo para todos os papéis.** Quem administra uma clínica cadastra as vacinas que ela usa e define o prazo do lembrete de cada uma, mas não altera nem inativa o que veio das diretrizes da WSAVA: aquele cálculo responde por todas as clínicas, e não por uma. É a mesma forma da linha de edição de registro alheio — a permissão não existe para ninguém, e não apenas está ausente de um papel. Quando a conduta do profissional diverge do protocolo, o caminho é a ordem da dose escolhida no ato do registro (RN36), que o sistema sugere e não impõe.
5. **A coluna administrativa é negativa em toda operação que envolva tutor, animal ou registro clínico.** À primeira vista, uma coluna quase vazia sugere papel supérfluo; é o contrário. O papel existe para que a administração da conta seja possível **sem** que ela abra qualquer porta para o dado do animal. Do desenho decorre a garantia enunciada em §3.1: toda informação clínica da plataforma provém de médico-veterinário identificado, e toda informação de identificação provém do veterinário ou do próprio tutor.

---

# 4. Requisitos funcionais

## 4.1 Autenticação, conta e perfil

**RF01 — Autenticar usuário por credenciais**
*Ator:* todos os papéis · *Prioridade:* Essencial · *Origem:* §3.3 · *Regras:* RN01, RN02, RN03, RN05

O sistema deve autenticar o usuário mediante endereço de correio eletrônico e senha, estabelecendo sessão por *cookie* `httpOnly` conforme o modo SPA do Laravel Sanctum. A resposta de erro deve ser idêntica para credencial inexistente e para senha incorreta, de modo a não revelar quais endereços possuem conta na plataforma.

A entrada pode indicar **com qual papel** o usuário pretende trabalhar. A credencial é a mesma e a conta é única (RN05); a indicação não seleciona conta nem restringe acesso, apenas determina o painel de destino — sem ela, quem acumula papéis seria sempre conduzido ao de maior precedência, inclusive quando veio exercer o outro.

*Critérios de aceitação:* a) credenciais válidas estabelecem sessão e direcionam o usuário ao painel do papel indicado na entrada; na ausência de indicação, ao painel de maior precedência entre os papéis que a conta exerce; b) credenciais inválidas retornam mensagem genérica; c) nenhum token de autenticação é gravado em `localStorage`; d) após o limite de tentativas de RN03, novas tentativas são recusadas pelo período definido; e) a indicação de papel não condiciona a autenticação: credencial válida de conta que não exerce o papel indicado estabelece sessão do mesmo modo, e o sistema oferece a criação do cadastro correspondente, na forma de RF12, em lugar de recusar o acesso.

**RF02 — Encerrar sessão**
*Ator:* todos os papéis · *Prioridade:* Essencial · *Origem:* §3.3 · *Regras:* RN01

O sistema deve permitir o encerramento explícito da sessão, invalidando-a no servidor e removendo o *cookie* correspondente. Sessões inativas expiram automaticamente conforme RN02.

*Critérios de aceitação:* a) após o encerramento, qualquer requisição autenticada com a sessão anterior é recusada; b) o encerramento não afeta sessões do mesmo usuário em outros dispositivos, salvo solicitação expressa.

**RF03 — Redefinir senha esquecida**
*Ator:* todos os papéis · *Prioridade:* Essencial · *Origem:* §3.3 · *Regras:* RN04

O sistema deve permitir a redefinição de senha mediante envio de ligação de uso único ao endereço de correio eletrônico cadastrado, com prazo de validade determinado. A tela de solicitação deve exibir a mesma confirmação independentemente de o endereço informado possuir conta, pela razão exposta em RF01.

*Critérios de aceitação:* a) a ligação expira conforme RN04 e torna-se inválida após o primeiro uso; b) a redefinição bem-sucedida encerra as demais sessões ativas do usuário; c) a nova senha é submetida à política de RN04.

**RF04 — Alterar a própria senha**
*Ator:* todos os papéis · *Prioridade:* Importante · *Origem:* §3.3 · *Regras:* RN04

O sistema deve permitir que o usuário autenticado altere sua senha, exigindo a confirmação da senha vigente.

*Critérios de aceitação:* a) a senha vigente incorreta impede a alteração; b) a nova senha observa a política de RN04.

**RF05 — Verificar o endereço de correio eletrônico**
*Ator:* tutor, veterinário, administrador do prestador · *Prioridade:* Essencial · *Origem:* §3.5 · *Regras:* RN42

O sistema deve verificar a titularidade do endereço de correio eletrônico mediante ligação de confirmação enviada no ato do cadastro, e deve sinalizar de forma visível, em todas as telas pertinentes, os cadastros cujo endereço permaneça não verificado.

*Critérios de aceitação:* a) endereços não verificados não recebem notificações de lembrete; b) o prestador visualiza, na ficha do tutor, indicação inequívoca da não verificação; c) o reenvio da confirmação está disponível ao tutor e ao prestador.

Este requisito parece acessório e não é: sem ele, todo o mecanismo de lembrete falha em silêncio, que é precisamente o modo de falha do arranjo em papel descrito no cenário. Um lembrete que não chega é indistinguível, do ponto de vista do tutor, de um lembrete que não foi enviado.

**RF06 — Manter o próprio perfil**
*Ator:* todos os papéis autenticados · *Prioridade:* Importante · *Origem:* §3.3 · *Regras:* RN06

O sistema deve permitir que o usuário consulte e atualize seus dados pessoais de contato. A alteração do endereço de correio eletrônico reinicia o ciclo de verificação de RF05.

*Critérios de aceitação:* a) alteração de endereço marca o cadastro como não verificado até nova confirmação; b) alterações são registradas com data, hora e autor.

## 4.2 Prestador e equipe

**RF07 — Cadastrar prestador**
*Ator:* administrador do prestador · *Prioridade:* Essencial · *Origem:* §3.1 · *Regras:* RN07, RN08

O sistema deve permitir o cadastro de prestador com razão social ou nome, tipo (clínica, hospital veterinário ou profissional autônomo), CNPJ, endereço com município e unidade federativa, contato e responsável técnico com número de CRMV. O cadastro cria simultaneamente o primeiro usuário administrador.

*Critérios de aceitação:* a) o tipo determina os rótulos exibidos na interface, sem alterar o modelo de dados; b) o município informado alimenta o diretório de RF11; c) prestador sem responsável técnico identificado não pode registrar informação clínica; d) o CNPJ é exigido dos três tipos, o profissional autônomo inclusive, e o cadastro não aceita CPF em seu lugar.

**RF08 — Manter os dados do prestador**
*Ator:* administrador do prestador · *Prioridade:* Importante · *Origem:* §3.1 · *Regras:* RN07

O sistema deve permitir a atualização dos dados cadastrais do prestador, preservando histórico das alterações relevantes para a identificação do estabelecimento em documentos já emitidos.

*Critérios de aceitação:* a) documentos exportados anteriormente conservam a denominação vigente à época da emissão; b) a alteração de município reflete-se no diretório.

**RF09 — Vincular médico-veterinário ao prestador**
*Ator:* administrador do prestador · *Prioridade:* Essencial · *Origem:* §4.2 · *Regras:* RN05, RN09

O sistema deve permitir o convite de médico-veterinário por endereço de correio eletrônico, com informação obrigatória do número de CRMV e da unidade federativa de registro. O convidado aceita o vínculo e define sua senha em primeiro acesso. Um mesmo profissional pode manter vínculo simultâneo com mais de um prestador, alternando o contexto ativo na interface.

*Critérios de aceitação:* a) o vínculo aceito confere ao profissional acesso ao contexto do prestador convidante, e somente a ele; b) profissional vinculado a mais de um prestador visualiza indicação permanente de qual contexto está ativo; c) todo registro clínico é carimbado com o prestador ativo no momento da criação.

**RF10 — Encerrar vínculo de médico-veterinário**
*Ator:* administrador do prestador · *Prioridade:* Importante · *Origem:* §4.2 · *Regras:* RN09, RN27

O sistema deve permitir o encerramento do vínculo entre profissional e prestador, cessando imediatamente o acesso do profissional àquele contexto. Os registros clínicos por ele produzidos permanecem íntegros, sob guarda do prestador, com a autoria preservada.

*Critérios de aceitação:* a) após o encerramento, o profissional não acessa dado algum do prestador; b) os registros anteriores continuam exibindo nome e CRMV do autor; c) o encerramento não remove nem anonimiza autoria.

**RF11 — Consultar o diretório de prestadores**
*Ator:* tutor · *Prioridade:* Essencial · *Origem:* §3.2, §4.1 · *Regras:* RN12

O sistema deve permitir que o tutor consulte quais prestadores utilizam a plataforma, com filtro por município e por nome, exibindo apenas informação pública de identificação e contato.

*Critérios de aceitação:* a) o diretório não expõe quantidade de animais, de atendimentos ou qualquer dado operacional do prestador; b) a consulta é o ponto de partida do fluxo de autorização de RF36.

## 4.3 Tutores

**RF12 — Cadastrar tutor**
*Ator:* tutor, veterinário · *Prioridade:* Essencial · *Origem:* §3.1 · *Regras:* RN05, RN10, RN11

O sistema deve admitir três origens para o cadastro do tutor, produzindo o mesmo registro global nos três casos:

- **Autocadastro.** O próprio tutor cria sua conta informando nome completo, CPF, endereço de correio eletrônico e senha, sem depender de convite de prestador algum.
- **Cadastro pelo prestador.** No atendimento, o veterinário ou o administrador cadastra o tutor com os mesmos dados, e o titular recebe convite de ativação na forma de RF14.
- **Acréscimo do papel a conta existente.** O usuário que já possui conta — tipicamente o médico-veterinário que é tutor dos próprios animais — acrescenta o cadastro de tutor à conta que já tem, informando apenas o CPF e prestando o mesmo consentimento exigido no autocadastro. Nome, endereço de correio eletrônico e senha são os da conta, e não se repetem.

O tutor é entidade global: não possui `prestador_id` e não pertence ao estabelecimento que o cadastrou, qualquer que tenha sido a origem.

*Critérios de aceitação:* a) o CPF é único em toda a plataforma e validado quanto aos dígitos verificadores; b) a tentativa de cadastro com CPF já existente, feita por prestador, conduz ao fluxo de RF13, e jamais cria segundo registro; c) o autocadastro dispara imediatamente a verificação de endereço de RF05; d) tutor autocadastrado não recebe acesso a dado algum de prestador, e nenhum prestador passa a enxergá-lo por força do cadastro; e) o endereço de correio eletrônico é único na plataforma, e nenhuma das três origens cria segunda conta para endereço já cadastrado; f) o acréscimo do papel a conta existente exige sessão autenticada do titular, produz no máximo um cadastro de tutor por conta e recusa, com a mesma reserva do autocadastro, o CPF que já pertença a outro cadastro — sem distinguir em resposta ou em tela qual dado coincidiu.

O autocadastro abre um caminho de adoção que não existia na versão anterior deste documento e que convém explicitar no Capítulo 4: o tutor pode começar a usar o sistema **antes** de qualquer clínica, registrando seus animais e o histórico pregresso de que disponha, e levando a plataforma ao profissional no atendimento seguinte. A adesão deixa de depender exclusivamente do estabelecimento, o que mitiga parcialmente a limitação de dependência de rede declarada na Etapa 2.

A terceira origem não acrescenta funcionalidade nova: ela dá caminho ao que §3.1 já afirmava ao listar "Dr. Marcelo, na condição de tutor do próprio cão" entre as acumulações previstas. Sem ela, exercer os dois papéis exigiria dois endereços de correio eletrônico, e a plataforma passaria a tratar como duas pessoas quem é uma só — com consequências sobre o registro de acessos de RF18, sobre a autoria preservada de RF10 e sobre a unicidade do CPF exigida no critério (a) deste requisito.

**RF13 — Localizar tutor já existente na plataforma**
*Ator:* veterinário · *Prioridade:* Essencial · *Origem:* §3.1, P4 · *Regras:* RN11, RN12

Quando o CPF informado já constar da base, o sistema deve informar a existência do cadastro e permitir o vínculo do tutor ao atendimento em curso, **sem** expor dado algum do tutor ou de seus animais antes da autorização prevista em RF36.

*Critérios de aceitação:* a) a tela informa apenas que existe cadastro para aquele CPF; b) nome, contato e relação de animais permanecem ocultos até a concessão de autorização; c) o fluxo encaminha diretamente à solicitação de acesso de RF38.

Este requisito é o ponto de maior risco de vazamento por desenho de interface de todo o sistema. A tentação natural é exibir os dados do tutor encontrado para confirmar que se trata da mesma pessoa; fazê-lo converteria o CPF em chave de consulta a dados pessoais de terceiros, exatamente a falha que a substituição da autenticação por e-mail (§3.3) pretendeu eliminar.

**RF14 — Ativar o acesso do tutor**
*Ator:* tutor · *Prioridade:* Essencial · *Origem:* §3.3 · *Regras:* RN04, RN10

Quando o cadastro tiver origem no prestador, o sistema deve enviar ao tutor convite de ativação, pelo qual ele define sua senha e passa a acessar o ambiente de consulta. O tutor cadastrado que não ativa o acesso permanece com registro clínico válido, produzido pelo prestador, mas não recebe lembretes, não concede autorizações e não cadastra animais. No autocadastro, a etapa é dispensada, pois a senha já foi definida pelo titular.

*Critérios de aceitação:* a) o convite expira e pode ser reenviado; b) tutor não ativado é sinalizado nas telas do prestador; c) a ativação verifica automaticamente o endereço, dispensando RF05.

**RF15 — Manter os dados do tutor**
*Ator:* tutor, veterinário · *Prioridade:* Importante · *Origem:* §3.1 · *Regras:* RN06, RN10

O sistema deve permitir a atualização dos dados do tutor pelo próprio titular e pelos prestadores autorizados. Toda alteração é registrada com autor, data e hora.

*Critérios de aceitação:* a) o CPF, uma vez cadastrado, não é editável por prestador; b) alterações realizadas por prestador ficam visíveis ao tutor.

## 4.4 Animais

**RF16 — Cadastrar animal com dados de identificação**
*Ator:* tutor, veterinário · *Prioridade:* Essencial · *Origem:* P4 · *Regras:* RN13, RN17, RN20

O sistema deve permitir o cadastro de animal com os dados de **identificação**: nome, espécie — exclusivamente cão ou gato — e fotografia. O cadastro pode ser realizado pelo próprio tutor ou pelo prestador no atendimento. Assim como o tutor, o animal é entidade global, sem `prestador_id`.

Facultativamente, o tutor pode informar sexo e data de nascimento estimada, campos que ficam assinalados como **declarados pelo tutor** e sujeitos a confirmação profissional na forma de RF19.

O cadastro realizado pelo tutor permanece assinalado como **preliminar** até que um médico-veterinário complete a caracterização, e essa condição é visível em todas as telas e nos documentos exportados.

*Critérios de aceitação:* a) espécies distintas de cão e gato são recusadas; b) a fotografia observa as restrições de formato e tamanho de RN20 e pode ser substituída pelo tutor a qualquer tempo; c) o cadastro gera o código único de RF17, seja qual for a origem; d) o cadastro preliminar exibe indicação inequívoca de que a caracterização está pendente; e) nenhum campo privativo do veterinário é apresentado ao tutor como editável, nem aceito pela interface de programação quando submetido por ele.

O item (e) não é redundância. A ocultação do campo na interface é conveniência; a recusa no servidor é a garantia. É a aplicação concreta de RNF09 e o tipo de detalhe que distingue, na defesa, um controle de acesso projetado de um controle de acesso aparente.

**RF17 — Gerar código único e permanente do animal**
*Ator:* sistema · *Prioridade:* Essencial · *Origem:* §3.1 · *Regras:* RN15

O sistema deve atribuir a cada animal, no ato do cadastro, identificador único, permanente, não sequencial e apto a representação em QR Code, que o acompanha por toda a vida, independentemente de mudanças de tutor, de prestador ou de município.

*Critérios de aceitação:* a) o código não é derivado de sequência previsível; b) o código não muda em nenhuma circunstância prevista no sistema; c) o código consta de toda exportação em PDF.

**RF18 — Localizar animal por código**
*Ator:* veterinário · *Prioridade:* Importante · *Origem:* §3.1 · *Regras:* RN12, RN15

O sistema deve permitir a localização de animal pelo código único, exibindo apenas a informação mínima de identificação quando não houver autorização vigente, e encaminhando ao fluxo de solicitação de RF38.

*Critérios de aceitação:* a) sem autorização, exibem-se somente espécie, nome e indicação de que há histórico disponível mediante autorização; b) a consulta sem autorização é registrada em log.

**RF19 — Completar e manter a caracterização do animal**
*Ator:* veterinário · *Prioridade:* Essencial · *Origem:* §2.3 (documento de decisões), P8 · *Regras:* RN06, RN14, RN18

O sistema deve permitir que o médico-veterinário complete e mantenha a caracterização do animal: raça, pelagem, sexo, situação reprodutiva, peso, número de micro-chip e confirmação da data de nascimento, com indicação de ser exata ou estimada. Esses campos são privativos do papel `veterinario`, por constituírem observação clínica e zootécnica, e não informação de identificação.

Completada a caracterização, cessa a condição de cadastro preliminar de RF16.

*Critérios de aceitação:* a) os campos são inacessíveis à escrita pelo tutor e pelo administrador do prestador, tanto na interface quanto na API; b) o dado declarado pelo tutor é apresentado ao veterinário para confirmação ou correção, e a substituição fica registrada; c) toda alteração registra autor, data e hora; d) a espécie não é alterável após o primeiro registro clínico.

**Observação para a modelagem (Etapa 5).** O peso não é atributo estável do animal: é medição, varia ao longo da vida e tem valor clínico justamente na série. Recomenda-se modelá-lo como medição datada, vinculada ao atendimento em que foi aferida, exibindo-se no cadastro o valor mais recente com a respectiva data. Tratá-lo como campo único sobrescrevível destruiria informação clínica útil e, mais grave, contrariaria a decisão de imutabilidade do registro (RN22).

**RF20 — Consolidar cadastro preliminar do tutor**
*Ator:* veterinário · *Prioridade:* Essencial · *Origem:* P4 · *Regras:* RN17, RN19

Quando o animal já houver sido cadastrado pelo tutor, o sistema deve conduzir o prestador ao registro existente — localizado pelo código único, pela relação de animais do tutor autorizado ou pelo micro-chip — em vez de permitir a criação de um segundo cadastro. Antes de confirmar qualquer cadastro novo, o sistema alerta sobre duplicidade provável quando houver, para o mesmo tutor, animal ativo de mesma espécie e nome semelhante.

*Critérios de aceitação:* a) o alerta de duplicidade precede a confirmação e identifica o cadastro possivelmente equivalente; b) a consolidação preserva o código único do cadastro mais antigo e todo o histórico pregresso já lançado pelo tutor; c) a consolidação é permitida enquanto um dos cadastros não possuir registro clínico.

**Limitação declarada.** Havendo registro clínico em ambos os cadastros duplicados, a fusão automática não é oferecida: implicaria reatribuir autoria e prestador de origem de registros imutáveis, o que a decisão de §3.4 impede. A hipótese deve constar da seção de limitações da monografia, com encaminhamento a Trabalhos Futuros. É a razão pela qual a prevenção da duplicidade, no item (a), importa mais do que a sua correção posterior.

**RF21 — Transferir a titularidade do animal**
*Ator:* tutor · *Prioridade:* Desejável · *Origem:* P4 · *Regras:* RN16

O sistema deve permitir a transferência da titularidade do animal a outro tutor cadastrado, mediante confirmação de ambas as partes, preservando integralmente o histórico clínico.

*Critérios de aceitação:* a) a transferência exige aceite do tutor de destino; b) o histórico anterior permanece acessível ao novo tutor; c) o tutor anterior perde o acesso a partir da transferência, e as autorizações por ele concedidas são encerradas.

A adoção é evento corriqueiro no domínio — Théo e Nina são ambos animais adotados — e a ausência deste requisito produziria, na prática, cadastro duplicado a cada mudança de responsável, reintroduzindo a fragmentação que o sistema pretende eliminar.

**RF22 — Registrar óbito e inativar o animal**
*Ator:* veterinário · *Prioridade:* Importante · *Origem:* §4.3 · *Regras:* RN21, RN51

O sistema deve permitir o registro do óbito do animal, com data, cessando o cálculo do calendário e a emissão de lembretes, e preservando integralmente o histórico pelo prazo de guarda legal.

*Critérios de aceitação:* a) nenhuma notificação é emitida após o registro do óbito; b) o histórico permanece consultável pelo tutor e pelos prestadores autorizados; c) o registro não pode ser excluído, apenas retificado na forma de RF33.

## 4.5 Catálogo de imunobiológicos e protocolos

**RF23 — Manter o catálogo de vacinas**
*Ator:* administrador da plataforma · *Prioridade:* Essencial · *Origem:* §3.6 · *Regras:* RN30, RN31

O sistema deve manter catálogo de imunobiológicos contendo denominação comercial e técnica, fabricante, espécie de destino, agentes cobertos, classificação em essencial ou não essencial e via de administração usual.

*Critérios de aceitação:* a) a vacina indisponível no catálogo não pode ser registrada como aplicação; b) a inativação de item do catálogo não afeta registros anteriores.

**RF24 — Parametrizar os protocolos vacinais de forma versionada**
*Ator:* administrador da plataforma · *Prioridade:* Essencial · *Origem:* §3.6 · *Regras:* RN31, RN32, RN33

O sistema deve armazenar os parâmetros temporais do calendário — idade mínima da primeira dose, intervalo entre doses da série primária, idade mínima da dose final, prazo do primeiro reforço e periodicidade da revacinação — em estrutura parametrizável e **versionada**, e não codificados no programa. Cada cálculo realizado registra a versão do protocolo aplicada.

*Critérios de aceitação:* a) a alteração de diretriz é absorvida por configuração, sem alteração de código; b) a publicação de nova versão não recalcula retroativamente datas já emitidas; c) todo registro de vacinação armazena a versão de protocolo vigente à época.

Este é o requisito de maior densidade técnica do documento e merece tratamento destacado no Capítulo 4. Ele responde a uma pergunta previsível da banca — o que acontece quando a WSAVA revisa as diretrizes? — e a resposta "altera-se o código" seria fraca. A parametrização versionada preserva, ainda, a auditabilidade: uma data prevista calculada em 2025 deve permanecer explicável pelos parâmetros de 2025, e não pelos que vierem a vigorar depois.

## 4.6 Vacinação e calendário

**RF25 — Registrar aplicação de vacina**
*Ator:* veterinário · *Prioridade:* Essencial · *Origem:* P8, §4.4 (Etapa 2) · *Regras:* RN21, RN22, RN34

O sistema deve registrar a aplicação de vacina contendo animal, imunobiológico do catálogo, fabricante, número de lote, data de validade, via de administração, data e hora da aplicação, ordem da dose na série e identificação do profissional aplicador por nome e número de CRMV.

*Critérios de aceitação:* a) lote e validade são de preenchimento obrigatório; b) a identificação do aplicador é preenchida automaticamente a partir do usuário autenticado e não é editável; c) vacina com validade expirada na data da aplicação exige confirmação explícita e fica sinalizada no registro; d) concluído o registro, o sistema calcula a próxima data conforme RF26.

A obrigatoriedade de lote e validade não é preferência de projeto: decorre da exigência de registro detalhado do procedimento introduzida pela Resolução CFMV nº 1.653/2025. Sem esses campos, é impossível rastrear falha vacinal atribuível ao imunobiológico, e o registro perde simultaneamente valor epidemiológico e valor jurídico.

**RF26 — Calcular automaticamente a data da próxima dose**
*Ator:* sistema · *Prioridade:* Essencial · *Origem:* P1 · *Regras:* RN31 a RN35

A cada vacinação registrada, o sistema deve calcular a data prevista da aplicação seguinte, considerando espécie, idade do animal na data da aplicação, imunobiológico, ordem da dose na série, intervalo decorrido desde a dose anterior e os parâmetros da versão vigente do protocolo. A data prevista é exibida ao veterinário no ato do registro e ao tutor na carteira digital.

*Critérios de aceitação:* a) filhote com dose final aplicada antes da idade mínima recebe agendamento de dose adicional, conforme RN33; b) o cálculo é apresentado com a justificativa da regra aplicada, em linguagem compreensível ao tutor; c) o resultado é reprodutível — mesmos parâmetros de entrada e mesma versão de protocolo produzem sempre a mesma data.

**RF27 — Recalcular o calendário diante de atraso ou intercorrência**
*Ator:* sistema · *Prioridade:* Essencial · *Origem:* P1 · *Regras:* RN34, RN35

O sistema deve recalcular a série quando a dose for aplicada fora da janela prevista, sinalizando o atraso e indicando a conduta parametrizada — prosseguimento da série ou reinício —, sem substituir a decisão do profissional, que pode registrar conduta diversa mediante justificativa.

*Critérios de aceitação:* a) atraso superior ao limite parametrizado gera alerta explícito ao veterinário; b) a conduta divergente da sugerida pelo sistema é registrada com justificativa e autoria; c) o sistema não impede o registro de conduta divergente.

A ressalva do item (c) é essencial e deve constar do texto: o sistema apoia a decisão clínica, não a substitui. Um sistema que recusasse o registro de conduta divergente da regra parametrizada seria, além de clinicamente inadequado, motivo legítimo de rejeição pelos profissionais.

**RF28 — Exibir a carteira de vacinação digital**
*Ator:* tutor, veterinário · *Prioridade:* Essencial · *Origem:* P3 · *Regras:* RN22, RN24

O sistema deve apresentar, em visão única por animal, todas as vacinações registradas em ordem cronológica, com todos os atributos de RF25, o prestador de origem de cada registro, as próximas doses previstas e a situação de cada uma — em dia, próxima do vencimento ou atrasada.

*Critérios de aceitação:* a) registros de histórico pregresso são exibidos com distinção visual inequívoca, conforme RN24; b) a visão é operável em tela de telefone celular; c) a origem de cada registro é sempre visível.

**RF29 — Registrar histórico pregresso não verificado**
*Ator:* tutor, veterinário · *Prioridade:* Essencial · *Origem:* P5 · *Regras:* RN24, RN25

O sistema deve permitir o lançamento de aplicações realizadas fora da plataforma — campanhas públicas, estabelecimentos não cadastrados, registros anteriores à adoção do sistema — com os dados de que se disponha, marcados de forma permanente e irreversível como não verificados.

*Critérios de aceitação:* a) a marcação de não verificado não pode ser removida por usuário algum, inclusive pelo veterinário; b) o registro identifica quem o lançou e em que data; c) a distinção visual em relação aos registros de origem profissional é mantida em todas as telas e na exportação em PDF.

**RF30 — Registrar reação adversa pós-vacinal**
*Ator:* veterinário · *Prioridade:* Desejável · *Origem:* P8 · *Regras:* RN21, RN23

O sistema deve permitir o registro de reação adversa vinculada a uma aplicação específica, com descrição, gravidade e conduta adotada, exibindo alerta nas aplicações subsequentes do mesmo imunobiológico ao mesmo animal.

*Critérios de aceitação:* a) a reação é vinculada ao registro de aplicação, não ao animal isoladamente; b) aplicações posteriores do mesmo imunobiológico exibem o alerta antes da confirmação.

## 4.7 Atendimento e prontuário

**RF31 — Registrar atendimento clínico**
*Ator:* veterinário · *Prioridade:* Essencial · *Origem:* P6, P8 · *Regras:* RN21, RN26

O sistema deve registrar o atendimento contendo data e hora, animal, motivo da consulta, anamnese, achados do exame físico, hipóteses diagnósticas, diagnóstico, conduta terapêutica e identificação do profissional responsável com nome e CRMV.

*Critérios de aceitação:* a) o registro é imutável após a confirmação, na forma de RN26; b) data, hora e autoria são atribuídas pelo sistema e não são editáveis; c) o atendimento é carimbado com o prestador ativo no momento da criação.

**RF32 — Anexar exames e documentos ao atendimento**
*Ator:* veterinário · *Prioridade:* Importante · *Origem:* P6 · *Regras:* RN26, RN28

O sistema deve permitir a anexação de arquivos em formato PDF ou imagem ao atendimento, com descrição e data do exame.

*Critérios de aceitação:* a) tipos e tamanhos de arquivo são restritos conforme RN28; b) o anexo herda a imutabilidade do registro ao qual se vincula; c) os arquivos não são acessíveis por ligação direta sem verificação de autorização.

Este requisito responde diretamente ao episódio de novembro de 2025 do cenário: o segundo profissional não repetiria o raspado cutâneo se tivesse acesso ao laudo do primeiro.

**RF33 — Retificar registro clínico**
*Ator:* veterinário autor do registro · *Prioridade:* Essencial · *Origem:* §3.4 · *Regras:* RN26, RN27

O sistema deve permitir a correção de registro clínico exclusivamente por retificação: cria-se novo registro vinculado ao original, contendo o conteúdo corrigido, o motivo da retificação, a autoria e a data. O registro original permanece íntegro e visível, sinalizado como retificado.

*Critérios de aceitação:* a) nenhum caminho da aplicação permite sobrescrever ou excluir registro clínico confirmado; b) a visualização apresenta o encadeamento entre original e retificação; c) apenas o autor do registro, no âmbito do prestador que o produziu, pode retificá-lo.

**RF34 — Programar retorno**
*Ator:* veterinário · *Prioridade:* Importante · *Origem:* P7 · *Regras:* RN29

O sistema deve permitir a vinculação, ao atendimento, de data prevista de retorno com finalidade descrita, alimentando o mecanismo de notificação e o painel de pendências.

*Critérios de aceitação:* a) o retorno programado gera lembrete conforme RF43; b) o retorno consta do painel de RF49; c) o retorno é encerrado automaticamente quando registrado novo atendimento do mesmo animal após a data prevista.

Corresponde à alternativa (b) da seção 5.7 do documento de estudo de caso, ali recomendada: resolve o problema P7 reaproveitando a infraestrutura de notificação já construída, sem incorporar a complexidade de um módulo completo de agenda, cuja ausência é delimitação de escopo assumida.

**RF35 — Visualizar o histórico consolidado do animal**
*Ator:* tutor, veterinário · *Prioridade:* Essencial · *Origem:* P4, P6 · *Regras:* RN12, RN37

O sistema deve apresentar, em ordem cronológica única, todos os atendimentos, vacinações, exames e retificações do animal, independentemente do prestador de origem, respeitada a autorização vigente, com identificação do prestador e do profissional responsável por cada item.

*Critérios de aceitação:* a) a origem de cada registro é sempre exibida; b) a visualização de registro produzido por outro prestador é gravada em log conforme RF52; c) na ausência de autorização, nada além da identificação do animal é exibido.

## 4.8 Autorização e compartilhamento

**RF36 — Conceder autorização nominal a prestador**
*Ator:* tutor · *Prioridade:* Essencial · *Origem:* §3.2, §4.1 · *Regras:* RN37, RN38, RN39

O sistema deve permitir que o tutor selecione, no diretório de RF11, um prestador e conceda autorização de acesso ao histórico de um animal determinado, com escolha explícita do animal quando houver mais de um.

*Critérios de aceitação:* a) a autorização é sempre nominal e por animal, jamais genérica; b) a concessão exige a confirmação de RF37; c) a autorização recebe prazo de validade conforme RN39; d) o ato é registrado com data, hora e origem.

**RF37 — Confirmar a autorização por código enviado ao tutor**
*Ator:* tutor · *Prioridade:* Essencial · *Origem:* §4.1 · *Regras:* RN38

O sistema deve exigir, para efetivar a concessão, a confirmação por código de uso único enviado ao endereço de correio eletrônico verificado do tutor.

*Critérios de aceitação:* a) o código expira em prazo curto e admite número limitado de tentativas; b) tutor com endereço não verificado não conclui a concessão; c) o código é enviado exclusivamente ao endereço cadastrado, jamais informado em tela.

O requisito existe para impedir o cenário em que o próprio balcão da clínica, de posse do dispositivo do tutor ou de sua atenção momentânea, conduza a concessão sem ato de vontade consciente do titular. É a materialização, em fluxo de interface, da exigência de consentimento livre e informado.

**RF38 — Solicitar acesso ao tutor**
*Ator:* veterinário · *Prioridade:* Importante · *Origem:* §4.1 · *Regras:* RN37, RN38

O sistema deve permitir que o prestador solicite autorização ao tutor de um animal, enviando-lhe notificação com a identificação do solicitante. A solicitação não confere acesso algum até a concessão.

*Critérios de aceitação:* a) a solicitação pendente não revela dado algum ao solicitante; b) a solicitação expira se não respondida no prazo parametrizado; c) o tutor visualiza quem solicitou, quando e para qual animal.

**RF39 — Revogar autorização**
*Ator:* tutor · *Prioridade:* Essencial · *Origem:* §4.3 · *Regras:* RN40, RN41

O sistema deve permitir a revogação, a qualquer tempo e sem justificativa, da autorização concedida a um prestador. A revogação encerra imediatamente o acesso ao histórico produzido por terceiros e não alcança os registros produzidos pelo próprio prestador revogado, que permanecem sob sua guarda.

*Critérios de aceitação:* a) o efeito é imediato, sem necessidade de nova sessão do prestador; b) o prestador revogado continua acessando exclusivamente os registros de sua própria autoria; c) a revogação é registrada e comunicada ao prestador; d) a interface explica ao tutor, antes da confirmação, exatamente o que a revogação alcança e o que não alcança.

A explicação exigida em (d) não é cortesia: sem ela, o tutor pode supor que a revogação apaga o prontuário mantido pela clínica, o que não é verdadeiro nem juridicamente possível, dada a obrigação de guarda perante o Conselho Federal de Medicina Veterinária.

**RF40 — Expirar e renovar autorizações**
*Ator:* sistema, tutor · *Prioridade:* Importante · *Origem:* §4.3 · *Regras:* RN39

O sistema deve encerrar automaticamente as autorizações ao término do prazo de validade, notificando o tutor com antecedência e oferecendo renovação em ato único.

*Critérios de aceitação:* a) a autorização expirada não confere acesso algum; b) o aviso prévio é enviado conforme RN39; c) a renovação estende o prazo sem exigir novo fluxo completo de concessão.

**RF41 — Consultar autorizações concedidas**
*Ator:* tutor · *Prioridade:* Importante · *Origem:* §4.1, §4.4 · *Regras:* RN41

O sistema deve apresentar ao tutor a relação de autorizações vigentes, expiradas e revogadas, por animal, com data de concessão, prazo e situação.

*Critérios de aceitação:* a) a revogação é acionável diretamente da relação; b) o histórico de autorizações encerradas permanece consultável.

## 4.9 Notificações

**RF42 — Notificar vacinação prevista**
*Ator:* sistema · *Prioridade:* Essencial · *Origem:* P2 · *Regras:* RN42, RN43, RN44

O sistema deve identificar diariamente as vacinações previstas e enviar ao tutor, por correio eletrônico, lembrete em antecedência parametrizada, reiteração na data prevista e alerta de atraso quando decorrido o prazo sem registro da aplicação.

*Critérios de aceitação:* a) a rotina é executada por comando agendado e as mensagens são despachadas por fila; b) nenhum lembrete é enviado em duplicidade para a mesma dose e a mesma janela, conforme RN43; c) o registro da aplicação cancela os lembretes pendentes daquela dose; d) tutores com endereço não verificado ou com descadastro ativo não são notificados; e) falhas de envio são registradas e submetidas à política de nova tentativa.

**RF43 — Notificar retorno programado**
*Ator:* sistema · *Prioridade:* Importante · *Origem:* P7 · *Regras:* RN42, RN43

O sistema deve enviar ao tutor lembrete do retorno programado em RF34, na antecedência parametrizada.

*Critérios de aceitação:* a) o registro de novo atendimento após a data prevista encerra o lembrete; b) aplicam-se as mesmas garantias de idempotência de RF42.

**RF44 — Gerenciar preferências de notificação**
*Ator:* tutor · *Prioridade:* Essencial · *Origem:* §3.5 · *Regras:* RN45

O sistema deve permitir ao tutor o descadastro individual por tipo de notificação, sem que isso desative as comunicações transacionais indispensáveis ao funcionamento da conta.

*Critérios de aceitação:* a) o descadastro é granular por tipo, e não global; b) confirmações de conta, redefinição de senha e códigos de autorização não são passíveis de descadastro; c) o descadastro é acessível a partir da própria mensagem recebida.

**RF45 — Consultar o histórico de notificações**
*Ator:* tutor, veterinário · *Prioridade:* Desejável · *Origem:* P2, P7 · *Regras:* RN43

O sistema deve registrar e permitir a consulta das notificações emitidas, com destinatário, tipo, data e situação de envio.

*Critérios de aceitação:* a) o prestador consulta apenas notificações referentes a animais sob autorização vigente; b) o registro é a fonte de verdade da idempotência de RN43.

Além de recurso de transparência, este registro é o que permite responder à pergunta que, no cenário, ninguém conseguia responder: este tutor já foi avisado, e quando.

## 4.10 Exportação e verificação

**RF46 — Exportar o histórico em PDF verificável**
*Ator:* tutor, veterinário · *Prioridade:* Essencial · *Origem:* P3, §4.5 · *Regras:* RN46, RN47

O sistema deve gerar documento em PDF com o histórico do animal — identificação, código único, vacinações, atendimentos e origem de cada registro —, contendo identificador próprio da emissão, resumo criptográfico do conteúdo e QR Code que remete à rota pública de verificação.

*Critérios de aceitação:* a) cada emissão possui identificador distinto e é registrada com autor e data; b) registros não verificados são assinalados como tais também no documento; c) o rodapé adverte o tutor de que, ao compartilhar o arquivo, assume a responsabilidade pela difusão daqueles dados, que saem do domínio de controle da plataforma; d) o documento é gerado em prazo compatível com o critério de RNF04.

O prazo importa por razão normativa: a Resolução CFMV nº 1.321/2020, alterada pela Resolução nº 1.653/2025, estabelece prazo de até cinco dias úteis para o fornecimento de cópia do prontuário ao responsável. A geração instantânea converte uma obrigação cumprida com dificuldade em obrigação cumprida por construção — argumento de conformidade que merece figurar no Capítulo 4.

**RF47 — Verificar publicamente documento exportado**
*Ator:* qualquer pessoa, sem autenticação · *Prioridade:* Essencial · *Origem:* §4.5 · *Regras:* RN47

O sistema deve disponibilizar rota pública que, informado o identificador da emissão, confirme se o documento é autêntico, quando foi emitido e a qual animal se refere, **sem** expor o conteúdo clínico.

*Critérios de aceitação:* a) a rota não revela dados clínicos nem dados do tutor; b) a verificação informa a data da emissão e permite ao conferente comparar o resumo criptográfico impresso; c) a rota é protegida contra tentativas automatizadas de enumeração de identificadores.

## 4.11 Painéis e consultas

**RF48 — Painel do veterinário**
*Ator:* veterinário · *Prioridade:* Importante · *Origem:* §2.2 (documento de decisões) · *Regras:* RN09, RN48

O sistema deve apresentar ao veterinário painel com os animais por ele atendidos e as informações registradas nos últimos trinta dias, com acesso direto ao histórico de cada um.

*Critérios de aceitação:* a) o painel abrange exclusivamente o contexto do prestador ativo; b) o intervalo é ajustável pelo usuário.

**RF49 — Painel de pendências vacinais do prestador**
*Ator:* veterinário · *Prioridade:* Essencial · *Origem:* P7 · *Regras:* RN37, RN48

O sistema deve apresentar consulta agregada dos animais sob autorização vigente do prestador cujas doses previstas estejam vencidas ou próximas do vencimento, com filtros por período, espécie e imunobiológico, e indicação da última notificação enviada.

*Critérios de aceitação:* a) a consulta responde no tempo definido em RNF03; b) o resultado é exportável para acompanhamento da rechamada ativa; c) animais sem autorização vigente não figuram no resultado.

Este é o requisito que converte a rechamada por amostragem, descrita no cenário, em rechamada por critério. É também o de maior apelo demonstrativo perante a banca, por responder, em segundos, a pergunta que o arquivo de papel tornava computacionalmente inviável.

**RF50 — Painel do tutor**
*Ator:* tutor · *Prioridade:* Essencial · *Origem:* P2 · *Regras:* RN10

O sistema deve apresentar ao tutor, em tela inicial, seus animais, as próximas doses previstas com respectivas datas e situação, e os retornos programados.

*Critérios de aceitação:* a) a tela é operável em telefone celular sem rolagem horizontal; b) doses atrasadas recebem destaque visual distinto das doses futuras.

**RF51 — Buscar animais e tutores no âmbito do prestador**
*Ator:* veterinário · *Prioridade:* Importante · *Origem:* P7 · *Regras:* RN12, RN48

O sistema deve permitir a busca de animais e tutores por nome, CPF, código do animal ou micro-chip, restrita ao âmbito de autorização do prestador ativo.

*Critérios de aceitação:* a) resultados fora do âmbito de autorização não são exibidos; b) a busca por CPF observa a restrição de RF13.

## 4.12 Auditoria e direitos do titular

**RF52 — Registrar acesso a histórico de outro prestador**
*Ator:* sistema · *Prioridade:* Essencial · *Origem:* §4.4 · *Regras:* RN49

O sistema deve registrar em log toda visualização de registro clínico originado de prestador distinto do ativo, gravando usuário, prestador, animal, natureza do dado acessado, data e hora.

*Critérios de aceitação:* a) o log é imutável e não é editável por nenhum papel; b) a gravação é condição da exibição, não posterior a ela; c) o log distingue acesso a registro próprio de acesso a registro de terceiro.

**RF53 — Consultar o log de acessos ao próprio animal**
*Ator:* tutor · *Prioridade:* Importante · *Origem:* §4.4 · *Regras:* RN49

O sistema deve apresentar ao tutor quem acessou o histórico de cada um de seus animais, quando e sob qual autorização.

*Critérios de aceitação:* a) a relação identifica prestador, profissional, data e hora; b) a revogação de RF39 é acionável diretamente da tela.

Trata-se, simultaneamente, de evidência de conformidade e do artefato mais demonstrável na apresentação: exibir à banca a auditoria de acessos torna concreto o discurso de proteção de dados, que de outro modo permaneceria abstrato.

**RF54 — Exportar os dados pessoais do titular**
*Ator:* tutor · *Prioridade:* Desejável · *Origem:* LGPD, art. 18 · *Regras:* RN50

O sistema deve permitir ao tutor obter, em formato legível por máquina, seus dados pessoais e os dados dos animais sob sua titularidade.

*Critérios de aceitação:* a) a exportação abrange dados cadastrais, histórico clínico e registros de autorização; b) a solicitação e o atendimento são registrados.

**RF55 — Encerrar a conta com preservação do prontuário**
*Ator:* tutor · *Prioridade:* Desejável · *Origem:* LGPD, art. 16, I · *Regras:* RN50, RN51

O sistema deve permitir o encerramento da conta do tutor, com anonimização dos dados pessoais não sujeitos a obrigação legal de guarda, preservando os registros clínicos pelo prazo determinado pela norma profissional.

*Critérios de aceitação:* a) o encerramento cessa todas as notificações e autorizações vigentes; b) os registros clínicos são preservados com a autoria do profissional e vínculo ao animal; c) a interface informa previamente, em linguagem clara, o que será apagado e o que será preservado, e por qual fundamento.

Há aqui tensão normativa real, e o texto da monografia ganha ao expô-la em vez de contorná-la: o direito à eliminação previsto na LGPD não é absoluto, cedendo diante do cumprimento de obrigação legal ou regulatória pelo controlador. A guarda mínima do prontuário exigida pelo Conselho Federal de Medicina Veterinária é exatamente essa hipótese. A solução adotada — anonimizar o titular, preservar o registro clínico — é a conciliação usual, e sustentá-la explicitamente demonstra compreensão da norma, não sua violação.

---

# 5. Requisitos não funcionais

Os requisitos não funcionais estão organizados segundo as características de qualidade da norma ISO/IEC 25010, o que confere ao capítulo estrutura reconhecível e evita a lista sem critério que costuma ocupar essa seção em monografias. Cada requisito acompanha **critério de verificação**: requisito não funcional sem forma de medição é declaração de intenção, não requisito.

## 5.1 Adequação funcional e correção

| Id. | Requisito | Critério de verificação |
|---|---|---|
| RNF01 | O cálculo do calendário vacinal deve ser determinístico e reprodutível: mesmos dados de entrada e mesma versão de protocolo produzem sempre o mesmo resultado | Conjunto de casos de teste automatizados cobrindo série primária completa, série com atraso, animal adulto sem histórico e dose final antes da idade mínima, com resultados esperados definidos previamente |
| RNF02 | O isolamento entre inquilinos deve ser garantido por construção, e não por disciplina de programação | Testes automatizados que, autenticados em um prestador, tentem acessar registros de outro e recebam recusa em todos os pontos de entrada da API |

O RNF02 merece nota. Em arquitetura multi-inquilino com base compartilhada, o vazamento entre inquilinos é a falha de maior gravidade possível e a mais fácil de introduzir: basta uma consulta que ignore o *Global Scope*. A existência de teste automatizado específico para essa hipótese é o que distingue a afirmação "o sistema é isolado" da sua demonstração, e é resposta direta a uma objeção previsível da banca quanto à escolha arquitetural.

## 5.2 Eficiência de desempenho

| Id. | Requisito | Critério de verificação |
|---|---|---|
| RNF03 | Consultas de listagem e painéis devem responder em até 2 segundos no 95º percentil, com base povoada com 5.000 animais e 50.000 registros clínicos | Medição com base de carga sintética, registrada no capítulo de Resultados |
| RNF04 | A exportação do histórico em PDF deve concluir em até 10 segundos para animal com até 200 registros | Medição sobre caso de teste com volume definido |
| RNF05 | O envio de notificações deve ocorrer de forma assíncrona, sem bloquear requisições de usuário | Verificação de que o comando agendado apenas enfileira, e que o despacho ocorre em processo de fila distinto |

## 5.3 Segurança

| Id. | Requisito | Critério de verificação |
|---|---|---|
| RNF06 | Todo o tráfego deve ocorrer sobre TLS, sem porta alternativa em texto claro | Inspeção de configuração e teste de redirecionamento |
| RNF07 | Senhas devem ser armazenadas com função de derivação de chave com sal e fator de custo configurável (Bcrypt ou Argon2id), jamais em texto claro ou com resumo simples | Inspeção da base e revisão de código |
| RNF08 | A sessão deve trafegar em *cookie* `httpOnly` com atributo `SameSite`, sem token em `localStorage`, com proteção contra falsificação de requisição entre sítios | Inspeção de cabeçalhos e teste de requisição forjada de origem externa |
| RNF09 | A autorização deve ser verificada no servidor, em *Policies*, para toda operação, independentemente de a interface exibir ou ocultar o comando correspondente | Testes que invoquem diretamente os pontos de entrada da API com papel insuficiente |
| RNF10 | Arquivos anexados não devem ser servidos por ligação direta e pública, mas por rota que verifique autorização a cada acesso | Teste de acesso ao arquivo por usuário não autorizado |
| RNF11 | Tentativas de autenticação, de confirmação de código e de verificação pública devem ser limitadas por taxa | Teste de repetição automatizada com verificação da recusa |
| RNF12 | Dados pessoais não devem ser gravados em registros de aplicação nem em mensagens de erro exibidas ao usuário | Inspeção de registros gerados em cenários de falha |

## 5.4 Usabilidade

| Id. | Requisito | Critério de verificação |
|---|---|---|
| RNF13 | A interface do tutor deve ser projetada para telefone celular, operável sem rolagem horizontal em largura de 360 pixels | Inspeção em emulador e em dispositivo real |
| RNF14 | A concessão de autorização deve ser concluída pelo tutor em, no máximo, quatro passos a partir da tela inicial | Contagem de passos em percurso guiado |
| RNF15 | O registro de vacinação deve ser concluído pelo veterinário em até noventa segundos, incluindo o preenchimento de lote e validade | Medição com usuário em teste de percurso; valores repetidos do último registro oferecidos como padrão |
| RNF16 | Toda informação exibida deve indicar sua origem e sua confiabilidade, distinguindo visualmente registro profissional de histórico pregresso não verificado | Inspeção de todas as telas que exibem registro clínico |
| RNF17 | Mensagens de erro e de confirmação devem ser redigidas em português, em linguagem compreensível a leigo, sem termos técnicos da implementação | Revisão textual sistemática |

O RNF15 traduz, em critério mensurável, a tensão identificada na persona do Dr. Marcelo: o formulário de vacinação disputa tempo com o atendimento, e um formulário que não cabe na consulta não é preenchido — é contornado.

## 5.5 Confiabilidade

| Id. | Requisito | Critério de verificação |
|---|---|---|
| RNF18 | Falhas no envio de notificação devem ser submetidas a política de nova tentativa e registradas, sem interromper o processamento das demais | Simulação de indisponibilidade do serviço de correio eletrônico |
| RNF19 | A execução repetida da rotina diária de lembretes, no mesmo dia, não deve produzir envio duplicado | Execução consecutiva do comando com verificação da tabela de notificações emitidas |
| RNF20 | Deve haver rotina de cópia de segurança diária da base de dados, com procedimento de restauração documentado e testado ao menos uma vez | Registro do teste de restauração no capítulo de Resultados |

## 5.6 Manutenibilidade

| Id. | Requisito | Critério de verificação |
|---|---|---|
| RNF21 | A regra de negócio deve residir em camada de serviço, mantendo controladores restritos à coordenação da requisição; validação em *FormRequests* e autorização em *Policies* | Revisão de código e inspeção da estrutura de diretórios |
| RNF22 | Os parâmetros do calendário vacinal devem ser alteráveis por configuração versionada, sem modificação de código | Alteração de parâmetro em ambiente de teste, com verificação do efeito sobre cálculos novos e da preservação dos anteriores |
| RNF23 | O código deve observar a PSR-12 no *backend* e padrão de formatação automatizado no *frontend*, com Vue 3 em Composition API e `<script setup>` exclusivamente | Verificação por ferramenta de análise estática integrada ao fluxo de desenvolvimento |

## 5.7 Portabilidade e compatibilidade

| Id. | Requisito | Critério de verificação |
|---|---|---|
| RNF24 | O sistema deve funcionar nas versões correntes dos navegadores de maior participação, em ambiente de mesa e móvel, sem exigir instalação de aplicativo nativo | Verificação funcional nos navegadores definidos como suportados |

## 5.8 Conformidade

| Id. | Requisito | Critério de verificação |
|---|---|---|
| RNF25 | O registro de vacinação e de atendimento deve conter todos os elementos exigidos pela Resolução CFMV nº 1.321/2020, alterada pela Resolução nº 1.653/2025 | Confronto item a item entre os campos implementados e o texto da norma, apresentado em quadro no Capítulo 4 |
| RNF26 | O tratamento de dados pessoais deve observar a Lei nº 13.709/2018, com base legal declarada, consentimento registrado para o compartilhamento, atendimento aos direitos do titular e medidas técnicas de segurança proporcionais ao risco | Inspeção documental e demonstração dos registros de consentimento e de acesso |

**Observação sobre a natureza dos dados tratados.** Convém precisão terminológica no texto da monografia, sob pena de crítica fácil na defesa: os dados de saúde tratados pelo sistema referem-se a **animais**, e não constituem dado pessoal sensível na acepção do artigo 5º, inciso II, da LGPD, que trata de dado relativo à saúde de pessoa natural. O que atrai a incidência da lei são os dados do **tutor** — nome, CPF, contato e endereço —, dados pessoais comuns, aos quais o histórico clínico do animal se vincula de forma identificável. A afirmação correta, portanto, não é "o sistema trata dados sensíveis", mas "o sistema trata dados pessoais de tutores, vinculados a informação clínica de terceiros, o que exige controle de acesso, registro de consentimento e medidas de segurança proporcionais ao risco, na forma do artigo 46".

---

# 6. Regras de negócio

## 6.1 Autenticação, contas e papéis

| Id. | Regra |
|---|---|
| RN01 | O acesso a qualquer dado do sistema exige autenticação prévia, ressalvada exclusivamente a rota pública de verificação de documento exportado |
| RN02 | Sessões inativas expiram automaticamente, exigindo nova autenticação |
| RN03 | Tentativas sucessivas de autenticação malsucedidas para a mesma origem ou o mesmo endereço são limitadas por taxa, com bloqueio temporário |
| RN04 | Senhas observam extensão mínima e verificação contra repositórios públicos de senhas comprometidas; ligações de redefinição e códigos de confirmação são de uso único e possuem prazo de validade |
| RN05 | A conta é única por endereço de correio eletrônico, e os papéis acumulam-se sobre ela: um mesmo usuário pode reunir os de tutor, de administrador do prestador e de médico-veterinário — os dois últimos inclusive sobre o mesmo prestador —, e pode manter vínculo com mais de um prestador, atuando sempre sob um contexto ativo determinado. Nenhum papel confere privilégio a outro, e o acréscimo de qualquer deles a uma conta existente exige sessão autenticada do titular |
| RN06 | Toda alteração de dado cadastral registra autor, data e hora |

## 6.2 Prestadores, tutores e animais

| Id. | Regra |
|---|---|
| RN07 | O prestador é a unidade de inquilino do sistema e abrange clínica, hospital veterinário e profissional autônomo, distinguidos por atributo de tipo; não existe entidade distinta para clínica |
| RN08 | Prestador que registra informação clínica possui responsável técnico identificado por nome e número de CRMV; o papel de administração da conta é atribuído ao responsável técnico ou a outro médico-veterinário da equipe e **não confere acesso a dados de tutores, de animais ou de registros clínicos** |
| RN09 | O registro clínico é sempre atribuído simultaneamente ao profissional autor e ao prestador ativo no momento da criação; o encerramento do vínculo entre ambos não altera a atribuição já efetuada |
| RN10 | Tutor e animal são entidades globais da plataforma, sem vínculo de propriedade com prestador algum |
| RN11 | O CPF identifica o tutor de forma única em toda a plataforma |
| RN12 | A existência de cadastro na plataforma não autoriza a exibição de seu conteúdo: sem autorização vigente, o prestador conhece apenas a existência do registro, jamais os dados do tutor, do animal ou do histórico |
| RN13 | O sistema atende exclusivamente às espécies canina e felina |
| RN14 | A data de nascimento pode ser exata ou estimada, e a natureza da informação é registrada e propagada a todo cálculo dela derivado |
| RN15 | Cada animal possui identificador único, permanente e não sequencial, que o acompanha por toda a vida, independentemente de mudança de tutor, de prestador ou de município |
| RN16 | A transferência de titularidade preserva integralmente o histórico do animal, encerra as autorizações concedidas pelo tutor anterior e exige aceite do tutor de destino |
| RN17 | O cadastro do animal pode ser iniciado pelo próprio tutor, limitado aos dados de identificação, e permanece assinalado como preliminar até ser completado por médico-veterinário |
| RN18 | A caracterização do animal — raça, pelagem, situação reprodutiva, peso, micro-chip e confirmação de sexo e de data de nascimento — é privativa do médico-veterinário, por constituir observação clínica e zootécnica, e não informação de identificação |
| RN19 | Cada animal possui um único cadastro ativo; o cadastro iniciado pelo tutor e o atendimento posterior em prestador consolidam-se por vínculo ao registro existente, nunca por duplicação |
| RN20 | A fotografia do animal é dado de identificação, e não registro clínico: observa restrição de formato e de tamanho e pode ser mantida pelo próprio tutor a qualquer tempo |

## 6.3 Registro clínico

| Id. | Regra |
|---|---|
| RN21 | Somente médico-veterinário com CRMV registrado no sistema pode criar registro clínico — vacinação, atendimento, prontuário e óbito |
| RN22 | Toda aplicação de vacina registra fabricante, número de lote, data de validade, via de administração, data e hora, e identificação do aplicador por nome e CRMV, em cumprimento à Resolução CFMV nº 1.321/2020, alterada pela Resolução nº 1.653/2025 |
| RN23 | Reação adversa vincula-se à aplicação específica que a originou, e não ao animal em abstrato |
| RN24 | Registro lançado como histórico pregresso é permanentemente marcado como não verificado, e a marcação não é removível por usuário algum |
| RN25 | O lançamento de histórico pregresso não constitui ato clínico e não atrai responsabilidade técnica do profissional que o transcreve |
| RN26 | Registro clínico confirmado é imutável: não admite alteração nem exclusão, apenas retificação vinculada, que preserva o original |
| RN27 | A retificação é privativa do profissional autor do registro, no âmbito do prestador que o produziu; nenhum papel edita registro alheio |
| RN28 | Anexos observam restrição de formato e de tamanho, e herdam a imutabilidade do registro a que se vinculam |
| RN29 | O retorno programado vincula-se ao atendimento que o originou e é encerrado pelo registro de novo atendimento do mesmo animal após a data prevista |

## 6.4 Calendário vacinal

| Id. | Regra | Fonte |
|---|---|---|
| RN30 | Apenas imunobiológicos constantes do catálogo podem ser registrados como aplicação | Decisão de projeto |
| RN31 | Os parâmetros temporais do calendário derivam das diretrizes da WSAVA e das recomendações de fabricantes, e são mantidos em estrutura versionada | WSAVA (2015; 2024) |
| RN32 | A publicação de nova versão de protocolo não recalcula retroativamente datas já emitidas; cada registro conserva a versão aplicada à época | Decisão de projeto |
| RN33 | A dose final da série primária de filhotes deve ser aplicada com dezesseis semanas de idade ou mais, em razão da interferência dos anticorpos de origem materna | WSAVA; DAY et al. (2016) |
| RN34 | As doses da série primária observam intervalo parametrizado de duas a quatro semanas; intervalo superior ao limite parametrizado caracteriza atraso e enseja alerta e conduta específica | WSAVA (2015; 2024) |
| RN35 | Após a série primária, aplica-se reforço aos seis ou doze meses; nos adultos, as vacinas essenciais são revacinadas em periodicidade não inferior a três anos, e a antirrábica e as não essenciais indicadas, anualmente | WSAVA (2015; 2024); prática nacional |
| RN36 | O sistema calcula e sugere; a decisão clínica é do médico-veterinário, que pode registrar conduta divergente mediante justificativa, sem que o sistema a impeça | Decisão de projeto |

**Nota de verificação pendente.** Os parâmetros de RN33 a RN35 foram extraídos das diretrizes já citadas no Capítulo 2 da monografia. Dois pontos exigem confirmação antes da implementação: o protocolo aplicável a **animal adulto sem histórico conhecido** — a situação da gata Nina, no cenário —, que difere entre espécies e entre vacinas vivas modificadas e inativadas; e a periodicidade da antirrábica, cuja recomendação técnica internacional e a prática regulatória brasileira nem sempre coincidem. Ambos devem ser confirmados junto às diretrizes originais e, preferencialmente, com o médico-veterinário entrevistado.

## 6.5 Autorização e compartilhamento

| Id. | Regra |
|---|---|
| RN37 | A autorização de acesso ao histórico é sempre nominal, dirigida a um prestador determinado e referente a um animal determinado; não existe autorização genérica nem automática |
| RN38 | Somente o tutor concede autorização, e a concessão exige confirmação por código de uso único enviado ao seu endereço de correio eletrônico verificado |
| RN39 | A autorização possui prazo de validade determinado — noventa dias, renováveis —, findo o qual se encerra automaticamente, com aviso prévio ao tutor |
| RN40 | A revogação encerra o acesso ao histórico produzido por terceiros e não alcança os registros produzidos pelo próprio prestador revogado, que permanecem sob sua guarda por obrigação legal |
| RN41 | Concessões, renovações, expirações e revogações são registradas de forma permanente e consultáveis pelo tutor |

A regra RN40 é a que exige maior cuidado de redação na monografia. Ela pode parecer uma restrição ao controle do titular, e é o oposto: a guarda do prontuário é obrigação do estabelecimento perante o Conselho Federal de Medicina Veterinária, não faculdade extinguível pelo tutor. O que o tutor controla é o acesso do prestador ao histórico **alheio** — e esse controle é integral.

## 6.6 Notificações

| Id. | Regra |
|---|---|
| RN42 | Somente tutores com endereço de correio eletrônico verificado recebem notificações |
| RN43 | Cada notificação é emitida uma única vez por destinatário, evento e janela, garantida por registro persistente das notificações já emitidas |
| RN44 | Para cada dose prevista emitem-se, no máximo, três comunicações: aviso prévio, aviso na data e alerta de atraso; o registro da aplicação encerra as pendentes |
| RN45 | O descadastro é individual por tipo de notificação e não alcança as comunicações transacionais indispensáveis — confirmação de conta, redefinição de senha e código de autorização |

## 6.7 Exportação, auditoria e conformidade

| Id. | Regra |
|---|---|
| RN46 | O documento exportado sai do domínio de controle da plataforma, e o rodapé adverte o tutor de que a responsabilidade pela difusão daqueles dados passa a ser sua |
| RN47 | Cada exportação possui identificador próprio e resumo criptográfico do conteúdo; a verificação pública informa autenticidade e data de emissão, sem revelar conteúdo clínico ou dado pessoal |
| RN48 | O painel de pendências e as consultas agregadas do prestador abrangem exclusivamente animais sob autorização vigente |
| RN49 | Toda visualização de registro clínico originado de outro prestador é gravada em log imutável, consultável pelo tutor |
| RN50 | O titular pode obter cópia de seus dados e requerer o encerramento da conta, com anonimização dos dados pessoais não sujeitos a obrigação legal de guarda |
| RN51 | Os registros clínicos são preservados pelo prazo mínimo determinado pela norma profissional, contado do último atendimento, ainda que o animal venha a óbito ou que a conta do tutor seja encerrada |

---

# 7. Rastreabilidade

**Quadro 2 — Rastreabilidade entre problema identificado e requisitos**

| Problema (Etapa 2) | Requisitos funcionais | Regras determinantes |
|---|---|---|
| P1 — Complexidade do calendário vacinal | RF23, RF24, RF26, RF27 | RN31 a RN36 |
| P2 — Ausência de lembrete confiável | RF05, RF42, RF44, RF50 | RN42 a RN45 |
| P3 — Fragilidade do documento físico | RF28, RF46, RF47 | RN46, RN47 |
| P4 — Fragmentação do histórico | RF13, RF16, RF17, RF18, RF20, RF21, RF35 | RN10, RN12, RN15, RN19 |
| P5 — Perda de informação | RF29, RF33, RF46 | RN24, RN25, RN26, RN51 |
| P6 — Descontinuidade do cuidado | RF31, RF32, RF35, RF36 a RF41 | RN37 a RN41 |
| P7 — Ausência de controle de retornos | RF34, RF43, RF48, RF49, RF51 | RN29, RN48 |
| P8 — Ausência de rastreabilidade | RF25, RF30, RF31, RF33, RF52, RF53 | RN21, RN22, RN26, RN27, RN49 |

**Quadro 3 — Rastreabilidade entre requisito e decisão arquitetural**

| Decisão (Etapa 1) | Requisitos que dela dependem |
|---|---|
| §3.1 — Multi-inquilino com base compartilhada | RF07, RF09, RF13, RF16, RF17, RF20, RF35, RF49; RNF02 |
| §3.2 — Compartilhamento controlado pelo tutor | RF11, RF36 a RF41, RF53 |
| §3.3 — Autenticação por credenciais | RF01 a RF06, RF14; RNF07, RNF08, RNF09 |
| §2.3 — Inversão da responsabilidade sobre o dado clínico | RF16, RF19, RF25, RF31, RF29 |
| §3.4 — Imutabilidade do registro clínico | RF25, RF31, RF33, RF22; RNF25 |
| §3.5 — Lembretes por correio eletrônico | RF05, RF42, RF43, RF44, RF45; RNF05, RNF18, RNF19 |
| §3.6 — Cálculo do calendário a partir da WSAVA | RF23, RF24, RF26, RF27; RNF01, RNF22 |

Os dois quadros acima são, do ponto de vista da avaliação, os artefatos mais valiosos deste documento. Eles demonstram que nenhum requisito foi incluído por hábito ou por imitação de concorrente: cada um responde a um problema identificado ou a uma decisão arquitetural declarada. A banca costuma perguntar por que determinada funcionalidade existe; a resposta está no quadro.

---

# 8. Escopo mínimo demonstrável

Cinquenta e quatro requisitos funcionais não cabem, integralmente, no prazo de um trabalho de conclusão de curso conduzido por um único autor. A priorização abaixo define o subconjunto que basta para demonstrar a proposta de forma íntegra — isto é, que percorre o arco completo do cenário, do cadastro do animal à exportação verificável — e serve de critério objetivo de corte quando o cronograma apertar.

**Núcleo indispensável (implementar primeiro, na ordem indicada):**

RF01, RF02, RF07, RF09 → RF12, RF14, RF16, RF17, RF19, RF20 → RF23, RF24, RF25, RF26 → RF28, RF50 → RF05, RF42, RF44 → RF11, RF36, RF37, RF39 → RF31, RF33, RF35, RF52 → RF49 → RF46, RF47 → RF29.

A ordem não é arbitrária: cada bloco produz uma fatia vertical funcionante, coerente com o roteiro de desenvolvimento adotado. Ao final do quarto bloco já existe demonstração completa do diferencial central — vacinação registrada por veterinário, calendário calculado, carteira visível ao tutor —, o que garante que, mesmo em cenário adverso de cronograma, exista sistema defensável.

**Segundo bloco, se o prazo permitir:** RF03, RF04, RF06, RF08, RF10, RF13, RF15, RF18, RF22, RF27, RF32, RF34, RF38, RF40, RF41, RF43, RF48, RF51, RF53.

**Remetidos a Trabalhos Futuros, salvo folga inesperada:** RF21, RF30, RF45, RF54, RF55.

Recomenda-se que a monografia apresente esta tabela de priorização **antes** do capítulo de Resultados. Declarar a priorização com antecedência transforma o que seria uma lacuna de implementação em decisão de engenharia documentada — diferença que, na defesa, é considerável.

---

# 9. Não requisitos

Registram-se as funcionalidades deliberadamente excluídas, com a respectiva justificativa. A lista é curta e deve constar do texto: escopo não declarado é escopo cobrado.

| Excluído | Justificativa |
|---|---|
| Gestão financeira, ponto de venda e emissão de documentos fiscais | Fora do núcleo do problema tratado; presente nos três concorrentes analisados, o que reforça a delimitação em vez de enfraquecê-la |
| Agendamento de banho, tosa e hospedagem | Serviço não clínico, sem relação com continuidade do histórico |
| Emissão de receituário e atestados | Ato clínico de natureza distinta, com exigências normativas próprias, inclusive de assinatura digital |
| Controle de estoque e de internação | Gestão operacional do estabelecimento, não do histórico do animal |
| Módulo completo de agenda de consultas | Substituído pelo retorno programado (RF34), que resolve o problema P7 a custo substancialmente menor |
| Canal de notificação por WhatsApp | Delimitação de escopo assumida na Etapa 1; canal abstraído na camada de notificação para inclusão futura |
| Aplicativo móvel nativo | Interface responsiva atende ao caso de uso do tutor sem o custo de duas plataformas adicionais |
| Espécies distintas de cão e gato | Os protocolos vacinais diferem substancialmente, e a generalização comprometeria a precisão do calendário |
| Interoperabilidade com sistemas de terceiros | Inexiste padrão de intercâmbio veterinário consolidado no Brasil; declarado como limitação |

---

# 10. Pendências e decisões em aberto

1. **Convenção de idioma dos identificadores.** Há inconsistência entre a convenção declarada — inglês para código, tabelas e colunas — e os identificadores efetivamente empregados nos documentos anteriores, todos em português (`prestadores`, `animais`, `vacinacoes`, `autorizacoes_acesso`, `prestador_id`). É necessário optar por uma das duas antes da Etapa 5, e a decisão deve ser aplicada retroativamente aos documentos, sob pena de a modelagem nascer ambígua. A recomendação é manter o português para o domínio, dada a densidade de termos técnicos veterinários sem tradução consagrada, e declarar a convenção explicitamente na monografia.
2. **Protocolo para animal adulto sem histórico conhecido.** Parametrização a confirmar nas diretrizes originais, conforme nota da seção 6.4. Afeta diretamente RN33 a RN35 e o caso da gata Nina no cenário.
3. **Acesso de urgência sem autorização prévia.** A Etapa 1 declarou a hipótese como limitação. Existe alternativa reconhecida na literatura de sistemas de saúde — o acesso excepcional com justificativa obrigatória, auditoria reforçada e notificação imediata ao titular. Convém decidir, com o orientador, entre declarar a limitação ou incorporar o mecanismo: a segunda opção fortalece o trabalho, ao custo de um requisito adicional de complexidade média e de um argumento a mais a sustentar na defesa.
4. **Repartição dos campos do animal entre tutor e veterinário.** Resolvida na versão 1.1 a questão da origem do cadastro: admitem-se o autocadastro do tutor e o cadastro pelo prestador, com identificação a cargo do tutor (RF16) e caracterização privativa do veterinário (RF19). Permanecem dois pontos em aberto, ambos de baixo custo e alto risco de retrabalho se decididos tarde:
   - **Sexo e data de nascimento.** São dados que o tutor conhece e que o veterinário confirma. Adotou-se a captura como declaração do tutor, sujeita a confirmação profissional. A alternativa — reservá-los integralmente ao veterinário — é mais simples de implementar e menos útil ao tutor que usa o sistema antes de qualquer atendimento. Convém validar com o profissional entrevistado.
   - **Fusão de cadastros duplicados** quando ambos já possuírem registro clínico, hoje declarada como limitação em RF20. Confirmar com o orientador se a declaração basta ou se a banca esperará tratamento.

5. **Efeito do histórico pregresso sobre o cálculo.** Definiu-se que o registro não verificado alimenta o cálculo do calendário, com propagação da marcação de origem às datas dele derivadas. A alternativa — ignorá-lo no cálculo — seria mais conservadora e menos útil. Confirmar com o profissional entrevistado.
6. **Verificação normativa.** Permanecem as pendências da Etapa 2: confirmar, no texto oficial, as Resoluções CFMV nº 1.321/2020 e nº 1.653/2025, especialmente quanto ao prazo de guarda e ao rol de elementos obrigatórios do prontuário, que sustentam RN22, RN51 e RNF25.
7. **Alcance do uso autônomo pelo tutor.** Com o autocadastro (RF12), passa a existir um tutor que usa a plataforma sem prestador algum: cadastra o animal, lança histórico pregresso e recebe lembretes calculados sobre informação não verificada. É preciso decidir se esse tutor recebe lembretes e, em caso afirmativo, com que ressalva. A recomendação é que os receba, com aviso explícito, em cada mensagem e em cada tela de previsão, de que o cálculo se apoia em informação não verificada — a previsão imperfeita é preferível à ausência de previsão, que é justamente o modo de falha do arranjo em papel. A decisão está acoplada à pendência 5 e deve ser tomada em conjunto com ela.

8. **Papel operacional restrito.** Excluída a recepção do rol de usuários (§2.4), a rechamada ativa passa a ser atribuição da equipe clínica. Se a entrevista com o profissional indicar que isso é inviável na rotina do estabelecimento, a alternativa é um quarto papel, limitado a nome do tutor, contato, animal e vacina vencida, sem acesso a prontuário, a diagnóstico ou a qualquer outro conteúdo clínico. Não implementar por ora; registrar em Trabalhos Futuros e levar a pergunta ao entrevistado, pois é exatamente o tipo de exigência operacional que a literatura não revela e a prática sim.

9. **Entrevista semiestruturada.** Reitera-se a recomendação da Etapa 2. Este documento oferece agora um roteiro pronto: submeter ao entrevistado as regras de negócio da seção 6 e as personas da seção 2, solicitando confirmação, correção ou complemento. O resultado converteria a especificação em requisito validado, e o Capítulo 3 passaria a descrever elicitação com fonte primária.

---

# 11. Aproveitamento no texto da monografia

| Seção deste documento | Destino sugerido | Observação |
|---|---|---|
| §1.2 Processo de elicitação | Capítulo 3 — Metodologia | Declara técnicas e fontes; sustenta o rigor do levantamento |
| §2 Personas | Capítulo 3 — Metodologia | Apresentar como técnica de elicitação, com a ressalva de construção pelo autor |
| §3 Atores e permissões | Capítulo 4 — Resultados | O Quadro 1 evidencia o diferencial do sistema em uma única página |
| §4 Requisitos funcionais | Capítulo 3 ou Apêndice | Recomenda-se apresentar no corpo apenas os requisitos essenciais, remetendo a lista completa ao Apêndice |
| §5 Requisitos não funcionais | Capítulo 3 — Metodologia | A organização por ISO/IEC 25010 confere respaldo normativo à seção |
| §6 Regras de negócio | Capítulo 3, com remissão ao Capítulo 2 | As regras de calendário conectam a fundamentação veterinária à implementação |
| §7 Rastreabilidade | Capítulo 4 — Resultados | Elemento de forte apelo perante a banca |
| §8 Escopo mínimo | Capítulo 3, antes dos Resultados | Converte lacuna de implementação em decisão documentada |
| §9 Não requisitos | Capítulo 1 — Delimitação do escopo | Deve aparecer cedo no texto, e não como justificativa tardia |

**Observação sobre a lacuna já identificada.** A fundamentação teórica da monografia permanece integralmente dedicada à saúde animal, sem conteúdo de Computação — apontado desde a Etapa 1 como o ponto mais provável de questionamento pela banca. Este documento agravou a necessidade e, simultaneamente, indicou o caminho: a seção 5 invoca a ISO/IEC 25010; a seção 4 pressupõe arquitetura em camadas, API REST e SPA; a seção 3 pressupõe controle de acesso baseado em papéis; e a seção 6 pressupõe modelo relacional e arquitetura multi-inquilino. Cada um desses conceitos precisa de fundamentação prévia no Capítulo 2, sob pena de o Capítulo 3 apoiar-se em noções nunca apresentadas. A ampliação da revisão bibliográfica deixou de ser recomendável e passou a ser necessária.

---

# 12. Referências utilizadas neste documento

BRASIL. **Lei nº 13.709, de 14 de agosto de 2018.** Lei Geral de Proteção de Dados Pessoais (LGPD). *(Citar especialmente os artigos 5º, 7º, 16, 18 e 46.)*

BRASIL. Conselho Federal de Medicina Veterinária. **Resolução nº 1.321, de 5 de junho de 2020.** Dispõe sobre a documentação médico-veterinária. *(Consultar o texto oficial.)*

BRASIL. Conselho Federal de Medicina Veterinária. **Resolução nº 1.653, de 26 de junho de 2025.** Altera dispositivos da Resolução nº 1.321/2020. *(Consultar o texto oficial.)*

CARROLL, J. M. **Making use:** scenario-based design of human-computer interactions. Cambridge: MIT Press, 2000.

DAY, M. J.; HORZINEK, M. C.; SCHULTZ, R. D.; SQUIRES, R. A. Diretrizes da WSAVA para a vacinação de cães e gatos. **Journal of Small Animal Practice**, v. 57, n. 1, p. E1–E45, 2016.

INTERNATIONAL ORGANIZATION FOR STANDARDIZATION. **ISO/IEC 25010:** systems and software engineering — systems and software Quality Requirements and Evaluation (SQuaRE) — system and software quality models. Genebra, 2011. *(Verificar se a edição de 2023 é a adotada; a numeração das características foi alterada.)*

**Sugestões de referência a incorporar na fundamentação teórica**, para suprir a lacuna de conteúdo de Computação apontada na seção 11: obra de referência em Engenharia de Requisitos, para sustentar a classificação entre requisitos funcionais, não funcionais e regras de negócio; obra de Engenharia de Software, para arquitetura em camadas e qualidade de produto; referência sobre estilo arquitetural REST; e referência sobre arquitetura multi-inquilino em software como serviço. A escolha das obras específicas deve ser combinada com o orientador, observando a bibliografia adotada nas disciplinas do curso.

---

*Documento produzido na Etapa 3 do projeto. Deve ser anexado, junto ao documento de decisões e ao estudo de caso, no início das conversas subsequentes.*
