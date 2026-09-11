# RivonPay — descobertas da API

Levantado em 2026-09-10 contra `https://api.rivonpay.com.br`.

> **Fonte principal:** a API publica a própria especificação em
> **<https://api.rivonpay.com.br/openapi.json>** (OpenAPI 3.1, `RivonPay API 0.1.0`).
> Não há portal de documentação — `docs.rivonpay.com.br` e `developers.rivonpay.com.br`
> não resolvem em DNS, e `/docs`, `/swagger`, `/reference` retornam 404.
> Sempre que houver dúvida, rebaixe o `openapi.json` e consulte-o.

Estado: **integração validada de ponta a ponta** — autenticação, criação da cobrança,
QR Code e confirmação de pagamento, todos verificados contra a API real com uma
cobrança de R$ 10,00 efetivamente paga em 2026-09-11.

Inclusive o formato do webhook, capturado apontando `postbackUrl` para uma URL de
captura e pagando de verdade — o que revelou que **ele não é assinado** (seção 6).

---

## 1. Autenticação — `Basic base64(publicKey:secretKey)`

A spec declara dois esquemas; o que interessa ao módulo é o `integracao`:

```json
"integracao": { "type": "http", "scheme": "basic",
                "description": "Basic base64(publicKey:secret)" }
```

Ou seja: `Authorization: Basic <base64("pk:sk")>` — **pk como usuário, sk como senha**.
As rotas `/v1/transactions/*` usam `security: [{ "integracao": [] }]`.
(O outro esquema, `sessao` = Bearer JWT, é do painel web e não serve para integração.)

### Evidência empírica (probes com credenciais falsas, sem chaves reais)

A API distingue "não reconheci o esquema" de "reconheci e o valor está errado",
o que permitiu eliminar os candidatos antes mesmo de ter chaves:

| Header enviado | Resposta |
|---|---|
| `Authorization: Basic base64(pk:sk)` | `401 Credencial inválida.` ← esquema **reconhecido** |
| `Authorization: Basic base64(sk:)` | `401 Credencial inválida.` ← esquema **reconhecido** |
| `Authorization: Bearer sk` | `401 Credencial ausente.` |
| `x-public-key` + `x-secret-key` | `401 Credencial ausente.` |
| `x-api-key` | `401 Credencial ausente.` |
| `api-key` | `401 Credencial ausente.` |
| `publicKey` + `secretKey` | `401 Credencial ausente.` |
| `Authorization: <sk>` (sem esquema) | `401 Credencial ausente.` |

**Conclusão:** só Basic é aceito. Bearer e os pares de headers customizados estão
descartados — eram as hipóteses do rascunho antigo e explicam a falha original.

### Confirmado com chaves reais (2026-09-10)

Rodando `tests/rivonpay_test.sh` com as chaves da conta:

- `Basic base64(pk:sk)` → **HTTP 502 `AcquirerUnavailable`** — ou seja, **passou pela
  autenticação** e chegou até a adquirente.
- `Basic base64(sk:)`, `Basic base64(sk:x)`, `Basic base64(pk:)` → **401 `Credencial inválida`**.

Está encerrado: **`Authorization: Basic base64(publicKey:secretKey)`**.

### Escopo da credencial de integração

A credencial Basic vale **somente para `/v1/transactions/*`**. Todo o resto
(`/me`, `/me/balance`, `/me/api-key`, `/me/webhooks/`, `/sales`, `/panel/*`)
responde `401 Credencial ausente` — essas rotas exigem o JWT de sessão do painel.

Consequência para o módulo: o WHMCS não consegue consultar saldo, KYC nem cadastrar
webhook via API. O cadastro de webhook tem de ser feito no painel — ou, melhor,
usando `postbackUrl` por cobrança, que a credencial de integração aceita.

As chaves da conta saem em `GET /me/api-key` (sessão do painel) ou no próprio painel;
`POST /me/api-key/rotate` gera um par novo.

---

## 2. Endpoints de cobrança

