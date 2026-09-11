#!/usr/bin/env bash
# Fase 1 - mesma validacao do rivonpay_test.php, para maquinas sem PHP.
#
# Uso:
#   RIVONPAY_PK=... RIVONPAY_SK=... ./tests/rivonpay_test.sh
#   ou preencha tests/.env e rode: ./tests/rivonpay_test.sh
#   consultar uma cobranca: ./tests/rivonpay_test.sh <id>

set -uo pipefail

BASE="https://api.rivonpay.com.br"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Diretorio temporario proprio. Nao usar /tmp: sob Git Bash no Windows o node
# e nativo e nao resolve caminhos MSYS como /tmp/x.json.
TMPD="$(mktemp -d 2>/dev/null || echo "${TEMP:-/tmp}/rivonpay.$$")"
mkdir -p "$TMPD"
trap 'rm -rf "$TMPD"' EXIT

# Carrega tests/.env SEM sobrescrever o que ja veio do ambiente,
# e ignorando valores vazios (placeholders do .env.example).
if [ -f "$DIR/.env" ]; then
  while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in ''|'#'*) continue;; *'='*) ;; *) continue;; esac
    k="${line%%=*}"
    v="${line#*=}"
    k="$(printf '%s' "$k" | tr -d '[:space:]')"
    v="${v%\"}"; v="${v#\"}"; v="${v%\'}"; v="${v#\'}"
    [ -z "$k" ] && continue
    [ -z "$v" ] && continue
    if [ -z "${!k:-}" ]; then
      export "$k=$v"
    fi
  done < "$DIR/.env"
fi

: "${RIVONPAY_PK:?defina RIVONPAY_PK (ambiente ou tests/.env)}"
: "${RIVONPAY_SK:?defina RIVONPAY_SK (ambiente ou tests/.env)}"

