---
title: "Imunia --- Documento de Decisões de Projeto"
subtitle: "Base de conhecimento consolidada para o Trabalho de Conclusão de Curso"
date: "Agosto de 2026 --- versão 1.1"
lang: pt-BR
---

# 1. Identificação do projeto

| Item | Definição |
|---|---|
| Nome do sistema | **Imunia** |
| Tipo | Aplicação web (SPA + API REST) |
| Domínio | Gestão de saúde de animais de companhia |
| Espécies atendidas | Exclusivamente cães e gatos |
| Natureza | Produto de software + monografia de conclusão de curso |

O nome **Imunia** deriva de *imunidade*. Foi verificada a inexistência de colisão aparente com produtos do setor *pet tech* brasileiro, ao contrário dos candidatos descartados (VacinaPet, ImunoPet, PetVita e ZeloPet, todos já em uso). Recomenda-se confirmar a disponibilidade do domínio `.com.br` no Registro.br e realizar consulta ao INPI antes da entrega final.

# 2. Problema e proposta

## 2.1 Problema

O calendário vacinal de cães e gatos é operacionalmente complexo: envolve doses múltiplas em intervalos distintos, reforços variáveis conforme espécie e vacina, influência dos anticorpos de origem materna e indicações individualizadas segundo raça, idade, localidade e estilo de vida. Essa complexidade torna o controle manual pouco confiável.

Somam-se dois agravantes: a fragmentação do histórico clínico entre diferentes estabelecimentos e a baixa adesão vacinal — estima-se que menos de 50% dos animais de estimação recebam vacinação adequada, mesmo em países desenvolvidos (VASCONCELOS, 2011). O impacto extrapola o bem-estar animal e alcança a saúde pública, por meio das zoonoses.

## 2.2 Proposta

Plataforma web que centraliza, em ambiente único, o gerenciamento de tutores, animais, vacinações e prontuários médicos, oferecendo carteira de vacinação digital, lembretes automáticos e continuidade do histórico clínico entre estabelecimentos, mediante autorização do tutor.

## 2.3 Diferencial

A inversão da responsabilidade sobre o dado clínico. O médico-veterinário é o único autorizado a registrar informação clínica, o que reduz erros de cadastro e interpretação comuns quando o próprio tutor realiza os registros. O tutor possui acesso em modo leitura, além do controle sobre quem pode acessar o histórico do seu animal.

## 2.4 Escopo excluído

Funcionalidades deliberadamente fora do escopo, por não integrarem o núcleo do problema tratado:

- Gestão financeira
- Agendamento de banho e tosa
- Emissão de receitas e documentos fiscais
- Controle de estoque e internação

Trata-se de delimitação de escopo, não de deficiência. O texto do TCC deve assumi-la explicitamente.

# 3. Decisões arquiteturais

## 3.1 Arquitetura multi-tenant com base de dados compartilhada

**Decisão.** O Imunia é um SaaS multi-inquilino com banco de dados único e discriminador de inquilino (`prestador_id`), aplicado por *Global Scope* no Eloquent.

O inquilino é o **prestador**, entidade única que abrange clínicas, hospitais veterinários e profissionais autônomos, distinguidos por um campo `tipo`. Não existe entidade `clinica` separada: a generalização evita que o veterinário autônomo fique sem lugar no modelo e garante que todo registro clínico tenha um responsável identificável. Na interface, o rótulo apresentado ao usuário permanece "clínica ou profissional".

**Justificativa.** Entre as três abordagens clássicas — banco por inquilino, *schema* por inquilino e base compartilhada com discriminador — a última é a única que viabiliza o compartilhamento de histórico entre estabelecimentos sem federação de dados. Acrescenta-se a manutenção de uma única sequência de *migrations* e o custo de infraestrutura constante em relação ao número de clínicas.

**Modelo de posse dos dados.**

| Entidade | Escopo | Observação |
|---|---|---|
| `prestadores` | Inquilino | Clínica, hospital ou autônomo, conforme `tipo` |
| `tutores` | Global | Sem `prestador_id`; identificado por CPF |
| `animais` | Global | Sem `prestador_id`; código único, apto a virar QR Code |
| `atendimentos` | Por prestador | Possui `prestador_id`; autoria preservada |
| `vacinacoes` | Por prestador | Possui `prestador_id` |
| `prontuarios` | Por prestador | Possui `prestador_id` |
| `autorizacoes_acesso` | Vínculo | Livro de consentimento (ver seção 4) |

**Consequência.** O dado nunca pertence a uma cidade ou a uma instância isolada: ele reside na plataforma. A mudança de cidade do tutor não exige importação, migração ou integração — exige apenas autorização.

## 3.2 Compartilhamento de histórico controlado pelo tutor

**Decisão.** O tutor detém controle total sobre quem acessa o histórico do seu animal. Pelo sistema, ele consulta quais prestadores utilizam a plataforma e concede autorização nominal ao escolhido.

**Justificativa.** O modelo coloca o titular dos dados como origem do consentimento, atendendo à Lei Geral de Proteção de Dados. Distingue-se favoravelmente do compartilhamento automático adotado por concorrentes, no qual o histórico circula entre profissionais sem ato de vontade do tutor.

As regras detalhadas dessa funcionalidade estão na seção 4.

## 3.3 Autenticação por login e senha

**Decisão.** Autenticação por credenciais, implementada com Laravel Sanctum em modo SPA — sessão em *cookie* `httpOnly`, sem token em `localStorage` — e senhas protegidas por Bcrypt ou Argon2. Três papéis: `admin_prestador`, `veterinario` e `tutor`, com autorização por *Policies* e *Gates*.

**Justificativa.** Substitui o desenho anterior, no qual o tutor acessaria apenas informando o e-mail. Tal desenho não autentica ninguém: inexiste segredo compartilhado, de modo que qualquer pessoa que conheça o endereço eletrônico acessaria dados de saúde e dados pessoais de terceiros.

A justificativa a ser redigida no TCC deve evitar a formulação "senha por exigência da LGPD". A LGPD não determina o uso de senha; seu artigo 46 exige medidas técnicas de segurança adequadas ao risco. O argumento correto reúne dois elementos:

1. Dados de saúde e dados pessoais demandam autenticação e controle de acesso baseado em papéis;
2. O ato clínico exige **não repúdio** — o sistema precisa comprovar quem registrou cada informação.

**Topologia de desenvolvimento (sessão F1).** O modo SPA do Sanctum exige que o navegador veja frontend e backend como a mesma origem — decisão já fixada acima, não repetida aqui. Em desenvolvimento, isso é obtido pelo proxy nativo do Vite (`server.proxy`, `imunia-frontend/vite.config.js`): o navegador fala só com `localhost:5173`, e o Vite repassa `/api/*` e `/sanctum/*` para o Laravel/Sail em `localhost:80`. Alternativa considerada e descartada: um proxy reverso externo (Nginx/Caddy) replicando produção com mais fidelidade, ao custo de uma peça de infraestrutura nova que o Vite já supre nativamente. Decisão registrada aqui porque, ao contrário da escolha de Sanctum SPA em si, não estava documentada em lugar algum antes desta sessão.

## 3.4 Imutabilidade do registro clínico

**Decisão.** Registros clínicos são imutáveis. Correções não sobrescrevem o original: entram como retificação vinculada ao registro corrigido, preservando ambos.

**Justificativa.** A prática replica o padrão consolidado de prontuário eletrônico na medicina humana e preserva autoria e responsabilidade técnica do profissional. É uma das decisões com melhor sustentação acadêmica do projeto.

**Consequência sobre a autorização.** A permissão concedida pelo tutor desmembra-se em três níveis distintos, que devem constar do texto de forma explícita:

| Nível | Alcance |
|---|---|
| Leitura | O prestador autorizado visualiza todo o histórico anterior, de qualquer origem |
| Escrita | Cria registros novos, atribuídos a ele e ao seu prestador |
| Edição | Inexistente sobre registro alheio; correções apenas por retificação |

## 3.5 Lembretes por correio eletrônico

**Decisão.** Canal único de notificação: e-mail. A implementação usa Laravel Scheduler (comando diário), Queues e Notifications, com o canal abstraído pela camada de *Notification*.

**Justificativa.** Reduz o escopo de forma significativa, dispensando verificação de negócio junto à Meta, aprovação de *templates* e custo por conversa. A abstração pelo canal de notificação permite acrescentar WhatsApp posteriormente sem alterar a regra de negócio.

**Requisitos associados.**

- O e-mail do tutor deve ser verificado no cadastro, sob pena de os lembretes se perderem silenciosamente;
- Cada tipo de notificação precisa de descadastro individual, que não pode desativar comunicações transacionais;
- Idempotência: tabela de notificações enviadas, impedindo duplicidade de lembrete para o mesmo reforço;
- Política de nova tentativa definida na fila.

Os três concorrentes analisados enviam lembretes por WhatsApp. A ausência desse canal deve ser assumida no texto como delimitação de escopo, com encaminhamento para Trabalhos Futuros.

## 3.6 Cálculo do calendário vacinal

**Decisão.** A lógica de intervalos, doses e reforços deriva das diretrizes da WSAVA e das recomendações de fabricantes.

**Justificativa.** A fonte cumpre função dupla: referência técnica para a implementação e fonte citável na fundamentação teórica.

# 4. Regras da funcionalidade de compartilhamento

## 4.1 Fluxo principal

1. O tutor consulta, pelo sistema, quais prestadores utilizam a plataforma.
2. Escolhe um prestador e concede autorização nominal.
3. O sistema exige confirmação do tutor por código enviado ao e-mail cadastrado.
4. Confirmada, cria-se o vínculo em `autorizacoes_acesso`.
5. O prestador autorizado passa a visualizar consultas, exames, diagnósticos e tratamentos registrados por outros profissionais, e pode acrescentar registros referentes ao atendimento atual.

## 4.2 Entidade de destino da autorização

**Regra.** A autorização aponta sempre para um **prestador** — a mesma entidade que serve de inquilino na arquitetura descrita na seção 3.1. O veterinário autônomo é modelado como prestador de um único integrante. Veterinários vinculados a um prestador herdam o acesso enquanto durar o vínculo empregatício.

**Motivo.** Um mesmo veterinário pode atuar em diversos estabelecimentos. Se a autorização fosse concedida à pessoa física, não haveria como determinar qual `prestador_id` carimbaria o atendimento registrado.

## 4.3 Revogação

**Regra.** A revogação encerra o acesso do prestador ao histórico produzido por terceiros. Não alcança os registros produzidos pelo próprio prestador, que permanecem sob sua guarda.

**Motivo.** A guarda do prontuário é obrigação legal do estabelecimento perante o Conselho Federal de Medicina Veterinária, e não faculdade extinguível pelo tutor. Deve-se consultar a resolução vigente do CFMV sobre guarda de prontuário para citação precisa.

**Prazo.** As autorizações possuem validade determinada — sugere-se noventa dias, renováveis — evitando o acúmulo silencioso de acessos vitalícios.

## 4.4 Registro de acesso

**Regra.** Toda visualização de prontuário originado de outro prestador é registrada em log: quem acessou, o que acessou e quando.

**Motivo.** Constitui a evidência de conformidade prevista na LGPD e permite ao tutor auditar quem consultou os dados do seu animal. É também um dos artefatos mais demonstráveis na apresentação à banca.

## 4.5 Exportação verificável em PDF

**Regra.** Quando não houver prestador cadastrado na localidade, o tutor exporta o histórico completo em PDF e o compartilha diretamente com o profissional que realizará o atendimento.

Cada exportação gera identificador próprio e *hash* do conteúdo, impressos como QR Code que aponta para rota pública de verificação (`/verificar/{codigo}`), a qual informa se o documento confere e quando foi emitido. Sem esse mecanismo, o arquivo é apenas um documento editável por qualquer pessoa.

O rodapé deve advertir que, ao compartilhar o arquivo, o tutor assume a responsabilidade pela difusão daqueles dados, os quais saem do domínio de controle da plataforma.

# 5. Tecnologias e convenções

## 5.1 Pilha tecnológica

| Camada | Tecnologia |
|---|---|
| Backend | Laravel 13 (API REST) |
| Frontend | Vue 3 (SPA, Composition API) |
| Banco de dados | MySQL |
| Autenticação | Laravel Sanctum, modo SPA por *cookie* |
| Tarefas assíncronas | Laravel Scheduler, Queues e Notifications |

## 5.2 Convenções de código

- Inglês para código, tabelas e colunas; português para textos de interface;
- Vue 3 com Composition API e `<script setup>`, exclusivamente;
- Validações em *FormRequests*;
- Autorização em *Policies*;
- Regra de negócio em *Services*, nunca em controladores.

# 6. Limitações reconhecidas

Limitações a serem declaradas honestamente no texto, acompanhadas de suas mitigações:

| Limitação | Mitigação |
|---|---|
| Clínicas que não utilizam a plataforma | Exportação verificável em PDF; campo de histórico pregresso preenchido manualmente e marcado como não verificado |
| Ausência de padrão de interoperabilidade veterinária no Brasil | Declarada como limitação; adoção de padrão remetida a Trabalhos Futuros |
| Atendimento de urgência sem tutor disponível para autorizar | Declarada como limitação; mitigada pela exportação prévia em PDF |
| Ausência do canal WhatsApp | Delimitação de escopo assumida; remetida a Trabalhos Futuros |

# 7. Pendências de correção no documento do TCC

## 7.1 Consistência com as decisões tomadas

1. Substituir todas as ocorrências de "nomedosistema" por **Imunia**, no texto corrido e na tabela comparativa.
2. Reescrever o item 4 da seção 2.3.1, removendo a passagem "sem necessidade de cadastro com senha, utilizando apenas o e-mail"; incorporar o modelo de autorização pelo tutor e a exportação em PDF.
3. Na tabela comparativa da seção 2.3, alterar a linha "Histórico compartilhado entre clínicas" para **Sim (mediante autorização do tutor)**.
4. Acrescentar à mesma tabela duas linhas que expressam o diferencial real do sistema: "Registro clínico exclusivo do veterinário" e "Acesso do tutor somente leitura". Em ambas, os concorrentes marcam Não e o Imunia marca Sim.

## 7.2 Normalização e referências

5. Corrigir a referência cruzada "A Tabela 1 apresenta o posicionamento estratégico", que deve apontar para a Tabela 3.
6. Converter as Tabelas 1, 2 e 3 em **Quadros**, por conterem dados qualitativos, conforme norma ABNT/IBGE.
7. Inserir as referências ausentes, hoje citadas apenas nas fontes das tabelas: WSAVA (2015; 2024), World Veterinária (2025) e Souza et al. (2023). Acrescentar também referência para NuvemVet e LoopVet, descritos na seção 2.3.1 sem qualquer fonte.
8. Citar as fontes da seção 2.2.2, atualmente sem nenhuma citação, embora o conteúdo derive claramente das diretrizes da WSAVA.
9. Explicitar o critério de seleção dos concorrentes analisados e a data em que a análise foi realizada.
10. Uniformizar as datas de acesso nas referências, que hoje alternam entre 2025 e 2026.
11. Corrigir a grafia de citações no corpo do texto: pela NBR 10520, autor citado fora dos parênteses vai em caixa baixa — "Harari (2015)", não "HARARI (2015)". Provável uso de `\cite` onde caberia `\citeonline`.
12. Corrigir erros de digitação: "estrátegico", "similares á proposta", "sera apresentada".

## 7.3 Lacuna de conteúdo

