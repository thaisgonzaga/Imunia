---
title: "Imunia --- Estudo de Caso"
subtitle: "Etapa 2 do Trabalho de Conclusão de Curso: caracterização do problema e da solução proposta"
date: "Agosto de 2026 --- versão 1.0"
lang: pt-BR
---

# 1. Nota metodológica preliminar

## 1.1 O que este documento é

Este documento apresenta um **cenário ilustrativo de aplicação**, construído a partir da literatura consultada e das normas profissionais vigentes, com a finalidade de caracterizar o problema tratado pelo sistema Imunia e demonstrar de que modo as decisões de projeto respondem a ele.

## 1.2 Uma advertência que precisa ser observada

Há um risco de defesa que deve ser eliminado antes da redação final: **o cenário aqui descrito é fictício, e não pode ser apresentado à banca como estudo de caso empírico**. Estudo de caso, na acepção consolidada por Yin (2015), é investigação empírica de um fenômeno contemporâneo em seu contexto real, com fontes múltiplas de evidência. Um cenário construído pelo autor não satisfaz essa definição, e rotulá-lo como tal expõe o trabalho a questionamento metodológico legítimo.

A solução não é abandonar o cenário, e sim nomeá-lo corretamente. Na Engenharia de Software e no design de interação, cenários e personas constituem técnica reconhecida de elicitação e comunicação de requisitos — o *scenario-based design* de Carroll (2000) é o marco teórico usual. Sob esse enquadramento, o material a seguir é legítimo, defensável e metodologicamente ancorado.

**Recomendações de rotulagem:**

| Uso | Rótulo recomendado | Onde |
|---|---|---|
| Caracterização do problema | *Cenário de aplicação* ou *Caso ilustrativo* | Capítulo 1 (Justificativa) e Capítulo 3 (Metodologia) |
| Demonstração da solução | *Cenário de validação* ou *Prova de conceito* | Capítulo 4 (Resultados) |
| A evitar | "Estudo de caso" sem qualificação | Todo o texto |

**Reforço empírico sugerido (alto retorno, baixo custo).** Uma entrevista semiestruturada com um único médico-veterinário atuante, com roteiro de dez a quinze perguntas derivadas das seções 4 e 5 deste documento, converteria o cenário de construção teórica em cenário validado por profissional da área. Bastaria acrescentar à Metodologia um parágrafo declarando a entrevista, e ao Apêndice o roteiro aplicado. Com isso, a expressão "estudo de caso" passa a ser sustentável, ainda que como estudo de caso único e exploratório.

---

# 2. Contextualização do problema

## 2.1 Dimensão do fenômeno

O Brasil possui uma das maiores populações de animais de companhia do mundo. Estimativas do setor apontam algo em torno de 160 milhões de animais de estimação no país, dos quais mais de 60 milhões são cães e aproximadamente 30 milhões são gatos (ABINPET; INSTITUTO PET BRASIL, 2024). A escala tem consequência direta sobre o problema tratado: cada um desses animais possui, em tese, um calendário vacinal individualizado, um histórico clínico e um responsável encarregado de acompanhá-los.

## 2.2 A adesão vacinal é incompleta

Vasconcelos (2011) estima que, mesmo em países desenvolvidos, menos de metade dos animais de estimação receba vacinação adequada, atribuindo parte relevante do problema à interpretação equivocada, por proprietários e por veterinários, sobre quais vacinas são necessárias e com que frequência devem ser administradas.

Dados brasileiros apontam na mesma direção. Análise da Pesquisa Nacional de Saúde conduzida por Oliveira, Tavela e Wagner (2023) identificou que, na região Sudeste — a de maior prevalência do país —, 84,8% dos domicílios vacinaram todos os seus cães e gatos contra a raiva, índice que cai para 63,7% na região Sul. Os autores identificaram ainda associação estatística entre a adesão vacinal e fatores como escolaridade e faixa etária do chefe do domicílio, concluindo pela necessidade de políticas públicas de orientação sobre guarda responsável.

Dois qualificadores importam para a leitura correta desse dado. Primeiro, ele se refere exclusivamente à vacina antirrábica, historicamente amparada por campanhas públicas gratuitas — as demais vacinas essenciais, aplicadas na rede privada e sem campanha correspondente, não gozam do mesmo suporte institucional e tendem a apresentar adesão inferior. Segundo, o indicador mede a ocorrência da vacinação, não a sua **adequação ao protocolo**: um animal que recebeu uma dose isolada e nenhum reforço é contabilizado como vacinado.

