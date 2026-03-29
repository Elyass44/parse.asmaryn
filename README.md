# parse.asmaryn

A Symfony 7.4 REST API that extracts structured data from PDF résumés using Mistral AI or OpenAI. Upload a PDF, get back clean JSON — name, experience, education, skills, languages, and more. Processing is asynchronous: the API returns a job ID immediately, results are retrieved by polling or pushed to a webhook when ready.

## Stack

- **PHP 8.3** / Symfony 7.4
- **PostgreSQL 16** — job and result storage
- **RabbitMQ 3** — async processing queue
- **Mistral AI / OpenAI** — résumé extraction (JSON mode, runtime-switchable)
- **Docker** + Traefik — local dev and VPS deployment

---

## Architecture

Uploads are accepted synchronously, then processed asynchronously by a dedicated worker container:

```
POST /api/parse
  └─► ParseUploadController
        ├─ Validate file (magic bytes, size, MIME)
        ├─ Persist ParseJob (status = pending)
        └─ Dispatch ParseResumeCommand → RabbitMQ

Worker container (messenger:consume async)
  └─► ParseResumeHandler
        ├─ Extract text       (smalot/pdfparser)
        ├─ Clean & truncate   (TextCleaner, max 3 000 chars)
        ├─ Call AI provider   (Mistral or OpenAI, JSON mode)
        ├─ Validate output    (SchemaValidator)
        ├─ Persist ParseResult
        └─ Dispatch NotifyWebhookCommand (if webhook_url provided)
```

The codebase follows Domain-Driven Design with a strict four-layer structure (`Domain → Application → Infrastructure → UI`). See [`docs/`](docs/) for detailed architectural notes.

---

## Prerequisites

- Docker & Docker Compose v2
- Make
- A Mistral AI or OpenAI API key

---

## Local setup

### 1. Clone and configure

```bash
git clone https://github.com/Elyass44/parse.asmaryn.git
cd parse.asmaryn
cp .env .env.local
```

Edit `.env.local` and fill in at minimum:

```dotenv
DATABASE_URL="postgresql://db_user:db_password@postgres:5432/db_name?serverVersion=16&charset=utf8"
MISTRAL_API_KEY=your_key_here
WEBHOOK_SECRET=any_random_string   # generate with: openssl rand -hex 32
```

### 2. Start the stack

```bash
make build   # first time — builds the PHP image
make start   # subsequent starts
```

Services:

| Service | URL |
|---|---|
| App | http://localhost:8080 |
| API docs (Swagger) | http://localhost:8080/api/doc |
| RabbitMQ UI | http://localhost:15672 (guest / guest) |
| Adminer | http://localhost:8081 |

### 3. Run migrations

```bash
make db-migrate
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
make worker   # run the Messenger worker manually (already starts with the stack)
```

---

## Running tests

```bash
make test     # PHPUnit
make lint     # PHP CS Fixer (dry-run) + PHPStan
make fix      # auto-fix CS violations
```

---

## Environment variables

| Variable | Description | Default |
|---|---|---|
| `DATABASE_URL` | PostgreSQL DSN | — |
| `MESSENGER_TRANSPORT_DSN` | RabbitMQ DSN | `amqp://guest:guest@rabbitmq:5672/%2f/messages` |
| `AI_PROVIDER` | Active AI provider: `mistral` or `openai` | `mistral` |
| `MISTRAL_API_KEY` | Mistral API key | — |
| `MISTRAL_MODEL` | Mistral model name | `mistral-small-latest` |
| `OPENAI_API_KEY` | OpenAI API key | — |
| `OPENAI_MODEL` | OpenAI model name | `gpt-4o-mini` |
| `WEBHOOK_SECRET` | HMAC-SHA256 signing key for `X-Signature` headers — generate with `openssl rand -hex 32` | — |
| `DEMO_RATE_LIMIT` | Max parse requests per IP per day | `5` |
| `LOG_LEVEL` | Monolog minimum level (`debug`, `info`, `warning`, `error`) | `info` |

---

## API reference

Full interactive docs at [`/api/doc`](http://localhost:8080/api/doc) (Swagger UI, `dev` environment only).

| Method | Path | Description |
|---|---|---|
| `POST` | `/api/parse` | Upload a PDF (`multipart/form-data`), returns `202` with a `job_id` |
| `GET` | `/api/parse/{id}` | Poll job status; returns result when `status = done` |
| `GET` | `/api/health` | Liveness check (DB + queue) |

### Upload a résumé

```bash
curl -X POST http://localhost:8080/api/parse \
  -F "file=@resume.pdf" \
  -F "webhook_url=https://yourserver.com/webhook"   # optional
```

Response `202`:
```json
{ "job_id": "018f1a2b-...", "status": "pending", "poll_url": "/api/parse/018f1a2b-..." }
```

### Poll for the result

```bash
curl http://localhost:8080/api/parse/018f1a2b-...
```

Response `200` when done:
```json
{
  "job_id": "018f1a2b-...",
  "status": "done",
  "result": {
    "personal": { "name": "Jane Doe", "email": "jane@example.com", "phone": "...", "location": "..." },
    "summary": "...",
    "experiences": [{ "title": "...", "company": "...", "start": "2022-01", "end": null, "current": true }],
    "education": [{ "degree": "...", "institution": "...", "start": "2018-09", "end": "2022-06" }],
    "skills": ["Python", "Docker"],
    "languages": [{ "language": "French", "level": "Native" }],
    "certifications": []
  }
}
```

---

## Webhook signature verification

Every webhook POST includes an `X-Signature` header — an HMAC-SHA256 hex digest of the raw JSON body signed with `WEBHOOK_SECRET`. Polling via `GET /api/parse/{id}` is always available as a fallback if webhook delivery fails.

### PHP

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

### Python

```python
import hmac, hashlib, os

def verify_webhook(body: bytes, signature: str) -> bool:
    secret = os.environ["WEBHOOK_SECRET"].encode()
    expected = hmac.new(secret, body, hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, signature)

body = request.get_data()
if not verify_webhook(body, request.headers.get("X-Signature", "")):
    abort(401)
```

---

## Deployment

Deployment is triggered by pushing a version tag:

```bash
git tag v1.0.0 && git push origin v1.0.0
```

The GitHub Actions workflow (`.github/workflows/deploy.yml`) builds the Docker image, pushes it to GHCR, SSHs into the VPS, and runs `prod/deploy.sh` — which pulls the new image, runs migrations, and restarts the stack with zero-downtime.

### First-time VPS setup

```bash
git clone https://github.com/Elyass44/parse.asmaryn.git /srv/parse
cd /srv/parse
cp prod/.env.prod.dist prod/.env.prod
# Fill in all values in prod/.env.prod
```

Ensure a `traefik` external Docker network exists on the VPS, then add these GitHub repository secrets: `VPS_HOST`, `VPS_USER`, `VPS_SSH_KEY`.

---

## Known limitations

- **Scanned PDFs** (image-only, no embedded text) are not supported — the job fails with error code `SCANNED_PDF`. OCR is out of scope.
- **No authentication** — the API is public in this MVP. Rate limiting (5 requests/IP/day) is the only protection.
- **Language support** — English and French résumés have been tested. Other languages may produce incomplete or inaccurate extraction.
- **File size** — maximum 5 MB per upload.

---

## Roadmap

- Authentication / API keys
- Multi-tenancy and usage metering
- BYOK (bring-your-own-key) for AI providers
- OCR support for scanned PDFs
- Skill taxonomy normalisation
- CV scoring and ranking
- Backoffice UI
