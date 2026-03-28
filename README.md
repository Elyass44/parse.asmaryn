# parse.asmaryn

A Symfony 7.4 REST API that extracts structured data from PDF résumés using Mistral AI. Upload a PDF, get back clean JSON — name, experience, education, skills, languages, and more. Results are delivered synchronously via polling or pushed to a webhook.

## Stack

- **PHP 8.3** / Symfony 7.4
- **PostgreSQL 16** — job and result storage
- **RabbitMQ 3** — async processing queue
- **Mistral AI** — résumé extraction (JSON mode)
- **Docker** + Traefik — local dev and VPS deployment

---

## Local setup

### Prerequisites

- Docker & Docker Compose
- Make

### 1. Clone and configure

```bash
git clone https://github.com/Elyass44/parse.asmaryn.git
cd parse.asmaryn
cp .env .env.local
```

Edit `.env.local` and fill in:

```dotenv
DATABASE_URL="postgresql://db_user:db_password@postgres:5432/db_name?serverVersion=16&charset=utf8"
MISTRAL_API_KEY=your_key_here
WEBHOOK_SECRET=any_random_string
```

### 2. Start the stack

```bash
make build   # first time — builds the PHP image
make start   # subsequent starts
```

Services available at:

| Service | URL |
|---|---|
| App | http://localhost:8080 |
| RabbitMQ UI | http://localhost:15672 (guest / guest) |
| Adminer | http://localhost:8081 |

### 3. Install dependencies and run migrations

```bash
make bash
composer install
php bin/console doctrine:migrations:migrate --no-interaction
exit
```

### 4. Build CSS

```bash
make css      # one-time build
make watch    # rebuild on change (keep running in a separate terminal)
```

---

## Daily workflow

```bash
make start    # start containers
make watch    # Tailwind watch (separate terminal)
make stop     # stop containers
make bash     # shell into the PHP container
```

---

## Testing & linting

```bash
make test     # PHPUnit
make lint     # PHP CS Fixer (dry-run) + PHPStan
```

---

## Environment variables

| Variable | Description | Default |
|---|---|---|
| `DATABASE_URL` | PostgreSQL DSN | — |
| `MESSENGER_TRANSPORT_DSN` | RabbitMQ DSN | `amqp://guest:guest@rabbitmq:5672/...` |
| `MISTRAL_API_KEY` | Mistral API key | — |
| `MISTRAL_MODEL` | Model to use | `mistral-small-latest` |
| `WEBHOOK_SECRET` | HMAC-SHA256 signing secret for webhooks | — |
| `DEMO_RATE_LIMIT` | Max parses per IP per day | `5` |
| `LOG_LEVEL` | Monolog minimum level | `info` |

---

## Webhook signature verification

Every webhook POST includes an `X-Signature` header — an HMAC-SHA256 hex digest of the raw JSON body, signed with your `WEBHOOK_SECRET`.

> Polling via `GET /api/parse/{id}` is always available as a fallback if webhook delivery fails or your server is temporarily unavailable.

### Generating a secret

```bash
openssl rand -hex 32
```

Set the value as `WEBHOOK_SECRET` in your environment.

### Verifying the signature — PHP

```php
$body      = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
$expected  = hash_hmac('sha256', $body, getenv('WEBHOOK_SECRET'));

if (!hash_equals($expected, $signature)) {
    http_response_code(401);
    exit('Invalid signature');
}

$payload = json_decode($body, true);
```

### Verifying the signature — Python

```python
import hmac, hashlib, os

def verify_webhook(body: bytes, signature: str) -> bool:
    secret = os.environ["WEBHOOK_SECRET"].encode()
    expected = hmac.new(secret, body, hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, signature)

# In your request handler:
body = request.get_data()  # raw bytes
if not verify_webhook(body, request.headers.get("X-Signature", "")):
    abort(401)

payload = request.get_json()
```

---

## Known limitations

- Scanned PDFs (image-only) are not supported — text extraction will fail with a clear error
- No authentication for the MVP — the API is public
- English and French résumés tested; other languages may produce incomplete results