É precisamente na diferença entre *vacinar* e *cumprir o protocolo* que reside o problema de informação tratado neste trabalho.

## 2.3 O protocolo é complexo por natureza

Conforme exposto no Capítulo 2 desta monografia, o calendário vacinal de cães e gatos envolve doses múltiplas em intervalos de duas a quatro semanas, dose final condicionada à idade mínima de dezesseis semanas, reforço aos seis ou doze meses, revacinação trienal para vacinas essenciais em adultos e revacinação anual para determinadas não essenciais. A esse arranjo somam-se a interferência dos anticorpos de origem materna, cujo nível varia entre ninhadas (DAY et al., 2016), e a individualização recomendada por Angélico (2011), segundo raça, idade, localidade, estilo de vida e histórico de saúde.

Trata-se, do ponto de vista computacional, de um **problema de agendamento com dependências temporais, condicionais e de estado** — exatamente a classe de problema que o controle manual executa mal e que sistemas de informação executam bem. Essa é a formulação que deve constar do texto: o Imunia não existe porque o papel é antiquado, mas porque o cálculo do calendário vacinal é uma regra de negócio complexa o bastante para justificar automação.

---

# 3. O cenário

## 3.1 Ambiente

**São Bento do Ribeirão (MG)** — município fictício da Zona da Mata mineira, com aproximadamente 45 mil habitantes. A infraestrutura veterinária local é composta por:

| Estabelecimento | Natureza | Registro clínico |
|---|---|---|
| Clínica Vet Amigo | Clínica veterinária, dois veterinários | Fichas em papel, arquivadas por ordem alfabética |
| Pet Center Bicho Feliz | Petshop com atendimento veterinário | Planilha eletrônica e caderno de aplicações |
| Dra. Larissa Freitas | Médica-veterinária autônoma, atendimento domiciliar | Anotações em aplicativo de notas do telefone |
| Campanha municipal antirrábica | Serviço público anual | Comprovante avulso em papel |

O município não possui hospital veterinário com plantão. Emergências noturnas são encaminhadas a Juiz de Fora, a aproximadamente noventa quilômetros.

## 3.2 Atores

**Helena Ramos**, 34 anos, professora da rede municipal. Tutora de dois animais. Utiliza aplicativos de banco e de mensagens com desenvoltura, mas não mantém controle organizado de documentos em papel. Representa o perfil predominante de tutor: atento ao bem-estar do animal, porém sem instrumento adequado de acompanhamento.

**Théo**, cão sem raça definida, adotado com aproximadamente sete semanas de vida em fevereiro de 2025.

**Nina**, gata, adotada adulta em 2024, sem histórico vacinal conhecido.

**Dr. Marcelo Antunes**, médico-veterinário responsável técnico da Clínica Vet Amigo, dezoito anos de profissão. Atende em média catorze animais por dia.

**Camila**, recepcionista da mesma clínica, encarregada informalmente de telefonar para tutores com vacinas em atraso, tarefa executada quando o movimento permite.

## 3.3 Linha do tempo

**Fevereiro de 2025 — primeira dose.** Helena adota Théo por intermédio de uma protetora independente. Aplica a primeira dose de V8 no Pet Center Bicho Feliz, que emite carteira de vacinação em papel. A etiqueta do frasco é colada no campo correspondente; a data é manuscrita.

**Março de 2025 — segunda dose em outro estabelecimento.** Por conveniência de horário, Helena leva Théo à Clínica Vet Amigo. O Dr. Marcelo não tem como confirmar o que foi aplicado antes: depende integralmente da carteira apresentada e da memória da tutora. Registra a segunda dose e abre uma ficha em papel, na qual transcreve manualmente o que consta da carteira.

**Abril de 2025 — a dose que atrasou.** A terceira dose estava prevista para o início de abril. Ninguém a lembrou. A carteira ficou guardada em uma gaveta. Helena retorna à clínica somente na terceira semana de maio, quando Théo já contava vinte semanas de vida. O Dr. Marcelo aplica a dose e explica que o intervalo prolongado compromete parcialmente a previsibilidade da resposta imune.

**Maio de 2025 — o registro que se perdeu na origem.** Théo recebe a vacina antirrábica na campanha municipal. O comprovante é um papel avulso, entregue na fila. Helena o guarda na bolsa. O documento não é anexado à carteira nem informado à clínica. Do ponto de vista de qualquer sistema de informação existente, essa aplicação nunca ocorreu.