| Método | Rota | Para quê |
|---|---|---|
| `POST` | `/v1/transactions/` | Cria a cobrança Pix (**note a barra final**) |
| `GET` | `/v1/transactions/{id}` | Consulta a cobrança (`id` é **UUID**) |
| `POST` | `/v1/transactions/{id}/refund` | Estorna uma cobrança paga |

`GET /v1/transactions` (sem id, sem barra) é **404** — a listagem do lojista fica em
`GET /sales` / `GET /sales/{id}`, que usam a sessão do painel, não a credencial de integração.

Healthcheck sem autenticação: `GET /health` → `{"ok":true,"at":"..."}`.

---

## 3. Corpo da criação

Obrigatórios: **apenas `amount` e `customer`.**

```json
{
  "amount": 1000,
  "customer": {
    "name": "João da Silva",
    "email": "cliente@exemplo.com.br",
    "phone": "11999998888",
    "document": { "type": "CPF", "number": "12345678909" }
  },
  "externalRef": "WHMCS-1234",
  "postbackUrl": "https://seudominio.com/modules/gateways/callback/rivonpay.php",
  "expiresInSeconds": 3600
}
```

| Campo | Tipo | Regras |
|---|---|---|
| `amount` | integer | **Valor em centavos**, `> 0`, **mínimo R$ 1,00 (`100`)**. `1000` = R$ 10,00. Tipo estrito: `"1000"` como string dá `400` |
| `customer.name` | string | mínimo 3 caracteres |
| `customer.email` | string | formato e-mail, validado por regex estrita |
| `customer.phone` | string | sem máscara definida |
| `customer.document.type` | enum | `CPF` ou `CNPJ` |
| `customer.document.number` | string | 11 a 14 caracteres (só dígitos) |
| `externalRef` | string | opcional, **máx. 120 chars** — usar o ID da fatura WHMCS |
| `postbackUrl` | string(uri) | opcional — **aceito no body**, não precisa configurar no painel |
| `expiresInSeconds` | integer | opcional, **60 a 86400** (máx. 24h). **O módulo não envia** — deixa a plataforma decidir e usa o `pix.expiresAt` devolvido |
| `metadata` | object | opcional, chaves livres |
| `split` | array | opcional, máx. 20 itens — atenção às unidades, ver seção 8 |

**Não existem** os campos `paymentMethod` nem `items[]` — a rota é exclusivamente Pix.
Enviar campos extras é desnecessário; o schema não declara `additionalProperties: false`
na requisição, mas não há motivo para incluí-los.

---

## 4. Resposta (HTTP **201** na criação, **200** na consulta)

O JSON é **plano — não vem embrulhado em `{ok:true,data:{...}}`** (o envelope `ok` só
aparece nos erros). `additionalProperties: false`, então estes são todos os campos:

```json
{
  "id": "uuid-da-transacao",
  "status": "...",
  "amount": 1000,
  "fee": 0,
  "netAmount": 1000,
  "externalRef": "WHMCS-1234",
  "pix": {
    "qrCode": "00020126...",
    "qrCodeImage": "data:image/png;base64,... ou null",
    "expiresAt": "2026-09-10T23:59:59.000Z"
  },
  "splits": [],
  "createdAt": "2026-09-10T23:00:00.000Z"
}
```

### Resposta real capturada (2026-09-11, cobrança de R$ 10,00)

```json
{
  "id": "3f2a1b4c-5d6e-4f70-8a91-b2c3d4e5f607",
  "status": "PENDING",
  "amount": 1000, "fee": 50, "netAmount": 950,
  "externalRef": "WHMCS-100234",
  "pix": {
    "qrCode": "00020101021226830014br.gov.bcb.pix2561qrcode.exemplo.com.br/v2/qr/cob/...5802BR5914LOJA EXEMPLO6009SAO PAULO62070503***630432B9",
    "qrCodeImage": "data:image/png;base64,iVBORw0KGgo...",
    "expiresAt": "2026-09-11T01:15:52.503Z"
  },
  "splits": [], "createdAt": "2026-09-11T00:15:53.287Z"
}
```

