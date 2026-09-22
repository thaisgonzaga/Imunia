---
title: "Imunia — Briefing de Design de Interface"
subtitle: "Etapa 4 do Trabalho de Conclusão de Curso: projeto da interface"
date: "Agosto de 2026 — versão 1.0"
lang: pt-BR
---

# 0. Como usar este briefing

Este documento é a especificação de projeto de interface do **Imunia**, derivada integralmente do documento de decisões (Etapa 1), do estudo de caso (Etapa 2) e do documento de requisitos v1.2 (Etapa 3). Toda tela aqui descrita responde a requisito funcional identificado; toda restrição visual responde a requisito não funcional ou a regra de negócio. Nenhum elemento foi incluído por convenção de mercado.

**Destinatário.** O Claude Design (ou qualquer ferramenta de geração de interface). O documento é longo por decisão: interface gerada a partir de briefing vago produz telas plausíveis e erradas, e neste sistema o erro de interface é erro de conformidade — a tela que exibe o nome de um tutor antes da autorização viola a LGPD tanto quanto a consulta que o faz.

**Sequência recomendada de trabalho.** Não peça as cinquenta telas de uma vez. Gere na ordem abaixo, validando cada bloco antes de seguir:

1. Sistema de design (seção 4) e biblioteca de componentes (seção 5), isoladamente, como *style guide* navegável;
2. Telas do tutor em largura de 360 px (T01 a T08), que são o caso de uso mais crítico de responsividade;
3. Telas do veterinário em 1440 px (V01 a V09), que são o caso de uso mais crítico de densidade;
4. Telas públicas e de autenticação (P01 a P09);
5. Administração do prestador (A01 a A03) e da plataforma (X01, X02);
6. Telas remanescentes e revisão de consistência.

**Prompt de abertura sugerido.**

> Você é o designer de produto do Imunia, sistema web de gestão de saúde animal para cães e gatos. Leia o briefing anexo por inteiro antes de desenhar. Comece produzindo apenas o *style guide*: paleta com os nomes e valores exatos da seção 4.1, escala tipográfica da 4.2, grade e espaçamento da 4.3, e a biblioteca de componentes da seção 5, em tema claro. Não invente cores, fontes ou raios fora dos definidos. Textos de interface em português do Brasil; nomes de componentes e de arquivos em inglês. Ao terminar, aguarde antes de desenhar telas.

**Prompt por tela sugerido.**

> Desenhe a tela `[código] — [nome]` conforme a seção 8 do briefing, usando exclusivamente os tokens e componentes já definidos. Entregue três larguras: 360, 768 e 1440 px. Inclua, além do estado normal, os estados de carregamento, vazio e erro descritos. Use dados de exemplo do cenário: tutora Helena Ramos, cão Théo (SRD, 18 meses), gata Nina (adulta, sem histórico), Clínica Vet Amigo, Dr. Marcelo Andrade (CRMV-MG 12345).

**Convenção de idioma.** Todo texto visível ao usuário é em português do Brasil. Nomes de componentes, arquivos, props e classes são em inglês, conforme a convenção de código do projeto. Persiste, do documento de requisitos (§10, item 1), a pendência sobre o idioma dos identificadores de banco; ela não afeta a camada de interface e não precisa ser resolvida aqui.

---

# 1. O produto em uma página

**O que é.** Plataforma web multi-inquilino de controle de calendário vacinal e prontuário eletrônico veterinário, restrita a cães e gatos.

**A tese do produto, em uma frase.** O dado clínico pertence ao animal, não ao estabelecimento que o produziu — e quem decide quem o vê é o tutor.

**A inversão que define a interface.** O médico-veterinário é o único autor de informação clínica. O tutor identifica o seu animal e consulta; não escreve prontuário. O pessoal administrativo não acessa dado algum de tutor, animal ou registro clínico. Essa fronteira precisa estar **visível na interface**, e não apenas garantida no servidor: quem olha uma tela do Imunia deve conseguir dizer, sem explicação, quem escreveu cada coisa.

**Os três usuários e seus contextos de uso.**

| | Helena, a tutora | Dr. Marcelo, o veterinário | Dra. Larissa, a autônoma |
|---|---|---|---|
| Papel | `tutor` | `veterinario` | `veterinario` + `admin_prestador` |
| Dispositivo | Telefone celular, quase sempre | Desktop no consultório | Telefone e tablet, em domicílio |
| Frequência | Baixa e episódica; entra quando recebe um lembrete | Diária e intensiva; várias vezes por atendimento | Diária, em campo |
| Competência digital | Alta para aplicativos comuns, nenhuma para jargão técnico | Alta no domínio, impaciente com formulário | Alta, mas sem apoio de recepção |
| O que não tolera | Sentir-se responsável por decisão técnica | Formulário que não cabe na consulta | Interface que pressupõe balcão e desktop |

**A consequência de projeto mais importante.** São dois produtos com um único sistema de design: um ambiente **de consulta**, móvel, generoso em espaço e explicativo em linguagem, e um ambiente **de registro**, denso, rápido, orientado a teclado. Não os unifique em um meio-termo. Componentes e tokens são compartilhados; densidade, navegação e ritmo tipográfico não são.

**Métricas de usabilidade que o design precisa atender** (RNF13 a RNF17, verificáveis):

- Tela do tutor operável em 360 px sem rolagem horizontal;
- Concessão de autorização concluída pelo tutor em, no máximo, **quatro passos** a partir da tela inicial;
- Registro de vacinação concluído pelo veterinário em até **noventa segundos**, incluindo lote e validade;
- Toda informação exibida indica sua origem e sua confiabilidade;
- Mensagens de erro e confirmação em linguagem compreensível a leigo, sem termos de implementação.

---

# 2. Princípios de projeto

Sete princípios, cada um derivado de requisito. Onde houver conflito entre eles e uma convenção estética, prevalece o princípio.

**P1 — A procedência é conteúdo de primeira classe.** Todo item clínico exibe, sempre e sem interação, quem o produziu: prestador, profissional e CRMV, ou a marca de não verificado. Nunca esconda a origem atrás de *hover*, *tooltip* ou tela de detalhe. (RNF16, RN24, RF35)

**P2 — A ausência de autorização é um estado de tela, não um erro.** O sistema exibe rotineiramente animais sobre os quais o prestador nada sabe. Essa tela precisa ser desenhada com o mesmo cuidado de uma tela cheia: identificação mínima, explicação do porquê, e um caminho único e claro para solicitar acesso. (RF13, RF18, RN12)

**P3 — Nada de irreversível sem antevisão do efeito.** Antes de confirmar revogação, encerramento de conta, registro imutável ou exportação, a interface enuncia em texto simples o que acontece **e o que não acontece**. A revogação, em particular, deve explicar que não apaga o prontuário da clínica. (RF39d, RF55c, RN26)

**P4 — O sistema calcula e sugere; o profissional decide.** Nenhuma tela bloqueia conduta clínica divergente. Alertas são informativos e acompanhados de campo de justificativa, jamais de botão desabilitado. (RN36, RF27c)

**P5 — Velocidade é requisito, não conforto.** No ambiente do veterinário: valores repetidos do último registro oferecidos como padrão, foco automático no primeiro campo, submissão por teclado, zero navegação entre passos para tarefas frequentes. (RNF15)

**P6 — Cor nunca é o único portador de significado.** Situação vacinal, procedência e espécie são comunicadas por ícone, forma e rótulo textual, com a cor como reforço. (Acessibilidade; RNF16)

**P7 — A interface não fala de si mesma.** Sem `prestador_id`, sem "erro 422", sem "token expirado". A mensagem descreve o que aconteceu no mundo e o que fazer em seguida. (RNF17)

---

# 3. Direção estética

**O mundo do assunto.** A carteira de vacinação brasileira é um caderninho pequeno, de capa colorida, preenchido à mão, onde o veterinário **cola o selo destacado do frasco da vacina** — fabricante, lote e validade impressos em tipografia miúda sobre papel adesivo. Esse selo é o artefato mais característico do domínio: é a prova material da aplicação, é exatamente o que a Resolução CFMV nº 1.653/2025 passou a exigir por escrito, e é o que se perde quando a carteira desbota ou some. O Imunia é, em boa medida, a digitalização desse selo.

**Elemento-assinatura: o selo de lote.** Todo registro de vacinação é apresentado como um bloco com marca de aplicação — um retângulo de cantos levemente arredondados, borda sólida, fundo branco levemente destacado do papel, com os dados de rastreabilidade (fabricante · lote · validade · via) em tipografia monoespaçada miúda, alinhada em grade, e a assinatura do profissional com CRMV no rodapé. É o componente pelo qual o sistema é reconhecido. Deve aparecer, com o mesmo desenho, na carteira digital, no histórico consolidado e no PDF exportado.

**Segundo elemento-assinatura: o trilho vacinal.** A série vacinal é uma sequência com significado temporal — primeira dose, intervalos, dose final com idade mínima, reforço, revacinação. Represente-a como um trilho: uma linha com estações, cada estação uma dose, com estado próprio. Horizontal no desktop, vertical no celular. A numeração das doses aqui é legítima porque a ordem carrega informação clínica real — não é ornamento sequencial.

**Restrições estéticas explícitas.** Não use: fundo creme com serifada de alto contraste e acento terracota; fundo quase preto com acento verde-ácido; diagramação de jornal com fios capilares e raio zero. São padrões genéricos e, num trabalho acadêmico, lidos como interface gerada sem direção. Não use ilustrações de mascotes, patinhas decorativas, gradientes coloridos ou emoji. O tom é o de um documento clínico bem desenhado: sóbrio, legível, com um único ponto de calor.

**Tema.** Claro, exclusivamente. Não projete tema escuro nesta etapa; registre-o como trabalho futuro.

---

# 4. Sistema de design (tokens)

Todos os valores abaixo são normativos. Nomes de token em inglês; a coluna "nome" traz a intenção em português para leitura humana.

## 4.1 Cor

**Neutros e superfícies**

| Token | Valor | Nome | Uso |
|---|---|---|---|
| `--surface-page` | `#F6F8F6` | papel | Fundo de todas as páginas |
| `--surface-card` | `#FFFFFF` | superfície | Cartões, tabelas, formulários |
| `--surface-sunken` | `#EDF1EE` | rebaixo | Fundos de campo, cabeçalho de tabela, áreas inativas |
| `--border-hairline` | `#E1E7E3` | traço | Bordas padrão, divisores |
| `--border-strong` | `#C3CDC7` | traço forte | Bordas de campo, contorno de componente interativo |
| `--ink` | `#14231F` | tinta | Texto principal |
| `--ink-muted` | `#56675F` | tinta suave | Rótulos, metadados, texto secundário |
| `--ink-faint` | `#7C8B84` | tinta fraca | Texto de apoio, contadores, marca d'água |

**Primária e ação**

| Token | Valor | Nome | Uso |
|---|---|---|---|
| `--brand` | `#0F5F4E` | verde-frasco | Marca, cabeçalhos, botão primário |
| `--brand-hover` | `#0B4C3E` | verde-frasco escuro | Estado *hover* do botão primário |
| `--brand-bright` | `#12866D` | verde-vivo | Ligações, ícones ativos, anel de foco |
| `--brand-wash` | `#E6F1ED` | verde lavado | Fundo de estado selecionado, faixa de contexto ativo |

**Semântica de situação vacinal e de sistema**

| Token | Valor | Nome | Significado exclusivo |
|---|---|---|---|
| `--status-ok` | `#0F5F4E` | em dia | Dose aplicada no prazo; protocolo em conformidade |
| `--status-due` | `#C98A12` | âmbar-lote | Próxima do vencimento; atenção sem urgência |
| `--status-due-text` | `#7A5407` | âmbar-texto | **Texto** em âmbar (o `--status-due` não tem contraste para texto) |
| `--status-late` | `#B4372E` | vermelho-atraso | Dose atrasada; falha de protocolo |
| `--status-late-wash` | `#FBEDEB` | atraso lavado | Fundo de linha ou cartão em atraso |
| `--unverified` | `#6B7A74` | não verificado | Registro pregresso, sem responsabilidade técnica |
| `--consent` | `#3B4E8C` | índigo-consentimento | **Uso exclusivo**: autorização, consentimento, auditoria de acesso, verificação pública |
| `--consent-wash` | `#EAEDF7` | índigo lavado | Fundos de bloco de autorização e de auditoria |

O índigo é o token mais disciplinado do sistema: aparece **somente** onde o assunto é quem pode ver o quê. Autorizações, códigos de confirmação, registro de acessos e verificação pública de documento. Em nenhum outro lugar. Essa regra faz com que o usuário aprenda, sem que ninguém lhe explique, que aquela cor significa consentimento.

**Regras de cor**

- Contraste mínimo de 4.5:1 para texto e 3:1 para elementos de interface (WCAG 2.1 AA).
- `--status-due` nunca é cor de texto; use `--status-due-text`.
- Situação vacinal sempre acompanhada de ícone e rótulo textual (P6).
- Registro não verificado usa `--unverified` **mais** hachura diagonal a 45° na borda esquerda de 4 px. Nunca verde, nunca âmbar: não é uma situação intermediária, é ausência de responsabilidade técnica.
- Nenhum verde para "não verificado" e nenhum vermelho para "óbito". Óbito é neutro (`--ink-muted`), com ícone e tarja discreta: não é erro nem alerta.

