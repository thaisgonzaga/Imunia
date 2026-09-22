# Publicação do Imunia (ambiente de testes gratuito)

O Imunia roda num único contêiner (`Dockerfile` da raiz): o FrankenPHP serve as
telas do Vue e a API do Laravel no **mesmo endereço**. É o que o Sanctum em modo
SPA pede, e dispensa CORS e cookies de terceiros.

| Peça | Serviço gratuito | Por que este |
|---|---|---|
| Aplicação | [Render](https://render.com) (web service, Docker) | Publica a cada `git push`, com HTTPS pronto |
| Banco | [TiDB Cloud Starter](https://tidbcloud.com) | Compatível com MySQL, 5 GiB, não hiberna. A suíte inteira passa no TiDB 8.5 |
| Arquivos | [Backblaze B2](https://www.backblaze.com/cloud-storage) | O disco do Render é apagado a cada deploy. 10 GB, compatível com S3 |
| E-mail | [Brevo](https://www.brevo.com) | O Render gratuito bloqueia SMTP. O envio sai pela API, com 300 e-mails por dia |
| Manter acordado | [UptimeRobot](https://uptimerobot.com) (opcional) | O Render gratuito hiberna após 15 min sem acesso |

Nenhum deles pede cartão de crédito. **Use a mesma região em todos (leste dos
EUA)**: cada tela faz várias consultas ao banco, e a distância entre servidor e
banco pesa em cada uma.

Vá anotando os valores marcados com ➜: eles são pedidos juntos no passo 4.

---

## 1. Banco: TiDB Cloud

1. Crie a conta em <https://tidbcloud.com>.
2. Crie um cluster **Starter** em **AWS, N. Virginia (us-east-1)**.
3. No cluster, abra **Connect**, gere a senha e anote:
   - ➜ `DB_HOST`: o host (algo como `gateway01.us-east-1.prod.aws.tidbcloud.com`)
   - ➜ `DB_USERNAME`: o usuário (algo como `2abc…xyz.root`)
   - ➜ `DB_PASSWORD`: a senha gerada. Ela só aparece uma vez.
4. No **SQL Editor** do cluster, rode:
   ```sql
   CREATE DATABASE imunia;
   ```

## 2. Arquivos: Backblaze B2

1. Crie a conta em <https://www.backblaze.com/sign-up/cloud-storage>. **No
   formulário, escolha a região US East.** Ela não pode ser trocada depois.
2. Em **Buckets → Create a Bucket**, crie um bucket **Private** com um nome
   único (por exemplo, `imunia-arquivos-thais`).
   - ➜ `AWS_BUCKET`: o nome do bucket
   - ➜ `AWS_ENDPOINT`: o *Endpoint* que aparece no bucket, com `https://`
     na frente (por exemplo, `https://s3.us-east-005.backblazeb2.com`)
   - ➜ `AWS_DEFAULT_REGION`: o trecho do endpoint entre `s3.` e
     `.backblazeb2.com` (por exemplo, `us-east-005`)
3. Em **Application Keys → Add a New Application Key**, crie uma chave com
   acesso **só a esse bucket**, do tipo **Read and Write**:
   - ➜ `AWS_ACCESS_KEY_ID`: o *keyID*
   - ➜ `AWS_SECRET_ACCESS_KEY`: o *applicationKey*. Ele só aparece uma vez.

## 3. E-mail: Brevo

1. Crie a conta gratuita em <https://www.brevo.com> e complete o perfil. Contas
   novas às vezes passam por uma validação antes de poder enviar.
2. Em **Senders, Domains & Dedicated IPs → Senders**, cadastre o remetente
   `Imunia` com o seu e-mail e confirme pelo código que chegar.
   - ➜ `MAIL_FROM_ADDRESS`: esse e-mail
3. Em **SMTP & API → API Keys**, gere uma chave:
   - ➜ `BREVO_API_KEY`: a chave

## 4. Aplicação: Render

1. Crie a conta em <https://render.com> entrando com o GitHub.
2. **New → Blueprint** e escolha o repositório do Imunia. O Render lê o
   `render.yaml` da raiz.
3. Preencha os valores ➜ anotados acima e mais um:
   - ➜ `ADMIN_PLATAFORMA_EMAIL`: o e-mail da conta que vai administrar o
     catálogo de vacinas (X01, X02). Pode ser o mesmo do remetente.
4. Confirme. O primeiro build leva de 5 a 10 minutos. No fim, os logs mostram as
   migrações, `Catálogo pronto: 12 imunobiológicos…` e
   `Administração da plataforma: …`.
5. O endereço público aparece no topo do serviço (algo como
   `https://imunia.onrender.com`). A aplicação o descobre sozinha. Não é
   preciso configurar `APP_URL`, `FRONTEND_URL` nem `APP_KEY`.

## 5. Primeiro acesso da administração

A conta de `ADMIN_PLATAFORMA_EMAIL` nasce com uma senha aleatória que ninguém
conhece. No site, vá em **Entrar → Esqueci minha senha**, informe esse e-mail e
defina a senha pelo link que chegar.

## 6. Manter acordado (opcional, recomendado)

Sem acessos, o Render gratuito hiberna após 15 minutos, e a próxima visita
espera cerca de 1 minuto. No UptimeRobot, crie um monitor **HTTP(s)** para
`https://SEU-ENDERECO.onrender.com/up` a cada **5 minutos**. As 750 horas
mensais do plano gratuito cobrem um serviço ligado o mês inteiro.

---

## Como atualizar

`git push` na `main`. O Render refaz a imagem e publica sozinho, em 3 a 5
minutos. As migrações novas rodam no início do contêiner. Banco e arquivos
ficam fora dele, então nada se perde.

## O que esperar do plano gratuito

Medido localmente com os mesmos limites do Render (0,1 CPU e 512 MB):

- início do contêiner: ~25 s (mais o tempo das migrações na primeira vez);
- telas e consultas: 0,02 a 0,2 s;
- login e cadastro: 2 a 3,5 s, por causa do custo do bcrypt. Com
  `BCRYPT_ROUNDS=10` no Render, o hash fica 4 vezes mais barato. O custo 10
  ainda é o mínimo recomendado pela OWASP;
- emissão do PDF: ~3 s;
- memória: ~140 MB de 512 MB.

Limites: 300 e-mails por dia (Brevo), 5 GiB de banco (TiDB), 10 GB de arquivos
(B2). Nenhum desses planos garante backup. Trate o ambiente como de testes.

## Avisos para quem for testar

- As mensagens do Imunia podem cair no **spam** nos primeiros envios.
- É um ambiente de testes. Quem preferir não usar dados reais pode usar CPF
  fictício válido, gerado em sites como o 4Devs.