13. A fundamentação teórica é integralmente dedicada à saúde animal e não contém conteúdo de Computação. Para um trabalho de Ciência da Computação, faltam as bases que sustentam tecnicamente as decisões: arquitetura cliente-servidor, API REST, *Single Page Application*, padrão MVC, modelo relacional, arquitetura multi-inquilino e LGPD. É a lacuna mais provável de ser questionada pela banca.

# 8. Etapas seguintes

O trabalho está organizado em conversas separadas, uma por fase, para preservar contexto e profundidade:

1. Análise do TCC — **concluída**, resultado neste documento
2. Metodologia
3. Estudo de caso
4. Requisitos
5. Modelagem
6. Design de interface
7. Implementação

Capítulos ainda por escrever: Introdução, Metodologia, Resultados e Conclusão.

O desenvolvimento seguirá roteiro de fatias verticais, uma funcionalidade completa por sessão, no ciclo explorar, planejar, implementar e consolidar em *commit*.

# 9. Divergências entre documento-fonte e verificação técnica

## 9.1 Paleta de `docs/design/tokens.md` §4.1 vs. o critério de contraste que o mesmo documento declara

Sessão de fundação F3 (tokens de design e *shells* de layout). `tokens.md` §4.1 declara o critério de aceitação — "contraste mínimo de 4,5:1 para texto e 3:1 para elementos de interface (WCAG 2.1 AA)" — na mesma seção em que declara a paleta de cores. Ao calcular a razão de contraste por luminância relativa (WCAG 2.1) de cada token contra as superfícies em que é usado, seis tokens reprovam o próprio critério que o documento declara, em pelo menos uma superfície do sistema:

| Token | Pior par testado | Razão | Critério |
|---|---|---|---|
| `--ink-faint` | sobre `--consent-wash` | 3,05:1 | 4,5:1 — reprova em **toda** superfície testada |
| `--border-hairline` | sobre `--surface-sunken` | 1,10:1 | 3,0:1 — reprova em **toda** superfície testada |
| `--border-strong` | sobre `--surface-sunken` | 1,43:1 | 3,0:1 — reprova em **toda** superfície testada |
| `--status-due` | sobre `--surface-sunken` | 2,58:1 | 3,0:1 — reprova em **toda** superfície testada |
| `--unverified` (como texto) | sobre `--consent-wash` | 3,85:1 | 4,5:1 — passa só sobre `--surface-card` (4,51:1) |
| `--brand-bright` (como ligação) | sobre `--consent-wash` | 3,86:1 | 4,5:1 — passa só sobre `--surface-card` (4,51:1) |

Nenhum valor hexadecimal foi alterado para resolver o achado — os seis são transcrição fiel de `tokens.md` §4.1, conferida caractere a caractere. A resolução aplicada foi restrição do **papel de uso**, não do valor:

- `--ink-faint` perde os usos "texto de apoio" e "contador" da descrição original (linha 22, que lista os três juntos: "texto de apoio, contadores, marca d'água"); mantém só "marca d'água" (decoração, isenta de exigência de contraste). "Texto de apoio" e "contador" migram para `--ink-muted`, que passa com folga em toda superfície testada.
- `--unverified` e `--brand-bright`, como cor de texto, ficam restritos a uso sobre `--surface-card` — a superfície mais clara do sistema, onde ambos passam.
- `--border-hairline`, `--border-strong` e `--status-due` **não têm papel mais restrito disponível para recuar** — já eram, respectivamente, borda decorativa, contorno de componente interativo e preenchimento de estado (nunca texto), e mesmo assim reprovam em toda superfície testada. Ficam registrados como pendência de hexadecimal, fora do escopo da sessão que os encontrou (que não altera valor transcrito do documento-fonte sem decisão explícita). Mitigação parcial existente: os estados que mais importam para `--border-strong` (foco, erro) já usam tokens conformes (`--brand-bright` e `--status-late`, ambos acima de 4,5:1 sobre `--surface-card`); `--status-due` nunca aparece como único portador de significado, por regra já obrigatória do próprio `tokens.md` (linha 52: ícone e rótulo textual sempre acompanham a situação vacinal).

Matriz completa (todo token de texto contra as três superfícies mais `--consent-wash`, `--status-late-wash`, `--brand-wash` e `--brand`; todo token de interface contra as três superfícies) documentada em `docs/planos/f3-tokens-e-shells.md` (Parte 3.3) e reproduzida na implementação (`imunia-frontend/src/styles/tokens.css`, comentários da tabela de cor).

## 9.2 Papel `admin_plataforma` — existe em `CLAUDE.md` §3, ausente de `docs/requisitos/00-visao-personas-atores.md`

Encontrado na mesma sessão F3. `CLAUDE.md` lista cinco papéis autenticáveis, incluindo `admin_plataforma` (catálogo de imunobiológicos e versões de protocolo). `docs/requisitos/00-visao-personas-atores.md` §3.1 lista só três: `tutor`, `veterinario`, `admin_prestador`. Por `CLAUDE.md` §11 (requisitos v1.2 > briefing de design > decisões), o documento de requisitos deveria prevalecer — mas ele simplesmente não menciona o papel, não o contradiz explicitamente, o que sugere desatualização em vez de decisão deliberada de excluí-lo.

Não resolvido nesta sessão. Efeito prático: a construção de `AdminShell` (layout de administração) ficou restrita a `admin_prestador` — nenhuma variação para `admin_plataforma`/telas X01–X02 até a divergência ser resolvida. Detalhe da pendência em `docs/requisitos/10-pendencias-monografia-referencias.md`.

**Resolução (fatia X01, 18/08/2026).** O papel existe agora como coluna booleana `admin_plataforma` em `users` (migration `2026_08_18_174743`), fora do `#[Fillable]` do model — nunca atribuível por formulário, só por seed ou por uma futura tela de gestão de administradores da plataforma. Entrou em `User::papeis()` e em `User::rotaInicial()` (`/plataforma/catalogo`), ao lado de `veterinario` e `admin_prestador`. `docs/requisitos/00-visao-personas-atores.md` (se existir na árvore atual do documento) continua sem o mencionar — a desatualização apontada acima permanece, mas deixou de bloquear a implementação: o papel tem lugar único e testado no código, e é essa a fonte de verdade a partir de agora. `PlataformaShell.vue` é o `AdminShell` desta variação, mínimo e específico do bloco X01/X02, e não uma generalização do shell de `admin_prestador`.

## 9.3 Coluna `fabricante` ausente de `imunobiologicos` — schema nasceu da fatia de vacinação, não de X01

Encontrado na fatia X01 (18/08/2026). A tabela `imunobiologicos` já existia antes desta fatia — migration, model, factory e `CatalogoImunobiologicosSeeder` foram escritos para dar suporte à carteira de vacinação (T05) e ao registro de histórico pregresso (RF29), fases que só liam o catálogo. Nenhuma delas precisava de fabricante, e a coluna não foi criada.

RF23 exige fabricante como campo do cadastro ("denominação comercial e técnica, **fabricante**, espécie de destino, agentes cobertos, classificação..."), e o mockup de X01 no Claude Design mostra a coluna na tabela e o campo no formulário. A divergência era do schema contra o requisito, não do requisito contra o desenho — os dois já concordavam.

**Resolução.** Migration `2026_08_18_174742` acrescenta `fabricante` (string, nullable no schema — a obrigatoriedade fica em `StoreImunobiologicoRequest`/`UpdateImunobiologicoRequest`, como o restante da validação do projeto). Sem `doctrine/dbal` instalado, não coube tornar a coluna `NOT NULL` por `->change()`; os cinco itens do seeder e a factory foram atualizados com fabricante real (Zoetis, MSD), e a obrigatoriedade de fato é garantida pela camada de validação, não pelo banco.

## 9.4 Versão de protocolo — cadeia de caracteres repetida por imunobiológico vs. entidade própria

Encontrado na fatia X02 (18/08/2026). A tabela `protocolos_vacinais`, criada na fase da carteira de vacinação, guardava a versão como coluna `versao` (string) repetida em cada linha de parâmetros, com um booleano `vigente` e um `publicado_em` por linha. Funcionava enquanto ninguém publicava nada: bastava ler "a linha vigente deste imunobiológico".

X02 não cabe nesse formato. O briefing §8.5 pede lista de versões com situação (rascunho, vigente, encerrada), data de publicação e **contagem de cálculos realizados sob cada uma**, com o editor de parâmetros por espécie e imunobiológico *dentro* da versão selecionada. Com a versão sendo texto repetido, "publicar a 2026.1" seria percorrer linhas soltas torcendo para que nenhuma ficasse para trás, e "quantos cálculos foram feitos sob a 2024.1" não teria a quem perguntar. Rascunho, então, não teria onde existir: o esquema antigo só sabia distinguir vigente de não vigente.

**Resolução.** Migration `2026_08_18_190000` cria `versoes_protocolo` (`rotulo`, `situacao`, `base`, `publicado_em`, `encerrado_em`) e liga `protocolos_vacinais` a ela por `versao_protocolo_id`, migrando os dados existentes — cada valor distinto de `versao` virou uma linha da tabela nova — e removendo `versao`, `vigente` e `publicado_em` da tabela de parâmetros. Nenhum registro de vacinação foi tocado: `vacinacoes.protocolo_vacinal_id` aponta para a linha de parâmetros, cujo id não mudou, que é exatamente o que RN32 promete. `ProtocoloVacinal::versao` continua existindo como *accessor* que lê o rótulo da versão — o dado mudou de lugar, não de nome, e `$vacinacao->protocoloVacinal->versao` segue respondendo em T06.

A mesma migration acrescenta três parâmetros que o cálculo precisava e não tinha: `limite_atraso_dias` e `conduta_apos_limite` (RF27, RN34 — o atraso acima do limite vira pergunta clínica, e a resposta é sugestão exibida ao profissional, nunca impedimento) e `doses_adulto_sem_historico` (o caso 3 de RNF01, a gata Nina do cenário).

Limitação conhecida do caminho de volta: o esquema antigo não sabia o que é rascunho, então descer e subir a migration devolve como encerrada a versão que estava em edição. É perda de rascunho, não de dado publicado.

## 9.5 Editor de X02 — unidades e parâmetros divergentes do mockup do Claude Design

Encontrado na mesma fatia. O mockup de X02 mostra seis campos numéricos e um seletor de conduta. Três divergências, resolvidas pela precedência de `CLAUDE.md` §11 (requisitos > briefing de design > decisões):

**Unidades do reforço.** O desenho exibe "Prazo do primeiro reforço · 365 · dias" e "Periodicidade da revacinação · 365 · dias". RN35 fala em meses e anos ("reforço aos seis ou doze meses"; "periodicidade não inferior a três anos"), e o esquema já guardava meses. Mantidos os meses, com o sufixo do campo trocado — a mudança visual é de uma palavra, e a alternativa exigiria converter três anos em 1.095 dias na tela que existe para tornar o cálculo conferível.

**Intervalo entre doses.** O desenho mostra um número único (21 dias). RN34 define uma janela ("intervalo parametrizado de duas a quatro semanas"), e o cálculo usa o ponto médio dela. O campo exibe os dois extremos na mesma moldura ("14 a 28 dias") e anuncia o previsto na dica abaixo — a grade de duas colunas do desenho permanece.

**Dois campos a mais.** `Doses da série primária` e `Doses no adulto sem histórico` não aparecem no mockup, mas governam o cálculo. Deixá-los fora tornaria a série do filhote e a do adulto sem histórico valores codificados, contra RF24a ("a alteração de diretriz é absorvida por configuração, sem alteração de código") — e o caso 3 do simulador, declarado em RNF01, não teria de onde tirar o número de doses do adulto.

**O que veio do desenho sem alteração:** parâmetros incoerentes não bloqueiam a gravação, só a publicação (o rascunho continua salvo e editável, e o botão "Publicar" fica indisponível enquanto os valores se contradisserem); a paleta própria do simulador, que separa à distância de um olhar o cálculo hipotético do parâmetro gravado; e o `ConfirmDialog` de publicação com as duas listas de RN32.

**Simulador.** As datas vêm de `CalendarioVacinalService::preverDoseSeguinte()`, o mesmo método que a carteira do tutor e a rechamada do veterinário usam — método extraído nesta fatia justamente para isso. RNF01 exige que o cálculo seja reprodutível, e uma tela que existe para conferi-lo não pode calcular por conta própria: conferiria a si mesma. O botão "Comparar com a vigente" exibe o quadro da outra versão inteiro, e não anotações linha a linha, porque versões com parâmetros diferentes produzem quantidades diferentes de passos — uma acusa atraso onde a outra o absorve no limite — e emparelhar linha com linha alinharia a data de um passo à explicação de outro.

## 9.6 Telas de exceção — o que E01–E03 exigiam e os documentos não fixavam

Encontrado na fatia E01–E03 (24/08/2026). O briefing especifica as três telas em quinze linhas (§8.1) e o mockup de `Admin Config Screens.dc.html` as desenha, mas quatro perguntas de implementação ficaram sem resposta em qualquer documento. Todas foram decididas nesta fatia:

**De onde vem o identificador de ocorrência de E03.** O briefing manda exibi-lo "discretamente, em monoespaçada, para suporte", e adverte que "não é código de erro técnico". Nada diz quem o gera. Decidido: é o identificador da **requisição**, não da falha — gerado na entrada da API por `IdentificarOcorrencia` (dois blocos de quatro caracteres, o mesmo alfabeto sem ambíguos de `CodigoDoAnimal`, porque é ditado ao telefone) e injetado em `Log::withContext`, de modo que toda linha escrita durante aquela requisição o carregue. Gerá-lo só no momento da exceção daria ao usuário um número presente numa única linha do registro, e o suporte não teria como reconstruir o que veio antes. A resposta 500 é montada em `bootstrap/app.php` pelo `respond`, e não por `render`: a decisão é tomada sobre o **status já preparado** pelo framework, de modo que tudo o que o Laravel converte em 403, 404, 422 ou 429 conserva a mensagem própria do seu ponto do sistema, e só a falha genuinamente inesperada vira E03. Fora de produção o detalhe técnico continua na resposta, sob a chave `depuracao` — a tela de exceção existe para o usuário, não para esconder do desenvolvedor o que quebrou.

**Falha do próprio SPA não tem ocorrência.** Quando o erro é de renderização ou de módulo que não carregou, não há requisição registrada a que se referir, e o bloco do rodapé simplesmente não aparece. Inventar um número no cliente mandaria a pessoa a uma conversa sem começo.

**Quando E01 aparece, já que a recusa é do servidor.** RNF09 exige que toda autorização seja verificada no servidor, e ela continua sendo. O que faltava decidir era o que a interface faz **antes** disso. Decidido: cada rota autenticada declara sua área (`meta.area`), e a guarda encaminha a E01 quem não tem o papel que a abre, em vez de deixar a tela montar-se para pedir dados que o servidor vai recusar e terminar num aviso de erro genérico. A tabela de áreas vive em `imunia-frontend/src/lib/areas.js` e reproduz a precedência de `User::rotaInicial()`.

**Que moldura desenhar.** O briefing pede "centrado na área de conteúdo, preservando a navegação do papel", sem dizer como resolver os casos cruzados. Decidido em `RoleShell`: a moldura é a da área do endereço quando o usuário tem o papel que a abre — é o que faz E02 dentro de `/clinica` conservar a barra lateral clínica —, e a do papel de quem chegou em qualquer outro caso, inclusive em E01, cuja moldura é a de quem foi recusado, nunca a da área recusada. Sem sessão, a moldura é a das telas públicas.