## 4.2 Tipografia

| Papel | Família | Justificativa |
|---|---|---|
| Display | **Bricolage Grotesque** (variável, pesos 500–700) | Grotesca com largura variável e desenho próprio; usada com parcimônia, dá personalidade sem custar legibilidade |
| Corpo e interface | **Public Sans** (400, 500, 600) | Fonte de sistema cívico, altamente legível em corpo pequeno e denso; o registro institucional combina com o caráter normativo do sistema (CFMV, LGPD) |
| Utilitária / dados | **IBM Plex Mono** (400, 500) | **Obrigatória** para identificadores que precisam ser conferidos caractere a caractere: código único do animal, lote, CRMV, CPF, resumo criptográfico, código de confirmação |

A escolha da monoespaçada é funcional, não decorativa: são exatamente os campos que alguém compara com um frasco, um documento ou um QR Code na mão.

**Escala** (base 16 px; `rem`)

| Token | Tamanho / entrelinha | Família | Uso |
|---|---|---|---|
| `--text-display` | 36 / 40 px, peso 600, *tracking* −0.02em | Bricolage | Título de página em desktop; usar no máximo uma vez por tela |
| `--text-h1` | 28 / 34 px, peso 600 | Bricolage | Título de página em celular; título de seção maior |
| `--text-h2` | 22 / 28 px, peso 600 | Bricolage | Cabeçalho de cartão e de bloco |
| `--text-h3` | 18 / 24 px, peso 600 | Public Sans | Subtítulo, cabeçalho de grupo de campos |
| `--text-body` | 16 / 24 px, peso 400 | Public Sans | Corpo padrão (obrigatório no ambiente do tutor) |
| `--text-body-sm` | 14 / 20 px, peso 400 | Public Sans | Corpo em tabelas e formulários densos do veterinário |
| `--text-label` | 13 / 16 px, peso 600, caixa alta, *tracking* 0.06em | Public Sans | Rótulos de campo, cabeçalho de coluna, sobrelinha |
| `--text-meta` | 12 / 16 px, peso 400 | Public Sans | Metadados, notas de rodapé, carimbos de data |
| `--text-mono` | 13 / 18 px, peso 500 | IBM Plex Mono | Identificadores; números tabulares sempre ativos |

**Regras.** Numerais tabulares (`font-variant-numeric: tabular-nums`) em toda tabela, data e valor. Nunca abaixo de 12 px. Nenhum texto em caixa alta acima de 13 px. Comprimento de linha entre 45 e 75 caracteres em blocos de leitura corrida (avisos de consentimento, explicações de revogação).

## 4.3 Grade, espaçamento e forma

**Espaçamento** — escala de 4: `4, 8, 12, 16, 24, 32, 48, 64, 96`. Nada fora dela.

**Raios** — `--radius-xs 4` (chips, selos), `--radius-sm 8` (campos, botões), `--radius-md 12` (cartões), `--radius-lg 16` (modais, folhas), `--radius-pill 999` (etiquetas de situação). Cantos vivos apenas na hachura de não verificado.

**Elevação** — o sistema é **orientado a borda**, não a sombra. Apenas duas sombras existem:
`--shadow-overlay: 0 4px 16px rgba(20,35,31,.10)` para menus e *popovers*; `--shadow-modal: 0 16px 48px rgba(20,35,31,.18)` para modais e folhas. Cartões usam borda `--border-hairline`, sem sombra.

**Grade.** Doze colunas, medianiz de 24 px, largura máxima de conteúdo 1280 px no ambiente do veterinário e 880 px no ambiente do tutor em desktop. Em celular, coluna única com margem lateral de 16 px.

**Pontos de quebra**

| Nome | Largura | Comportamento |
|---|---|---|
| `base` | 360–479 px | Referência obrigatória de projeto do ambiente do tutor |
| `sm` | 480–767 px | Celular grande; ainda coluna única |
| `md` | 768–1023 px | Tablet; duas colunas; barra lateral colapsa em gaveta |
| `lg` | 1024–1439 px | Desktop; barra lateral fixa e estreita (72 px, só ícones) |
| `xl` | ≥ 1440 px | Referência obrigatória de projeto do ambiente do veterinário; barra lateral expandida (240 px) |

**Alvos de toque.** Mínimo 44 × 44 px em qualquer largura abaixo de 1024 px. Espaçamento vertical mínimo de 8 px entre alvos adjacentes.

## 4.4 Iconografia

**Conjunto único: Lucide** (`lucide-vue-next`), traço 1.75 px, tamanhos 16 / 20 / 24 px apenas. Nenhum ícone de outro conjunto, nenhum emoji, nenhum ícone preenchido misturado a contornado.

Vocabulário fixo — o mesmo conceito usa sempre o mesmo ícone:

| Conceito | Ícone | Conceito | Ícone |
|---|---|---|---|
| Vacinação | `syringe` | Autorização / consentimento | `key-round` |
| Atendimento / prontuário | `stethoscope` | Acesso registrado em log | `eye` |
| Animal (genérico) | `paw-print` | Auditoria / histórico de acessos | `history` |
| Cão / gato | `dog` / `cat` | Documento verificável | `file-check` |
| Em dia | `circle-check` | Código / QR | `qr-code` |
| Próxima do vencimento | `clock-alert` | Prestador | `building-2` |
| Atrasada | `triangle-alert` | Profissional | `user-round-check` |
| Não verificado | `circle-help` | Notificação | `bell` |
| Registro imutável | `lock` | Retificação | `file-pen-line` |
| Retorno programado | `calendar-clock` | Óbito | `moon` |

Espécie é indicada por ícone **e** rótulo textual, nunca por cor.

## 4.5 Movimento

Contido e funcional. Durações: 120 ms para retorno de toque, 200 ms para entrada de elemento, 280 ms para folha ou modal. Curva `cubic-bezier(.2,0,0,1)`. Animações permitidas: entrada de folha inferior no celular, revelação de linha em tabela, transição de estado do trilho vacinal ao registrar dose, pulso único do anel de foco no campo de código de confirmação. Proibidas: rolagem paralaxe, contadores animados, entrada escalonada de cartões, qualquer laço contínuo. Respeitar `prefers-reduced-motion`: sob ele, transições limitam-se a variação de opacidade em 100 ms.

## 4.6 Foco e acessibilidade

- Anel de foco visível em **todo** elemento interativo: contorno 2 px sólido `--brand-bright`, deslocamento 2 px, raio herdado. Nunca `outline: none` sem substituto.
- Ordem de tabulação segue a ordem visual. Modais aprisionam o foco e o devolvem ao gatilho ao fechar.
- Todo campo tem rótulo persistente acima, não apenas *placeholder*. Rótulo flutuante é proibido: some quando o usuário mais precisa dele.
- Erros de validação: texto abaixo do campo, ícone à esquerda, borda `--status-late`, e o campo recebe `aria-invalid`. Um resumo dos erros no topo do formulário quando houver mais de três.
- Regiões dinâmicas (contadores de pendência, resultado de verificação) anunciadas por `aria-live="polite"`.
- Nenhuma informação transmitida somente por cor, forma ou posição.

---

# 5. Biblioteca de componentes reutilizáveis

Nomes em inglês, prontos para virar componentes Vue 3 com `<script setup>`. Cada verbete traz variantes e estados. Um componente que aparece em duas telas deve ter exatamente o mesmo desenho nas duas.

## 5.1 Componentes de base

**`AppButton`** — variantes `primary` (fundo `--brand`, texto branco), `secondary` (fundo branco, borda `--border-strong`), `ghost` (sem borda, texto `--brand-bright`), `danger` (borda e texto `--status-late`, fundo branco; preenchido apenas na confirmação final de ação destrutiva). Tamanhos `sm` 32 px, `md` 40 px, `lg` 48 px (obrigatório em celular). Estados: normal, *hover*, foco, ativo, desabilitado (opacidade 0.45, cursor `not-allowed`), carregando (rótulo permanece, ícone substituído por indicador; largura não muda). O rótulo é sempre um verbo no infinitivo ou imperativo que descreve o efeito: "Registrar vacinação", "Conceder autorização", "Revogar acesso" — nunca "Enviar", "OK", "Confirmar" sozinho.

**`AppInput` / `AppSelect` / `AppTextarea` / `AppDatePicker`** — rótulo persistente `--text-label` acima, campo com borda `--border-strong` e fundo branco, altura 40 px (48 em celular), texto de apoio opcional em `--text-meta`, contador de caracteres quando houver limite. `AppDatePicker` aceita digitação em `dd/mm/aaaa` e abre calendário; nunca exige o calendário. Campos de identificador (`código`, `lote`, `CPF`, `CRMV`, `micro-chip`) usam `IBM Plex Mono` no valor digitado e máscara visível.

**`OtpInput`** — seis casas separadas, monoespaçada, avanço automático, colagem inteligente, contador regressivo de validade abaixo, ligação "Reenviar código" desabilitada até o fim do contador. Fundo `--consent-wash`. Usado apenas na confirmação de autorização (RF37).

**`AppTable`** — cabeçalho `--surface-sunken`, `--text-label`, divisores `--border-hairline`, linhas de 48 px, zebra desligada (o divisor basta), coluna de ação alinhada à direita e fixa na rolagem horizontal. Ordenação por clique no cabeçalho com indicador. Em `md` e abaixo, a tabela **se converte em lista de cartões** — nunca rolagem horizontal em celular.

**`FilterBar`** — campo de busca com ícone à esquerda, seletores de filtro em pílula, contador de resultados à direita, ligação "Limpar filtros" visível apenas quando há filtro ativo. Filtros aplicados aparecem como pílulas removíveis abaixo.

**`Pagination`** — "Exibindo 1–20 de 137", setas e salto direto. Em celular, botão "Carregar mais".

**`AppModal` / `BottomSheet`** — o mesmo conteúdo renderiza como modal centrado (≥ 768 px) e como folha inferior arrastável (< 768 px). Cabeçalho com título e fechar, corpo rolável, rodapé fixo com ações (primária à direita em desktop; empilhadas, primária no topo, em celular).

**`ConfirmDialog`** — específico para ações irreversíveis. Estrutura obrigatória: título que nomeia a ação; um bloco **"O que acontece"** e um bloco **"O que não acontece"**, ambos em lista; e, nos casos mais graves (encerrar conta, revogar), campo de confirmação por digitação. Cumpre P3.

**`Toast`** — canto inferior direito em desktop, topo em celular. Quatro tons: sucesso, informação, atenção, erro. Duração 5 s, com ação de desfazer quando a operação for desfazível — e apenas então. Nenhum *toast* para ação irreversível: essas confirmam-se com mudança de estado na própria tela.

**`EmptyState`** — ícone 32 px em `--ink-faint`, título em `--text-h3`, uma frase explicando o porquê do vazio, e **sempre** uma ação. Vazio nunca é uma tela morta.

**`SkeletonLoader`** — blocos em `--surface-sunken` com animação de brilho de 1.2 s, na forma exata do conteúdo que substituem. Obrigatório em painéis e listas; proibido substituí-lo por indicador circular centralizado.

**`AppBadge`** — pílula, `--text-meta` peso 600, altura 22 px, ícone 14 px opcional à esquerda.

## 5.2 Componentes de domínio — o coração do sistema

**`ProvenanceChip`** — o componente mais importante do sistema. Declara a origem de qualquer informação clínica. Cinco variantes, visualmente inconfundíveis:

| Variante | Desenho | Rótulo |
|---|---|---|
| `professional` | Borda sólida `--border-strong`, ícone `user-round-check`, fundo branco | "Clínica Vet Amigo · Dr. Marcelo Andrade · CRMV-MG 12345" |
| `unverified` | Borda tracejada `--unverified`, faixa hachurada 4 px à esquerda, ícone `circle-help` | "Histórico pregresso — não verificado · lançado por Helena Ramos em 12/01/2026" |
| `owner-declared` | Borda pontilhada `--consent`, ícone `user-round` | "Declarado pelo tutor — aguarda confirmação profissional" |
| `other-provider` | Como `professional`, mais ícone `eye` ao final | "Outro prestador · esta visualização é registrada" |
| `rectified` | Como `professional`, com tarja `--status-due` e ícone `file-pen-line` | "Retificado em 03/02/2026 — ver retificação" |

Regra: nenhum registro clínico é renderizado em qualquer tela sem um `ProvenanceChip`. Se o desenho não comporta o chip, o desenho está errado.

**`BatchSeal` (selo de lote)** — o elemento-assinatura. Bloco de 1 px de borda `--border-strong`, raio `--radius-xs`, fundo branco, com grade interna de duas colunas em `IBM Plex Mono` 13 px: `FABRICANTE`, `LOTE`, `VALIDADE`, `VIA`. Rodapé separado por fio, com o `ProvenanceChip` do aplicador. Variante `expired`: tarja `--status-late` no topo com o texto "Aplicada com validade expirada — confirmada pelo profissional". Variante `unverified`: hachura e campos ausentes exibidos como "não informado" em `--ink-faint`, jamais em branco.