**Setembro de 2025 — a mudança de cidade.** Helena é transferida para outro município. Precisa escolher um novo veterinário. Leva a carteira, que apresenta três problemas: uma das etiquetas desbotou e tornou-se ilegível; uma das aplicações foi registrada sem número de lote e sem identificação do profissional; e não há qualquer menção à antirrábica de maio. O novo veterinário registra em ficha própria a expressão "histórico vacinal incompleto, informado pela tutora".

**Novembro de 2025 — o retrabalho clínico.** Théo apresenta quadro dermatológico. É atendido em uma clínica, que solicita raspado cutâneo e prescreve tratamento. Sem melhora aparente em dez dias, Helena procura segunda opinião em outro estabelecimento. O segundo profissional não tem acesso ao laudo do exame nem à prescrição anterior; repete o exame e inicia esquema terapêutico distinto. Helena, sem formação técnica, não é capaz de relatar com precisão o princípio ativo utilizado anteriormente nem a dose.

**Janeiro de 2026 — a perda total.** Durante a mudança de residência, a carteira de vacinação é extraviada. Helena telefona ao Pet Center Bicho Feliz e descobre que o estabelecimento encerrou as atividades. A Clínica Vet Amigo confirma possuir a ficha de Théo, mas apenas com os atendimentos que ela mesma realizou. O comprovante da campanha municipal é irrecuperável.

**Fevereiro de 2026 — a decisão sob incerteza.** Diante de histórico não comprovável, o veterinário recomenda reiniciar o esquema vacinal por precaução. Théo recebe doses que, com alta probabilidade, já havia recebido. O custo é assumido pela tutora; a exposição desnecessária, pelo animal.

## 3.4 O mesmo período, do outro lado do balcão

Na Clínica Vet Amigo, o mesmo intervalo produziu uma sequência paralela de dificuldades.

O Dr. Marcelo não dispõe de qualquer instrumento que lhe informe quantos animais sob seus cuidados estão com reforço vencido. A informação existe — está distribuída por centenas de fichas em papel —, mas é inacessível na prática, porque extraí-la exigiria folhear o arquivo inteiro.

Camila executa a rechamada ativa por telefone e por mensagens, apoiada em um caderno no qual anota, de memória e por amostragem, os casos que percebe. Não há critério, não há cobertura e não há registro do que foi tentado. Um tutor pode ser contatado duas vezes na mesma semana; outro pode não ser contatado durante um ano.

Quando Helena solicita, em janeiro de 2026, uma segunda via do histórico de Théo, a clínica leva três dias para localizar a ficha e fotografá-la. A Resolução CFMV nº 1.321/2020, alterada pela Resolução nº 1.653/2025, estabelece prazo de até cinco dias úteis para o fornecimento de cópia do prontuário mediante solicitação do responsável — prazo que o arquivo em papel cumpre com dificuldade e sem margem.

A mesma norma exige que o prontuário registre os procedimentos realizados com detalhamento de data, hora e identificação do profissional responsável, por nome e número de CRMV, e determina guarda mínima de cinco anos contados do último atendimento, ainda que o animal venha a óbito. Uma ficha manuscrita, sem hora, sem identificação padronizada do aplicador e sujeita a extravio físico, atende a essas exigências apenas nominalmente.

---

# 4. Análise do caso: decomposição dos problemas

O cenário descrito não é uma sucessão de acidentes. Cada episódio decorre de uma falha estrutural identificável, e é essa decomposição que confere ao capítulo valor analítico, e não meramente narrativo.

## P1 — Complexidade operacional do calendário vacinal

**Manifestação no cenário.** A terceira dose de Théo atrasou seis semanas. Não houve negligência da tutora: houve ausência de instrumento capaz de converter uma regra temporal condicional em um aviso no momento correto.

**Natureza do problema.** O cálculo da próxima dose depende de espécie, idade na aplicação anterior, tipo de vacina, número de doses já administradas e do próprio intervalo decorrido. É determinístico, mas não é trivial, e sua execução mental é sujeita a erro sistemático.

**Consequência.** Protocolos cumpridos parcialmente produzem imunização de eficácia incerta — o pior dos resultados possíveis, por gerar percepção de proteção sem a proteção correspondente.

## P2 — Ausência de mecanismo de lembrete confiável

**Manifestação no cenário.** Nenhum aviso foi emitido em abril de 2025. A carteira em papel é um artefato passivo: informa quem a consulta, mas não avisa quem a esqueceu.

**Natureza do problema.** O sistema de informação vigente transfere integralmente ao tutor a responsabilidade pela iniciativa, sem lhe oferecer suporte. A rechamada existente é humana, manual e não sistemática.

## P3 — Fragilidade material do documento físico