**Uma frase do desenho não serve a todo mundo.** O mockup de E01 traz "Esta área pertence a outro perfil da clínica. Se você precisa dela para trabalhar, fale com quem administra a conta." — texto escrito para quem trabalha num prestador. Ao tutor que esbarra numa área clínica esse conselho seria pior que silêncio: mandaria pedir a um estranho um acesso que o sistema nunca lhe daria, e sugeriria que o dado clínico é administrado por quem cuida do animal, quando RN08 e o desenho inteiro dizem o contrário. A frase foi partida em duas: o que a área é, sempre; o caminho pela administração, apenas para `veterinario` e `admin_prestador`.

**O que não mudou, e por quê.** A segunda variante de E01 prevista no briefing — "quando a causa for ausência de autorização do tutor, o texto muda para o padrão de P2" — já estava implementada em V03 e V06, e continua lá. Ausência de autorização é estado de tela, não erro (P2): mandar o veterinário a uma parede quando ele podia estar solicitando acesso trocaria um caminho por um beco. E o estado de erro de carregamento de cada tela (§6.1, item 3) permanece dentro da tela que falhou, com a moldura e os blocos já carregados de pé — E03 é para quando não há tela.

## 9.7 V07a e V08a — a etapa que o briefing supunha e não descrevia

Encontrado na fatia V07a–V08a (24/08/2026). O briefing manda o botão "Registrar" do cabeçalho abrir "V07 ou V08" (§8.3, V01), mas aquelas duas telas têm o código do animal no endereço: `/clinica/animais/:codigo/vacinar`. Falta, portanto, uma etapa que o texto pressupõe sem descrever — e que o mockup de `Clinical Entry Screens.dc.html` desenha sob o rótulo V07a · V08a. As decisões desta fatia:

**A etapa é tela, e não campo dentro do formulário.** Um seletor de animal dentro de V07 deixaria o profissional redigir o registro inteiro para descobrir, na confirmação, que não pode gravá-lo naquele animal — o pior momento possível para descobrir isso, num sistema em que o registro é imutável (RN26) e em que a autorização é a regra que não admite erro (RN48). A escolha vem antes de qualquer campo de prontuário.

**A ação viaja no caminho, e não na consulta.** `/clinica/registrar/:acao`, com `vacinacao` e `atendimento` como únicos valores aceitos — endereço fora dessa lista cai em E02. Foi preferido a `?registrar=` sobre V03 porque a ação decide a pergunta da tela e a rota de destino: é matéria do endereço, não filtro dele. As duas ações são uma tela só, porque o âmbito, o atalho e a recusa por falta de autorização não mudam entre vacinar e atender.

**Rota própria no servidor, busca compartilhada.** `GET /api/clinica/registrar` responde com o que `BuscaClinicaService` já responde a V03 — inclusive a existência fora do âmbito (RN12) e o registro de acesso que a precede (RF18b) — acrescido do atalho dos últimos atendidos. A busca **não** foi reescrita: uma segunda consulta com regra própria seria uma segunda chance de errar a regra de RN48. O que V03 não tem, e não deveria ter, é o atalho: lá o campo vazio é o estado em que nada foi consultado, e uma lista de animais ali diria ao profissional coisas que ele não perguntou.

**O atalho é do prestador ativo e das autorizações vigentes, nas duas pontas.** Entram os animais atendidos pelo prestador nos últimos trinta dias — o mesmo intervalo padrão de V01, para que "recentemente" signifique o mesmo nas duas telas do mesmo profissional —, e sai quem já não tem autorização vigente. O registro do atendimento continua sendo do prestador (RN27); o que caiu foi o acesso, e sem ele não há novo registro a começar. Vacinação de origem `pregresso` não põe ninguém no atalho: é memória do tutor (RF29), não passagem pelo balcão.

**A etiqueta nomeia a vacina pendente.** O painel (V01) e a rechamada (V02) dizem "atrasada"; aqui a linha diz "antirrábica há 42 dias". A tela é o momento em que se decide o que aplicar, e a pergunta que ela responde não é "há pendência?" e sim "o que este animal está devendo?". Sem pendência, vale a situação da carteira; sem vacinação alguma, "sem dados" — RF50 manda dizer que o sistema ainda não sabe, nunca "em dia" por omissão.

**Resultado único é escolha feita.** Em vez de uma lista de um item, a tela apresenta o animal com o prazo do acesso — "autorizado até 12/11/2026" — e o botão que abre o registro. É o caminho da busca por código ditado pelo tutor, e é por causa dele que o cartão de V03 passou a trazer `autorizado_ate`: informação do prestador sobre a própria autorização, nada do tutor e nada de terceiro.

**Dois defeitos de V03 corrigidos de passagem**, ambos encontrados na verificação em navegador desta fatia e presentes na tela anterior pelo mesmo motivo: a espécie do cartão de consentimento saía crua (`cao`, e não `cão`), e os dois botões dos estados vazios ficavam encostados um no outro porque o compilador do Vue condensa o espaço entre duas tags irmãs. O segundo foi corrigido no `EmptyState`, que passou a espaçar as ações do próprio bloco, para que nenhuma tela precise se lembrar disso.

## 9.8 V07 — a primeira escrita de registro clínico profissional

Encontrado na fatia V07 (24/08/2026). Até aqui, o único caminho que criava `vacinacoes` era o lançamento pregresso do tutor (T09), que não é ato clínico (RN25) e deixa nulo quase tudo. O registro profissional de RF25 obrigou a decidir sete coisas que nenhum documento fixava.

**A prévia passa pelo mesmo código que a carteira.** O painel de cálculo ao vivo não recalcula por conta própria: `RegistroDeVacinacaoService::prever()` monta a coleção hipotética — as aplicações que existem mais a que está sendo redigida — e a entrega a `CalendarioVacinalService::montarCarteiraCom()`, método público que já existia por causa de V02. A data que o profissional vê antes de confirmar e a que o tutor vê depois são a mesma porque saem da mesma linha, e não porque um teste as comparou. É o argumento de §9.5 aplicado de novo: uma tela que existe para mostrar a consequência do cálculo não pode ter cálculo próprio, ou mostraria a consequência de si mesma. O teste que sustenta essa arquitetura compara, caractere por caractere, a `prevista_para` da prévia com a que a carteira devolve depois de gravado o registro.

**Abrir a tela e recalcular são rotas diferentes.** `GET /vacinar` grava a linha de `RegistroDeAcesso` quando o animal tem registro de outro prestador (RN49) — o alerta de atraso conta ao profissional quando venceu a dose anterior, e essa data pode ser alheia; `GET /vacinar/previa` não escreve nada. Fundidas, uma vacinação de noventa segundos deixaria de dez a vinte linhas no livro que o tutor lê em T14, e RF53 o promete legível. Um teste chama a prévia dez vezes e exige zero linhas.

**Cinco colunas novas em `vacinacoes`,** e a mais importante não estava prevista. `aplicador_user_id` (FK): `atendimentos` guarda `profissional_user_id` desde que existe, mas `vacinacoes` só tinha o nome em texto — que colide entre homônimos e muda quando a pessoa corrige o próprio cadastro. Sem essa coluna, RN27 ("a retificação é privativa do autor") seria inexequível para vacinação em V09. `ordem_dose_sugerida`: RF27b exige a divergência auditável, e a ordem calculada **não** é reconstruível depois — ela deriva da contagem de doses do grupo, e um pregresso lançado pelo tutor no mês seguinte muda essa contagem retroativamente. Mais `justificativa_conduta` (RF27b), `observacao` (briefing) e `sitio_anatomico`.

**`sitio_anatomico`, e não `local_aplicacao`.** A coluna que já existia significa o *lugar* onde a aplicação pregressa ocorreu — "campanha pública", "clínica no bairro antigo". O campo "Local anatômico" do mockup é sítio anatômico. Escrever "escápula direita" naquela coluna poria dois significados numa coluna só, para sempre, e o T06 do tutor a exibe sob o rótulo "Onde foi aplicada".

**A validade é mês e ano, gravada no último dia do mês.** O rótulo do frasco traz "04/2027" e é assim que o profissional confere; pedir um dia obrigaria a inventá-lo. Grava-se 30/04/2027 porque a convenção do setor é que o lote vale até o fim daquele mês — gravar o dia 01 obrigaria todo comparador a lembrar de `endOfMonth()`, regra escondida em código e esquecida em um dos lugares. Não serve `App\Support\DataAproximada`, que resolve problema parecido para o pregresso: lá a regra escolhe deliberadamente o *primeiro* dia, e aceita o ano sozinho. Regra própria em `App\Rules\ValidadeMesAno`.

**Dois defeitos corrigidos de passagem, ambos anteriores à fatia.** O primeiro: a comparação de RF25c era de data pura contra data-e-hora, de modo que a vacina aplicada às 14h do seu último dia de validade sairia marcada como aplicada com validade expirada — marca permanente e visível ao tutor na carteira. A comparação passou a ser dia contra dia, como `calcularSituacao()` já fazia pelo mesmo motivo. O segundo: `VaccineDetailView` (T06) exibia a validade por `emNumeros()`, mostrando ao tutor "30/04/2027" — um dia que ninguém digitou, apresentado como leitura do frasco; passou a `m/Y`, como `BatchSeal` e `HistoricoConsolidadoService` já faziam.

**O prestador ativo se perdia entre V06 e V07.** Os seis links de ação de `FichaAnimalView` apontavam para `/vacinar` sem query, e nenhuma view do ambiente clínico lia `route.query.prestador` — o prestador ativo vivia só em estado de componente. Para um veterinário com dois vínculos, V07 recairia no primeiro e carimbaria `prestador_id` **errado** num registro que RN26 torna imutável e para o qual V09 não existe: erro sem conserto. O contexto passou a viajar em `?prestador=` em toda ligação que sai de V06 para tela de escrita, V06 passou a lê-lo na carga, e o `ConfirmDialog` de V07 **nomeia o prestador e o CRMV** — é a última tela antes do irreversível, e é ali que um contexto errado ainda pode ser visto.

**A ordem da dose declarada passou a governar o cálculo.** `rotularDoses()` e `calcularProximaDose()` liam a posição na coleção e ignoravam a coluna `ordem_dose`, que existia sem ser consultada. Com o campo editável que o briefing pede, isso produziria três números para a mesma dose: o veterinário registraria "1ª dose" ao reiniciar a série, o banco guardaria 1, e a carteira do tutor continuaria dizendo "5ª dose" e prevendo reforço anual — desmentindo, na tela do tutor, a conduta que o próprio sistema aceitou registrar (RF27c, RN36). As duas leituras passaram a preferir a ordem declarada, com a contagem do grupo como resposta para quem não declara nenhuma: todo pregresso (RN25) e todo registro anterior a esta fatia. A mudança é neutra na suíte inteira — 452 testes — porque em nenhum fixture existente a ordem declarada difere da posição.

**Três recortes de escopo, declarados.** O alerta de reação adversa anterior (RF30b) que o mockup desenha **não** foi implementado: RF30 está remetido a Trabalhos Futuros e não há tabela de reações a consultar, e vale aqui a razão já registrada em `FichaClinicaService` para V06 — um alerta clínico que o sistema não pode sustentar é dano, não lacuna. A linha "Lembrete ao tutor · 22/07/2027" do painel perdeu a data: o motor de notificação é fatia própria e nenhum documento fixa a antecedência do lembrete, de modo que a tela promete o lembrete sem inventar o prazo. E a **dispensa** da dose adicional de RN33 não é oferecida — a dose adicional é derivada em tempo de leitura, não gravada, e uma justificativa em texto não impediria a carteira de continuar prevendo-a nem os lembretes de continuarem cobrando-a; o painel informa a regra e o profissional decide a conduta, como em qualquer outro alerta.

**Uma frase do mockup não corresponde ao esquema.** O desenho diz "o limite parametrizado para a antirrábica **nesta clínica** é de 30 dias", mas `limite_atraso_dias` é parâmetro de `protocolos_vacinais`, que X02 mantém para a plataforma inteira, não por prestador. O texto da tela diz "o limite parametrizado para a antirrábica é de 30 dias". Parametrização por prestador seria outra decisão, e não é a que o schema tomou.

## 9.9 V08 — o prontuário, o anexo que ainda não é registro e o peso que é medição

Encontrado na fatia V08 (24/08/2026). O esquema de `atendimentos` e `anexos_atendimento` existia desde T08, que só lê. Escrevê-lo obrigou a decidir oito coisas.

**O anexo sobe antes da confirmação, e é rascunho até ela.** `POST /clinica/animais/{codigo}/atender/anexos` recebe **um** arquivo por pedido e o guarda em disco privado, na pasta do próprio profissional, sem linha em `anexos_atendimento`; a confirmação move o arquivo e cria o registro. É o que dá as três coisas que o mockup desenha e que um envio junto com o formulário não daria: progresso por arquivo, recusa imediata por formato ou tamanho (RN28) e a promessa — verdadeira — de que o anexo já enviado não precisa subir de novo quando a gravação falha. E é o que impede o inverso: anexo existindo sem o registro clínico a que RN28 manda que ele se vincule. A posse do rascunho está no caminho (`rascunhos/anexos/{user_id}/{uuid}.{ext}`), não numa comparação a esquecer; a extensão vem do formato **conferido**, não do nome do arquivo, de modo que um "laudo.pdf.exe" não escolhe como é guardado; e o rascunho vence em 24 horas, apagado na abertura seguinte da tela pelo mesmo profissional — dado clínico esquecido fora de todo controle de autorização é pior do que anexo perdido.

**O título não é campo do formulário.** A coluna `titulo` é NOT NULL desde T08 e o mockup não desenha campo algum para ela — pedi-lo seria um sétimo campo numa tela que já é seis de texto longo. Deriva-se da primeira oração do motivo da consulta, sem a pontuação final, limitada a 80 caracteres: "Prurido intenso e alopecia focal em região dorsal, com duas semanas de evolução." Nunca do diagnóstico, como a própria migration já advertia — o diagnóstico é muitas vezes a pendência ("Aguardando resultado do raspado"), que é o estado do atendimento e não o nome dele.

**O peso entra em `atendimentos`, e não em `animais`.** É a primeira vez que peso existe no esquema, e entra como medição datada, na forma que a observação de modelagem de RF19 recomenda: a série é o que tem valor clínico, e um campo sobrescrevível no cadastro destruiria a medição anterior a cada consulta — escrita sobre registro clínico, que RN26 não admite. Não foi criada tabela própria porque a medição já tem data e autoria: as do atendimento em que foi aferida. Uma tabela de pesos com `atendimento_id` repetiria essas duas colunas para guardar um número, e abriria a porta para peso sem atendimento, medição sem quem a tomou.

**Abrir a tela grava a linha do livro de acessos.** Como em V06 e V07, e pela mesma razão: V08 conta ao profissional quanto o animal pesava na consulta anterior e que há retorno em aberto, e esses dois fatos podem ter sido escritos por outra clínica (RF52b, RN49). A gravação precede a montagem da resposta.

**O retorno é data e finalidade, ou nenhum dos dois.** `required_with` nos dois sentidos: data sem finalidade é compromisso sem o que cumprir, e finalidade sem data não alimenta o lembrete de RF43. A tela também **anuncia o retorno que este registro encerra** (RN29), com a mesma conta que `FichaClinicaService` já fazia — retorno cuja data foi ultrapassada por atendimento posterior está cumprido, sem que ninguém o marque —, e o `ConfirmDialog` diz isso antes da confirmação: é consequência que acontece fora da tela, no painel de pendências e no lembrete ao tutor.