**`VaccineRail` (trilho vacinal)** — representa a série de um imunobiológico. Linha de 2 px com estações circulares de 24 px. Estados de estação: aplicada (preenchida `--status-ok`, ícone `circle-check`), prevista (contorno `--border-strong`, tracejado), próxima (contorno `--status-due`, ícone `clock-alert`, com data em destaque), atrasada (preenchida `--status-late`, ícone `triangle-alert`), extra (estação adicional inserida por regra de idade mínima, com marcador losangular e nota "dose adicional — dose final aplicada antes de 16 semanas"). Cada estação exibe rótulo ("1ª dose"), data e, ao tocar, abre o detalhe. Horizontal com rolagem em desktop; **vertical** em celular. Sob a linha, uma nota explicativa em linguagem de leigo com a regra aplicada e a versão do protocolo: "Intervalo de 21 dias — protocolo WSAVA, versão 2024.1".

**`StatusPill`** — situação de dose: `em dia` (verde, `circle-check`), `próxima` (âmbar, `clock-alert`, com "em 6 dias"), `atrasada` (vermelho, `triangle-alert`, com "há 42 dias"), `não verificada` (cinza hachurado, `circle-help`). Sempre ícone + texto.

**`AnimalCard`** — foto circular 56 px (ou ícone de espécie sobre `--surface-sunken` quando ausente), nome em `--text-h3`, linha de metadados (espécie · raça · idade), `StatusPill` da dose mais urgente, e — quando o cadastro for preliminar — tarja `--consent` com "Cadastro preliminar: aguarda caracterização por veterinário". Toque em toda a área do cartão.

**`AuthorizationCard`** — cartão em `--consent-wash`, borda `--consent`. Exibe prestador, animal, data de concessão, prazo com barra de tempo restante, e ação de revogar ou renovar. Variantes: vigente, a expirar (< 15 dias, âmbar), expirada, revogada (esmaecida, com data).

**`AccessLogRow`** — linha do registro de acessos: data e hora em monoespaçada, prestador, profissional, natureza do dado acessado, e ação "Revogar acesso" acionável ali mesmo.

**`ContextBanner`** — faixa fixa de 32 px abaixo do cabeçalho, fundo `--brand-wash`, exibindo "Contexto ativo: **Clínica Vet Amigo**" com seletor, quando o profissional tem vínculo com mais de um prestador. Exigência direta de RF09(b). Nunca ocultável.

**`ImmutableNotice`** — bloco informativo com ícone `lock`, exibido no topo de todo formulário de registro clínico antes da confirmação: "Depois de confirmado, este registro não pode ser alterado nem excluído. Correções entram como retificação vinculada, e as duas versões ficam visíveis."

**`ConsentNotice`** — bloco em `--consent-wash` com o texto de consentimento aplicável, usado na concessão de autorização e na exportação de PDF. Texto redigido para leigo, com no máximo 75 caracteres por linha.

**`VerificationResult`** — resultado da verificação pública: cartão grande, ícone 48 px, três estados — autêntico (`file-check`, `--consent`), não localizado (`circle-help`, `--ink-muted`), divergente (`triangle-alert`, `--status-late`). Exibe data de emissão, animal e resumo criptográfico em monoespaçada para conferência visual. **Nunca** exibe conteúdo clínico nem dados do tutor.

**`TimelineEntry`** — item do histórico consolidado: marcador na linha vertical por tipo (`syringe`, `stethoscope`, `file-pen-line`, `moon`), data, título, resumo de duas linhas, `ProvenanceChip` e indicador de anexos.

## 5.3 Estrutura de página

**`AppShell`** — três variações por papel:

- *Tutor*: cabeçalho de 56 px (marca à esquerda, sino de notificações e avatar à direita) e **barra de abas inferior** em celular com quatro destinos: Início · Animais · Compartilhamento · Conta. Em desktop, as abas viram barra lateral de 240 px e o conteúdo limita-se a 880 px.
- *Veterinário*: barra lateral persistente (240 px em `xl`, 72 px só-ícones em `lg`, gaveta abaixo disso), `ContextBanner`, cabeçalho com busca global sempre visível e botão primário "Registrar" com menu de duas opções (vacinação, atendimento). Em celular, barra inferior reduzida: Painel · Buscar · Registrar.
- *Administração*: barra lateral curta com três itens e, obrigatoriamente, um bloco fixo no rodapé da barra: "Este perfil administra a conta do prestador. Dados de tutores, animais e registros clínicos não são acessíveis por ele." — a explicação de uma ausência é parte do design (P2, RN08).

**`PageHeader`** — sobrelinha (categoria), título, subtítulo opcional, ações à direita, trilha de navegação quando houver mais de dois níveis.

---

# 6. Padrões transversais

## 6.1 Estados obrigatórios de tela

Toda tela que carrega dados precisa dos seis estados abaixo desenhados, e o briefing os exige nominalmente em cada verbete da seção 8:

1. **Carregando** — `SkeletonLoader` na forma do conteúdo.
2. **Vazio** — `EmptyState` com causa e ação.
3. **Erro de carregamento** — mensagem em linguagem simples e botão "Tentar novamente"; jamais código de erro visível.
4. **Sem autorização** — identificação mínima + explicação + caminho para solicitar (P2). Distinto de erro e distinto de vazio.
5. **Parcial** — dados carregados com algum bloco indisponível; o bloco falho se identifica sozinho, sem derrubar a tela.
6. **Sucesso após ação** — mudança de estado visível na própria tela, não apenas *toast*.

## 6.2 Voz da interface

- Rótulos de ação nomeiam o efeito e mantêm o mesmo verbo do começo ao fim do fluxo: o botão "Conceder autorização" produz a confirmação "Autorização concedida".
- Erros não pedem desculpas e não são vagos. "O código expirou. Peça um novo código para concluir a autorização." — não "Ops! Algo deu errado."
- Nada de jargão de implementação em texto visível (RNF17).
- Datas sempre por extenso abreviado com dia da semana quando for futura e relevante: "ter, 12 de agosto de 2026". Prazos relativos acompanham a data absoluta, nunca a substituem: "em 6 dias (12/08/2026)".
- Termos técnicos veterinários são mantidos (anamnese, imunobiológico), mas explicados em nota quando exibidos ao tutor.

## 6.3 Padrões de segurança visíveis

- **Nunca** exibir dado de tutor ou de animal antes de autorização vigente. Telas de busca por CPF e por código único exibem exclusivamente a existência do cadastro (RF13, RF18).
- Toda visualização de registro de outro prestador é precedida de aviso: bloco `--consent-wash` com "Este histórico foi produzido por outro prestador. Sua visualização será registrada e ficará visível ao tutor." O aviso aparece **antes** do conteúdo, porque a gravação do log é condição da exibição (RF52b).
- Cadastro com e-mail não verificado exibe tarja permanente e persistente nas telas pertinentes, com ação de reenvio (RF05b).
- Tutor não ativado é sinalizado nas telas do prestador (RF14b).
- Sessão: aviso 2 minutos antes da expiração por inatividade, com ação de continuar.

## 6.4 Responsividade — regras gerais

- Projete o ambiente do tutor a partir de 360 px e o do veterinário a partir de 1440 px; encontre-se no meio.
- Nenhuma rolagem horizontal em nenhuma largura, em nenhuma tela (RNF13, RF28b, RF50a).
- Tabelas viram cartões abaixo de 768 px, com as três colunas mais informativas promovidas ao cartão e o restante em revelação.
- Formulários longos do veterinário: duas colunas em `xl`, uma coluna abaixo de `lg`, com barra de ação fixa no rodapé da viewport em celular.
- Modais viram folhas inferiores abaixo de 768 px.
- Fotografia do animal é decorativa em desktop e funcional em celular (âncora de reconhecimento rápido); mantenha-a em ambos.

---

# 7. Inventário de telas

| Cód. | Tela | Rota | Papel | Requisitos | Prioridade |
|---|---|---|---|---|---|
| P01 | Página inicial pública | `/` | público | — | Importante |
| P02 | Entrar | `/entrar` | público | RF01 | Essencial |
| P03 | Criar conta de tutor | `/criar-conta` | público | RF12, RF05 | Essencial |
| P04 | Cadastrar prestador | `/cadastrar-prestador` | público | RF07 | Essencial |
| P05 | Recuperar senha | `/recuperar-senha` | público | RF03 | Essencial |
| P06 | Definir nova senha | `/redefinir-senha/:token` | público | RF03 | Essencial |
| P07 | Aceitar convite | `/convite/:token` | público | RF09, RF14 | Essencial |
| P08 | Confirmar endereço de e-mail | `/verificar-email/:token` | público | RF05 | Essencial |
| P09 | Verificar documento | `/verificar/:codigo` | público | RF47 | Essencial |
| T01 | Painel do tutor | `/inicio` | tutor | RF50 | Essencial |
| T02 | Meus animais | `/animais` | tutor | RF16 | Essencial |
| T03 | Cadastrar animal | `/animais/novo` | tutor | RF16, RF17 | Essencial |
| T04 | Perfil do animal | `/animais/:codigo` | tutor | RF16, RF17, RF19 | Essencial |
| T05 | Carteira de vacinação | `/animais/:codigo/carteira` | tutor | RF28, RF26 | Essencial |
| T06 | Detalhe da vacinação | `/animais/:codigo/vacinas/:id` | tutor, vet | RF25, RF30 | Essencial |
| T07 | Histórico consolidado | `/animais/:codigo/historico` | tutor, vet | RF35, RF52 | Essencial |
| T08 | Detalhe do atendimento | `/animais/:codigo/atendimentos/:id` | tutor, vet | RF31, RF32, RF33 | Essencial |
| T09 | Lançar histórico pregresso | `/animais/:codigo/pregresso/novo` | tutor, vet | RF29 | Essencial |
| T10 | Diretório de prestadores | `/prestadores` | tutor | RF11 | Essencial |
| T11 | Conceder autorização | `/autorizacoes/nova` | tutor | RF36, RF37 | Essencial |
| T12 | Minhas autorizações | `/autorizacoes` | tutor | RF41, RF39, RF40 | Essencial |
| T13 | Solicitações de acesso | `/solicitacoes` | tutor | RF38 | Importante |
| T14 | Quem acessou meus dados | `/acessos` | tutor | RF53 | Importante |
| T15 | Exportar histórico | modal sobre T04/T07 | tutor, vet | RF46 | Essencial |
| T16 | Preferências de notificação | `/conta/notificacoes` | tutor | RF44 | Essencial |
| T17 | Histórico de notificações | `/conta/notificacoes/enviadas` | tutor, vet | RF45 | Desejável |
| T18 | Minha conta | `/conta` | todos | RF04, RF06, RF54, RF55 | Essencial |
| T19 | Transferir titularidade | `/animais/:codigo/transferir` | tutor | RF21 | Desejável |
| V01 | Painel do veterinário | `/clinica/painel` | vet | RF48 | Importante |
| V02 | Pendências vacinais | `/clinica/pendencias` | vet | RF49 | Essencial |
| V03 | Buscar animal ou tutor | `/clinica/buscar` | vet | RF51, RF18, RF13 | Essencial |
| V04 | Cadastrar tutor | `/clinica/tutores/novo` | vet | RF12, RF13, RF14 | Essencial |
| V05 | Cadastrar animal | `/clinica/animais/novo` | vet | RF16, RF20 | Essencial |
| V06 | Ficha clínica do animal | `/clinica/animais/:codigo` | vet | RF19, RF35 | Essencial |
| V07 | Registrar vacinação | `/clinica/animais/:codigo/vacinar` | vet | RF25, RF26, RF27 | Essencial |
| V08 | Registrar atendimento | `/clinica/animais/:codigo/atender` | vet | RF31, RF32, RF34 | Essencial |
| V09 | Retificar registro | modal sobre T06/T08 | vet | RF33 | Essencial |
| V10 | Solicitar autorização | modal sobre V03/V06 | vet | RF38 | Importante |
| V11 | Registrar reação adversa | modal sobre T06 | vet | RF30 | Desejável |
| V12 | Registrar óbito | modal sobre V06 | vet | RF22 | Importante |
| A01 | Painel administrativo | `/prestador` | admin | RF08 | Importante |
| A02 | Dados do prestador | `/prestador/dados` | admin | RF08 | Importante |
| A03 | Equipe | `/prestador/equipe` | admin | RF09, RF10 | Essencial |
| X01 | Catálogo de imunobiológicos | `/plataforma/catalogo` | admin plataforma | RF23 | Essencial |
| X02 | Protocolos vacinais | `/plataforma/protocolos` | admin plataforma | RF24 | Essencial |
| E01 | Sem permissão (403) | qualquer | todos | RNF09 | Essencial |
| E02 | Não encontrado (404) | qualquer | todos | — | Essencial |
| E03 | Falha do sistema (500) | qualquer | todos | — | Essencial |

---

# 8. Especificação tela a tela

Cada verbete traz sete campos: **Objetivo**, **Componentes**, **Layout**, **Navegação**, **Interações**, **Estados**, **Responsividade**. Onde um campo repete regra já enunciada nas seções 4 a 6, o verbete apenas a invoca.

## 8.1 Telas públicas e de autenticação

### P01 · Página inicial pública — `/`

**Objetivo.** Explicar o que o sistema faz, em uma tela, para dois públicos distintos, e conduzir cada um ao seu ponto de entrada. Serve também como abertura da demonstração à banca.

**Componentes.** `PageHeader` público (marca, "Entrar", "Criar conta"); herói com a tese do produto; bloco de três diferenciais; `BatchSeal` de exemplo em tamanho real; campo de verificação pública de documento; rodapé com ligação para a rota `/verificar`.