**Manifestação no cenário.** Etiqueta desbotada em setembro de 2025; extravio total em janeiro de 2026.

**Natureza do problema.** A carteira de vacinação em papel concentra as seguintes vulnerabilidades: cópia única, sem redundância; suporte degradável; preenchimento manuscrito sujeito a ilegibilidade; campos frequentemente incompletos, notadamente lote, validade e identificação do aplicador; e ausência de qualquer mecanismo de verificação de autenticidade.

**Observação normativa.** A Resolução CFMV nº 1.653/2025 passou a determinar que documentos como prontuários e carteiras de vacinação sejam emitidos em duas vias, uma para o profissional e outra para o responsável pelo animal. A medida mitiga a vulnerabilidade da via única, mas não resolve a fragmentação: as duas vias ainda são artefatos isolados, sem sincronização entre si.

## P4 — Fragmentação do histórico entre estabelecimentos

**Manifestação no cenário.** O histórico de Théo, em janeiro de 2026, encontrava-se distribuído por quatro custódias distintas: um petshop encerrado, uma clínica, um serviço público de campanha e um veterinário de outro município. Nenhuma delas detinha o conjunto; nenhuma delas conhecia a existência das demais.

**Natureza do problema.** Cada estabelecimento constitui uma ilha de dados. A única entidade que percorre todas as ilhas é o animal, e é precisamente a entidade sem representação computacional persistente no arranjo vigente.

**Efeito colateral relevante.** A fragmentação penaliza desproporcionalmente o animal cujo tutor se muda, adota em situação de rua, utiliza serviços de campanha ou recorre a mais de um profissional — que é, na prática, a maioria.

## P5 — Perda irreversível de informação

**Manifestação no cenário.** A antirrábica de maio de 2025 desapareceu na origem, por nunca ter sido incorporada a qualquer registro persistente. O restante desapareceu em janeiro de 2026, com o extravio da carteira.

**Natureza do problema.** Há duas modalidades distintas de perda, que devem ser tratadas separadamente no texto: a **perda por não captura**, quando o dado nunca chega a ser registrado em meio recuperável, e a **perda por destruição do suporte**, quando o registro existiu e foi extraviado. A primeira é problema de cobertura do sistema; a segunda, de persistência e redundância.

**Consequência.** Em fevereiro de 2026, a perda de informação converteu-se em intervenção clínica desnecessária. Este é o ponto em que o problema deixa de ser administrativo e passa a ser sanitário e econômico.

## P6 — Descontinuidade do cuidado clínico

**Manifestação no cenário.** Em novembro de 2025, o exame dermatológico foi repetido e a conduta terapêutica foi alterada sem conhecimento da anterior.

**Natureza do problema.** A ausência de histórico acessível produz três efeitos encadeados: repetição de exames, com custo e desconforto para o animal; decisão clínica tomada sobre informação incompleta; e transferência ao tutor — leigo — do papel de intermediário técnico entre profissionais, função para a qual não possui qualificação.

## P7 — Ausência de controle sistemático sobre atendimentos e retornos

**Manifestação no cenário.** A rechamada ativa da Clínica Vet Amigo é executada por amostragem, sem critério e sem registro. O retorno recomendado em novembro de 2025 não foi objeto de qualquer acompanhamento.

**Natureza do problema.** A informação necessária ao controle existe, mas está armazenada em suporte que não permite consulta agregada. A pergunta "quais animais estão com reforço vencido neste mês?" é, em um arquivo de papel, computacionalmente inviável, embora trivial em uma base relacional.

**Consequência para o estabelecimento.** Perde-se receita recorrente e, sobretudo, perde-se a oportunidade de intervenção preventiva — que é a finalidade sanitária da atividade.

## P8 — Ausência de rastreabilidade e de responsabilização

**Manifestação no cenário.** Uma das aplicações registradas na carteira de Théo não identificava lote nem profissional responsável.

**Natureza do problema.** Sem lote e validade, é impossível rastrear falha vacinal atribuível ao imunobiológico. Sem identificação do aplicador, é impossível estabelecer responsabilidade técnica. O registro perde, simultaneamente, valor epidemiológico e valor jurídico.

**Observação normativa.** É exatamente o que a Resolução CFMV nº 1.653/2025 passou a exigir de forma expressa, ao determinar o registro detalhado dos procedimentos com data, hora e identificação do profissional por nome e número de CRMV. A exigência regulatória converte o que seria uma boa prática em requisito de conformidade — e, portanto, em requisito de sistema.

## Síntese

**Quadro 1 — Problemas identificados e respectivas causas estruturais**