**O sucesso vai para a aba Histórico de V06, e não para T08.** O briefing manda abrir "T08 do atendimento criado", mas T08 é a tela do **tutor**: a rota que a serve exige tutor autenticado e responderia 403 a quem acabou de escrever o prontuário. A leitura equivalente no ambiente clínico é a aba Histórico da ficha, que passou a receber `?aba=historico&novo=<id>` com o mesmo destaque de dois segundos que V07 já usava na Carteira — a tela de detalhe clínico do atendimento é fatia de V09.

**Reenvio em dez minutos devolve o registro que já existe,** pela regra e pelo número de V07: com RN26 e sem V09, um reenvio de rede deixaria dois prontuários permanentes onde houve uma consulta. Aqui a comparação é pelo motivo da consulta — duas consultas de verdade ao mesmo animal, no mesmo prestador, pelo mesmo profissional e em dez minutos não têm o mesmo motivo.

**Um recorte declarado: "sessão prestes a expirar" virou "seu acesso expirou".** O mockup desenha o aviso de dois minutos antes, mas o servidor não anuncia ao cliente prazo algum de sessão, e um relógio inventado na tela mentiria com precisão. O que existe é o tratamento de 401 e 419 no envio: o painel diz que o acesso caiu, lembra que rascunho não é registro e leva ao login — e o texto reaparece porque o rascunho está no navegador, não na sessão que caiu.

**Duas peças compartilhadas ganharam capacidade nova, ambas opt-in.** `api.js` passou a exportar `apiUpload`, que usa `XMLHttpRequest` em vez de `fetch` porque só ele informa quanto do corpo já subiu — e devolve `{ promessa, cancelar }`, que é o que o botão "Cancelar envio" precisa. `AppTextarea` ganhou `autoExpansivel`: a caixa cresce com o texto e encolhe quando ele sai, sem rolagem interna, que é o que o briefing pede para os campos de 120 px de altura mínima. Nenhuma das duas muda o comportamento de quem já as usava.

**Quatro defeitos encontrados na verificação em navegador, todos desta fatia.** A regra `mimetypes` recebia a lista com espaço depois da vírgula, e " image/jpeg" não é formato algum: toda imagem era recusada. O painel "Não conseguimos gravar agora" aparecia também no 422, mandando procurar na rede um problema que estava no formulário — agora o 422 só marca os campos, e rola até o primeiro deles, que num formulário mais alto que a tela é a diferença entre erro visível e botão que parece não responder. A recusa de anexo vinda do servidor nomeava o arquivo duas vezes, porque a mensagem da API já o nomeia — a tela passou a usar a primeira frase da resposta como título. E o botão "Confirmar atendimento" aparecia duas vezes abaixo de `lg`, no cabeçalho e na barra fixa, porque `.botao` declara `display` e vem depois na folha. Por fim, a grade de duas colunas foi movida de 1280 para 1440 px (`xl`, §4.2 do briefing): em 1280 a coluna lateral ficava com 233 px ao lado da barra de navegação, dois campos de anexo espremidos onde cabe um.

## 9.10 V09 — a única escrita sobre registro que já existe, e que não escreve sobre ele

Encontrado na fatia V09 (24/08/2026). Todas as fatias anteriores puderam tratar RN26 como ausência: não havia rota de alteração porque não havia correção alguma a fazer. Esta é a fatia em que a correção existe, e por isso é a que teve de demonstrar — e não apenas afirmar — que corrigir não é alterar.

**A retificação vale para as duas espécies de registro clínico, e `vacinacoes` não tinha as colunas.** `atendimentos` tem `retifica_atendimento_id` e `motivo_retificacao` desde T08, que só lia; `vacinacoes` nasceu da carteira (T05) e do lançamento pregresso (T09), duas fases em que a correção não estava em questão. A assimetria era histórica, não conceitual: RN26 nunca distinguiu as duas, e RF33 fala de "registro clínico". As colunas novas repetem o nome das de `atendimentos` de propósito — é a mesma regra, e quem ler as duas tabelas deve reconhecê-la sem tradução.

**As duas colunas de retificação ganharam índice `unique`, inclusive a de `atendimentos`, que já existia sem ele.** Sem o índice, duas correções do mesmo original seriam duas versões vigentes ao mesmo tempo, e nenhuma resposta possível à pergunta "qual delas vale?". Com ele a cadeia é uma linha: corrigir uma correção é criar a terceira versão, jamais uma segunda segunda. É também o que fecha a porta ao clique duplo — que, sob RN26, deixaria dois registros permanentes onde houve uma correção. A conferência em PHP (`recusarSegundaRetificacao`) cobre o caso comum e responde 409 com texto útil; o índice cobre o que escapa entre a leitura e a gravação, que é justamente o que a conferência não alcança.

**A versão substituída de uma aplicação sai do cálculo do calendário — e esse é o ponto que a fatia existia para não errar.** Uma aplicação e a sua retificação descrevem **uma** dose: o que mudou foi o lote, o sítio, a ordem declarada. Contadas como duas, o rótulo de todas as doses seguintes se deslocaria e a data da próxima mudaria (RF26) — o tutor veria a carteira ganhar uma dose porque alguém corrigiu um número de lote. O corte é feito em `CalendarioVacinalService::montarCarteiraCom()`, o funil por onde passa todo cálculo do sistema (carteira de T05, rechamada de V02, busca de V03, prévia ao vivo de V07), e não em cada consulta: uma consulta esquecida seria uma tela contando duas doses onde houve uma. É feito pela própria coleção, sem consulta a mais, porque quem chega ali traz as aplicações do animal inteiro. Um teste compara a `prevista_para` antes e depois da retificação e exige que sejam idênticas.

**O que sai do cálculo não sai do histórico.** RF33 manda o original permanecer "íntegro e visível, sinalizado como retificado", e uma linha do tempo que só mostrasse a versão final seria indistinguível de uma em que a correção apagou o que havia antes. `HistoricoConsolidadoService` ganhou um segundo laço, sobre as versões substituídas, que a carteira já não traz — e a versão vigente que corrige outra passou a anunciar-se com `tipo: retificacao` e `vinculada_a`, como a retificação de atendimento já fazia.

**A retificação conserva a data do ato e ganha data própria de gravação.** `atendido_em` é copiado do original: a consulta aconteceu quando aconteceu, e uma correção com a data de hoje inventaria um atendimento que ninguém prestou — e deslocaria o "último atendimento" e o retorno em aberto de V06. A data da correção é o `created_at`, e foi para ele que `AtendimentoService` passou a apontar ao dizer "retificado em"; antes usava `atendido_em`, o que era correto enquanto nenhum caminho criava retificações e passaria a mentir agora. A autoria, ao contrário, é **reescrita** a partir de quem retifica, e não copiada: é a mesma pessoa (RN27 garante), mas o CRMV é o que vale hoje, e é ele que responde por esta versão.

**Duas coisas são recalculadas na gravação, e uma terceira deliberadamente não é.** `validade_expirada_confirmada` (RF25c) é recalculada porque a correção pode mexer justamente na validade ou na data, e manter a marca antiga deixaria no registro uma afirmação que os seus próprios campos desmentem. O título do atendimento é rederivado do motivo corrigido, pela mesma regra de V08 — duas regras de titulação fariam a correção aparecer na linha do tempo com um nome que o original nunca teria recebido. Já `ordem_dose_sugerida` **não** é recalculada: ela é o retrato do que o sistema sugeriu no dia do registro, e recalculá-la hoje apagaria a divergência que RF27b existe para tornar auditável.

**A confirmação de RF25c não é repetida na retificação.** O que aquela regra exige é o consentimento de quem está prestes a aplicar um lote vencido — decisão clínica, tomada diante do animal. Numa retificação a aplicação já aconteceu meses atrás, e pedir que o profissional "confirme" um fato passado seria pedir consentimento para o que não depende mais dele. O que RF25c pede em substância — que o registro fique sinalizado — continua valendo, pelo recálculo acima.

**Recortes declarados.** A retificação de vacinação **não** troca o imunobiológico: trocar a vacina não é corrigir um campo, refaz o protocolo aplicável (RN32), a ordem sugerida, o alerta de validade e a conferência de espécie (RN30) — é o formulário inteiro de V07. Uma aplicação lançada com a vacina errada se corrige registrando a certa e retificando esta para dizê-lo na observação. Pelo mesmo critério, a retificação de atendimento cobre as seis seções clínicas de RF31 e não o peso aferido, o retorno programado nem os anexos: o retorno já gerou lembrete ao tutor e os anexos pertencem ao registro original (RF32), e desvinculá-los dele seria perder a consulta que os pediu.

**A tela do veterinário não é T06 nem T08 com outra moldura.** Aquelas rotas partem da titularidade do tutor e respondem 403 a quem escreveu o prontuário — foi a razão pela qual V08 mandou o seu sucesso para a aba Histórico de V06, anotando que "a tela de detalhe clínico do atendimento é fatia de V09". A rota clínica parte da autorização vigente do prestador ativo (RN48), grava a linha do livro de acessos quando o registro é de outro prestador (RN49, e chegar por endereço direto não pode custar menos do que chegar pela ficha), e devolve `pode_retificar`. Uma view serve às duas espécies de registro, porque o que ela faz é o mesmo nas duas.

**A ação não aparece para quem não pode, e a recusa é do servidor de todo modo** (RNF09, RN27). Não desabilitada, não com explicação: ausente — para o colega de equipe, para o mesmo profissional atuando por outro vínculo, para quem administra a conta e para o tutor. `pode_retificar` viaja sempre, inclusive falso, porque a tela precisa saber que **não** deve desenhar a ação, o que é diferente de não receber notícia dela.

**A autoria não dispensa o consentimento.** Revogada a autorização (RF39), o prestador não alcança mais o registro que ele próprio produziu — nem para ler, nem para corrigir. É a mesma regra de V06, V07 e V08, e aqui ela contraria a intuição de propriedade: o vínculo que permite acompanhar o animal é do tutor.

**Três defeitos encontrados na verificação em navegador.** O primeiro é de acessibilidade e teria passado despercebido fora do teclado: o campo "Motivo da consulta" tem chave `motivo`, e o identificador `retificar-${chave}` colidia com o do campo "Motivo da retificação" — dois `id` iguais, e o rótulo do segundo apontando para a caixa do primeiro. Os outros dois são de largura: os botões do rodapé ficavam com 40 px entre 768 e 1023 px, violando o alvo mínimo de toque de §4.3, que vale **abaixo** de 1024 px; e a comparação lado a lado abria já em `lg`, onde cada coluna fica com cerca de 430 px de campo para texto clínico longo. A grade de duas colunas foi para 1280 px, e abaixo dela as colunas empilham com o original recolhido sob "Ver versão original", como o desenho de 1024 px prescreve — a mesma correção que V08 fizera pelo mesmo motivo, uma fatia antes.

**Três defeitos encontrados ao preparar o ambiente para conferência, e o primeiro é o mais instrutivo.** O menu "⋯" de V06 tinha o item "Retificar um registro" apontando para `/clinica/animais/{codigo}/retificar` — endereço escrito quando V09 não existia, seguindo a convenção de apontar para a tela futura e cair em E02 até que ela chegue. Só que V09 **não é uma tela por animal**, e não poderia ser: retificar exige saber qual registro se corrige. O item passou a levar à aba Histórico, que é onde essa pergunta se responde, com a nota "Escolha no histórico qual corrigir" — a ação continua vivendo no registro, para o autor dele. A lição vale para as convenções de destino futuro: quando a fatia chega, o endereço que a esperava pode não ser o endereço que ela tem.

O segundo saiu do primeiro: `comContexto()` montava `?prestador=` sempre com `?`, e o destino novo já viajava com `?aba=historico` — dois pontos de interrogação num endereço que o roteador não reconhece, e um link da própria ficha caindo em "Esta página não existe". O separador passou a depender do caminho, nas duas telas que têm a função.

O terceiro é de dado, não de código: **nenhum seeder preenchia `aplicador_user_id`**. A coluna existe desde V07 e é ela que RN27 consulta; sem ela o cenário afirmava que o Dr. Marcelo aplicou a dose e o sistema não sabia identificá-lo, de modo que **nenhuma aplicação aparecia retificável para ninguém** — metade de V09 parecia não existir. No mesmo movimento, o `created_at` da retificação do Théo passou a ser fixado em 15/11/2025: agora que a tela lê essa coluna para dizer "retificado em", deixá-la solta fazia o cenário canônico anunciar a correção na data em que o seeder foi executado.

**Um defeito anterior à fatia, registrado e não corrigido aqui.** `TermoDeBusca::de()` só reconhece o código do animal digitado sem hifens quando ele contém algum algarismo, e o alfabeto de `CodigoDoAnimal` gera códigos só de letras em cerca de 10% dos sorteios — o teste que cobre esse caminho falha intermitentemente na mesma proporção. A condição existe para não confundir o código com um nome próprio de dez letras começado por "Im", e desfazê-la exige uma decisão que não é desta fatia.

## 9.11 P03 — a recusa por cadastro existente deixava escapar qual dado coincidia

O briefing §P03 descreve o estado como "**CPF já cadastrado** — mensagem 'Já existe uma conta com este CPF.' […] e nenhuma outra informação sobre a conta existente", e era isso o que a tela fazia: a frase nomeava o CPF e, logo abaixo, repetia o número formatado. Duas coisas estavam erradas nisso, e a segunda é a que o desenho não tinha visto.

**Repetir o número era informação sobre a conta existente.** Quem digitou o CPF já o conhece — a linha não lhe dizia nada de novo. Para quem digitou o CPF de outra pessoa, ela confirmava o dígito verificador do documento que estava sendo testado, na tela, sem custo. A linha saiu.

**A distinção entre "CPF em uso" e "e-mail em uso" é um oráculo de enumeração**, e da mesma espécie que RF01b já proíbe no acesso: se a recusa por CPF tem frase diferente da recusa por e-mail, o formulário público responde "esta pessoa tem conta aqui" a quem perguntar com um CPF, e "este endereço tem conta aqui" a quem perguntar com um e-mail — num sistema onde ter conta significa ter animal com prontuário. As três situações (CPF em uso, e-mail em uso, ambos) passaram a devolver a mesma frase, "Já existe uma conta com estes dados.", e um teste compara os três corpos de resposta caractere por caractere.

**A mensagem única não bastaria: a chave do erro contava sozinha.** `unique:tutores,cpf` responde em `errors.cpf` e `unique:users,email` em `errors.email` — uniformizar o texto e manter as regras de campo deixaria a resposta HTTP dizendo exatamente o que a tela calava, e quem enumera lê a resposta, não a tela. As duas regras saíram de `rules()` e a apuração foi para `after()`, que adiciona um erro só, na chave neutra `conta`. É por essa chave, e não pelo texto da mensagem, que a interface reconhece o estado — casar por frase, como se fazia, torna a proteção refém de uma vírgula.

**Dado malformado continua respondendo no seu campo.** CPF com dígito verificador errado ou e-mail sem domínio recusam-se em `errors.cpf` e `errors.email`, com mensagem própria: ali a recusa é sobre o que foi digitado e não afirma nada sobre cadastro alheio — e quem errou a digitação precisa saber qual campo corrigir. Por isso `after()` desiste quando um dos dois campos já falhou: a duplicidade só é apurada sobre dado bem formado.

**O botão de saída mudou de nome porque não pode mais mudar de campo.** Era "Corrigir CPF", e limpava o estado de validação do CPF; sem saber qual dado coincidiu, apontar um campo seria adivinhação. Passou a "Revisar meus dados", devolvendo o formulário preenchido como estava.

## 9.12 T18 — a tela que todas as molduras prometiam e nenhuma fatia tinha construído