**Layout.** Herói ocupando a primeira dobra: título em `--text-display` com a frase central — "O histórico do seu animal pertence a ele, não à clínica." —, subtítulo de duas linhas e dois botões (`primary` "Criar conta de tutor", `secondary` "Cadastrar meu estabelecimento"). À direita do herói, em `xl`, um `VaccineRail` vertical estático com três estações, servindo de demonstração silenciosa do produto. Abaixo, três blocos: calendário calculado, carteira verificável, autorização do tutor — cada um com ícone, título e duas frases. Ao final, faixa em `--consent-wash` com o campo "Recebeu um documento do Imunia? Verifique aqui" e campo monoespaçado para o código.

**Navegação.** Saídas: P02, P03, P04, P09.

**Interações.** Rolagem simples. O `VaccineRail` do herói anima uma única vez na carga (200 ms, respeitando `prefers-reduced-motion`). O campo de verificação valida formato antes de submeter.

**Estados.** Normal; campo de verificação com formato inválido; carregamento do resultado da verificação (leva a P09).

**Responsividade.** Em `base`, herói em coluna única, trilho abaixo do texto, botões empilhados em largura total. Faixa de verificação vira bloco de duas linhas.

### P02 · Entrar — `/entrar`

**Objetivo.** Autenticar por e-mail e senha, estabelecendo sessão por cookie, e encaminhar ao painel correspondente ao papel.

**Componentes.** Cartão centrado de 400 px; `AppInput` de e-mail e de senha (com alternância de visibilidade); caixa "Manter conectado"; `AppButton primary` "Entrar"; ligações "Esqueci minha senha" e "Criar conta"; área de mensagem de erro.

**Layout.** Cartão único, verticalmente centrado sobre `--surface-page`, marca acima do cartão, rodapé discreto com ligação para a verificação pública.

**Navegação.** Sucesso → T01 (tutor), V01 (veterinário), A01 (admin). Usuário com dois papéis → T01 ou V01 conforme o último contexto, com alternador acessível no menu do avatar. Saídas: P03, P05.

**Interações.** Foco automático no e-mail. Enter submete. Botão entra em estado de carregamento sem mudar de largura.

**Estados.** Normal; carregando; **erro genérico** — "E-mail ou senha incorretos." — idêntico para credencial inexistente e senha errada, sem qualquer pista sobre a existência da conta (RF01b); **bloqueio por tentativas** — "Muitas tentativas. Tente novamente em 15 minutos." com contador; e-mail não verificado (entra, mas T01 exibe a tarja de verificação pendente).

**Responsividade.** Em `base`, cartão ocupa a largura com margem de 16 px, sem sombra, sem borda; campos com 48 px de altura.

### P03 · Criar conta de tutor — `/criar-conta`

**Objetivo.** Permitir o autocadastro do tutor, sem depender de convite de prestador.

**Componentes.** Cartão de formulário; campos nome completo, CPF (máscara e monoespaçada), e-mail, senha, confirmação; medidor de força da senha com os critérios listados como itens verificáveis; caixa de aceite dos termos e do aviso de privacidade com ligação; `AppButton primary` "Criar conta".

**Layout.** Coluna única, campos empilhados, agrupados em "Seus dados" e "Acesso". Barra lateral em `xl` com três frases explicando o que o tutor pode fazer sozinho — cadastrar animais, lançar histórico pregresso, autorizar clínicas.

**Navegação.** Sucesso → P08 (aviso de verificação enviada) e, ao confirmar, T01 com estado de primeira visita.

**Interações.** CPF validado quanto aos dígitos verificadores no *blur*, com mensagem imediata. Critérios de senha marcam-se conforme o usuário digita. Botão desabilitado apenas enquanto houver campo obrigatório vazio.

**Estados.** Normal; validação por campo; **CPF já cadastrado** — mensagem "Já existe uma conta com este CPF." com ligação para P05, e nenhuma outra informação sobre a conta existente; carregando; sucesso.

**Responsividade.** Coluna única em todas as larguras; barra lateral some abaixo de `lg`.

### P04 · Cadastrar prestador — `/cadastrar-prestador`

**Objetivo.** Criar o inquilino e, simultaneamente, o primeiro usuário administrador.

**Componentes.** Assistente de três passos com `Stepper`: (1) o estabelecimento — tipo (clínica, hospital veterinário, profissional autônomo), razão social ou nome, CNPJ, endereço com município e UF, contato; (2) responsável técnico — nome, CRMV e UF de registro; (3) acesso — e-mail e senha do administrador.

**Layout.** Coluna de 640 px, `Stepper` horizontal no topo, ações no rodapé do cartão ("Voltar" e "Continuar"; no último passo, "Criar conta do estabelecimento").

**Navegação.** Sucesso → A01 com painel de primeira configuração.

**Interações.** O tipo escolhido no passo 1 **altera os rótulos** dos passos seguintes, sem alterar os campos (RF07a): escolhido "profissional autônomo", o passo 2 passa a dizer "Seus dados profissionais" e informa que os papéis de administração e de atendimento serão atribuídos à mesma conta. Resumo revisável antes da confirmação final.

**Estados.** Normal por passo; validação; CNPJ já cadastrado; carregando; sucesso.

**Responsividade.** `Stepper` vira indicador "Passo 2 de 3" com barra de progresso abaixo de `md`.

### P05 · Recuperar senha — `/recuperar-senha`

**Objetivo.** Iniciar a redefinição sem revelar quais endereços possuem conta.

**Componentes.** Cartão com um campo de e-mail e botão "Enviar instruções".

**Layout.** Idêntico a P02.

**Navegação.** Entrada a partir de P02; saída de volta a P02.

**Interações.** Submissão única; botão em carregamento.

**Estados.** Normal; carregando; **confirmação idêntica em qualquer caso** — "Se houver uma conta com este endereço, enviaremos as instruções em instantes. Verifique também a caixa de spam." (RF03); limite de taxa atingido.

**Responsividade.** Como P02.

### P06 · Definir nova senha — `/redefinir-senha/:token`

**Objetivo.** Concluir a redefinição por ligação de uso único.

**Componentes.** Campos de nova senha e confirmação; critérios verificáveis; aviso em `--consent-wash`: "Ao definir a nova senha, você será desconectado dos outros dispositivos."

**Layout.** Como P02.

**Navegação.** Sucesso → P02 com *toast* "Senha alterada. Entre com a nova senha."

**Interações.** Validação em tempo real dos critérios.

**Estados.** Normal; **ligação expirada** — tela própria, com explicação e botão "Pedir nova ligação"; **ligação já utilizada** — mesma tela, texto distinto; carregando; sucesso.

**Responsividade.** Como P02.

### P07 · Aceitar convite — `/convite/:token`

**Objetivo.** Ativar acesso de tutor cadastrado por prestador, ou de veterinário convidado por administrador.

**Componentes.** Cartão com identificação de quem convidou (nome do prestador e, no caso do veterinário, do administrador), resumo do que o convite concede, campos de senha, botão "Ativar meu acesso".

**Layout.** Cartão de 480 px com cabeçalho destacado exibindo o convidante.

**Navegação.** Sucesso do tutor → T01; do veterinário → V01 com `ContextBanner` do prestador convidante.

**Interações.** Variante do veterinário exibe CRMV e UF informados pelo administrador, para conferência, com aviso de que a correção deve ser solicitada ao administrador.

**Estados.** Normal (duas variantes); convite expirado com ação "Solicitar novo convite"; convite já utilizado; carregando; sucesso. A ativação verifica o endereço automaticamente e dispensa P08 (RF14c).

**Responsividade.** Como P02.

### P08 · Confirmar endereço de e-mail — `/verificar-email/:token`

**Objetivo.** Confirmar a titularidade do endereço, condição para o recebimento de lembretes.

**Componentes.** Cartão de estado com ícone grande, título, explicação de por que a verificação importa — "Sem o endereço confirmado, não conseguimos avisar você sobre as próximas doses." — e ação.

**Layout.** Cartão único centrado.

**Navegação.** Sucesso → T01 ou V01.

**Interações.** Variante "aguardando": exibe o endereço parcialmente mascarado e botão "Reenviar confirmação" com contador de 60 s.

**Estados.** Aguardando; confirmado; ligação expirada; reenviado.

**Responsividade.** Como P02.

### P09 · Verificar documento — `/verificar/:codigo`

**Objetivo.** Permitir que qualquer pessoa, sem conta, confirme a autenticidade de um PDF exportado — sem expor conteúdo clínico. É o destino do QR Code impresso e a tela que atende ao profissional fora da plataforma.

**Componentes.** `VerificationResult`; campo monoespaçado para digitação manual do código; bloco de conferência do resumo criptográfico; explicação em três linhas do que a verificação prova e do que não prova.

**Layout.** Coluna de 560 px centrada, cabeçalho público mínimo. O resultado ocupa o centro da tela, com o estado comunicado por ícone de 48 px, cor e título — nessa ordem de leitura.

**Navegação.** Entrada por QR Code, por P01 ou por digitação direta. Nenhuma saída para dentro do sistema além de "Conhecer o Imunia".

**Interações.** Ao entrar pelo QR, o código já vem preenchido e a verificação é automática. O resumo criptográfico é exibido em blocos de quatro caracteres, com botão de cópia, para comparação com o impresso no rodapé do PDF.

**Estados.** **Autêntico** — "Documento autêntico. Emitido em 12/01/2026, referente ao animal Théo (cão)." e nada mais; **não localizado**; **divergente** — "O conteúdo apresentado não corresponde ao documento emitido."; carregando; limite de taxa atingido, com mensagem neutra (a rota é protegida contra enumeração, RF47c).

**Responsividade.** Coluna única; campo de código com teclado numérico-alfabético e 48 px de altura em celular. É provável que a maior parte dos acessos venha de celular, por leitura de QR: projete esta tela primeiro em 360 px.

### E01–E03 · Telas de exceção

**Objetivo.** Comunicar impedimento sem expor detalhe de implementação.

**Componentes.** `EmptyState` em escala maior, com ícone 40 px, título, uma frase e ação de retorno.

**Layout.** Centrado na área de conteúdo, preservando a navegação do papel.

**Navegação.** Ação primária retorna ao painel do papel.

**Interações.** Nenhuma além da ação.

**Estados.** **E01 sem permissão** — "Você não tem acesso a esta página." e, quando a causa for ausência de autorização do tutor, o texto muda para o padrão de P2 com o caminho de solicitação; **E02 não encontrado**; **E03 falha do sistema** — "Não conseguimos carregar esta página. Tente novamente em instantes." com botão de nova tentativa e, discretamente, um identificador de ocorrência em monoespaçada para suporte (não é código de erro técnico).

**Responsividade.** Coluna única em todas as larguras.

## 8.2 Ambiente do tutor

Projetado a partir de 360 px. Corpo de texto em `--text-body` (16 px), nunca menor. Alvos de 48 px. Linguagem sem jargão.

### T01 · Painel do tutor — `/inicio`

**Objetivo.** Responder, na primeira dobra e sem rolagem, à única pergunta que traz Helena ao sistema: **o que está pendente para os meus animais?**

**Componentes.** Saudação curta; bloco "Precisa de atenção" com cartões de dose atrasada ou próxima; carrossel ou pilha de `AnimalCard`; bloco "Retornos programados"; tarja de verificação de e-mail pendente, quando for o caso; atalho "Autorizar uma clínica".

**Layout.** Ordem vertical fixa: (1) tarja de pendência de verificação, se houver; (2) bloco de atenção — cada item é um cartão com foto do animal, nome do imunobiológico, `StatusPill` e botão "Ver carteira"; (3) "Meus animais" em cartões; (4) retornos programados; (5) atalho de autorização. Se não houver pendência, o bloco (2) é substituído por uma confirmação positiva discreta: "Théo e Nina estão com as vacinas em dia." com ícone `circle-check`.

**Navegação.** Barra de abas inferior. Cartão de atenção → T05. `AnimalCard` → T04. Atalho → T10. Sino → lista de notificações.

**Interações.** Puxar para atualizar em celular. Cartões inteiramente clicáveis. Nenhuma ação destrutiva acessível a partir daqui.

**Estados.** Carregando (esqueleto de três cartões); **vazio de animais** — `EmptyState` "Cadastre seu primeiro animal" com ação; **vazio de pendências** — confirmação positiva; erro de carregamento; **e-mail não verificado** — tarja âmbar persistente com "Confirme seu e-mail para receber lembretes" e ação de reenvio (RF05).

**Responsividade.** `base`: coluna única, cartões em largura total, barra de abas inferior fixa. `md`: dois cartões por linha. `lg`+: barra lateral substitui as abas, conteúdo limitado a 880 px, blocos de atenção e animais lado a lado.

### T02 · Meus animais — `/animais`

**Objetivo.** Listar os animais sob titularidade do tutor e dar acesso ao cadastro de novos.

**Componentes.** `PageHeader` com ação "Cadastrar animal"; lista de `AnimalCard`; filtro de espécie e de situação, apenas quando houver mais de quatro animais; seção recolhida "Animais inativos" para óbitos e transferências.

**Layout.** Lista vertical de cartões de 88 px de altura, com foto à esquerda.

**Navegação.** Cartão → T04. Ação → T03.