Fatos confirmados na prática:

- **`status` inicial = `PENDING`, em MAIÚSCULAS**, virando **`PAID`** após a
  liquidação (ambos confirmados). A comparação no módulo é case-insensitive
  mesmo assim.
- **`qrCodeImage` VEM PREENCHIDO** nesta conta: data URI PNG completo (~3 KB),
  pronto para `<img src="...">`. Não é preciso gerar QR no cliente. Ainda assim o
  schema declara o campo nullable, então mantenha um fallback.
- **`fee`** é cobrada do lojista: `amount 1000 → fee 50 → netAmount 950`.
- **`expiresAt` = `createdAt` + 1 hora** quando `expiresInSeconds` não é enviado.
- O EMV aponta para o PSP `qrcode.exemplo.com.br`, favorecido `LOJA EXEMPLO`.

### Rate limit

A resposta traz `X-Ratelimit-Limit: 300`, `X-Ratelimit-Remaining`, `X-Ratelimit-Reset: 5`.
Relevante se o módulo fizer polling de status — 300 requisições por janela é
folgado para uso normal, mas não convém consultar em loop apertado.

Mapeamento para o módulo:

- **ID da transação** → `id` (UUID; é o que vai em `logTransaction` e `checkCbTransID`)
- **Copia-e-cola** → `pix.qrCode` ← este é o EMV do Pix
- **Imagem do QR** → `pix.qrCodeImage` — **nullable**. O módulo precisa saber gerar o
  QR a partir de `pix.qrCode` quando vier `null`.
- **Expiração** → `pix.expiresAt`
- Campos obrigatórios na resposta: `id, status, amount, fee, netAmount, externalRef,
  pix, splits, createdAt` — todos sempre presentes.

---

## 5. Formato de erro

Envelope consistente: `{ "ok": false, "code": "...", "message": "...", "details": ... }`.
Mensagens em português. Stack: Node/Express + Zod, atrás de Caddy (`Via: 1.1 Caddy`).

| HTTP | `code` | Quando | `details` |
|---|---|---|---|
| 400 | `ValidationError` | Schema inválido (campo faltando, tipo errado) | **array** de `{path, message}` |
| 401 | `Unauthorized` | `Credencial ausente.` (esquema não reconhecido) ou `Credencial inválida.` (valor errado) | — |
| 404 | `NotFound` | Rota ou transação inexistente | — |
| 422 | `AmountBelowMinimum` | `amount` < 100 | — |
| 422 | `SplitExceedsNet` | Soma do split maior que o líquido | — |
| 422 | `SplitRecipientNotFound` | `recipientSplitId` inexistente | — |
| 502 | `AcquirerUnavailable` | Adquirente recusou | **string** |

> **Atenção ao implementar:** `details` é polimórfico — array em `ValidationError`,
> string em `AcquirerUnavailable`. O log do módulo tem de tratar os dois casos
> (`is_array($details) ? json_encode($details) : $details`).

Exemplos reais:

```json
{"ok":false,"code":"ValidationError","message":"Dados inválidos.",
 "details":[{"path":"/customer","message":"Invalid input: expected object, received undefined"}]}

{"ok":false,"code":"AmountBelowMinimum","message":"Valor mínimo por cobrança é R$ 1,00."}
```

---

## 6. Webhooks

Duas formas, e a primeira basta para o WHMCS:

1. **`postbackUrl` no corpo da criação** — por cobrança. É o caminho mais simples;
   não exige configuração no painel.
2. **Webhooks cadastrados na conta** — `GET/POST/PUT/DELETE /me/webhooks/`, com
   `{ "url": "...", "events": ["..."] }`. Entregas auditáveis em
   `GET /me/webhooks/logs` e `GET /me/webhooks/{id}/logs`.
   `POST /me/webhooks/test` dispara um evento de teste para uma URL.

> `POST /webhooks/{slug}` é a rota **de entrada** da RivonPay para as adquirentes.
> Não tem relação com o callback do WHMCS.