Encontrado em uso (25/08/2026): clicar em "Conta" caía em E02. A moldura do tutor aponta para `/conta` desde a primeira fatia autenticada, a do veterinário também, e T11 oferece o mesmo endereço como saída — três promessas a uma tela que existia só no briefing. É o caso oposto ao de V09, onde o endereço que esperava a fatia estava errado; aqui ele estava certo e vazio.

**A rota não tem área, e é a única do sistema autenticado que não tem.** Todas as demais declaram `area` e são recusadas a quem não tem o papel correspondente (RNF09). A conta é anterior ao papel: o veterinário que acumula vínculos, o administrador do prestador e a tutora entram todos por ela, e a moldura desenhada é a de quem chegou — `RoleShell`, a mesma peça que as telas de exceção usam pelo mesmo motivo. Trocar a senha não pode custar a barra lateral do ambiente em que a pessoa estava.

**O controlador ficou em `Auth\`, junto de sessão, senha esquecida e confirmação de endereço,** e não entre os controladores de tela. É do que ele trata: RF04 e RF06 são §3.3, o mesmo bloco dos outros três, e nenhuma linha dele consulta animal, prestador ou registro clínico.

**A recusa por endereço já cadastrado é específica aqui, ao contrário de P03.** A discrição de RF12b protege o formulário **público** de servir de consulta a quais endereços têm conta; esta rota exige sessão, e quem pergunta já está identificado pelo cookie que a abriu. Guardar segredo não esconderia cadastro de ninguém — deixaria a titular diante de um formulário que se recusa a salvar sem dizer por quê.

**Trocar a senha derruba as outras sessões e preserva a que pediu a troca.** É o mesmo efeito de RF03b na redefinição, e pelo mesmo motivo: quem troca a senha costuma trocar porque desconfia de quem mais a tem. Poupar a sessão corrente é o que distingue esta operação da redefinição — pedir à pessoa que entre de novo no aparelho que ela tem na mão seria punir o cuidado.

**O nome alterado acompanha o cadastro de tutor.** `users.name` e `tutores.nome` nascem iguais no autocadastro (P03), e é o segundo que encabeça a carteira e o histórico exportados. Deixá-los divergir faria a mesma pessoa aparecer com dois nomes conforme a tela, e o documento verificável exibiria o antigo para sempre.

**Três seções do briefing ficaram de fora, e a tela não as anuncia.** Preferências e histórico de notificação (T16, T17) e os direitos do titular — "Baixar meus dados" e "Encerrar minha conta" (RF54, RF55, remetidos a Trabalhos Futuros pelo §9.2 dos requisitos). Botão que não leva a lugar algum é exatamente o defeito que trouxe esta fatia à existência; o sino do cabeçalho do tutor, que aponta para `/conta/notificacoes/enviadas`, continua caindo em E02 e espera a fatia de T17.

**O CPF é somente leitura, e isso é decisão, não limitação técnica.** Ele identifica o titular perante RF13, é a chave da transferência de titularidade (RF19) e viaja nos documentos já emitidos. Corrigi-lo é caso de suporte com prova documental, não de formulário aberto.

**A coluna precisou dispensar o limite de leitura da moldura para ficar centrada.** `.tutor-shell__limite` restringe o conteúdo a 880 px **alinhados à esquerda** — medida pensada para texto corrido, onde a linha longa cansa. Uma coluna de 640 px centrada dentro desse limite não fica centrada na área de conteúdo: sobrava 40 px de diferença em 1280 px e 128 px em 1440 px, e o desequilíbrio cresce com a tela. A moldura já previa o caso na prop `amplo`; o que faltava era `RoleShell` repassá-la, e ele passou a repassar apenas às molduras que a declaram, pelo mesmo mecanismo com que já filtra `titulo`. As telas de exceção, que não a passam, seguem exatamente como estavam.

**Um defeito de alvo de toque, o mesmo de V08 e V09.** Os botões caíam para 40 px a partir de 768 px, porque o recorte de densidade foi copiado de A02 — que tem o mesmo problema, não corrigido aqui. §4.3 exige 44 px **abaixo** de 1024 px, e a faixa de 768 a 1023 px é a que escapa. A regra passou para `min-width: 1024px`.

## 9.13 T15 — o documento que três telas prometiam e a verificação pública esperava

Encontrado em uso (31/08/2026): "Exportar PDF" em T04, T05 e T07 caía em E02 — o mesmo defeito que trouxe T18 à existência, agora em triplicata. A fatia de P09 já verificava documentos (RF47) que nenhuma fatia sabia emitir; o modelo `Exportacao` anotava, desde então, que a geração seria a fatia de T15. É esta.

**A exportação é modal sobre a tela de origem, não rota própria — mas o endereço que circulou não vira "página não existe".** O briefing sempre disse modal (T15); os botões, escritos antes da fatia, apontavam para `/animais/:codigo/exportar`. Os três viraram botões que abrem o modal, e a rota antiga redireciona ao perfil com `?exportar=1`, que o abre na chegada: um endereço que já esteve num botão pode estar num favorito.

**O resumo criptográfico assina o conteúdo, não os bytes do arquivo.** O PDF imprime o próprio resumo no rodapé — um hash dos bytes teria de estar dentro do arquivo cujos bytes resume. A serialização canônica do conteúdo (o mesmo array que o Blade imprime) é o que se assina; reemitir o mesmo recorte produz o mesmo resumo sob outro código, que é a forma de RF46a dizer "duas emissões do mesmo documento". Pelo mesmo motivo, o download entrega o arquivo gravado na emissão, nunca uma segunda geração: regenerar sobre dados que mudaram entregaria um documento divergente do próprio rodapé.

**`exportacoes` ganhou `animal_id`, que a fatia de leitura não precisava.** A verificação pública responde pelo retrato gravado na linha (RN47) e segue sem consultar o vínculo; é o download autenticado que precisa dele, porque o âmbito é a titularidade (RN12: emissão de animal alheio responde 404 igual a inexistente). Anulável com `nullOnDelete`, pela mesma razão do autor — remover o cadastro não pode invalidar documento que já circula.

**Sem registros no recorte, 422 — não documento vazio.** Um PDF autêntico e vazio afirmaria, com selo da plataforma, que nada aconteceu, que não é o que a ausência de registro diz (RN25). O modal conta os registros por conta própria (uma leitura de T07) e desabilita o botão com explicação antes que o servidor precise recusar.

**Um reset universal de CSS apaga, em silêncio, os elementos `position: fixed` do dompdf 3.** O rodapé inteiro — advertência de RN46, código da emissão, resumo, QR Code — sumia do PDF sem erro algum; `pdftotext` foi o que acusou. A bissecção isolou o `* { margin: 0; padding: 0 }`: com ele, o fixo não é desenhado; sem ele, tudo aparece. Os resets foram para os elementos, um a um. O conteúdo clínico, esse, vem inteiro dos mesmos serviços de T05 e T07 (`CalendarioVacinalService`, `HistoricoConsolidadoService`) — o papel e a tela dizem por construção as mesmas palavras, inclusive o "registro não verificado" do pregresso (RF46b, RN24).

**O botão de V06 continua caindo em E02.** RF46 nomeia o veterinário como ator, mas o âmbito dele é outro — autorização vigente, não titularidade — e o registro de acesso de RN49 entra na conversa. É fatia própria, não um `if` a mais nesta.

## 9.14 V04 — o cadastro que começa por descobrir se deve existir

Encontrado na fatia V04 (31/08/2026). Os botões "Cadastrar tutor" de V01 e V03 já apontavam para `/clinica/tutores/novo` e caíam em E02. As decisões da fatia:

**A verificação de CPF não tem rota própria — é a busca de V03.** O "Verificar" da primeira etapa chama `GET /api/clinica/buscar`, pela mesma razão de V07a (§9.7): a regra de revelar só a existência (RN12) e o registro de acesso que a precede (RF18b) já moram ali, e uma segunda consulta com regra própria seria uma segunda chance de errar a regra. As três respostas da busca são os três estados da tela: `sem_resultado` abre o formulário; `existencia.tipo === 'tutor'` é o estado crítico de RF13; `autorizados` não vazio significa que não há o que cadastrar — a tela mostra os animais e leva à ficha.

**O CPF existente no envio também fica registrado.** `POST /api/clinica/tutores` reconfere o CPF e responde 409 sem dado algum do cadastro encontrado — e grava a linha de `registros_de_acesso` se o tutor não estiver sob autorização vigente do prestador. No caminho desenhado a existência já foi revelada (e registrada) pela verificação; chegar ao envio com CPF existente é a janela entre uma e outro, e o log não pode depender de qual dos dois caminhos revelou. A exceção espelha a da busca: tutor já autorizado não gera linha, porque anunciar o que o âmbito já mostra não revela nada (é a mesma condição de `BuscaClinicaService::existenciaForaDoAmbito`).

**O e-mail em uso responde no campo, ao contrário do autocadastro.** P05 recusa CPF e e-mail repetidos pela mesma chave `conta`, com a mesma frase, porque quem pergunta é um visitante anônimo enumerando cadastros (§9.11). Aqui quem pergunta é um profissional autenticado com o titular à sua frente, o CPF já foi verificado como novo na etapa anterior, e sem nomear o campo a tela não teria como seguir. A recusa confirma que o endereço tem conta — não de quem, nem de quê.

**A conta nasce como a do veterinário convidado em A03.** Senha aleatória inacessível, `ativado_em` nulo, convite `tipo: 'tutor'` emitido na transação e enviado fora dela (falha de entrega não desfaz cadastro). `termos_aceitos_em` fica nulo: o veterinário não pode consentir pelo titular. O aceite de P07 já cobria este caminho — `ConviteAtivacaoTest` reproduzia "o estado deixado pelo cadastro do tutor no atendimento" desde a fatia P07 — de modo que RF14 fechou sem uma linha nova naquele fluxo.

**A ação única de RF13 aponta para V10, que ainda não existe.** "Solicitar autorização ao tutor" leva a `/clinica/autorizacoes/nova`, o mesmo destino do cartão de consentimento de V03 — endereço definitivo primeiro, tela depois, como manda o padrão da casa. O mesmo vale para o "Cadastrar animal" do sucesso, que aponta para V05 (`/clinica/animais/novo`).

## 9.15 Relação de animais da clínica — o destino da barra lateral que o briefing desenhou sem catalogar

Encontrado na fatia da relação de animais (31/08/2026). O item "Animais" da moldura do veterinário (§5.3) apontava para `/clinica/animais` e caía em E02 desde a fatia de V01 — endereço definitivo primeiro, tela depois. Mas a tabela de telas do briefing não tem tela nesse endereço: V05 é o cadastro (`/clinica/animais/novo`) e V06 é a ficha (`/clinica/animais/:codigo`). A tela nasceu, portanto, sem código de briefing, e as decisões dela são estas:

**É lista de navegação, não busca.** Uma linha por animal sob autorização vigente do prestador ativo (RN48, o âmbito de V01 e V02), em ordem alfabética. Achar um animal determinado continua sendo papel de V03 — que responde também sobre o que está fora do âmbito, com a parcimônia de RN12 e o log de RF18b — e é por isso que a tela não tem campo de texto: um campo de nome aqui seria uma segunda busca com regra própria, a segunda chance de errar a única regra que não admite erro. A urgência também não ordena esta lista: ela tem tela própria (V02), a um clique na mesma barra lateral.

**Sem registro de acesso, como V01 e V02.** Listagem agregada não é abertura de histórico; o acesso "por ver" é o da ficha (RF52b), e é para lá que cada linha leva.

**A linha diz o que decide a leitura, e três ausências são informação.** Situação da carteira pela pior dose (`situacaoDaCarteira`), última passagem pelo prestador (atendimento ou vacina de origem `profissional`, nunca pregresso — RF29), e vencimento da autorização com o mesmo aviso de quinze dias de T12 e V06 (RN39): renovar é do tutor, mas saber até quando se enxerga o histórico é da clínica. Animal com óbito aparece sem etiqueta de situação (RF22a cessa o calendário — "atrasada" sobre quem morreu não é informação clínica), carteira vazia aparece como "sem registro" (RF50 proíbe "em dia" por omissão), e o cadastro preliminar se anuncia na linha (RF16d). O filtro "sem registro" exclui o óbito: oferecê-lo como carteira a preencher mentiria duas vezes na mesma linha.

**`minmax(0, …fr)` na grade da tabela, e a razão de ser.** Cabeçalho e corpo são grades independentes (`thead tr` e `tbody tr`), e faixa `fr` seca não desce abaixo do min-content — em 768 px o cabeçalho em caixa alta ("SITUAÇÃO VACINAL") travava larguras diferentes das células e desalinhava a coluna do próprio título, coisa que a medição por `getBoundingClientRect` pegou e a leitura da árvore não pegaria. V02 escapou por sorte de conteúdo; a regra vale para toda tabela-grade futura.

## 9.16 V10 — o pedido que quatro telas prometiam, e o CPF que não cabe numa tabela nominal

Construída na fatia de V10 (31/08/2026), fechando o "Não encontramos esta página" dos botões "Solicitar autorização ao tutor" de V03, V04, V06 e V07a. As decisões:

**O alvo do pedido viaja como termo, nunca como id.** `POST /clinica/solicitacoes` recebe o mesmo vocabulário da busca de V03 — CPF, código do animal ou micro-chip — e resolve o cadastro no servidor. Não é preciosismo: a resposta de V03 não entrega identificador de cadastro alheio (RN12), então a tela literalmente não tem um id para mandar. O nome não é chave de pedido (422): a correspondência por nome é um conjunto indeterminado de titulares, e pedido a esmo é varredura — o cartão coletivo de V03 trocou o botão por uma frase que manda buscar pela chave exata.

**Por CPF, o pedido se desdobra em uma linha por animal — e a resposta não conta.** A tabela é nominal por animal (RN37) e o prestador não conhece a relação de animais do tutor; o desdobramento preserva as duas coisas: cada linha é um pedido que o tutor responde individualmente em T13. A resposta ao prestador é idêntica haja cinco animais, um ou nenhum — distinguir "enviado" de "não havia a quem enviar" seria a contagem que RN12 proíbe, no valor zero. Tutor sem animais recebe, portanto, um "enviada" que não criou linha alguma, e isso é deliberado.

**Pedido repetido é estado, não erro.** O 409 `ja_pendente` devolve a data do pedido que já espera, e as três telas trocam o botão pela etiqueta "Solicitação enviada · aguardando o tutor até {data}" — sustentada por `solicitacao_pendente` que V03 e V06 passaram a incluir no cartão de existência (é ato do próprio prestador, não dado do tutor). Animal já sob autorização vigente responde 409 `ja_autorizada`, alcançável só por corrida: a interface nem mostra o botão nesse caso.

**Tutor não ativado: o pedido entra, o e-mail não sai.** A conta criada no balcão (V04) existe e o pedido a espera em T13; o modal explica que o titular precisa ativar antes de responder (RF14b). O aviso por e-mail (RF38) só vai a conta ativada — a comunicação pendente do não ativado é o convite, e um segundo e-mail sobre um app em que ele nunca entrou confundiria mais do que informaria.

**A mensagem opcional é citada, não incorporada.** Coluna `mensagem` (280 caracteres) gravada no pedido e exibida em T13 entre aspas com autoria ("mensagem de {prestador}"): é contexto do prestador para o tutor reconhecer o pedido, nunca texto da plataforma — e por isso vai também no e-mail identificada como dele.

**O endereço que circulou não vira 404.** `/clinica/autorizacoes/nova` é redirect (mesmo raciocínio de `/animais/:codigo/exportar`): com `?animal=`, ficha do animal com o modal aberto por `?solicitar=1` — parâmetro que a tela remove do endereço na chegada, para recarregar não reabrir pedido já decidido; sem contexto, a busca, que é onde o pedido nasce. O modal é sobre a tela de origem (V10 do briefing), e a rota própria existe só como compatibilidade com o endereço já impresso nos botões.

## 9.17 V05 — o cadastro que o veterinário faz inteiro, e a caracterização que não vaza

Construída na fatia de V05 (01/09/2026), fechando o "Cadastrar animal" que V01, V03, V04 e a relação de animais prometiam — e o `/clinica/animais/:codigo/caracterizar` que a tarja "Cadastro preliminar" de V06 prometia desde a fatia da ficha. As decisões:

**Uma tela, duas portas — e a mesma metade privativa.** `/clinica/animais/novo` cria com identificação e caracterização de uma vez; `/caracterizar` é o modo consolidação (RF20b): a identificação aparece como fato, não como formulário, porque é do tutor (RF16) e a consolidação nunca cria um segundo cadastro (RN19). As regras da caracterização moram num trait compartilhado pelas duas FormRequests, porque para quem preenche é a mesma metade da tela e o erro tem de ter a mesma frase.

**O tutor do cadastro entra por CPF, com a barreira de V04.** A verificação é a busca de V03 — mesma regra de existência (RN12), mesmo registro (RF18b) — e o formulário só abre com titular resolvido: animal sem tutor não existe no sistema. CPF sem cadastro conduz a V04 ("o animal vem em seguida", agora nos dois sentidos). Nenhum id de tutor viaja em URL ou formulário: id é enumerável, CPF ditado no balcão não.

**O cadastro do veterinário nasce caracterizado; o âmbito, não.** RN17 fala do cadastro *iniciado pelo tutor* — o criado por veterinário em atendimento nunca foi preliminar, e campo em branco ali é escolha profissional, não pendência. Já a autorização não nasce nunca do balcão: o sucesso conduz à ficha em estado P2, onde está o pedido de V10 — o caminho completo do balcão vira tutor (V04) → animal (V05) → solicitação (V10) → concessão do titular (T13/T11), sem atalho que dispense o consentimento.

**A duplicidade alerta nos dois âmbitos, mas identifica só no autorizado.** O 409 de RF20a traz o cartão completo quando o animal parecido está sob autorização vigente; fora dele, o mínimo que RF18a admite — nome e espécie, sem código — e a revelação vira linha no livro de acessos com natureza própria (`alerta_de_duplicidade`), porque descobrir pelo formulário que o tutor tem um animal parecido é descobrir por busca com outro nome. Micro-chip igual em cadastro do mesmo tutor é duplicidade certa; em cadastro alheio, erro de campo que não diz de quem é (RN12).

**A data exata muda o que o campo exige (RN14).** Estimada, o nascimento aceita ano ou mês/ano, como o tutor declara em T03; marcada exata — afirmação privativa do veterinário —, exige dia, mês e ano, com máscara própria (`formatarDataCompleta`). A estimativa por ano reaparece na consolidação como `01/aaaa`, perda aceitável porque o veterinário está ali exatamente para confirmar ou corrigir o que vê (RF19b).

**Peso e foto ficaram fora, por motivos opostos.** O peso porque já tem lugar certo: é medição datada vinculada ao atendimento (`atendimentos.peso_aferido`, V08), e um campo no cadastro recriaria o atributo sobrescrevível que a observação de RF19 manda evitar — o "Peso aferido hoje" do briefing se cumpre no atendimento que o balcão registra em seguida. A foto porque é dado de identificação mantido pelo tutor (RN20), e a rota de envio existente é do ambiente dele; o cadastro clínico segue sem foto e o tutor a acrescenta quando ativar o acesso.

**T04 ganhou o estado preenchido que esperava.** O bloco de caracterização do perfil do tutor saiu do `EmptyState` permanente para exibir raça, pelagem, situação reprodutiva e micro-chip com a assinatura de RF19c ("Registrada por {veterinário}") — sem botão de edição, porque para o tutor esses campos continuam sendo leitura (RF19a).

## 9.18 Exportação pela clínica — o segundo ator de RF46, e a linha de log que a fatia de T15 anunciou

Encontrado em uso (01/09/2026): "Exportar PDF" na ficha do veterinário (V06) caía em E02 — exatamente como a fatia de T15 deixou anotado que cairia ("é fatia própria, não um `if` a mais nesta"). É esta.

**A porta é outra; o documento é o mesmo.** `POST /api/clinica/animais/{codigo}/exportacoes` reusa `EmitirExportacaoRequest` e a montagem de `ExportacaoDeHistoricoService` — mesmo conteúdo, mesmo resumo, mesma verificação pública, e o teste prova que a emissão do veterinário e a do tutor sobre o mesmo recorte assinam o mesmo resumo (RN47: duas emissões do mesmo documento). O que muda é a verificação de âmbito: autorização vigente do prestador ativo, não titularidade — e a falta dela é 403 que nomeia o caminho (a régua de V07), não o 404 de RN12, porque negar a existência de quem o profissional já encontrou o mandaria procurar de novo.

**A linha de RN49 é sobre o documento emitido, não sobre o animal.** A emissão cujo recorte leva registro de outro prestador grava `exportacao_de_registro_alheio` **antes** de existir — a ordem de RF52b, aplicada à emissão. Natureza própria, e não a `historico_de_outro_prestador` de V06, porque ler na tela e emitir um arquivo que circula fora da plataforma (RN46) são fatos diferentes para o tutor que lê T14. E a pergunta é feita ao recorte: o período que deixa o atendimento alheio de fora não presta contas dele, a carteira responde por vacinação alheia e não por atendimento, e o pregresso não conta em recorte algum (RN24 — seria dizer ao tutor que uma clínica leu o que ele mesmo escreveu). O download não grava linha nova — a emissão é o ato e o arquivo é o artefato dela —, mas reverifica a autorização a cada pedido, como o anexo de RF32c: revogada entre emitir e baixar, o arquivo fecha.

**O contexto viaja no endereço do download.** A resposta da emissão devolve `url_documento` já com `?prestador=`, porque a rota reverifica o âmbito e, sem o contexto, o pedido recairia no primeiro vínculo do profissional (RF09b) — que pode não ser o prestador autorizado.

**No frontend, o modal de T15 ganhou o segundo contexto em vez de um irmão.** `ExportarDocumentoModal` aceita `contexto="clinica"` (muda a rota da emissão), `prestadorId` e `entradas` — as entradas vêm da própria ficha, que V06 já responde inteira, porque a rota de contagem de T07 é do tutor e responderia 403 ao veterinário. O endereço `/clinica/animais/:codigo/exportar` virou redirect para a ficha com `?exportar=1` (o mesmo raciocínio de `/animais/:codigo/exportar`), preservando o resto da query — perder `?prestador=` no redirecionamento abriria a ficha de outra clínica.

## 9.19 V12 — o registro que é atributo do animal, e o calendário que cessa na fonte

Encontrado em uso (01/09/2026): "Registrar óbito" na ficha (V06) caía em E02 — o endereço circulou no menu antes de a fatia existir, como manda o padrão da casa. É esta.

**O óbito escreve no animal, não numa tabela de registros.** As colunas nasceram com V06 (que lê o estado); V12 acrescenta a causa (opcional por desenho — exigi-la produziria "indeterminada" digitado) e a autoria completa: prestador do âmbito e CRMV copiado do vínculo no ato, porque o vínculo pode encerrar-se e o registro continua respondendo por quem o assinou (RN27, RN22). Não há serviço próprio: cinco colunas num `update` são o fluxo simples que o padrão de P03 admite inline. A escrita é condicionada ao banco (`whereNull('obito_em')`), não à leitura — dois modais abertos ao mesmo tempo não sobrescrevem a autoria um do outro; o segundo recebe 409 com `situacao: 'ja_registrado'`, data e autor, o mesmo desenho do `ja_pendente` de V10. E exige autorização vigente **sem** a exceção do atendimento de V08: o óbito muda o estado do animal para o tutor e para todo prestador, não o prontuário de uma clínica.

**RF22a corta na fonte, não em cada consumidor.** As fatias anteriores tratavam o animal inativo tela a tela (lista da clínica, alertas da ficha) — e o resumo da ficha e a rechamada de V02 vazavam dose prevista para animal morto. O corte foi para `montarGrupo`, o funil por onde passa todo cálculo: sem previsão, `proximas_doses` esvazia em toda tela de uma vez — ficha, V02, carteira do tutor, lembretes. A situação do grupo virou `encerrada` ("calendário encerrado", pílula neutra com lua, borda cheia e não tracejada — encerrado é definitivo, não incerteza), porque o `nao-verificada` de "sem data suficiente para calcular" seria mentira educada: data havia, o cálculo é que cessou. Aplicações passadas ficam — histórico não é previsão (RF22b).

**Na linha do tempo, o óbito é a única entrada que vem do próprio animal.** `entradaDeObito()` produz no máximo uma, com o id do animal como chave estável (dentro do tipo não há segundo com quem colidir) e a procedência completa de P1. O resumo repete o compromisso de RF22b — encerrou-se o calendário, não o histórico — porque é o que o tutor em T07 precisa ler. O detalhe (`/obitos/:id`) segue caindo em E02, como o de exame: a resposta certa para rota que ainda não existe.

**No frontend, modal sobre V06 com a estrutura de P3 — e a ficha recarrega no registro, não no fechamento.** `RegistrarObitoModal` segue o par solicitar/retificar: folha inferior no celular, 480 px a partir de `md`, `ConfirmDialog` empilhado com "O que acontece"/"O que não acontece" nos textos do briefing. O `@registrado` dispara `carregar()` imediatamente — quando o modal se despede, a tarja, o selo e a supressão das ações já estão atrás dele. Aberto sobre animal que já tem óbito (endereço guardado, aba antiga), não oferece formulário: informa o que consta, que é o estado "já registrado" do briefing. O endereço `/clinica/animais/:codigo/obito` virou redirect com `?obito=1`, preservando a query, como o de exportar. Limitação herdada do padrão: o redirect só abre o modal quando a ficha **monta** — `push` para a mesma rota reusa o componente e não roda `carregar()`; vale igualmente para `?solicitar=1` e `?exportar=1`, e a correção, se vier, é das três de uma vez.

## 9.20 Relação de registros da clínica — o destino da barra lateral cujo âmbito é a autoria

Encontrado em uso (01/09/2026): "Registros" na barra lateral do veterinário caía em E02 desde a fatia de V01 — o último dos quatro destinos da moldura sem tela, anotado no próprio `VetShell` como fatia própria. É esta. Como a relação de animais (§9.15), nasceu sem código de briefing, e as decisões são estas:

**O âmbito é a autoria, e a inversão é a tela.** `GET /api/clinica/registros` relaciona os atendimentos e as vacinações com `prestador_id` do prestador ativo — nenhuma junção com autorização. É o oposto exato da relação de animais, e o par deixa explícita a regra que atravessa o sistema desde §9.15: decisão de acesso filtra pela autorização; atribuição e guarda de autoria, nunca. Lá, a autorização vigente decide quem a clínica pode *acompanhar* (RN48); aqui, a autoria decide o que a clínica *produziu e guarda* (RN40). O registro do animal cuja autorização venceu ou foi revogada continua no livro — retirá-lo desmentiria a frase que T12 exibe ao tutor antes de revogar ("os registros que essa clínica criou continuam sob a guarda dela", RF39d) e a guarda que a Resolução CFMV nº 1.321/2020 impõe. O animal autorizado que nunca passou pelo balcão, por sua vez, figura na relação de animais e não aqui. O pregresso não entra por dupla condição (`prestador_id` + `origem = 'profissional'`): não passou por prestador nenhum (RN25), e a redundância deixa a regra escrita.

**A guarda lista; a leitura integral é fatia própria.** Sem autorização vigente a linha fica, mas vem sem endereço (`url: null`) e com a marca "sob guarda" — e a nota sob a tabela explica a diferença, porque ausência sem explicação parece defeito (a régua de E01). Abrir a linha levaria a V09, que exige autorização vigente e montaria a resposta com o que RN48 fecha: `detalheAplicacao` constrói a série da dose com as aplicações vigentes de **todos** os prestadores, e `paraListagem` calcula a situação atual do animal — dado vivo de terceiros, que a guarda de RN40 não cobre. A leitura do registro sob guarda, na versão que mostra o próprio registro sem vazar a série alheia, fica anunciada aqui como a exportação pela clínica ficou em T15. A retificação já tinha a mesma resposta, deliberada, na FormRequest de V09: a autoria não dispensa o consentimento.

**Cada linha diz o que o livro sabe por si.** Data do ato, tipo, título (o do prontuário; o nome comercial do imunobiológico), animal, tutor, e o retrato de autoria que RN22 mandou gravar (`profissional_nome`/`aplicador_nome` + CRMV) — nunca o vínculo atual, que pode ter acabado sem apagar a assinatura (RF10b). A dose exibida é a `ordem_dose` declarada no ato (RF27), e não o rótulo que a carteira calcula: o rótulo depende da série inteira do animal, que tem registros alheios. Nenhuma coluna de situação vacinal ou de óbito — status é resposta da ficha e da relação de animais; este livro responde pelo que foi registrado.

**Nada sai do livro por ter sido corrigido (RN26).** Original e retificação são duas linhas, cada uma com a sua marca (`retificado` / `retificação`), adjacentes porque a retificação conserva a data do ato e a ordenação é pela cronologia do ato com desempate pela gravação — o encadeamento de RF33b visível na própria lista. Borda cheia nas marcas, não tracejada: o tracejado é o vocabulário do não verificado, e nada aqui depende de verificação.

**O filtro de profissional sai do próprio livro, não da equipe de A03.** As opções são os autores que assinam linhas, com o retrato mais recente do nome — de propósito: um filtro montado da equipe ativa tornaria inencontráveis as linhas de quem foi desligado (RF10b). O id fora do livro volta ao padrão como qualquer valor fora da lista (RF48b), e é o serviço quem o descarta, porque é ele quem conhece o livro — o filtro não vira sonda de ids.

**Sem registro de acesso, com um motivo a mais que o de §9.15.** Toda linha desta resposta é produção do próprio prestador; o livro de T14 conta ao tutor o acesso ao que é de terceiros (RN49), do qual não há nenhum aqui.

## 9.21 Memória de contexto — e a administração ao alcance da barra da clínica

Encontrado em uso (02/09/2026): um animal recém-autorizado à clínica ficou invisível para o veterinário que também atende como autônomo. Não era regra de acesso — era o contexto: o padrão caía sempre no primeiro vínculo, e os destinos da barra lateral, que nunca carregaram `?prestador=`, devolviam o profissional ao consultório a cada navegação, desfazendo em silêncio a troca que ele tinha acabado de fazer.

**O contexto ativo passou a ser lembrado no servidor, não na URL nem no navegador.** `users.ultimo_prestador_id` guarda a última escolha; os dois resolvedores (`ResolvePrestadorAtivo`, `ResolvePrestadorAdministrado`) a preferem quando o pedido não traz `?prestador=` e a gravam quando traz. É memória, não decisão de acesso: quem a lê confere o vínculo vigente antes de honrá-la, e o id de vínculo encerrado é ignorado sem recusa — o comentário de `rotaInicial()` já anunciava essa persistência como pendência. A escrita é direta na tabela, sem eventos nem `updated_at`: lembrar onde alguém estava não é alteração do cadastro. A memória é **uma só para os dois ambientes**, de propósito: quem clica em "Equipe" estando na clínica administra a clínica, e voltar ao ambiente clínico o devolve ao mesmo lugar.

**O payload dos vínculos diz quantos animais estão sob autorização vigente em cada um — e nada mais.** `vinculosDe()` ganhou `animais` (contagem de animais distintos, porque a renovação antecipada convive com a autorização anterior) e `admin`. A contagem existe para avisar que há plantel esperando em outro contexto, sem nome nem código de animal. O payload administrativo (`vinculosAdministradosDe`) não ganhou contagem alguma: RN08 não distingue agregado de nominal, e a moldura de A01-A03 continua sem número derivado de animal.

**O aviso é em palavras, num lugar só — as listas de vínculos ficam limpas.** `AvisoOutroContexto` ("Há 1 animal sob autorização vigente em Clínica X — Trocar para lá") aparece no painel de V01 e nos estados `sem_autorizacoes` de V01 e da relação de animais, com a troca de contexto a um clique. Veste a cor da faixa de contexto, de que é prolongamento — não é alerta. Não entra em toda tela nem na moldura: no painel ele é uma linha; em toda tela seria ruído permanente para quem trabalha em dois contextos movimentados. A primeira versão punha também um selo numérico em cada vínculo do alternador e da barra lateral; saiu a pedido — o mesmo número em três lugares era redundância, e o aviso responde sozinho pela descoberta. Os itens de "Seus vínculos" na barra lateral, antes texto informativo, viraram botões de troca — o mesmo gesto da gaveta do celular, e clicar no vínculo já ativo deliberadamente não recarrega nada.

**Quem administra o prestador ativo vê os três destinos da administração na própria barra da clínica.** O item único "Administração" era uma troca de ambiente às cegas; para o veterinário que administra a conta em que está, a barra desenha A01, A02 e A03 diretamente ("Painel da conta", "Dados do prestador", "Equipe"), e a memória de contexto garante que abrem no prestador certo. A decisão é por vínculo, não por papel global: o flag `admin` vem do payload, e quem administra outra conta que não a do contexto ativo mantém o item único de antes. As molduras continuam duas — a administrativa guarda o bloco de limitação de RN08, que é argumento de projeto, não redundância.

## 9.22 A administração que não se pode conceder — e o contexto que ela desfazia

Encontrado em uso (03/09/2026): quem atende numa clínica e mantém o próprio consultório trocava para a clínica, clicava em "Administração" e via os dados do consultório. Duas coisas diferentes estavam erradas ao mesmo tempo, e a segunda é a que faltava desde a fatia de A01–A03.

**A memória de contexto guarda escolha, não acaso.** O resolvedor administrativo caía no primeiro vínculo administrado — o consultório, único que a pessoa administra — e gravava esse desvio em `ultimo_prestador_id` como se fosse escolha. Como a memória é uma só para os dois ambientes (§9.21), passar pela administração desfazia a troca de contexto: voltar ao ambiente clínico devolvia ao consultório. Os dois resolvedores passaram a gravar **apenas** quando o pedido traz `?prestador=`; o vínculo a que se chega por falta de outro não é decisão de ninguém, e gravá-lo apaga a decisão anterior. Como o fallback é determinístico, nada se perde em estabilidade.

**A tela administrativa diz de onde a pessoa veio.** As três telas de A01–A03 devolvem `contexto_clinico` — o prestador em que ela atende, quando não é o que ela administra —, e a moldura abre com a explicação e o caminho de volta ("Você atende em X, mas não administra essa conta. Aqui você administra Y"). A faixa é sóbria, e não a cor da marca, que fica com o alternador logo abaixo: é explicação, não ação.

**O papel administrativo passou a ser concedível, e a concessão é do responsável técnico.** Até aqui `admin_prestador` só nascia em P04, com quem cadastrava o prestador — não havia rota, serviço nem tela que o desse a mais alguém, embora §2.4 dos requisitos já dissesse que o papel "passa a ser atribuição do responsável técnico ou de outro médico-veterinário da equipe". A consequência era dupla: o convidado nunca administrava, e a conta de administrador único não tinha como ganhar um segundo. `POST`/`DELETE /api/prestador/equipe/{vinculo}/administracao` concedem e retiram, e quem os alcança é apenas o responsável técnico — identificado pelo par CRMV/UF do cadastro, e não pelo nome, que é texto digitado. É segregação entre pares: administrar é convidar, desligar e alterar o cadastro do estabelecimento, e quem responde tecnicamente por ele decide a quem esse poder cabe. Quem não concede lê na tela a quem pedir, uma vez sob a tabela e não em cada linha — o motivo é de quem olha, não do membro.

**A conta administrada não troca de casca quando é a conta em que se atende.** Os três destinos administrativos já eram desenhados na barra do ambiente clínico (§9.21), mas clicar em qualquer um deles substituía a moldura inteira pela administrativa — que oferecia um "Ambiente clínico" de volta para onde a pessoa nunca tinha saído. `ContaShell` escolhe a moldura: clínica quando o payload diz `atende_aqui` **e** não há contexto divergente; administrativa nos demais casos, que é onde mora o bloco de limitação de RN08 e a explicação da divergência. A moldura clínica veste o contexto ativo, e por isso não serve quando a tela administra outro prestador — a barra diria um nome e o conteúdo, outro. O alternador da moldura clínica lista vínculos que nem sempre são administrados: trocar para um deles leva ao painel da clínica, e não a uma tela que o servidor recusaria.

**E a casca é escolhida antes da resposta, não depois.** A primeira versão decidia pelo payload, e como `atende_aqui` só chega com ele, a tela desenhava a moldura administrativa durante os seiscentos milissegundos da requisição e a trocava pela clínica em seguida — a barra lateral inteira piscava a cada clique em "Equipe", defeito que a troca de moldura tinha acabado de criar. A moldura clínica passou a anunciar o contexto que desenha (`stores/contextoClinico`), e a moldura da conta lê esse anúncio para escolher a casca no primeiro quadro; na falta dele — primeira tela da sessão, ou recarga da página — vale o papel da sessão. Não é cache de resposta: a tela continua consultando o servidor e substitui o palpite pelo que ele responder. Enquanto isso a barra veste o contexto lembrado e só o conteúdo mostra esqueleto, como nas telas do ambiente clínico, onde a casca nunca some entre uma navegação e outra. `vinculos_clinicos` vem sem a contagem de animais que `vinculosDe()` entrega ao ambiente clínico — a lista dos próprios vínculos não é dado clínico; o número de animais em cada um seria (RN08).

**Conceder é linha nova no pivô, e é isso que preserva a assinatura.** O papel administrativo vira uma segunda linha para a mesma pessoa no mesmo prestador — a forma que o fundador já tinha desde P04 —, de modo que retirar a administração encerra aquela linha sem tocar no CRMV com que ela assina os registros (RN22). Duas recusas: convite ainda não aceito não recebe administração (é endereço de correio, não pessoa), e ninguém retira a própria — que, como quem concede é sempre administrador, é também o que impede a conta de ficar sem nenhum. Encerrar o vínculo (RF10) continua encerrando as duas linhas de uma vez.

## 9.23 As telas do prestador falam do lugar onde se está, e não do que se administra

Encontrado em uso (03/09/2026): estando no contexto de uma clínica, a seção do prestador mostrava o consultório — a única conta administrada. A explicação da faixa (§9.22) resolvia o susto, mas não o pedido: quem atende numa clínica quer ver aquela clínica.

**A pergunta que as três telas respondem mudou.** Antes era "qual conta você administra?"; agora é "onde você está?". `contextoDoPrestador()` resolve entre **todos** os vínculos vigentes, preferindo o contexto ativo, depois uma conta administrada, e só então o primeiro vínculo. Com isso a divergência de §9.22 praticamente desaparece — a tela abre onde a pessoa está —, e a faixa permanece para o resto: contexto lembrado que já não vige, ou quem administra sem atender.

**O que muda é o poder, não o acesso.** O terceiro valor devolvido, `pode_administrar`, é o que decide se a tela desenha ações. Toda escrita continua entrando por `contextoAdministrativo()`, que recusa quem não administra: A02 vira leitura, A03 perde a coluna de ação e o convite, A01 perde as pendências — mas o bloqueio de responsável técnico ausente fica, porque sem ele ninguém registra nada ali, e isso interessa a quem atende tanto quanto a quem administra (RF07c). Ler o cadastro do estabelecimento e a lista de colegas não fere RN08: não é dado de tutor, animal nem registro clínico.

**Leitura não é formulário desabilitado.** A02 em leitura é uma lista de definição, e não os mesmos campos em cinza: campo que não recebe foco convida a tentar e diz "quebrado" onde deveria dizer "não é seu". A lista sai do mesmo lugar que o formulário conhece, para que um campo novo não apareça em um e falte no outro.

**A frase termina num nome.** `administrada_por` vem da relação de usuários com papel administrativo vigente, e não da tabela da equipe: a administradora sem CRMV não figura em `equipe()`, que é a relação dos vínculos clínicos, e montá-la dali deixaria de fora justamente quem cuida da conta. "A administração desta conta é de Dra. Camila Martins Oliveira" diz a quem pedir; a ausência de ação sem essa frase leria como tela incompleta.

**Uma verificação encontrou um botão que o servidor recusaria.** Ao abrir a tela para quem não administra, `concessao()` passou a responder "pode conceder" ao responsável técnico **sem** papel administrativo — a escrita continuava barrada, mas a interface prometia. Conceder é ato administrativo, e a condição virou administrar **e** ser responsável técnico.

## 9.24 O prestador é sempre pessoa jurídica — e o campo genérico deixou de fazer sentido

Corrigido a pedido (04/09/2026): o cadastro aceitava CPF ou CNPJ e decidia entre os dois pelo comprimento do que fosse digitado. A premissa era falsa. O médico-veterinário **não pode ser microempreendedor individual** — a atividade está fora do rol do MEI —, então o profissional autônomo também exerce sob CNPJ, e não existe prestador pessoa física a acomodar. Um campo que aceita CPF convida a informar o documento errado justamente onde a dúvida é maior.

**A coluna passou a se chamar `cnpj`.** Ela se chamava `documento` porque guardava dois documentos possíveis; guardando um só, o nome genérico escondia a regra em vez de anunciá-la. É `char(14)`, como o `cpf` do tutor é `char(11)`: tamanho fixo é o que se pode afirmar de um documento cujo comprimento é lei. RF07 ganhou o critério (d), que diz o que a validação faz e para os três tipos.

**Uma regra polimórfica virou duas.** `DocumentoInscricaoValido` existia para decidir entre CPF e CNPJ, com uma bandeira `apenasCpf` que o tutor levantava. Sem nenhum chamador que aceite os dois, a decisão não tem mais o que decidir: ficaram `CpfValido` e `CnpjValido`, cada uma dizendo no nome o que confere. A alternativa — somar uma bandeira `apenasCnpj` — permitiria construir a regra que recusa tudo.

**O teste que provava o contrário passou a provar a regra.** Havia um caso afirmando que o autônomo se cadastra com CPF; ele agora se cadastra com CNPJ, e ao lado dele entrou o que faltava: um CPF de dígito verificador impecável, recusado no tipo autônomo. O ponto não é que o número esteja errado, é que a pessoa física não se cadastra — e o autônomo é onde a tentação de informá-la existe.

**A tela ganhou a conferência que só o servidor fazia.** O cliente validava comprimento, nunca o dígito verificador do CNPJ, e o formulário lia qualquer 422 no campo como "já existe um estabelecimento" — de modo que **errar um dígito acusava cadastro alheio inexistente**. Com `cnpjValido` espelhando a regra do servidor no passo 1, o que chega ao 422 só pode ser unicidade, e a mensagem voltou a ser verdadeira.

**Três CNPJs do cenário não fechavam.** `77888999000122` e outros dois nasceram plausíveis e nunca foram conferidos, porque seeder escreve direto no modelo, sem passar por formulário. Sob a regra nova eles seriam recusados na primeira edição em A02 — dado de demonstração que a própria tela rejeita —, e foram trocados por números válidos. A fábrica também passou a calcular os dígitos verificadores em vez de sortear catorze algarismos: nascer válida poupa cada teste de sobrescrever o campo só para chegar ao que quer exercitar.

## 9.25 O acervo próprio da clínica — e por que ele não mora numa versão publicada

Pedido a fatia (04/09/2026): semear o catálogo com as vacinas essenciais e não essenciais da WSAVA 2024, já com o cálculo pronto, e dar a quem administra uma clínica — ou ao autônomo, que recebe o mesmo papel — o poder de cadastrar as vacinas que a diretriz não nomeia, definindo quando o tutor deve ser lembrado delas.

**Isto reverte, em escopo limitado, o que §3.6 decidira.** Lá se registrou que `limite_atraso_dias` "é parâmetro de `protocolos_vacinais`, que X02 mantém para a plataforma inteira, não por prestador", e a decisão continua valendo para o que ela julgava: os parâmetros das vacinas da diretriz seguem sendo os mesmos em toda parte, porque o mesmo cálculo tem de responder igual em qualquer clínica (RN31). O que se abre é outra coisa — o acervo que a diretriz nunca examinou, e sobre o qual a plataforma não tem o que dizer.

**Um `prestador_id` nulo é o catálogo oficial.** A separação vive numa coluna, e não em tabela nova, porque as duas coisas são o mesmo tipo de objeto: um imunobiológico que se escolhe ao registrar uma aplicação. Duplicar a tabela duplicaria o motor de cálculo. A coluna fica fora do `#[Fillable]`, como `admin_plataforma` no usuário: de quem é o item é decisão do servidor, nunca campo de formulário.