| Cód. | Problema | Causa estrutural | Afetado principal |
|---|---|---|---|
| P1 | Complexidade do calendário vacinal | Regra temporal condicional executada manualmente | Animal |
| P2 | Ausência de lembrete confiável | Artefato de registro passivo | Tutor |
| P3 | Fragilidade do documento físico | Suporte único, degradável e não verificável | Tutor |
| P4 | Fragmentação do histórico | Dado sob custódia do estabelecimento, não do animal | Veterinário |
| P5 | Perda de informação | Ausência de captura e de persistência redundante | Ambos |
| P6 | Descontinuidade do cuidado | Histórico inacessível no momento da decisão clínica | Veterinário |
| P7 | Ausência de controle de retornos | Dado armazenado em suporte não consultável | Estabelecimento |
| P8 | Ausência de rastreabilidade | Registro incompleto e sem autoria identificada | Todos |

Uma causa raiz comum atravessa os oito itens: **o dado clínico está vinculado ao estabelecimento que o produziu, e não ao animal a que se refere**. Essa é a formulação central do problema, e convém que apareça literalmente na Justificativa do Capítulo 1.

---

# 5. A resposta do sistema proposto

Esta seção associa cada problema às funcionalidades do Imunia e, o que é academicamente mais relevante, às decisões arquiteturais que as tornam possíveis.

## S1 — Cálculo automatizado do calendário vacinal (responde a P1)

A lógica de intervalos, doses e reforços, derivada das diretrizes da WSAVA e das recomendações de fabricantes, é implementada como regra de negócio em camada de serviço. A cada vacinação registrada, o sistema calcula a data prevista da próxima aplicação considerando espécie, idade do animal, tipo de vacina, ordem da dose na série e intervalo decorrido.

O ganho não é a substituição de um cálculo fácil, e sim a **eliminação de uma classe inteira de erro humano** em uma regra que a literatura veterinária descreve como frequentemente mal compreendida pelos próprios profissionais (VASCONCELOS, 2011).

## S2 — Notificação automática por correio eletrônico (responde a P2)

Rotina diária executada pelo Laravel Scheduler identifica vacinações previstas e enfileira notificações, despachadas de forma assíncrona. O canal é abstraído pela camada de *Notification*, de modo que a inclusão futura de outro meio não altera a regra de negócio.

A confiabilidade do mecanismo depende de quatro requisitos associados, já definidos na etapa anterior: verificação do endereço eletrônico no cadastro; descadastro individualizado por tipo de notificação, preservadas as comunicações transacionais; idempotência garantida por tabela de notificações enviadas; e política de nova tentativa na fila.

O contraste com o cenário é direto: em abril de 2025, a diferença entre o protocolo cumprido e o protocolo interrompido foi uma mensagem que ninguém enviou.

## S3 — Carteira de vacinação digital com exportação verificável (responde a P3)

O registro persiste em base de dados, com redundância assegurada pela infraestrutura, e não em suporte físico sob custódia do tutor. A perda do telefone ou a mudança de residência não têm efeito sobre a integridade da informação.

Para os casos em que o documento precisa circular fora da plataforma, a exportação em PDF gera identificador próprio e *hash* do conteúdo, impressos como QR Code que aponta para rota pública de verificação. O terceiro que recebe o documento pode conferir se ele confere e quando foi emitido — o que a carteira em papel jamais permitiu.

## S4 — Modelo de dados centrado no animal (responde a P4)

Esta é a decisão arquitetural que sustenta o diferencial do sistema, e merece tratamento destacado no Capítulo 4.

Na arquitetura multi-inquilino com base compartilhada adotada, `tutores` e `animais` são entidades **globais**, sem discriminador de inquilino, ao passo que `atendimentos`, `vacinacoes` e `prontuarios` são escopados por `prestador_id`, preservando a autoria e a responsabilidade técnica de cada registro.

A consequência é a inversão exata da causa raiz enunciada na seção 4: o dado clínico permanece vinculado ao animal, e o estabelecimento produtor é atributo do registro, não seu proprietário exclusivo. A mudança de cidade do tutor deixa de exigir importação, migração ou integração — exige apenas autorização.

## S5 — Persistência e captura ampliada (responde a P5)

Contra a perda por destruição do suporte, a persistência em base relacional é suficiente. Contra a perda por não captura, o sistema oferece duas respostas de naturezas distintas, que devem ser apresentadas com honestidade no texto:

