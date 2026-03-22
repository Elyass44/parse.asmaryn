# Parse — Resume Parser

A Symfony 7.4 API that accepts PDF résumé uploads, extracts structured data via Mistral AI, and delivers results synchronously (polling) or asynchronously (webhook). Deployable on a VPS via Docker + Traefik.

Frontend uses **Tailwind CSS** (installed via Symfony AssetMapper). Use Tailwind utility classes for all UI work — no custom CSS unless strictly necessary.

Full MVP spec: `.claude/mvp.md`
**Current progress (read before starting any work): `.claude/progress.md`**

---

## Architecture

The project follows **Domain-Driven Design** with a strict three-layer structure:

```
src/
├── Domain/          # Business logic — no framework dependencies
│   └── Parsing/
│       ├── Model/          # Entities, Value Objects, Enums (ParseJob, ParseResult, JobStatus…)
│       ├── Repository/     # Repository interfaces (not implementations)
│       ├── Service/        # Domain services (PdfExtractor, TextCleaner, SchemaValidator…)
│       ├── Command/        # Messenger messages (ParseResumeCommand, NotifyWebhookCommand)
│       ├── Handler/        # Messenger handlers (ParseResumeHandler, NotifyWebhookHandler)
│       └── Exception/      # Domain exceptions (ScannedPdfException, InvalidAiOutputException…)
├── Infrastructure/  # Adapters for external systems
│   ├── Ai/                 # MistralProvider (implements AiProviderInterface)
│   ├── Persistence/        # Doctrine entities, repositories, migrations
│   └── Http/               # Symfony HttpClient wrappers
└── UI/              # Entry points
    └── Api/
        ├── Controller/     # Slim controllers — validate input, delegate to domain, return response
        └── DTO/            # Request/response DTOs with OpenAPI annotations
```

### Key rules

- **Domain layer has zero framework imports.** No Symfony, no Doctrine annotations — only PHP and your own interfaces.
- **Entities live in `Domain/Parsing/Model/`**, mapped by Doctrine XML or attribute mapping in `Infrastructure/Persistence/`.
- **Repository interfaces** are declared in `Domain`, implementations in `Infrastructure/Persistence`.
- **Controllers do one thing**: validate HTTP input → call a domain service or dispatch a command → return a response. Business logic never lives in a controller.
- **Messenger handlers** orchestrate domain services; they do not contain business logic themselves.

---

## Code quality standards

### PHP
- **PHP 8.3+** features encouraged: readonly properties, enums, named arguments, fibers where appropriate.
- Strict types declared in every file: `declare(strict_types=1);`
- No `mixed` types without justification. Prefer explicit union types over `mixed`.
- Value Objects are **immutable** — no setters, all state set in constructor.
- Entities use **private/readonly** properties with named constructors (`ParseJob::create(...)`) rather than raw constructors.
- Avoid `array` as a return/parameter type when a typed DTO or Value Object is possible.

### Exceptions
- Always throw domain-specific exceptions (`ScannedPdfException`, `AiProviderException`, `InvalidAiOutputException`), never generic `\Exception` or `\RuntimeException`.
- Catch at the handler boundary, not deep inside services.

### Symfony
- Use **constructor injection** everywhere. No property injection, no `ContainerAwareTrait`.
- Services are `private` by default in `services.yaml`. Expose only what the DI container needs.
- Never call `$this->getDoctrine()` or `$this->get('...')` in controllers — inject repositories directly.
- Use **Symfony Validator** for input validation; never validate manually in controllers.
- Use `#[Route]` attributes on controllers; keep route names consistent: `api_parse_upload`, `api_parse_status`, `api_health`.

### Database
- UUIDs for all primary keys (`Uuid` type from `symfony/uid`).
- Enums mapped as Doctrine `string` type backed by PHP enums.
- All migrations are **reversible** — implement both `up()` and `down()`.
- Never use `$em->flush()` inside a loop.

### Testing
- Unit tests live in `tests/Unit/` and test domain services and value objects in isolation.
- Integration tests live in `tests/Integration/` and may use the database.
- Functional/API tests live in `tests/Functional/` using Symfony's `WebTestCase`.
- No test should rely on execution order.
- Aim for **100% coverage of domain services**; infrastructure adapters need at least one integration test.
- Use PHPUnit **data providers** for testing multiple résumé formats (EN/FR, edge cases).