**`versao_protocolo_id` passou a aceitar nulo, e é isto que sustenta o desenho.** A tentação era gravar o agendamento da clínica dentro da versão vigente. Três coisas quebrariam de uma vez, e nenhuma daria erro: `criarRascunhoAPartirDaVigente()` copiaria o acervo privado de cada clínica para dentro do rascunho da plataforma; `incoerencias()` conferiria essas linhas, de modo que uma clínica que digitasse um número absurdo **impediria a plataforma de publicar**; e `publicar()` as encerraria junto — `protocoloVigente()` filtra `situacao = vigente`, então toda vacina própria de toda clínica pararia de calendarizar no dia em que a WSAVA fosse revisada, em silêncio. Com a nulidade, nada disso precisa de verificação: `$versao->protocolos` é um `hasMany` que não alcança a linha nula.

**Editar um agendamento cria a linha seguinte, e nunca altera a anterior.** `vacinacoes.protocolo_vacinal_id` congela a linha que explicou o cálculo; mudar-lhe um número recalcularia, em silêncio, uma data já mostrada ao tutor. A cópia-ao-escrever é incondicional de propósito: sendo sempre append, RN32 deixa de ser condição avaliada em tempo de execução e passa a ser propriedade da tabela — nenhuma linha muda, logo nenhuma data emitida pode mudar. É o mesmo raciocínio de RN26 no registro clínico, onde corrigir é criar a versão nova. O índice único em `(imunobiologico_id, versao_protocolo_id)`, que faltava desde sempre, entrou junto: o MySQL trata NULLs como distintos, então ele fecha a corrida do `updateOrCreate` de X02 e continua admitindo as N linhas sem versão que a cópia-ao-escrever produz.