- Aplicações realizadas por prestadores cadastrados são capturadas integralmente e de forma verificada;
- Aplicações realizadas fora da plataforma — a campanha municipal de maio de 2025, por exemplo — podem ser lançadas em campo de histórico pregresso, **explicitamente marcado como não verificado**, com distinção visual em relação aos registros de origem profissional.

A segunda resposta é uma mitigação, não uma solução. Reconhecê-la como tal fortalece o trabalho perante a banca, em vez de enfraquecê-lo.

## S6 — Continuidade do histórico mediante autorização do tutor (responde a P6)

O tutor consulta, pelo próprio sistema, quais prestadores utilizam a plataforma, e concede autorização nominal ao escolhido, confirmada por código enviado ao endereço eletrônico cadastrado. Confirmada a autorização, cria-se vínculo em `autorizacoes_acesso`, e o prestador autorizado passa a visualizar consultas, exames, diagnósticos e tratamentos registrados por outros profissionais.

A autorização desmembra-se em três níveis, coerentes com a decisão de imutabilidade do registro clínico:

| Nível | Alcance |
|---|---|
| Leitura | Visualiza todo o histórico anterior, de qualquer origem |
| Escrita | Cria registros novos, atribuídos a ele e ao seu prestador |
| Edição | Inexistente sobre registro alheio; correções apenas por retificação |

Aplicado ao cenário: em novembro de 2025, o segundo profissional teria visualizado o laudo do raspado cutâneo e a prescrição anterior antes de decidir, sem repetir o exame e sem depender do relato de uma tutora leiga.

**Distinção relevante para a comparação com concorrentes.** O compartilhamento automático praticado por parte do mercado faz o histórico circular entre profissionais sem ato de vontade do titular. O modelo do Imunia coloca o consentimento na origem do fluxo, o que o distingue tanto do ponto de vista de projeto quanto do ponto de vista da Lei Geral de Proteção de Dados.

## S7 — Painel de pendências e retorno programado (responde a P7)

Ao veterinário e ao prestador, o sistema oferece consulta agregada às pendências vacinais dos animais sob seus cuidados, e o painel de acompanhamento dos atendimentos registrados nos últimos trinta dias. A pergunta que o arquivo de papel não respondia passa a ser uma consulta com filtro por data.

**Ponto de decisão em aberto.** O item "controle de consultas" comporta duas leituras, e convém que a monografia declare qual foi adotada:

| Alternativa | Alcance | Custo | Avaliação |
|---|---|---|---|
| (a) Registro de atendimentos | Já contemplado pela entidade `atendimentos` | Nulo | Insuficiente para o problema P7 |
| (b) Retorno programado | Data de retorno vinculada ao atendimento, alimentando o motor de lembretes | Baixo | **Recomendada** |
| (c) Módulo completo de agenda | Marcação, horários, disponibilidade, confirmação | Alto | Fora do escopo delimitado |

A alternativa (b) resolve o problema identificado no cenário — o retorno de novembro de 2025 que ninguém acompanhou — sem incorporar a complexidade de um módulo de agenda, cuja ausência já foi assumida como delimitação de escopo. Reaproveita, ademais, a infraestrutura de notificação já construída para o calendário vacinal.

## S8 — Registro completo, imutável e auditável (responde a P8)

Cada vacinação registra fabricante, lote, validade, via de administração e identificação do profissional aplicador com número de CRMV, atendendo ao que a Resolução CFMV nº 1.321/2020, alterada pela Resolução nº 1.653/2025, passou a exigir do prontuário.

Os registros clínicos são imutáveis: correções não sobrescrevem o original, entrando como retificação vinculada ao registro corrigido, e preservando ambos. A prática replica o padrão consolidado do prontuário eletrônico na medicina humana e preserva autoria e responsabilidade técnica.

Complementarmente, toda visualização de prontuário originado de outro prestador é registrada em log — quem acessou, o que acessou e quando —, o que constitui evidência de conformidade e permite ao tutor auditar quem consultou os dados do seu animal.

---

# 6. Matriz de rastreabilidade

**Quadro 2 — Rastreabilidade entre problema, requisito e decisão arquitetural**