---

## Development commands

```bash
make start       # Build and start all containers
make stop        # Stop containers
make test        # Run PHPUnit test suite
make lint        # Run PHP CS Fixer + PHPStan
```

---

## Environment variables

| Variable | Description | Default |
|---|---|---|
| `DATABASE_URL` | PostgreSQL DSN | `pgsql://db_user:db_password@postgres:5432/db_name` |
| `MESSENGER_TRANSPORT_DSN` | RabbitMQ DSN for async transport | `amqp://guest:guest@rabbitmq:5672/%2f/messages` |
| `MISTRAL_API_KEY` | Mistral API key | — |
| `MISTRAL_MODEL` | Mistral model name | `mistral-small-latest` |
| `WEBHOOK_SECRET` | HMAC-SHA256 signing key for `X-Signature` | — |
| `DEMO_RATE_LIMIT` | Max parse requests per IP per day | `5` |
| `LOG_LEVEL` | Monolog minimum log level | `info` |

---

## API surface

| Method | Path | Description |
|---|---|---|
| `POST` | `/api/parse` | Upload a PDF, get a job ID back (`202`) |
| `GET` | `/api/parse/{id}` | Poll job status + result |
| `GET` | `/api/health` | Liveness check (DB + queue) |
| `GET` | `/api/doc` | Swagger UI (dev only) |
| `GET` | `/api/doc.json` | Raw OpenAPI spec (dev only) |

### Error format (all errors)

```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "The uploaded file is not a valid PDF.",
    "details": {}
  }
}
```

Error codes: `VALIDATION_ERROR` (422), `SCANNED_PDF` (422), `NOT_FOUND` (404), `RATE_LIMITED` (429), `PROCESSING_ERROR` (500).

---

## Processing pipeline

```
POST /api/parse
  └─► ParseUploadController
        ├─ Validate file (magic bytes, size, MIME)
        ├─ Store to var/uploads/{job_id}.pdf
        ├─ Persist ParseJob (status=pending)
        └─ Dispatch ParseResumeCommand → async transport (RabbitMQ)
              └─► ParseResumeHandler (worker container)
                    ├─ PdfExtractor::extract()       → raw text
                    ├─ TextCleaner::clean()          → normalised text (≤3000 chars)
                    ├─ MistralProvider::extract()    → raw JSON
                    ├─ SchemaValidator::validate()   → typed DTO
                    ├─ Persist ParseResult
                    ├─ ParseJob status = done
                    └─ Dispatch NotifyWebhookCommand (if webhook_url set)
                          └─► NotifyWebhookHandler
                                ├─ POST JSON + X-Signature to webhook_url
                                └─ ParseJob.webhook_status = delivered | failed
```

---

## Deployment

### CI/CD (GitHub Actions)

Deployment is triggered by pushing a **version tag** (`v*`):

```bash
git tag v1.0.0 && git push origin v1.0.0
```

The workflow (`.github/workflows/deploy.yml`):
1. Builds the Docker image and pushes to GHCR as `ghcr.io/elyass44/parse.asmaryn:<tag>`
2. SSHs into the VPS and runs `prod/deploy.sh` with the new `IMAGE_TAG`

### Production file structure

```
prod/
├── compose.prod.yaml   # prod stack — pulls image from GHCR, no build on server
├── deploy.sh           # pull → migrate → docker compose up -d
├── .env.prod.dist      # template — copy to .env.prod on the VPS and fill in secrets
└── .env.prod           # gitignored — real secrets, lives only on the VPS
```

### VPS setup (one-time)

1. Clone the repo to `/srv/parse`
2. `cp prod/.env.prod.dist prod/.env.prod` and fill in all values
3. Ensure a `traefik` external Docker network exists
4. Add GitHub secrets: `VPS_HOST`, `VPS_USER`, `VPS_SSH_KEY`

### Key prod decisions

- The **same `docker/Dockerfile`** is used for both `php` and `worker` services — the worker overrides the command to run `messenger:consume async`
- `nginx` in prod does **not** embed app code — it proxies to the `php` container; static assets are served directly
- Migrations run automatically on every deploy before containers are restarted

---

## Out of scope (MVP)

Authentication, multi-tenancy, billing, BYOK, multi-provider AI, OCR, backoffice UI, skill taxonomy, CV scoring.