**Interações.** Toque no cartão. Nenhum deslize para ação destrutiva: o tutor não exclui animal.

**Estados.** Carregando; vazio; erro; **cadastro preliminar** sinalizado por tarja no cartão; **animal com óbito registrado** exibido apenas na seção recolhida, com ícone `moon` e data, sem cor de alerta.

**Responsividade.** `base` coluna única; `md`+ grade de dois; `xl` grade de três dentro dos 880 px.

### T03 · Cadastrar animal — `/animais/novo`

**Objetivo.** Permitir que o tutor identifique seu animal — e deixar claro, sem constrangê-lo, que a caracterização clínica cabe ao veterinário.

**Componentes.** Campo de fotografia com recorte circular; nome; seletor de espécie em dois cartões grandes com ícones `dog` e `cat`; sexo e data de nascimento estimada, opcionais e no mesmo cartão de nome e espécie; `ConsentNotice` explicando a divisão de responsabilidade; botão "Cadastrar animal".

**Layout.** Coluna única. O seletor de espécie é o elemento visualmente mais destacado — é a única escolha irreversível da tela após o primeiro registro clínico. Abaixo dos campos opcionais, bloco informativo: "Raça, peso, situação reprodutiva e micro-chip são preenchidos pelo veterinário na primeira consulta. Até lá, o cadastro fica marcado como preliminar."

**Navegação.** Sucesso → T04 do animal recém-criado, com o código único em destaque e um *toast* discreto.

**Interações.** Fotografia opcional com pré-visualização, recorte e limites de formato e tamanho explicitados antes do envio. Campos opcionais entram marcados como "declarado por você" com o `ProvenanceChip owner-declared` visível na pré-visualização.

**Estados.** Normal; validação; **provável duplicidade** — quando já houver animal ativo do mesmo tutor, mesma espécie e nome semelhante, exibe-se, **antes** da confirmação, um bloco identificando o cadastro possivelmente equivalente com ações "Ver o cadastro existente" e "Cadastrar mesmo assim" (RF20a); carregando; sucesso; erro de envio da fotografia (o cadastro prossegue sem ela).

**Responsividade.** Coluna única em todas as larguras; barra de ação fixa no rodapé da viewport em celular.

### T04 · Perfil do animal — `/animais/:codigo`

**Objetivo.** Ser a página-âncora do animal: identidade, código único, situação vacinal resumida e portas para carteira, histórico e exportação.

**Componentes.** Cabeçalho com foto 72 px, nome em `--text-h1`, espécie com ícone e rótulo, idade calculada com indicação de data exata ou estimada; bloco de identificação com o código único em `IBM Plex Mono` e botão de QR Code; bloco "Caracterização" preenchido pelo veterinário ou vazio com explicação; resumo de situação vacinal com `VaccineRail` compacto; três ações principais: "Ver carteira", "Ver histórico", "Exportar PDF"; ações secundárias: "Lançar histórico pregresso", "Transferir titularidade".

**Layout.** Em celular, ordem: cabeçalho, código, situação, ações, caracterização. Em desktop, duas colunas: identidade e código à esquerda (320 px), situação e ações à direita.

**Navegação.** → T05, T07, T15, T09, T19.

**Interações.** Código único com botão de cópia e botão que abre modal com o QR Code em tamanho grande, apto a ser lido por outro dispositivo. Tarja de cadastro preliminar, quando aplicável, com o texto "Um veterinário ainda não completou a caracterização deste animal."

**Estados.** Carregando; erro; cadastro preliminar; **caracterização vazia** — `EmptyState` interno explicando que esses campos são privativos do veterinário, sem botão de edição algum; óbito registrado — cabeçalho neutro com tarja "Óbito registrado em ..." e supressão das ações de vacinação e de lembrete.

**Responsividade.** `base` coluna única com ações em largura total; `md`+ duas colunas.

### T05 · Carteira de vacinação — `/animais/:codigo/carteira`

**Objetivo.** Substituir a carteira de papel. É a tela mais importante do ambiente do tutor e o principal artefato de demonstração do sistema.

**Componentes.** `VaccineRail` por imunobiológico; `BatchSeal` para cada aplicação registrada; `StatusPill`; `ProvenanceChip` em cada registro; bloco de próximas doses; botão "Exportar PDF"; filtro por imunobiológico quando houver mais de três séries.

**Layout.** Agrupamento por imunobiológico, não por data — é como o tutor pensa ("a antirrábica está em dia?"). Cada grupo é um cartão contendo: nome do imunobiológico e classificação (essencial ou não essencial), o `VaccineRail` vertical em celular, e as aplicações como `BatchSeal` empilhados em ordem cronológica. Registros pregressos aparecem no mesmo trilho, com hachura e chip `unverified`, imediatamente distinguíveis. No topo, faixa de resumo: "2 vacinas em dia · 1 próxima do vencimento".

**Navegação.** `BatchSeal` → T06. "Exportar" → T15.

**Interações.** Toque na estação do trilho rola até a aplicação correspondente e a destaca por 1 s. Cada próxima dose prevista exibe, sob a data, a justificativa da regra em linguagem simples — "Reforço anual, contado a partir da última aplicação" — atendendo a RF26(b).

**Estados.** Carregando; **vazio** — "Nenhuma vacinação registrada ainda" com duas ações: "Lançar histórico pregresso" e "Encontrar uma clínica"; apenas pregresso — a tela funciona normalmente, com aviso de que nenhum registro foi feito por profissional na plataforma; com atraso — o grupo em atraso sobe para o topo e recebe fundo `--status-late-wash`; erro.

**Responsividade.** **Requisito verificável**: operável em 360 px sem rolagem horizontal (RF28b). Trilho vertical em `base` e `sm`; horizontal a partir de `md`. O `BatchSeal` mantém a grade de dois campos por linha em celular e quatro em desktop.

### T06 · Detalhe da vacinação — `/animais/:codigo/vacinas/:id`

**Objetivo.** Exibir integralmente o registro de uma aplicação, com toda a rastreabilidade exigida pela norma.

**Componentes.** `BatchSeal` em tamanho ampliado; dados completos — imunobiológico, fabricante, lote, validade, via, data e hora, ordem da dose na série; `ProvenanceChip professional`; bloco "Próxima dose" com data prevista, regra aplicada e versão do protocolo; bloco de reação adversa, quando houver; `ImmutableNotice` em versão informativa; ação "Retificar" visível somente ao veterinário autor.

**Layout.** Coluna única com o selo no topo, seguido de lista de definições em duas colunas (rótulo em `--text-label`, valor em monoespaçada quando for identificador).

**Navegação.** Voltar para T05. Para o veterinário autor, "Retificar" abre V09.

**Interações.** Nenhuma edição em nenhuma hipótese. Se o registro foi retificado, exibe-se no topo uma tarja com ligação para a retificação, e a retificação exibe ligação de volta ao original — o encadeamento é navegável nos dois sentidos (RF33b).

**Estados.** Normal; retificado; retificação (variante que exibe motivo e autoria); aplicado com validade expirada (tarja explicativa); pregresso não verificado (campos ausentes marcados, nenhuma ação de retificação disponível).

**Responsividade.** Lista de definições em duas colunas a partir de `md`, uma coluna abaixo.

### T07 · Histórico consolidado — `/animais/:codigo/historico`

**Objetivo.** Apresentar, em ordem cronológica única, tudo o que aconteceu com o animal, independentemente do prestador de origem.

**Componentes.** Linha do tempo vertical com `TimelineEntry`; filtros por tipo (vacinação, atendimento, exame, retificação, óbito) e por prestador; aviso de acesso registrado quando houver registro de outro prestador (visão do veterinário); botão "Exportar PDF".

**Layout.** Linha vertical à esquerda com marcadores por tipo; agrupamento por mês com cabeçalho fixo durante a rolagem; cada entrada com data, título, resumo de duas linhas, `ProvenanceChip` e contador de anexos.

**Navegação.** Entrada → T06 ou T08. "Exportar" → T15.

**Interações.** Filtros aplicam sem recarregar a página. Entradas de retificação aparecem vinculadas visualmente à entrada original por um conector, e não como itens soltos.

**Estados.** Carregando; vazio; filtrado sem resultado (com ação de limpar); **sem autorização** (visão do veterinário) — a linha do tempo é substituída pelo padrão P2; erro.

**Responsividade.** Marcadores reduzidos e resumo em uma linha em `base`; filtros viram folha inferior.

### T08 · Detalhe do atendimento — `/animais/:codigo/atendimentos/:id`

**Objetivo.** Exibir o prontuário de um atendimento, em leitura, com autoria e imutabilidade evidentes.

**Componentes.** Cabeçalho com data, hora, prestador e profissional com CRMV; seções nomeadas — motivo, anamnese, exame físico, hipóteses diagnósticas, diagnóstico, conduta; lista de anexos; bloco de retorno programado; `ImmutableNotice`; ação "Retificar" para o autor.

**Layout.** Coluna de leitura de 640 px, seções separadas por fio, rótulos em `--text-label`. Anexos como lista com ícone de tipo, descrição, data do exame e ação de abrir.

**Navegação.** Voltar para T07. Anexo abre visualizador em modal. "Retificar" → V09.

**Interações.** Termos técnicos exibidos ao tutor recebem nota explicativa em linguagem simples, acessível por toque no termo — "anamnese: o relato do que você contou ao veterinário". Anexos são servidos por rota autorizada; nenhum endereço direto é exposto.

**Estados.** Normal; retificado; retificação; sem anexos; anexo indisponível; sem autorização; erro.

**Responsividade.** Coluna única; visualizador de anexo em tela cheia no celular.

### T09 · Lançar histórico pregresso — `/animais/:codigo/pregresso/novo`

**Objetivo.** Permitir o registro de aplicações feitas fora da plataforma, com a marca permanente e irreversível de não verificado.

**Componentes.** `ConsentNotice` em destaque no topo; campos com obrigatoriedade reduzida — imunobiológico (do catálogo ou "não sei informar"), data aproximada, local ou origem em texto livre, fabricante, lote e validade, todos opcionais; pré-visualização ao vivo do `BatchSeal unverified` que será gerado; botão "Lançar registro não verificado".

**Layout.** Duas partes: formulário à esquerda, pré-visualização à direita em `lg`+; empilhados em celular, com a pré-visualização acima do botão.

**Navegação.** Sucesso → T05, com o novo registro destacado.

**Interações.** A pré-visualização atualiza a cada campo preenchido, mostrando exatamente como o registro aparecerá — hachurado, cinza, com "não informado" nos campos vazios. É a forma mais honesta de comunicar a consequência antes da ação.

**Estados.** Normal; **confirmação obrigatória** antes de salvar, com `ConfirmDialog`: "O que acontece: o registro entra na carteira marcado como não verificado, permanentemente. O que não acontece: esta marca não pode ser removida por ninguém, nem por um veterinário." (RF29a); carregando; sucesso.

**Responsividade.** Uma coluna abaixo de `lg`, com a pré-visualização recolhível.

### T10 · Diretório de prestadores — `/prestadores`

**Objetivo.** Ser o ponto de partida da autorização: encontrar o estabelecimento que atenderá o animal.

**Componentes.** Campo de busca por nome; filtro por município e UF; lista de cartões de prestador com nome, tipo, município e contato público; botão "Autorizar acesso" em cada cartão.

**Layout.** `FilterBar` no topo, lista de cartões abaixo. O cartão exibe **exclusivamente** informação pública de identificação e contato — nenhuma contagem de animais, de atendimentos ou de profissionais (RF11a).

**Navegação.** "Autorizar acesso" → T11 com o prestador pré-selecionado.

**Interações.** Busca com atraso de 300 ms. Filtro de município pré-preenchido pelo município do tutor, quando conhecido, com ação de limpar.

**Estados.** Carregando; vazio ("Nenhum estabelecimento encontrado em Viçosa, MG"); sem filtro (lista dos mais próximos); erro; **prestador já autorizado** — o botão vira etiqueta "Autorizado até 12/11/2026" com ligação para T12.

**Responsividade.** Cartões em coluna única até `md`; filtros em folha inferior no celular.

### T11 · Conceder autorização — `/autorizacoes/nova`

**Objetivo.** Executar o ato central do modelo de consentimento do sistema, em no máximo quatro passos a partir da tela inicial (RNF14), com confirmação por código.

**Componentes.** `Stepper` de três passos em `--consent-wash`: (1) escolher o prestador — pré-preenchido se veio de T10; (2) escolher o animal — lista de cartões com seleção múltipla explícita por animal, jamais um "selecionar todos" implícito; (3) confirmar — `ConsentNotice` completo e `OtpInput`.

**Layout.** Coluna de 560 px. Toda a tela usa a cor de consentimento: cabeçalho, `Stepper`, bordas dos cartões selecionados. O passo 3 é o momento mais importante do sistema e deve parecer diferente de qualquer outra tela.

**Navegação.** Sucesso → T12 com a nova autorização destacada e *toast* "Autorização concedida".

**Interações.** O passo 3 exibe, antes do campo de código, um resumo em texto corrido: "Você está autorizando a **Clínica Vet Amigo** a ver o histórico completo de **Théo** por **90 dias**, até 12/11/2026. Você pode revogar a qualquer momento." O código é enviado ao e-mail verificado e **nunca** exibido em tela (RF37c). Contador de validade visível; reenvio bloqueado até o fim do contador; número limitado de tentativas.