### Payload real (capturado em 2026-09-11)

Nada disso está no `openapi.json`. Foi obtido apontando `postbackUrl` para uma URL
de captura e pagando uma cobrança real.

O envelope é `{ event, createdAt, data }`. **Dispara na criação e no pagamento.**

`transaction.created`:

```json
{"event":"transaction.created","createdAt":"2026-09-11T01:46:10.437Z",
 "data":{"id":"3f2a1b4c-…","amount":100,"qrCode":"00020101…",
         "status":"PENDING","expiresAt":"2026-09-11T02:46:09.677Z",
         "externalRef":"WHMCS-100234"}}
```

`transaction.paid`:

```json
{"event":"transaction.paid","createdAt":"2026-09-11T01:47:39.268Z",
 "data":{"id":"3f2a1b4c-…","amount":100,"paidAt":"2026-09-11T01:47:35.000Z",
         "status":"PAID","endToEndId":"E00000000202601011200abcdef12345",
         "externalRef":"WHMCS-100234"}}
```

O ID da transação vem em **`data.id`**. Note que `data.endToEndId` — o
identificador Pix ponta a ponta, útil para conciliação bancária — **só existe no
webhook**; o `GET /v1/transactions/{id}` não devolve esse campo.

### ⚠ O webhook NÃO é assinado

Headers recebidos, na íntegra:

```
accept: */*                        content-length: 276
accept-encoding: gzip, br          content-type: application/json
accept-language: *                 sec-fetch-mode: cors
user-agent: RivonPay-Webhooks/1.0  x-forwarded-for: <ip de nuvem>
x-forwarded-proto: https
```

**Não há cabeçalho de assinatura** — nem HMAC, nem token compartilhado, nem nada
que prove a origem. Qualquer pessoa que descubra a URL do callback pode postar

```json
{"event":"transaction.paid","data":{"id":"…","status":"PAID"}}
```

e, num módulo que confiasse no corpo recebido, **dar baixa em fatura sem pagamento**.

Por isso a regra do briefing não é preciosismo, é a **única** proteção existente:
do POST sai apenas o ID; o status vem sempre de `GET /v1/transactions/{id}`
autenticado. O corpo é tratado como um palpite sobre o que consultar, nunca como
prova de pagamento.

O `x-forwarded-for` traz um IP de nuvem e é um filtro fraco, que não deve ser
usado como controle: a RivonPay não publica lista de IPs, o endereço pode mudar
sem aviso, e o cabeçalho é forjável quando não há proxy confiável na frente. A
reconsulta é estritamente melhor e já resolve.

---

## 7. Episódio `AcquirerUnavailable` — RESOLVIDO

> **Status: resolvido em 2026-09-11 00:15 UTC.** Depois de ~20 minutos falhando,
> o mesmo payload passou sem nenhuma alteração no código e a cobrança foi criada.
> Era indisponibilidade temporária da adquirente. O registro abaixo fica porque o
> módulo **precisa tratar esse 502 com elegância** — ele volta a acontecer.

Durante a janela de falha, toda tentativa de criar cobrança retornava:

```json
{ "ok": false, "code": "AcquirerUnavailable",
  "message": "Não foi possível gerar a cobrança agora. Tente novamente.",
  "details": "RAPDYN recusou a cobrança." }
```

`RAPDYN` é a adquirente roteada para esta conta.

**Descartado como causa** — todas estas variações deram exatamente o mesmo 502:

| Variação testada | Resultado |
|---|---|
| Repetição do mesmo payload (transitório?) | 502 idêntico |
| `amount` R$1 / R$5 / R$10 / R$20 / R$25 / R$50 / R$100 / R$500 / R$1.000 | 502 idêntico |
| `phone` com e sem `+55` | 502 idêntico |
| Outro cliente e outro CPF válido | 502 idêntico |
| `CNPJ` em vez de `CPF` | 502 idêntico |
| Com e sem `expiresInSeconds` | 502 idêntico |