| Problema | Requisito funcional | Decisão arquitetural de suporte |
|---|---|---|
| P1 | RF03 — Cálculo automático do calendário vacinal | Regra de negócio em *Service*; base WSAVA (§3.6) |
| P2 | RF05 — Lembretes automáticos por e-mail | Scheduler, Queues e Notifications (§3.5) |
| P3 | RF04 — Carteira digital<br>RF09 — Exportação em PDF com QR de verificação | Persistência relacional; *hash* e rota pública de verificação (§4.5) |
| P4 | RF01 — Cadastro global do animal com código único | Multi-inquilino com base compartilhada; `animais` e `tutores` sem `prestador_id` (§3.1) |
| P5 | RF10 — Histórico pregresso não verificado | Marcação explícita de origem e de confiabilidade do registro (§6) |
| P6 | RF07 — Autorização nominal pelo tutor<br>RF14 — Revogação | Livro de consentimento `autorizacoes_acesso` (§3.2, §4) |
| P7 | RF13 — Painel de pendências vacinais<br>RF12 — Retorno programado | Consulta agregada sobre base relacional; reuso do motor de notificação |
| P8 | RF02 — Registro de lote, validade e CRMV do aplicador<br>RF06 — Prontuário imutável<br>RF08 — Log de acesso | Imutabilidade com retificação (§3.4); autenticação por credenciais e não repúdio (§3.3) |

> **Nota.** A numeração de requisitos acima é provisória e deverá ser consolidada na Etapa 4 (Requisitos). Registre-se, contudo, que **RF02 e RF12 emergiram desta análise** e não constavam do documento de decisões — o que ilustra a função do cenário como técnica de elicitação, e não apenas de exposição.

---

# 7. O cenário sob a hipótese de adoção do sistema

A releitura contrafactual da mesma trajetória evidencia, de forma sintética, o efeito agregado das funcionalidades descritas.

**Fevereiro de 2025.** O Pet Center cadastra Théo, que recebe código único e permanente na plataforma. A primeira dose é registrada com fabricante, lote, validade e CRMV do aplicador. O sistema calcula a data prevista da segunda dose.

**Março de 2025.** Helena autoriza a Clínica Vet Amigo. O Dr. Marcelo visualiza a dose anterior com todos os seus atributos, e não depende de transcrição manual nem da memória da tutora.

**Abril de 2025.** Helena recebe notificação sete dias antes da data prevista. A terceira dose é aplicada no prazo, e o protocolo se completa dentro da janela recomendada.

**Maio de 2025.** A antirrábica de campanha continua fora da plataforma — o serviço público não é prestador cadastrado. Helena lança a aplicação como histórico pregresso, marcado como não verificado. **A limitação permanece, mitigada.**

**Setembro de 2025.** A mudança de cidade não produz efeito algum sobre o histórico. Helena consulta os prestadores disponíveis no novo município e autoriza um deles. O acesso é imediato e integral.

**Novembro de 2025.** O segundo profissional consultado visualiza o laudo e a prescrição anteriores antes de decidir. O exame não é repetido. O acesso fica registrado em log.

**Janeiro de 2026.** Não há carteira a extraviar. A segunda via é gerada pelo próprio tutor, em PDF verificável, em segundos — e não em três dias.

**Fevereiro de 2026.** Não há doses desnecessárias, porque não há incerteza sobre o histórico.

---

# 8. Limitações da solução proposta

A honestidade quanto ao alcance da proposta é, em trabalho acadêmico, elemento de força. As limitações abaixo devem constar do texto acompanhadas de suas mitigações.

**Quadro 3 — Limitações reconhecidas**

| Limitação | Impacto no cenário | Mitigação adotada |
|---|---|---|
| Prestadores não cadastrados na plataforma | A campanha municipal permanece fora do registro verificado | Exportação verificável em PDF; campo de histórico pregresso marcado como não verificado |
| Dependência da adesão de estabelecimentos | O valor da rede cresce com o número de participantes | Declarada; o sistema é útil ainda com adesão parcial, pois o histórico permanece com o animal |
| Ausência do canal WhatsApp | Tutores com baixo uso de correio eletrônico podem não ser alcançados | Delimitação de escopo assumida; canal abstraído para inclusão futura |
| Atendimento de urgência sem tutor disponível para autorizar | Emergência em Juiz de Fora, fora do horário, sem autorização prévia | Declarada; mitigada pela exportação prévia em PDF |
| Ausência de padrão de interoperabilidade veterinária no Brasil | Impossibilidade de intercâmbio com sistemas de terceiros | Declarada; remetida a Trabalhos Futuros |
| Cenário ilustrativo, não empírico | O problema é caracterizado por construção, não por levantamento de campo | Rotulagem correta como cenário; entrevista com profissional, se realizada |

---

# 9. Aproveitamento no texto da monografia