**Estados.** Por passo; **e-mail não verificado** — o fluxo é interrompido no passo 3 com explicação e ação de verificar (RF37b); código incorreto (com tentativas restantes); código expirado; limite de tentativas; carregando; sucesso.

**Responsividade.** `Stepper` vira "Passo 2 de 3". `OtpInput` com teclado numérico e casas de 44 px em celular.

### T12 · Minhas autorizações — `/autorizacoes`

**Objetivo.** Dar ao tutor o controle visível sobre quem vê o quê, e permitir revogação e renovação em um toque.

**Componentes.** Abas "Vigentes", "A expirar", "Encerradas"; lista de `AuthorizationCard`; ação primária "Autorizar uma clínica".

**Layout.** Agrupamento por animal, com o nome e a foto do animal como cabeçalho de grupo — a pergunta do tutor é "quem vê o Théo?", não "quais autorizações existem". Cada cartão traz prestador, data de concessão, prazo restante em barra e ações.

**Navegação.** "Autorizar uma clínica" → T10. Cartão → detalhe em folha inferior.

**Interações.** **Revogar** abre `ConfirmDialog` com a estrutura obrigatória: *O que acontece* — a clínica deixa imediatamente de ver o histórico produzido por outros prestadores; *O que não acontece* — os registros que essa clínica mesma criou continuam sob a guarda dela, porque o Conselho Federal de Medicina Veterinária exige que o prontuário seja preservado. Essa explicação é requisito, não cortesia (RF39d). **Renovar** estende o prazo em ato único, sem repetir o fluxo completo (RF40c).

**Estados.** Carregando; vazio com explicação do que é uma autorização; a expirar (âmbar, com aviso "expira em 12 dias"); expirada; revogada (esmaecida, com data e sem ações); erro.

**Responsividade.** Coluna única; ações em largura total no celular.

### T13 · Solicitações de acesso — `/solicitacoes`

**Objetivo.** Exibir os pedidos de autorização feitos por prestadores e permitir aceitar ou recusar.

**Componentes.** Lista de cartões em `--consent-wash` com prestador solicitante, animal, data do pedido e prazo de expiração; ações "Autorizar" e "Recusar".

**Layout.** Lista simples; contador na aba de navegação quando houver pendências.

**Navegação.** "Autorizar" entra diretamente no passo 3 de T11 (o prestador e o animal já estão definidos) — o que mantém o percurso dentro do limite de quatro passos.

**Interações.** "Recusar" não exige justificativa e não notifica motivo. A solicitação pendente não revela dado algum ao solicitante (RF38a) — e o texto da tela informa isso ao tutor: "Enquanto você não autorizar, esta clínica não vê nada sobre o Théo."

**Estados.** Carregando; vazio; solicitação expirada (esmaecida); recusada; erro.

**Responsividade.** Coluna única.

### T14 · Quem acessou meus dados — `/acessos`

**Objetivo.** Materializar a auditoria prevista na LGPD: mostrar ao tutor quem consultou o histórico de cada animal, quando e sob qual autorização. É, junto de T11, o artefato mais demonstrável do trabalho.

**Componentes.** Filtro por animal e por período; lista de `AccessLogRow`; bloco explicativo no topo.

**Layout.** Agrupamento por dia, com cabeçalho fixo. Cada linha: hora em monoespaçada, prestador, profissional com CRMV, natureza do dado acessado ("histórico de vacinação", "prontuário de 12/11/2025"), e ligação "Revogar acesso deste prestador".

**Navegação.** "Revogar" abre o mesmo `ConfirmDialog` de T12 (RF53b).

**Interações.** Nenhuma edição. Exportação da lista não é oferecida nesta etapa.

**Estados.** Carregando; **vazio** — "Ninguém acessou o histórico dos seus animais ainda." (estado positivo, não negativo); filtrado sem resultado; erro.

**Responsividade.** Linhas viram cartões abaixo de `md`, com hora e prestador no topo.

### T15 · Exportar histórico — modal sobre T04 ou T07

**Objetivo.** Gerar o PDF verificável e comunicar, antes da geração, a responsabilidade que o compartilhamento transfere ao tutor.

**Componentes.** `AppModal` com seleção de conteúdo (carteira de vacinação, histórico completo, período); pré-visualização em miniatura da primeira página; `ConsentNotice` com a advertência de RN46; botão "Gerar documento".

**Layout.** Modal de 560 px; após a geração, o conteúdo é substituído pelo resultado: identificador da emissão em monoespaçada, QR Code, data e hora, botões "Baixar PDF" e "Copiar link de verificação".

**Navegação.** Fecha para a tela de origem. O link de verificação aponta para P09.

**Interações.** A advertência é exibida **antes** do botão, não como nota de rodapé: "Ao compartilhar este arquivo, os dados saem do controle da plataforma e a responsabilidade pela difusão passa a ser sua." A geração exibe progresso quando ultrapassa 2 s; o critério de desempenho é de até 10 s para 200 registros (RNF04).

**Estados.** Configuração; gerando; pronto; falha na geração com nova tentativa; **sem registros no período selecionado** (botão desabilitado com explicação).

**Responsividade.** Folha inferior em celular, ocupando 90% da altura; botões em largura total.

### T16 · Preferências de notificação — `/conta/notificacoes`

**Objetivo.** Permitir descadastro granular por tipo, preservando as comunicações transacionais.

**Componentes.** Lista de tipos com interruptor: lembrete de dose prevista, aviso na data prevista, alerta de atraso, lembrete de retorno, aviso de expiração de autorização. Bloco separado, sem interruptores, para as comunicações que não podem ser desativadas.

**Layout.** Duas seções nitidamente separadas por fio e por cabeçalho: "Você escolhe receber" e "Sempre enviadas". A segunda seção lista confirmação de conta, redefinição de senha e código de autorização, com ícone `lock` e a explicação — "essas mensagens fazem a sua conta funcionar e não podem ser desativadas" (RF44b).

**Navegação.** A partir de T18 e do rodapé de qualquer e-mail recebido (RF44c).

**Interações.** Interruptor salva imediatamente, com *toast* e possibilidade de desfazer. Tarja no topo quando o e-mail não estiver verificado, explicando que nenhuma notificação será enviada até a confirmação.

**Estados.** Normal; salvando; e-mail não verificado; erro ao salvar (interruptor volta ao estado anterior com mensagem).

**Responsividade.** Coluna única.

### T17 · Histórico de notificações — `/conta/notificacoes/enviadas`

**Objetivo.** Responder à pergunta que o arquivo de papel não respondia: este tutor já foi avisado, e quando?

**Componentes.** Tabela com data e hora, tipo, animal, destinatário mascarado e situação de envio.

**Layout.** Tabela em desktop, lista de cartões em celular. Situação com `AppBadge`: enviada, falhou, reenviada.

**Navegação.** Item → detalhe em folha inferior.

**Interações.** Somente leitura. Na visão do veterinário, a tela abrange apenas notificações de animais sob autorização vigente (RF45a).

**Estados.** Carregando; vazio; erro; falha de envio com explicação em linguagem simples.

**Responsividade.** Conversão de tabela em cartões abaixo de `md`.

### T18 · Minha conta — `/conta`

**Objetivo.** Reunir dados pessoais, senha, notificações e os direitos do titular previstos na LGPD.

**Componentes.** Seções: dados pessoais (nome, CPF somente leitura, e-mail com estado de verificação, telefone); alterar senha; preferências de notificação (ligação para T16); **seus direitos** — "Baixar meus dados" e "Encerrar minha conta".

**Layout.** Coluna única com seções em cartões. A seção de direitos fica ao final, em cartão de borda `--consent`, com texto explicativo antes das ações.

**Navegação.** → T16. "Encerrar" abre `ConfirmDialog` estendido.

**Interações.** A alteração do e-mail avisa, antes de salvar, que reinicia a verificação e suspende os lembretes até a nova confirmação (RF06a). O encerramento exibe o diálogo mais elaborado do sistema, com dois blocos e digitação de confirmação: *O que será apagado* — seus dados pessoais de identificação e contato; *O que será preservado* — os registros clínicos dos seus animais, com a autoria dos profissionais, porque o Conselho Federal de Medicina Veterinária exige a guarda do prontuário por prazo determinado (RF55c). Nenhum eufemismo, nenhuma omissão.

**Estados.** Normal por seção; salvando; e-mail não verificado; carregando exportação de dados; conta em processo de encerramento.

**Responsividade.** Coluna única em todas as larguras.

### T19 · Transferir titularidade — `/animais/:codigo/transferir`

**Objetivo.** Transferir o animal a outro tutor preservando integralmente o histórico. Requisito desejável; desenhar por último.

**Componentes.** Campo de CPF do tutor de destino; bloco de efeitos; botão "Solicitar transferência"; painel de estado da solicitação pendente.

**Layout.** Coluna de 560 px.

**Navegação.** Sucesso → T04 com tarja "Transferência aguardando aceite".

**Interações.** O CPF localiza a existência do cadastro sem revelar dado algum do destinatário, exatamente como em RF13. `ConfirmDialog` com: *O que acontece* — o histórico completo acompanha o animal e o novo tutor passa a vê-lo; você perde o acesso e todas as suas autorizações sobre ele são encerradas. *O que não acontece* — nada é apagado.

**Estados.** Normal; CPF sem cadastro (com orientação); aguardando aceite; aceita; recusada; expirada.

**Responsividade.** Coluna única.

## 8.3 Ambiente do veterinário

Projetado a partir de 1440 px. Densidade alta, corpo em `--text-body-sm` (14 px), alvos de 32–40 px em desktop. Operável por teclado do começo ao fim. `ContextBanner` permanente quando houver mais de um vínculo.

### V01 · Painel do veterinário — `/clinica/painel`

**Objetivo.** Retomar o trabalho: quem foi atendido, o que foi registrado e o que está pendente no prestador ativo, nos últimos trinta dias.

**Componentes.** Quatro indicadores no topo (atendimentos no período, vacinas aplicadas, doses vencidas, retornos previstos); lista "Animais atendidos recentemente" com acesso direto ao histórico; bloco "Pendências desta semana" com prévia de V02; seletor de intervalo.

**Layout.** Grade de 12 colunas: faixa de indicadores em largura total (quatro cartões de 3 colunas); abaixo, à esquerda 8 colunas com a lista de animais recentes em `AppTable`; à direita 4 colunas com pendências e retornos. Os indicadores são números, não gráficos: nenhum gráfico entra nesta etapa sem que responda a uma pergunta declarada.

**Navegação.** Linha da tabela → V06. "Ver todas" → V02. Botão "Registrar" no cabeçalho → V07 ou V08.

**Interações.** Intervalo ajustável pelo usuário (RF48b), padrão de 30 dias, com opções de 7, 30 e 90 dias. Indicadores clicáveis, cada um levando à lista filtrada correspondente.

**Estados.** Carregando (esqueleto de indicadores e de seis linhas); **vazio** — primeiro acesso do profissional, com orientação em três passos: cadastrar tutor, cadastrar animal, registrar atendimento; erro; **contexto sem autorizações** — explica que o painel abrange somente animais sob autorização vigente (RN48).

**Responsividade.** `xl` conforme descrito; `lg` indicadores em duas linhas de dois; `md` coluna única com pendências acima da lista; `base` painel reduzido a indicadores e pendências, tabela convertida em cartões.

### V02 · Pendências vacinais — `/clinica/pendencias`

**Objetivo.** Converter a rechamada por amostragem em rechamada por critério. É o requisito de maior apelo demonstrativo do sistema (RF49).

**Componentes.** `FilterBar` com período, espécie, imunobiológico e situação; `AppTable` com colunas animal, tutor, imunobiológico, dose, data prevista, dias de atraso, última notificação enviada; ação por linha "Abrir ficha"; ação de exportar o resultado; contador de resultados.

**Layout.** Filtros fixos no topo; tabela em largura total; coluna de situação com `StatusPill`; linhas atrasadas com fundo `--status-late-wash`. Ordenação padrão por dias de atraso, decrescente.

**Navegação.** Linha → V06. Exportação gera arquivo para acompanhamento da rechamada (RF49b).

**Interações.** Filtros combináveis, refletidos em pílulas removíveis. A coluna "Última notificação" exibe data e situação de envio, respondendo diretamente a "este tutor já foi avisado?". Seleção múltipla de linhas apenas se a exportação parcial for oferecida; caso contrário, sem caixas de seleção.

**Estados.** Carregando; **vazio positivo** — "Nenhuma dose vencida ou próxima do vencimento no período." com ícone `circle-check`; filtrado sem resultado; erro; **desempenho** — a consulta deve responder em até 2 s no 95º percentil (RNF03); acima de 1 s, exibir esqueleto e não travar os filtros.

**Responsividade.** Tabela vira cartões abaixo de `md`, promovendo animal, imunobiológico e dias de atraso; filtros em folha inferior. Em campo, a Dra. Larissa usa esta tela no celular.

### V03 · Buscar animal ou tutor — `/clinica/buscar`

**Objetivo.** Localizar rapidamente por nome, CPF, código do animal ou micro-chip, respeitando estritamente o âmbito de autorização. É a tela de maior risco de vazamento por desenho de interface de todo o sistema.