**Valor mínimo não é a causa.** A API tem sim um mínimo, mas ele é de R$ 1,00 e
rejeita com `422 AmountBelowMinimum` — erro claro e diferente. Tudo de R$ 1,00 para
cima dá o mesmo 502.

**A camada de validação está funcionando**, o que prova que o 502 é a jusante dela:

- `{"amount":1,...}` → `422 AmountBelowMinimum`
- `{"amount":"1000",...}` → `400 Invalid input: expected number, received string`
- `{"amount":-5,...}` → `400 Too small: expected number to be >0`
- `{"amount":1000}` sem `customer` → `400 ValidationError`

Ou seja: **não era o payload**. Payload ruim vira `400`/`422` com mensagem específica;
o que se recebia era `502` vindo da adquirente, depois de tudo validado e autenticado.

**Lição para o módulo:** `502 AcquirerUnavailable` é uma falha **transitória e
esperada**, não um erro de programação. O tratamento correto é:

- não gravar transação nem marcar a fatura;
- mostrar ao cliente uma mensagem em português pedindo para tentar de novo em
  instantes (a própria API já sugere isso em `message`);
- registrar o `details` no Gateway Log para diagnóstico;
- **nunca** deixar o erro vazar como exceção ou página em branco na fatura.

---

## 8. Split — segregação de valores

Permite dividir a cobrança entre vários recebedores. Campo `split` opcional no
corpo da criação, **máximo 20 itens**:

```json
"split": [
  { "recipientSplitId": "00000000-0000-4000-8000-000000000001",
    "amountType": "PERCENTAGE", "value": 1000 },
  { "recipientSplitId": "00000000-0000-4000-8000-000000000002",
    "amountType": "FIXED", "value": 500 }
]
```

### ⚠ A unidade de `value` muda conforme o `amountType`

Este é o ponto perigoso, e **não está no `openapi.json`** — a spec declara apenas
`integer`, sem unidade:

| `amountType` | Unidade de `value` | Exemplo |
|---|---|---|
| `FIXED` | **centavos** | `500` = R$ 5,00 |
| `PERCENTAGE` | **centésimos de %** | `1000` = **10%**, não 1000% nem 10,00% |

Quem seguir só a spec manda `10` esperando 10% e recebe **0,1%** — um erro de
100× que não gera erro nenhum, apenas divide errado e silenciosamente.

### Verificado contra a API (2026-09-11)

Duas cobranças de R$ 5,00, sem pagar — `splits[].amount` já vem calculado na
resposta da criação, o que permite conferir a unidade sem mover dinheiro:

| Enviado | `splits[0].amount` | Leitura |
|---|---|---|
| `FIXED`, `value: 50` | `50` → R$ 0,50 | centavos confirmado |
| `PERCENTAGE`, `value: 1000` | `45` → R$ 0,45 | 10% confirmado |

O segundo caso confirma duas coisas de uma vez: R$ 0,45 é 10% de **R$ 4,50**, o
líquido, e não de R$ 5,00. Ou seja, a unidade **e** a base de cálculo.

> **Para testar split, use este método.** `splits[].amount` na resposta da
> criação é a fonte confiável, e não exige pagamento nem consulta ao painel.

### Limites e erros do split

Verificado em 2026-09-11:

| Cenário | Resposta |
|---|---|
| Split **igual** ao líquido | `201` — permitido |
| Split 1 centavo **acima** do líquido | `422 SplitExceedsNet` |
| `PERCENTAGE` de 100% | `201` — permitido (repassa o líquido inteiro) |
| Recebedor inexistente | `422 SplitRecipientNotFound` |

```json
{"ok":false,"code":"SplitExceedsNet",
 "message":"A soma do split (R$ 30,00) passa do valor líquido da cobrança (R$ 0,57)."}
```

O limite é o líquido, e ele pode ser consumido por inteiro. Com **valor fixo**
isso estoura facilmente em faturas pequenas: a taxa de R$ 0,50 sai primeiro, de
modo que um split fixo de R$ 0,50 já não cabe numa cobrança de R$ 1,00.