| Seção deste documento | Destino sugerido | Observação |
|---|---|---|
| §2 Contextualização | Capítulo 1 — Justificativa | Dados e literatura sustentam a relevância do trabalho |
| §3 Cenário | Capítulo 3 — Metodologia | Apresentar como técnica de elicitação, com referência a Carroll (2000) |
| §4 Análise dos problemas | Capítulo 1 — Justificativa, com remissão | Quadro 1 é candidato natural a figurar no texto |
| §5 Resposta do sistema | Capítulo 4 — Resultados | Estrutura principal do capítulo |
| §6 Matriz de rastreabilidade | Capítulo 4 — Resultados | Elemento de forte apelo para a banca |
| §7 Cenário contrafactual | Capítulo 4 — Resultados, ao final | Fecha o arco narrativo aberto no Capítulo 1 |
| §8 Limitações | Capítulo 5 — Conclusão | Alimenta diretamente a seção de Trabalhos Futuros |

A estrutura resultante produz um arco coerente: o Capítulo 1 apresenta o problema por meio do cenário, o Capítulo 4 retoma o mesmo cenário para demonstrar a solução, e o Capítulo 5 reconhece o que permaneceu fora do alcance. É uma organização que a banca reconhece e valoriza.

---

# 10. Pendências decorrentes deste documento

1. **Decidir** entre as alternativas (a), (b) e (c) da seção 5.7 quanto ao controle de consultas. Recomendação: alternativa (b).
2. **Incorporar** RF02 (lote, validade, fabricante, via e CRMV do aplicador) ao levantamento de requisitos da Etapa 4 — trata-se de exigência normativa, não de funcionalidade opcional.
3. **Verificar no texto oficial** as Resoluções CFMV nº 1.321/2020 e nº 1.653/2025 antes da citação definitiva, no portal do próprio Conselho. Este documento apoia-se em fontes secundárias (CRMV-SP, CRMV-GO e imprensa especializada); há divergência entre fontes quanto ao prazo de guarda do prontuário, prevalecendo a indicação de cinco anos contados do último atendimento. **Confirmar diretamente na norma.**
4. **Confirmar** os números populacionais junto à ABINPET, cuja publicação mais recente deve ser consultada no portal da própria associação.
5. **Avaliar** a realização de uma entrevista semiestruturada com médico-veterinário, conforme seção 1.2 — é a intervenção de maior retorno metodológico disponível neste momento do trabalho.
6. **Definir** o nome do município fictício em conjunto com o orientador, verificando se ele admite cenário construído ou se prefere caso anonimizado real.

---

# 11. Referências utilizadas neste documento

## 11.1 Já constantes da monografia

ANGÉLICO, S. **Vacinas para cães — afinal, quais e quando aplicar?** 2011.

DAY, M. J.; HORZINEK, M. C.; SCHULTZ, R. D.; SQUIRES, R. A. Diretrizes da WSAVA para a vacinação de cães e gatos. **Journal of Small Animal Practice**, v. 57, n. 1, p. E1–E45, 2016.

VASCONCELOS, A. V. **Imunização em cães e gatos:** tendências atuais. Monografia (Especialização em Residência Médico-Veterinária) — Universidade Federal de Minas Gerais, Belo Horizonte, 2011.

## 11.2 A acrescentar

ABINPET — Associação Brasileira da Indústria de Produtos para Animais de Estimação; INSTITUTO PET BRASIL. **Informações gerais do setor.** 2024. *(Verificar a edição mais recente e a forma de citação adotada pela entidade.)*

BRASIL. Conselho Federal de Medicina Veterinária. **Resolução nº 1.321, de 5 de junho de 2020.** Dispõe sobre a documentação médico-veterinária. *(Consultar o texto oficial.)*

BRASIL. Conselho Federal de Medicina Veterinária. **Resolução nº 1.653, de 26 de junho de 2025.** Altera dispositivos da Resolução nº 1.321/2020. *(Consultar o texto oficial.)*

CARROLL, J. M. **Making use:** scenario-based design of human-computer interactions. Cambridge: MIT Press, 2000. *(Confere fundamento metodológico ao uso de cenários; verificar disponibilidade.)*

OLIVEIRA, F. M.; TAVELA, A. O.; WAGNER, K. J. P. Associação entre fatores socioeconômicos e demográficos e vacinação antirrábica de cães e gatos domésticos. **Cadernos de Saúde Coletiva**, v. 31, n. 2, 2023. DOI: 10.1590/1414-462x202331020063.

YIN, R. K. **Estudo de caso:** planejamento e métodos. 5. ed. Porto Alegre: Bookman, 2015. *(Necessário apenas se a Metodologia discutir a natureza do cenário, o que se recomenda.)*

---

*Documento produzido na Etapa 2 do projeto. Deve ser anexado, junto ao documento de decisões, no início das conversas subsequentes.*