**Componentes.** Campo de busca único com detecção automática do tipo de termo; seletor de tipo quando ambíguo; resultados em duas seções — "Sob sua autorização" e "Existe cadastro na plataforma"; leitor de QR Code em celular.

**Layout.** Campo de busca proeminente e centrado no primeiro uso, deslocando-se para o topo após a primeira consulta. As duas seções de resultado têm desenhos deliberadamente diferentes: a primeira traz `AnimalCard` completos; a segunda traz cartões **minimalistas**, em `--surface-sunken`, com borda tracejada `--consent`.

**Navegação.** Resultado autorizado → V06. Resultado não autorizado → V10 (solicitar autorização).

**Interações.** Esta é a interação mais sensível do projeto. Quando o CPF ou o código pertence a cadastro existente **sem autorização vigente**, o cartão exibe **exclusivamente**: para busca por CPF, "Existe um cadastro com este CPF." — sem nome, sem contato, sem relação de animais (RF13a, RF13b); para busca por código do animal, apenas espécie, nome do animal e a indicação de que há histórico disponível mediante autorização (RF18a). Ação única: "Solicitar autorização ao tutor". A consulta sem autorização é registrada em log (RF18b), e a interface informa isso ao profissional em nota discreta.

**Estados.** Inicial (campo vazio com exemplos de formato); carregando; sem resultado; **resultado sem autorização** (padrão P2, o estado mais importante desta tela); resultado autorizado; erro; termo inválido (CPF com dígito verificador incorreto).

**Responsividade.** Campo em 48 px no celular com botão de leitura de QR ao lado; resultados em coluna única.

### V04 · Cadastrar tutor — `/clinica/tutores/novo`

**Objetivo.** Cadastrar tutor no atendimento, ou vincular o cadastro já existente sem expor seus dados.

**Componentes.** Campo de CPF em primeiro lugar, isolado, com botão "Verificar"; formulário completo revelado apenas após a verificação; bloco de convite de ativação.

**Layout.** Fluxo de duas etapas em uma tela: o CPF é a chave e a barreira. Enquanto não verificado, o restante do formulário permanece oculto — não desabilitado, oculto —, porque um formulário visível sugere que o caminho normal é preenchê-lo.

**Navegação.** CPF novo → formulário → sucesso → V05 ou ficha do tutor. CPF existente → V10.

**Interações.** Quando o CPF já existe, a tela **não** exibe nome, contato ou animais. Exibe: "Já existe um cadastro com este CPF na plataforma. Para vincular este tutor ao atendimento e ver o histórico dos animais dele, solicite a autorização." com ação única (RF13). Ao concluir cadastro novo, informa que o tutor receberá convite de ativação e que, até ativar, não receberá lembretes nem poderá conceder autorizações (RF14).

**Estados.** Inicial; verificando CPF; CPF novo; **CPF existente** (estado crítico); validação; carregando; sucesso com aviso de convite enviado; **tutor não ativado** — sinalizado permanentemente nas telas do prestador (RF14b).

**Responsividade.** Coluna única; barra de ação fixa no rodapé em celular.

### V05 · Cadastrar animal — `/clinica/animais/novo`

**Objetivo.** Cadastrar o animal no atendimento, prevenindo duplicidade e conduzindo ao cadastro existente quando houver.

**Componentes.** Campos de identificação (nome, espécie, foto) e, na sequência, os campos privativos de caracterização (raça, pelagem, sexo, situação reprodutiva, peso, micro-chip, data de nascimento com marcação exata ou estimada); bloco de alerta de duplicidade; seletor do tutor vinculado.

**Layout.** Duas colunas em `xl`: identificação à esquerda, caracterização à direita, separadas por fio e por cabeçalhos que nomeiam a divisão de responsabilidade — "Identificação (o tutor também pode preencher)" e "Caracterização (privativa do médico-veterinário)". A divisão que sustenta o diferencial do sistema fica, assim, explícita na própria tela.

**Navegação.** Sucesso → V06. Duplicidade detectada → cadastro existente.

**Interações.** Antes de confirmar, o sistema alerta sobre duplicidade provável quando houver, para o mesmo tutor, animal ativo de mesma espécie e nome semelhante, identificando o cadastro possivelmente equivalente (RF20a). O peso é registrado como **medição datada**, não como campo sobrescrevível: o formulário exibe "Peso aferido hoje" e a ficha exibe o valor mais recente com a data.

**Estados.** Normal; alerta de duplicidade (bloqueante até escolha explícita); validação; carregando; sucesso; **consolidação** — quando o animal já fora cadastrado pelo tutor, a tela abre em modo de complemento, com os campos de identificação preenchidos e somente a caracterização por preencher.

**Responsividade.** Coluna única abaixo de `lg`, com a caracterização em bloco recolhível.

### V06 · Ficha clínica do animal — `/clinica/animais/:codigo`

**Objetivo.** Ser o centro de trabalho do veterinário sobre um animal: identidade, caracterização, carteira, histórico e ações de registro, sem troca de tela.

**Componentes.** Cabeçalho com foto, nome, espécie, idade, código único, tutor e situação de autorização; abas — Resumo, Carteira, Histórico, Anexos; barra de ações persistente com "Registrar vacinação", "Registrar atendimento", "Exportar PDF" e menu com ações menos frequentes (reação adversa, óbito, retorno); painel lateral de alertas.

**Layout.** Cabeçalho fixo de 96 px durante a rolagem, contendo o essencial de identificação. Conteúdo em 8 colunas, painel lateral de alertas em 4: reações adversas registradas, doses atrasadas, retorno programado em aberto, cadastro preliminar, autorização a expirar. Alertas clínicos ficam **acima** de alertas administrativos.

**Navegação.** Abas sem recarregar. Ações abrem V07, V08, V11, V12, T15. Anexos abrem visualizador.

**Interações.** Ao abrir aba que contenha registro produzido por outro prestador, exibe-se, **antes** do conteúdo, o aviso em `--consent-wash`: "Parte deste histórico foi produzida por outro prestador. Sua visualização é registrada e fica visível ao tutor." A gravação do log é condição da exibição (RF52b), e a interface deve refletir essa ordem. Barra de ações fixa no rodapé em telas menores.

**Estados.** Carregando; **sem autorização vigente** — exibe apenas espécie, nome e código, com o padrão P2 e ação de solicitar (RF35c); autorização a expirar (tarja âmbar com renovação a pedir ao tutor); cadastro preliminar (tarja com ação "Completar caracterização"); óbito registrado (ações de registro clínico suprimidas, exceto retificação); erro.

**Responsividade.** `xl` conforme descrito; `lg` painel de alertas passa a faixa acima do conteúdo; `md` abas viram seletor; `base` cabeçalho reduzido a 64 px, ações em barra inferior fixa com as duas principais e menu.

### V07 · Registrar vacinação — `/clinica/animais/:codigo/vacinar`

**Objetivo.** Registrar a aplicação em até noventa segundos, incluindo lote e validade, e exibir imediatamente a próxima data calculada. É o requisito de usabilidade mais exigente do sistema (RNF15) e a tela onde o produto se ganha ou se perde.

**Componentes.** `ImmutableNotice` no topo; seletor de imunobiológico do catálogo com busca por digitação; campos fabricante, lote, validade, via de administração; data e hora (pré-preenchidas com o momento atual); ordem da dose na série (calculada, editável com justificativa); campo de observação; painel lateral de cálculo; botão "Confirmar aplicação".

**Layout.** Duas colunas em `xl`: formulário à esquerda (7 colunas), **painel de cálculo ao vivo** à direita (5 colunas). O painel mostra, em tempo real, a próxima data prevista, a regra aplicada e a versão do protocolo — o profissional vê a consequência do que está registrando antes de confirmar.

**Navegação.** Sucesso → V06, aba Carteira, com o novo `BatchSeal` destacado por 2 s.

**Interações.** Decisivas para o critério de 90 segundos:
- foco automático no seletor de imunobiológico, com busca por digitação e seleção por Enter;
- **valores repetidos do último registro oferecidos como padrão**: escolhido o imunobiológico, fabricante, lote, validade e via vêm pré-preenchidos com os da última aplicação do mesmo item no prestador, marcados visualmente como sugestão e substituíveis com um toque (RNF15);
- aplicador preenchido automaticamente a partir do usuário autenticado e **não editável** (RF25b);
- lote e validade obrigatórios, com máscara e teclado numérico em celular;
- `Ctrl/Cmd + Enter` confirma;
- ao confirmar, o painel de cálculo se converte no resumo do registro, sem troca de página.

**Estados.** Normal; **validade expirada na data da aplicação** — não bloqueia; exibe alerta e exige confirmação explícita, e o registro fica sinalizado (RF25c); **atraso acima do limite parametrizado** — alerta com a conduta sugerida (prosseguir ou reiniciar a série) e campo de justificativa para conduta divergente, **nunca** com o botão desabilitado (RF27, RN36); **dose final antes da idade mínima** — o painel avisa que uma dose adicional será agendada, com a explicação da interferência dos anticorpos maternos (RN33); **reação adversa anterior ao mesmo imunobiológico** — alerta destacado exibido antes da confirmação (RF30b); confirmando; sucesso; erro de gravação com os dados preservados.

**Responsividade.** Abaixo de `lg`, painel de cálculo vira bloco recolhido acima do botão, expandido automaticamente quando houver alerta. Em celular, campos de 48 px, teclado numérico nos campos de lote e data, barra de ação fixa.

### V08 · Registrar atendimento — `/clinica/animais/:codigo/atender`

**Objetivo.** Registrar o prontuário completo, com anexos e retorno programado, sob regime de imutabilidade.

**Componentes.** `ImmutableNotice`; campos motivo da consulta, anamnese, exame físico, hipóteses diagnósticas, diagnóstico, conduta terapêutica; peso aferido; área de anexos com arrastar e soltar; bloco "Programar retorno" com data e finalidade; botão "Confirmar atendimento".

**Layout.** Coluna de leitura de 720 px para os campos de texto longo — largura de linha confortável importa em texto clínico. Anexos e retorno em cartões laterais em `xl`; abaixo do formulário nas demais larguras.

**Navegação.** Sucesso → T08 do atendimento criado.

**Interações.** Rascunho salvo automaticamente no cliente enquanto não confirmado, com indicação "rascunho salvo às 14:32" — e aviso claro de que rascunho **não é registro**. A confirmação abre `ConfirmDialog` com a estrutura de P3. Anexos aceitam PDF e imagem, com restrição de formato e tamanho informada antes do envio, descrição e data do exame por arquivo. O retorno programado, quando preenchido, informa que gerará lembrete ao tutor e constará do painel de pendências.

**Estados.** Rascunho; validação; anexo enviando (com progresso por arquivo); anexo recusado por formato ou tamanho, com mensagem específica; confirmando; sucesso; erro de gravação com conteúdo preservado; **sessão prestes a expirar durante a redação** — aviso proeminente com ação de renovar sem perder o texto.

**Responsividade.** Coluna única abaixo de `lg`; campos de texto com altura mínima de 120 px e expansão automática; barra de ação fixa em celular.

### V09 · Retificar registro — modal sobre T06 ou T08

**Objetivo.** Corrigir registro clínico exclusivamente por retificação vinculada, preservando o original — a materialização em interface da decisão de imutabilidade.

**Componentes.** `AppModal` largo com duas colunas: à esquerda, o registro original em leitura, esmaecido e marcado "original"; à direita, o formulário da retificação com os mesmos campos; campo obrigatório "Motivo da retificação"; `ConfirmDialog` na confirmação.

**Layout.** Comparação lado a lado, com os campos alterados destacados em `--status-due` à medida que divergem do original.

**Navegação.** Disponível apenas ao profissional autor, dentro do prestador que produziu o registro (RF33c). Para todos os demais, a ação simplesmente não existe na interface — não aparece desabilitada.

**Interações.** Nenhum caminho da tela permite sobrescrever ou excluir. O texto de confirmação: *O que acontece* — uma nova versão é criada, vinculada à original, com o seu nome e a data; *O que não acontece* — a versão original não é apagada e continua visível a todos que veem este registro.

**Estados.** Normal; sem alterações (botão desabilitado com explicação); motivo não preenchido; confirmando; sucesso, com a tela de origem exibindo o encadeamento navegável entre original e retificação.

**Responsividade.** Abaixo de `lg`, as colunas empilham com o original recolhido sob "Ver versão original"; folha inferior em celular.

### V10 · Solicitar autorização — modal sobre V03 ou V06

**Objetivo.** Pedir ao tutor acesso ao histórico, sem obter nada antes da concessão.

**Componentes.** `AppModal` em `--consent-wash` com identificação do que se está solicitando (animal, quando conhecido; ou apenas o CPF localizado), campo opcional de mensagem, e explicação do que acontece em seguida.

**Layout.** Modal estreito, 480 px, com o texto explicativo acima da ação.

**Navegação.** Sucesso → volta à tela de origem com etiqueta "Solicitação enviada · aguardando o tutor".

**Interações.** O texto informa ao profissional, sem rodeios, que a solicitação não confere acesso algum e que o tutor precisará confirmar por código. Prazo de expiração da solicitação exibido.

**Estados.** Normal; enviando; enviada; já existe solicitação pendente (com data); tutor não ativado — explica que ele precisa ativar o acesso antes de poder autorizar.

**Responsividade.** Folha inferior em celular.

### V11 · Registrar reação adversa — modal sobre T06