**Como o módulo trata:** erro de split é configuração do lojista, não problema do
cliente. Em vez de bloquear o pagamento com uma mensagem que o cliente não
entende, a cobrança é **refeita sem split**, o pagamento segue, e o motivo vai
para o Gateway Log como `split-recusado-pela-API`. O desvio favorece o lojista,
que recebe integral — nunca um terceiro.

### Incide sobre o líquido, não sobre o bruto

Confirmado numericamente acima, e coerente com a descrição da rota:

> "O split é opcional; quando presente, incide sobre o valor líquido — a taxa
> sai antes e é paga apenas pelo lojista da cobrança."

A ordem é `amount → (− fee) → netAmount → split`. Como a taxa é fixa em R$ 0,50,
ela pesa desproporcionalmente em valores baixos: numa cobrança de R$ 1,00 o
líquido é R$ 0,50, e um split fixo de R$ 0,50 consumiria **todo** o líquido.

### Não há API para gerenciar recebedores

Nenhuma rota do `openapi.json` cria, lista ou consulta recebedores de split —
busca por `recipient`, `split` ou `beneficiario` nos caminhos não retorna nada.
Os `recipientSplitId` têm de ser obtidos no painel da RivonPay e configurados
manualmente de onde forem usados.

### Resposta

O objeto devolvido traz `splits` como array de
`{ recipientSplitId, amount }`. Em cobranças sem split, vem `[]` — confirmado nas
cobranças reais. **A unidade de `splits[].amount` na resposta não foi verificada**
(presumivelmente centavos, como todo valor da API, mas isso é inferência, não
observação).

### Status no módulo

**Implementado e verificado.** Configurável no admin e desligado por padrão:
recebedor, tipo e valor. O admin informa no formato natural (`10` para 10%,
`5,00` para R$ 5,00) e a conversão para a unidade da API acontece em
`rivonpay_splitFor()`, num lugar só.

Configuração inválida não impede o pagamento: registra `split-ignorado` no
Gateway Log e emite a cobrança sem divisão. Dividir errado é pior que não dividir.

O mapeamento adotado é **fixo global** — todo pagamento do gateway divide com o
mesmo recebedor. Split por produto ou por cliente exigiria campos personalizados
e soma de regras por item da fatura, o que não foi necessário até agora.

---

## Situação da verificação

| Item | Situação |
|---|---|
| Autenticação | ✅ `Basic base64(pk:sk)` |
| `status` inicial | ✅ `PENDING` |
| `status` de pago | ✅ `PAID` |
| `qrCodeImage` preenchido? | ✅ Sim, data URI PNG |
| Payload do webhook | ✅ `{event, createdAt, data}`, id em `data.id` |
| Assinatura do webhook | ✅ Verificado: **não existe** |
| Unidades do split | ✅ FIXED em centavos, PERCENTAGE em centésimos de % |

Tudo o que o módulo usa foi observado contra a API real.

### Ciclo de status observado

```
PENDING  ->  PAID
```

Ambos em MAIÚSCULAS, e coerentes entre a API e o webhook.

Não foram observados os status de expiração e estorno. Como
`POST /v1/transactions/{id}/refund` existe na API, deve haver ao menos mais um
valor — por isso `$paidStatuses` é uma lista configurável, e não uma comparação
fixa com `PAID`.

### Taxa

`fee` é **fixa, não percentual**: R$ 0,50 tanto numa cobrança de R$ 10,00 quanto
numa de R$ 1,00 (onde representa 50% do valor). `netAmount = amount - fee`, e
nenhum dos dois muda com o pagamento.

### Como reproduzir

Com `tests/.env` preenchido:

```bash
./tests/rivonpay_test.sh                       # cria e imprime o status inicial
./tests/rivonpay_test.sh <id>                  # depois de pagar, imprime o status
TEST_POSTBACK_URL=<url> ./tests/rivonpay_test.sh   # captura o payload do webhook
```

(ou `php tests/rivonpay_test.php` onde houver PHP)