mask() { local s="$1"; if [ ${#s} -le 10 ]; then printf '%*s' ${#s} '' | tr ' ' '*'; else printf '%s******%s' "${s:0:6}" "${s: -4}"; fi; }

b64() { printf '%s' "$1" | base64 -w0 2>/dev/null || printf '%s' "$1" | base64; }

# Pretty-print JSON usando node se existir; senao imprime cru.
pp() { if command -v node >/dev/null 2>&1; then node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>{try{console.log(JSON.stringify(JSON.parse(s),null,2))}catch(e){console.log(s)}})'; else cat; fi; }

echo "RivonPay - validacao da API"
echo "Base .......: $BASE"
echo "Public key .: $(mask "$RIVONPAY_PK")"
echo "Secret key .: $(mask "$RIVONPAY_SK")"

# openapi.json declara: integracao = http basic, "Basic base64(publicKey:secret)"
declare -a LABELS=("Basic base64(pk:sk)" "Basic base64(sk:)" "Basic base64(sk:x)" "Basic base64(pk:)")
declare -a VALUES=(
  "Basic $(b64 "${RIVONPAY_PK}:${RIVONPAY_SK}")"
  "Basic $(b64 "${RIVONPAY_SK}:")"
  "Basic $(b64 "${RIVONPAY_SK}:x")"
  "Basic $(b64 "${RIVONPAY_PK}:")"
)

# ---- modo consulta ---------------------------------------------------------
if [ $# -ge 1 ]; then
  echo; echo "===== GET /v1/transactions/$1 ====="
  for i in "${!LABELS[@]}"; do
    code=$(curl -s -o "$TMPD/get.json" -w '%{http_code}' --max-time 30 \
      -H "Accept: application/json" -H "Authorization: ${VALUES[$i]}" \
      "$BASE/v1/transactions/$1")
    printf '[%-22s] HTTP %s\n' "${LABELS[$i]}" "$code"
    if [ "$code" -ge 200 ] && [ "$code" -lt 300 ]; then
      pp < "$TMPD/get.json"
      exit 0
    fi
  done
  echo "Nao foi possivel consultar."
  exit 1
fi

# ---- modo criacao ----------------------------------------------------------
: "${TEST_DOC_NUMBER:?defina TEST_DOC_NUMBER (CPF/CNPJ do cliente de teste) em tests/.env}"

AMOUNT="${TEST_AMOUNT_CENTS:-1000}"   # centavos: 1000 = R$ 10,00
NAME="${TEST_NAME:-Cliente Teste}"
EMAIL="${TEST_EMAIL:-teste@example.com}"
PHONE="${TEST_PHONE:-61999999999}"
DOCTYPE="${TEST_DOC_TYPE:-CPF}"

# postbackUrl e opcional. Definir TEST_POSTBACK_URL (ex.: uma URL de captura como
# webhook.site) faz a RivonPay entregar o aviso de pagamento la, o que permite
# ver o formato real do payload - nao documentado no openapi.json.
POSTBACK=""
if [ -n "${TEST_POSTBACK_URL:-}" ]; then
  POSTBACK=",\"postbackUrl\":\"$TEST_POSTBACK_URL\""
  echo "postback ...: $TEST_POSTBACK_URL"
fi

# Obrigatorios no openapi.json: amount + customer{name,email,phone,document{type,number}}
PAYLOAD=$(cat <<JSON
{"amount":$AMOUNT,"customer":{"name":"$NAME","email":"$EMAIL","phone":"$PHONE","document":{"type":"$DOCTYPE","number":"$TEST_DOC_NUMBER"}},"externalRef":"whmcs-fase1-$(date +%Y%m%d%H%M%S)"$POSTBACK}
JSON
)

echo; echo "===== 1) POST /v1/transactions/ ====="
echo "Body enviado:"; printf '%s' "$PAYLOAD" | pp

> "$TMPD/post.json"
OK_AUTH=""
CREATED=0
for i in "${!LABELS[@]}"; do
  code=$(curl -s -o "$TMPD/post.json" -D "$TMPD/post.hdr" -w '%{http_code}' --max-time 30 \
    -X POST "$BASE/v1/transactions/" \
    -H "Content-Type: application/json" -H "Accept: application/json" \
    -H "Authorization: ${VALUES[$i]}" -d "$PAYLOAD")
  echo; printf '[%-22s] HTTP %s\n' "${LABELS[$i]}" "$code"
  pp < "$TMPD/post.json"

  # 401 = esquema/credencial rejeitado -> tenta o proximo modo.
  # Qualquer outro codigo significa que a AUTENTICACAO PASSOU; o que vier
  # depois e erro de negocio e nao adianta testar outro modo.
  if [ "$code" != "401" ]; then
    OK_AUTH="${VALUES[$i]}"
    echo; echo ">>> AUTENTICACAO ACEITA: ${LABELS[$i]}"
    if [ "$code" -ge 200 ] && [ "$code" -lt 300 ]; then
      CREATED=1
    else
      echo ">>> ...mas a cobranca NAO foi criada (HTTP $code) - erro de negocio, nao de credencial."
    fi
    break
  fi
done

if [ -z "$OK_AUTH" ]; then
  echo; echo "Todos os modos deram 401. Confira as chaves no painel e rode de novo."
  exit 1
fi

if [ "$CREATED" -eq 0 ]; then
  echo
  echo "As chaves estao corretas. Resolva o erro acima (conta/adquirente) e rode de novo."
  exit 2
fi

echo; echo "===== 2) Headers da resposta aceita ====="
cat "$TMPD/post.hdr"

ID=""
if command -v node >/dev/null 2>&1; then
  ID=$(node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>{try{console.log(JSON.parse(s).id||"")}catch(e){console.log("")}})' < "$TMPD/post.json" 2>/dev/null)
fi

if [ -z "$ID" ]; then
  echo "Nao consegui extrair o id automaticamente - veja o JSON acima."
  exit 0
fi

echo; echo "===== 3) GET /v1/transactions/$ID ====="
curl -s --max-time 30 -H "Accept: application/json" -H "Authorization: $OK_AUTH" \
  "$BASE/v1/transactions/$ID" | pp

echo
echo "Pague o Pix (ou marque como pago no painel) e rode:"
echo "  ./tests/rivonpay_test.sh $ID"
echo "para descobrir o valor de status equivalente a PAGO."