**Objetivo.** Vincular a reação à aplicação específica que a originou, alimentando o alerta de aplicações futuras.

**Componentes.** Resumo da aplicação vinculada; campos descrição, gravidade (escala com rótulos textuais) e conduta adotada; `ImmutableNotice`.

**Layout.** Modal de 560 px, com a aplicação vinculada exibida como `BatchSeal` compacto no topo — deixando inequívoco a qual registro a reação se vincula (RN23).

**Navegação.** Sucesso → T06 com o bloco de reação exibido.

**Interações.** Aviso de que aplicações futuras do mesmo imunobiológico ao mesmo animal exibirão alerta antes da confirmação.

**Estados.** Normal; validação; confirmando; sucesso.

**Responsividade.** Folha inferior em celular.

### V12 · Registrar óbito — modal sobre V06

**Objetivo.** Registrar o óbito, cessando cálculo de calendário e lembretes, preservando o histórico.

**Componentes.** Campo de data; campo de causa, opcional; `ConfirmDialog` com estrutura de P3.

**Layout.** Modal estreito, tom sóbrio, sem cor de alerta. Ícone `moon`, neutro.

**Navegação.** Sucesso → V06 em estado de animal inativo.

**Interações.** Texto de confirmação: *O que acontece* — nenhum lembrete será enviado ao tutor a partir de agora e o calendário deixa de ser calculado; *O que não acontece* — o histórico permanece consultável pelo tutor e pelos prestadores autorizados, e este registro não pode ser excluído, apenas retificado. A redação evita eufemismo e evita frieza; é uma das poucas telas em que o tom importa mais que a eficiência.

**Estados.** Normal; confirmando; sucesso; já registrado.

**Responsividade.** Folha inferior em celular.

## 8.4 Administração do prestador

Três telas, deliberadamente poucas. O papel administra a conta e **não acessa dado algum** de tutor, animal ou registro clínico. Essa ausência precisa ser explicada na interface, não apenas produzida por ela.

### A01 · Painel administrativo — `/prestador`

**Objetivo.** Dar ao administrador o estado da conta: dados do estabelecimento, equipe vinculada e pendências de configuração.

**Componentes.** Cartão de identificação do prestador; cartão de equipe com contagem e convites pendentes; lista de pendências de configuração (responsável técnico não informado, endereço incompleto, convites expirados); bloco explicativo do alcance do papel.

**Layout.** Duas colunas em `lg`+. O bloco explicativo fica ao pé da barra lateral, permanente: "Este perfil administra a conta do prestador. Dados de tutores, animais e registros clínicos não são acessíveis por ele."

**Navegação.** → A02, A03.

**Interações.** Pendências com ação direta. Nenhum indicador clínico, nenhuma contagem de animais ou de atendimentos — a ausência é intencional e é o argumento de projeto.

**Estados.** Carregando; **prestador sem responsável técnico** — alerta bloqueante explicando que, sem ele, nenhuma informação clínica pode ser registrada (RF07c); convites pendentes; erro.

**Responsividade.** Coluna única abaixo de `lg`.

### A02 · Dados do prestador — `/prestador/dados`

**Objetivo.** Manter os dados cadastrais, preservando o histórico relevante para documentos já emitidos.

**Componentes.** Formulário com tipo, razão social ou nome, CNPJ, endereço com município e UF, contato, responsável técnico com CRMV; bloco "Histórico de alterações".

**Layout.** Coluna de 720 px, campos agrupados por assunto.

**Navegação.** A partir de A01.

**Interações.** Ao salvar alteração de denominação, aviso: "Documentos já exportados continuam exibindo o nome vigente na data de emissão." (RF08a). Alteração de município informa que se refletirá no diretório consultado pelos tutores.

**Estados.** Normal; salvando; validação; erro; somente leitura para campos que exijam verificação.

**Responsividade.** Coluna única.

### A03 · Equipe — `/prestador/equipe`

**Objetivo.** Convidar e desvincular médicos-veterinários, com CRMV obrigatório.

**Componentes.** `AppTable` com nome, CRMV e UF, e-mail, situação (ativo, convite pendente, vínculo encerrado) e ação; modal de convite com e-mail, CRMV e UF; `ConfirmDialog` para encerramento de vínculo.

**Layout.** Tabela em largura total, ação de convidar no `PageHeader`.

**Navegação.** A partir de A01.

**Interações.** O convite informa que o profissional definirá a própria senha no primeiro acesso. O encerramento abre confirmação com: *O que acontece* — o profissional perde imediatamente o acesso a este contexto; *O que não acontece* — os registros que ele produziu continuam íntegros, sob a guarda do prestador, exibindo o nome e o CRMV dele; a autoria não é removida nem anonimizada (RF10).

**Estados.** Carregando; vazio (apenas o administrador); convite pendente com reenvio e contagem de expiração; vínculo encerrado (linha esmaecida com data); erro.

**Responsividade.** Tabela vira cartões abaixo de `md`.

## 8.5 Administração da plataforma

Duas telas. Não fazem parte da experiência dos usuários finais, mas são indispensáveis à demonstração do requisito de maior densidade técnica do trabalho (RF24).

### X01 · Catálogo de imunobiológicos — `/plataforma/catalogo`

**Objetivo.** Manter o catálogo que restringe o que pode ser registrado como aplicação.

**Componentes.** `AppTable` com denominação comercial e técnica, fabricante, espécie de destino, agentes cobertos, classificação (essencial ou não essencial), via usual e situação; formulário em painel lateral deslizante; filtro por espécie e por classificação.

**Layout.** Tabela em largura total; painel lateral de 480 px para criação e edição, sem sair da lista.

**Navegação.** → X02 pela ligação "Protocolos que usam este imunobiológico".

**Interações.** Inativação, nunca exclusão: o aviso informa que a inativação impede novos registros e **não afeta** os registros anteriores (RF23b).

**Estados.** Carregando; vazio; salvando; item inativo (linha esmaecida com etiqueta); erro.

**Responsividade.** Cartões abaixo de `md`; painel lateral vira tela cheia.

### X02 · Protocolos vacinais — `/plataforma/protocolos`

**Objetivo.** Editar e publicar versões dos parâmetros temporais do calendário, sem alteração de código. É a tela que responde à pergunta previsível da banca — o que acontece quando a WSAVA revisa as diretrizes.

**Componentes.** Lista de versões com situação (rascunho, vigente, encerrada), data de publicação e contagem de cálculos realizados sob cada uma; editor de parâmetros por espécie e imunobiológico — idade mínima da primeira dose, intervalo entre doses da série primária, idade mínima da dose final, prazo do primeiro reforço, periodicidade da revacinação, limite de atraso e conduta associada; **simulador**; ação "Publicar nova versão".

**Layout.** Duas colunas: lista de versões à esquerda (4 colunas), editor à direita (8). O simulador ocupa um cartão ao pé do editor.

**Navegação.** A partir de X01 e da navegação da plataforma.

**Interações.** O **simulador** é o elemento mais valioso desta tela para a demonstração acadêmica: informa-se espécie, data de nascimento e datas de aplicação hipotéticas, e o painel exibe o calendário calculado resultante, com a regra aplicada em cada passo. Permite conferir os quatro casos de teste declarados em RNF01 — série primária completa, série com atraso, adulto sem histórico e dose final antes da idade mínima — sem tocar em dado real. A publicação abre `ConfirmDialog`: *O que acontece* — novos cálculos passam a usar esta versão; *O que não acontece* — datas já emitidas não são recalculadas, e cada registro conserva a versão aplicada à época (RN32).

**Estados.** Rascunho; vigente; encerrada; validando parâmetros incoerentes (intervalo maior que o prazo do reforço, por exemplo); simulando; publicando; erro.

**Responsividade.** Coluna única abaixo de `lg`; o simulador é a última seção. Esta é uma tela de desktop; não otimize para celular.

---

# 9. Fluxos ponta a ponta

Cinco percursos que atravessam as telas. Use-os para validar a coerência do conjunto e como roteiro da demonstração à banca — eles reproduzem, na ordem, o cenário do estudo de caso.

**F1 — Helena chega antes de qualquer clínica.**
P01 → P03 (autocadastro) → P08 (confirma e-mail) → T01 (vazio, com orientação) → T03 (cadastra Théo) → T04 (recebe o código único) → T09 (lança o histórico pregresso que tem em mãos) → T05 (a carteira já existe, ainda que só com registros não verificados).
*O que este fluxo prova:* a adoção não depende do estabelecimento.

**F2 — A autorização, em quatro passos.**
T01 → T10 (encontra a Clínica Vet Amigo) → T11 passo 2 (escolhe Théo) → T11 passo 3 (lê o resumo, recebe e digita o código) → T12 (autorização vigente).
*O que este fluxo prova:* o consentimento é ato do titular, verificado por segundo fator, e cabe em quatro passos (RNF14). Cronometre-o na demonstração.

**F3 — O registro de vacinação, em noventa segundos.**
V01 → V03 (busca "Théo") → V06 (ficha, autorização vigente) → V07 (imunobiológico, lote e validade pré-preenchidos do último registro; confirma) → painel de cálculo exibe a próxima data e a regra → V06 aba Carteira, com o novo selo.
*O que este fluxo prova:* o formulário cabe na consulta (RNF15). Cronometre-o também.

**F4 — O histórico atravessa o estabelecimento.**
V03 (busca por código, animal de outro prestador, **sem** autorização — estado P2) → V10 (solicita) → [Helena] T13 → T11 passo 3 → [Dr. Marcelo] V06 com aviso de acesso registrado → T07 (histórico consolidado, com procedência de cada item) → [Helena] T14 (vê exatamente quem acessou, quando e o quê).
*O que este fluxo prova:* o dado segue o animal, o tutor controla o acesso, e o acesso é auditável. É o núcleo da tese do trabalho.

**F5 — O documento sai da plataforma e continua verificável.**
T04 → T15 (lê a advertência de responsabilidade, gera o PDF) → [terceiro fora da plataforma] lê o QR Code → P09 (documento autêntico, emitido em tal data, referente a tal animal — e nada mais).
*O que este fluxo prova:* a mitigação da limitação de adesão parcial funciona sem abrir dado clínico a quem não deve vê-lo.

---

# 10. Conformidade do design com os requisitos não funcionais

Lista de verificação a aplicar sobre cada tela entregue. Serve também de instrumento de avaliação no capítulo de Resultados da monografia.

| Requisito | O que verificar no design | Telas críticas |
|---|---|---|
| RNF13 | Operação em 360 px sem rolagem horizontal | T01, T04, T05, T07, T12 |
| RNF14 | Concessão em até quatro passos desde a tela inicial | T01 → T10 → T11 |
| RNF15 | Registro de vacinação em até 90 s, com lote e validade | V07 |
| RNF16 | Toda informação exibe origem e confiabilidade | T05, T06, T07, T08, V06 |
| RNF17 | Mensagens sem jargão de implementação | todas, com atenção a E01–E03 |
| RNF09 | Ação indisponível não aparece na interface; a recusa é do servidor | V09, V06, A01 |
| RN12 | Existência de cadastro nunca revela conteúdo | V03, V04, T19 |
| RN24 | Não verificado permanentemente distinguível, inclusive no PDF | T05, T09, T15 |
| RN26 | Nenhum caminho permite sobrescrever ou excluir registro | V07, V08, V09, T06, T08 |
| RN36 | Nenhum bloqueio de conduta clínica divergente | V07 |
| RN46 | Advertência de responsabilidade antes da exportação | T15 |
| RN47 | Verificação pública sem conteúdo clínico | P09 |
| RN48 | Painéis restritos a animais sob autorização vigente | V01, V02, T17 |
| RN49 | Aviso de registro de acesso antes da exibição | V06, T07 |
| LGPD art. 18 | Direitos do titular acessíveis a partir da conta | T18, T14 |

---

# 11. O que não desenhar

Delimitação de escopo já assumida nas etapas anteriores. Não produza telas para: gestão financeira, ponto de venda ou emissão de documento fiscal; agendamento de banho, tosa ou hospedagem; emissão de receituário e atestados; controle de estoque e de internação; módulo completo de agenda de consultas (substituído pelo retorno programado); canal de WhatsApp; aplicativo móvel nativo; espécies distintas de cão e gato; integração com sistemas de terceiros; tema escuro.

Se, ao desenhar, surgir a tentação de acrescentar uma dessas telas "para completar o produto", não a acrescente. Escopo não declarado é escopo cobrado — e a delimitação, aqui, é argumento, não lacuna.

---

# 12. Entregáveis esperados desta etapa

1. *Style guide* navegável com tokens (seção 4) e biblioteca de componentes (seção 5), em uma única tela de referência;
2. Telas do inventário (seção 7) nas três larguras — 360, 768 e 1440 px —, com os estados nomeados em cada verbete;
3. Os cinco fluxos da seção 9 montados como percursos navegáveis, para a demonstração;
4. Nota de projeto de uma página registrando as decisões de interface e suas justificativas — insumo direto do Capítulo 4 da monografia, em especial a divisão de responsabilidade entre tutor e veterinário na tela V05 e o tratamento do estado "sem autorização" em V03.

---

*Documento produzido na Etapa 4 do projeto. Deve ser anexado, junto ao documento de decisões, ao estudo de caso e ao documento de requisitos, no início das conversas subsequentes.*