**A intocabilidade vale nos dois sentidos, e o inverso é o mais silencioso.** Que a clínica não edite o catálogo oficial era o pedido; que a plataforma não edite o acervo da clínica não estava dito e é mais grave. X01 listava `Imunobiologico::query()` sem filtro, e X02 oferecia o item privado no seletor de parâmetros — gravar ali daria ao imunobiológico **duas linhas candidatas** a `protocoloVigente()`, a sem versão e a versionada, e o desempate por identificador decidiria por acaso qual explica a data daquele tutor. Isso é corrupção de cálculo, não incômodo de interface.

**O formulário pergunta em vocabulário de quem atende.** X02 pergunta dez parâmetros de diretriz porque é disso que a administração da plataforma responde. A04 pergunta quantas doses são, com que intervalo, se repete e de quanto em quanto tempo — e a prévia mostra, em datas, o que a resposta produz. As quatro colunas que não são perguntadas são a decisão: as duas idades mínimas ficam nulas porque são leitura de diretriz sobre anticorpos maternos (RN33), e a da dose final é justamente a que faria o motor agendar uma dose adicional sobre uma vacina que a WSAVA nunca examinou; `limite_atraso_dias` e `conduta_apos_limite` ficam no padrão porque o limite é o ponto em que o atraso vira pergunta clínica, e ninguém precisa opinar sobre isso antes de ter o caso na frente.

