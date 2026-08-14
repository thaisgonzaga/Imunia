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

---

*Documento gerado como referência reutilizável entre as fases do projeto. Deve ser anexado ao início de cada nova conversa.*