**O intervalo da série é perguntado em semanas, e não em meses.** Duas a quatro semanas é a grandeza real de RN34 e não cabe em meses; a regra de meses e anos vale para o horizonte do reforço, que é o que gera o lembrete.

**A periodicidade passou a aceitar nulo — "dose única, sem revacinação".** Foi preciso ensinar ao motor que uma série pode acabar: `preverDoseSeguinte()` devolve `data` nula com tipo `serie_concluida`, e a situação do grupo diz "série concluída". Deixá-la cair no fallback existente diria "sem data suficiente para calcular", que é mentira — houve data, o cálculo correu — e que na carteira do tutor se lê como falha do sistema.

**Dois defeitos anteriores vieram junto, e o primeiro é a razão de a semeadura ter de esperar por ele.** No primeiro reforço, a data usava `primeiroReforcoMeses()` e o rótulo usava `periodicidade_revacinacao_meses`. Enquanto nenhum protocolo distinguia os dois prazos, os números coincidiam e a contradição não tinha como aparecer; a tríplice felina da WSAVA distingue — reforço por volta dos seis meses, revacinação trienal —, e sem a correção a carteira diria "Reforço a cada 3 anos" ao lado de uma data de seis meses. O segundo: `opcoesDeOrdem()` sempre oferecia uma ordem além da série, e o rótulo dessa opção sai da periodicidade — com ela nula, V07 responderia 500 no caminho normal, não numa borda.

**Três escolhas da semeadura divergem da leitura literal da tabela da WSAVA**, e cada uma está comentada no seeder. Os polivalentes caninos revacinam anualmente, e não a cada três anos, porque contêm leptospirose — é o componente de prazo mais curto que rege o produto, e um V10 trienal deixaria o cão descoberto por dois anos. A antirrábica é anual porque a própria diretriz manda seguir a regulamentação local antes da bula. E o FeLV passou de "não essencial" a "essencial", que é onde a Tabela 2 de 2024 o posiciona. As vacinas que a diretriz classifica como **não recomendadas** não foram semeadas — `classificacao` só distingue essencial de não essencial, e cadastrá-las como "não essencial" diria que a diretriz as admite. A giárdia é a exceção deliberada, e entra **inativa**: existe no mercado brasileiro, a WSAVA não a recomenda, e inativa ela diz as duas coisas ao mesmo tempo — além de dar a X01 um caso real de inativação para demonstrar (RF23b).

**Uma aproximação declarada.** A WSAVA ancora o primeiro reforço na *idade* do animal ("por volta dos 6 meses"); o motor o conta a partir da *última aplicação*. Contado de uma dose final às 16 semanas, o reforço de 6 meses cai por volta dos 10 meses de idade — entre o que a diretriz prefere e os 12 a 16 meses que ela quer evitar. Ancorar por idade exigiria um parâmetro que `protocolos_vacinais` não tem, e inventá-lo seria mudar o motor para caber num caso.

## 9.26 T17 — o sino do cabeçalho do tutor ganha destino

Encontrado em uso (17/09/2026): o sino caía em E02. §9.12 já o registrava como pendente da fatia de T17; é esta.

**A rota fica sob `/conta`, mas tem área — ao contrário de T18.** O briefing abre T17 também ao veterinário (RF45a), só que com outro âmbito: autorização vigente do prestador ativo, e não titularidade. É fatia própria, pelo mesmo motivo que a exportação pela clínica não foi um `if` em T15. Até lá a rota declara `area: 'tutor'`, e quem não é tutor recebe E01 com a frase certa, em vez de uma tela que se monta para ouvir 403.

**`notificacoes` ganhou `destinatario`, um retrato do endereço no momento do envio.** RF45 pede o registro "com destinatário", e a tabela nascida em V02 não o tinha: aquela coluna só precisava de data e situação. Ler o endereço do cadastro do tutor seria errado exatamente no caso em que o histórico mais importa — quem corrigiu o e-mail depois de um lembrete não entregue veria a falha atribuída ao endereço novo. A migração preenche as linhas existentes com o endereço atual (as do cenário de demonstração, que nenhum motor emitiu), e a tela avisa, na folha de detalhe, quando o endereço do envio já não é o da conta.

**O âmbito é `tutor_id`, e não a titularidade do animal**, pela mesma razão do livro de acessos de T14: depois de uma transferência (RF21), o antigo tutor continua sabendo o que recebeu e o novo não herda lembretes que nunca lhe foram enviados. O caminho para a carteira só aparece quando o animal ainda é de quem lê.

**As situações são as três que a tabela registra, e não as do briefing.** O briefing fala em "enviada, falhou, reenviada"; o esquema de V02 distingue entregue, sem confirmação e não entregue, e essa distinção já decide a conduta da rechamada. A mesma mensagem não pode ter um nome em V02 e outro em T17, então os rótulos vêm de `Notificacao::descreverSituacao()` nas duas telas. "Reenviada" espera a política de nova tentativa de RF42e, que é do motor de envio. Os tipos usam os nomes que T16 dará aos interruptores de descadastro.

**Cada situação vem explicada, e não só a falha.** O briefing pede explicação em linguagem simples para a falha de envio; as outras duas pedem o mesmo pelo motivo oposto — quem abre o detalhe de uma mensagem entregue quase sempre está procurando por ela e não a achou.

**Helena continua sem notificação alguma no cenário.** O seeder a deixa assim de propósito: o Théo é o "nunca notificado" de V02. T17 com dados pode ser vista pelas contas de Antônio Prado e Ruan Teixeira; Sofia Nunes também tem uma notificação, mas nunca ativou o acesso (RF14b).

## 9.27 Um e-mail, uma conta — o veterinário que também é tutor

Encontrado em uso (23/09/2026): não havia como ser tutor e veterinário com o mesmo endereço de correio. Os dois autocadastros criavam conta nova e recusavam o endereço repetido — P03 com a frase neutra de §9.11, P04 com `unique:users,email` —, de modo que acumular papéis exigia dois endereços. É o caso da Larissa das personas, que §2 já apresenta como o motivo de RN05 existir, e o modelo inteiro já o suportava: `papeis()` soma vínculos de prestador e registro de tutor, `tutores.user_id` é único, e o convite de equipe de A03 reaproveita a conta de quem já é tutor desde a primeira fatia. Faltavam os dois caminhos de autocadastro, e faltava o caminho inverso do convite.

**Duas contas não são duas contas: são duas pessoas.** Nada no sistema liga um usuário a outro, e a duplicidade se propaga para onde mais custa — o livro de acessos de T14 mostra dois nomes onde há um profissional, a autoria dos registros de RF10b fica repartida entre as duas, e o CPF de RF12a, que é único na plataforma, prende o cadastro de tutor a uma delas para sempre. Por isso a correção é no cadastro, e não na tela: uma conta por endereço, e os papéis somando-se nela.

**A conta que já existe não se toma pela frente pública.** Quem chega anônimo com endereço já cadastrado continua sendo recusado, e com a mesma frase de sempre: aceitar ali seria acrescentar papel a conta alheia sem prova de titularidade, e a frase que explicasse por que foi aceito já seria o oráculo que §9.11 fechou. O acréscimo do cadastro de tutor acontece autenticado — `POST /api/conta/tutor` —, porque aí a senha já provou de quem é a conta. O vínculo com estabelecimento tem caminho próprio, e é o convite de A03: ali quem já está dentro atesta quem entra (§9.28).

**A recusa passou a dizer para onde ir.** "Já existe uma conta com este e-mail" mandava a pessoa embora sem alternativa; em P04, onde o endereço já respondia pelo campo `email`, a frase agora aponta o convite de quem administra o estabelecimento. Em P03 a frase neutra não muda — dizer mais ali contaria qual dado coincidiu.

**O CPF do cadastro de tutor autenticado sai pela chave neutra, como em P03.** Estar autenticado poderia parecer folga, e é o contrário: bastaria uma conta qualquer para transformar a rota em consulta de "este CPF tem cadastro no Imunia?", que é o que RN12 nega fora do atendimento — dentro dele V03 responde, e registra em log quem perguntou (RF18b). A rota tem limite de frequência pelo mesmo motivo.

**O nome da conta não é reescrito por cadastro nenhum.** O responsável técnico informado em P04 batiza a conta que nasce ali, e só ela: onde a conta já existe, o nome é o da pessoa e é ele que assina cada registro clínico que ela já produziu. O formulário de cadastro de tutor só pede o nome de quem ainda não tem — o convidado de A03 que nunca o definiu —, pela mesma razão que `ContaController::update()` mantém o nome do tutor casado com o do usuário.

**A entrada passou a ter duas portas, `/entrar/tutor` e `/entrar/veterinario`.** A credencial é a mesma e a conta é uma só; o que a porta escolhe é com qual papel se trabalha agora. Sem isso a precedência de `rotaInicial()` — que existe para RF01a e continua valendo em `/entrar` — mandaria ao ambiente clínico quem acumula, inclusive quando veio ver a carteira do próprio animal. O papel viaja em `meta` da rota, e não em query, porque o endereço é o que se guarda nos favoritos.

**Entrar pela porta de um papel que a conta não tem não é recusa.** A credencial estava certa: a sessão abre, e a tela oferece criar o cadastro que falta — ou seguir para o ambiente que já é seu. Tratar isso como erro de acesso mandaria a pessoa conferir uma senha que estava correta.

**Mais de um estabelecimento por conta continua previsto.** RF09 admite vínculo simultâneo com vários prestadores e RF09b alterna o contexto ativo entre eles — os vínculos além do primeiro chegam por convite (§9.28); o que não se repete é a conta. "Uma vez cada" vale para o que o esquema já garantia: um cadastro de tutor por conta, e um vínculo por papel em cada prestador.

**Nota sobre os códigos de tela, descoberta ao escrever esta entrada.** O inventário do briefing (§7) fixa **P03 = criar conta de tutor** (`/criar-conta`) e **P04 = cadastrar prestador** (`/cadastrar-prestador`). Vinte e uma das cinquenta e três citações espalhadas pelo repositório invertiam os dois, em quinze arquivos — comentários de código e quatro menções em três entradas anteriores deste documento (§9.12, §9.19 e §9.22) —, provavelmente porque a ordem em que as duas telas foram construídas é a inversa da ordem em que o briefing as numera. A inversão era só de citação: o comportamento descrito em cada trecho estava correto, e nenhum código mudou. Todas foram uniformizadas pelo inventário nesta fatia.

Quatro citações que pareciam suspeitas **não** foram alteradas, porque estão certas: `SenhaForte` e `PasswordChecklist` citam P03 para o checklist de senha, que é mesmo da tela do tutor; `VerifyEmailView` e os dois "fora da transação, como em P03" citam um caminho que as duas telas percorrem, e P03 é referente legítimo.

**"O que esta conta é", em T18.** A acumulação de papéis é invisível até alguém precisar dela, e quem não a vê conclui que precisa de uma segunda conta — que é exatamente como este defeito chegou. A seção lista os papéis vigentes e oferece o que falta, e é também o caminho de quem não passou por uma tela de entrada.

## 9.28 A tela pública de cadastro não lê a sessão de quem a preenche

Encontrado em uso (23/09/2026), no mesmo dia de §9.27 e por causa dela: quem cadastrava um profissional autônomo em P04 já não via os campos de e-mail e senha, e lia, no terceiro passo, que o estabelecimento seria cadastrado "na conta que você já usa" — com o endereço de quem estava no navegador. A pergunta de quem encontrou foi a certa: como o sistema sabe que aquela é a conta do profissional que está sendo cadastrado?

**Não sabe, e não tinha como saber.** O que a tela lia era o cookie de sessão do navegador, não o formulário. §9.27 tratou a sessão como prova de titularidade, e ela prova apenas quem está logado ali — que é a mesma pessoa com frequência, mas não sempre: quem cadastra pode estar abrindo o consultório de um colega, ou o de um cliente, no próprio computador. Nesses casos o cadastro criava `admin_prestador` **e** `veterinario` na conta de quem apenas digitou, e carimbava nela o CRMV de outra pessoa — que é justamente o que RN22 manda preservar em cada registro assinado.

**P04 voltou a pedir endereço e senha de quem quer que a envie.** Sem ramo, sem consulta a `/api/sessao` na montagem, sem campo `prohibited` no servidor: uma tela de porta, aberta a quem ainda não tem conta, que cria o estabelecimento e a conta que o administra no mesmo ato, como RF07 descreve. A ambiguidade não vinha da regra, e sim de deixar a tela adivinhar de quem é o cadastro.

**O caminho de quem já está no Imunia é o convite de A03.** Vale para o vínculo com estabelecimento que já existe e para o segundo vínculo de RF09, e é o que tem prova do lado certo: quem administra a conta atesta quem entra, em vez de a tela inferir do navegador. A oferta de T18 e a da porta do veterinário (P02) passaram a dizer isso — e a dizer, sem rodeio, que cadastrar um estabelecimento abre a conta administradora dele, com endereço e senha próprios.

**O que §9.27 resolveu continua de pé onde a prova existe.** O cadastro de tutor autenticado (`POST /api/conta/tutor`) não muda: ali a pessoa está na própria conta, pede o próprio cadastro e presta o próprio consentimento. Larissa continua tutora e veterinária com um endereço só — o que ela não faz mais é abrir um consultório a partir de uma tela pública que presumiu ser dela.

## 9.29 T18 não anuncia mais os papéis da conta

Pedido em uso (23/09/2026): a seção "O que esta conta é" saiu da tela de conta — a relação de papéis vigentes e as duas ofertas que §9.27 acrescentara ali.

**A tela voltou a ser o que o título dela diz.** T18 responde por dados pessoais, senha e sessão (RF04, RF06): o que se edita da própria conta. A relação de papéis não se edita, e a oferta de cadastrar um estabelecimento aparecia justamente a quem não atende — quem entrou para ver a carteira do próprio cão lia, no fim da tela, um convite a abrir clínica.

**O caminho de quem descobre que lhe falta um papel continua de pé, e chega na hora certa.** As portas de P02 dizem isso a quem entrou com a credencial certa e o papel que falta (§9.27), e é ali que a pessoa esbarra na falta; `/conta/tutor` e `/cadastrar-prestador` seguem alcançáveis, e a recusa de P04 continua apontando o convite de A03 (§9.28).

**O que se perde, dito por inteiro.** A acumulação de papéis (RN05) fica sem lugar onde se explique sozinha — era esse o argumento de §9.27 para a seção —, e quem chega a T18 sem ter passado por uma porta de entrada não encontra mais a explicação. `GET /api/conta` continua devolvendo `conta.papeis`, agora sem leitor no frontend: o contrato não mudou para não fazer de uma retirada de tela uma mudança de API.

---

*Documento gerado como referência reutilizável entre as fases do projeto. Deve ser anexado ao início de cada nova conversa.*
