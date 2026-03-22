# Resume Parser — MVP Feature Spec

> Scope: Mistral-only · Demo page + REST API · Webhook delivery · No auth · No billing · No multi-tenancy  
> Goal: working end-to-end parse loop, publicly demoable, deployable on VPS via Docker

---

## Epics overview

| # | Epic | Tickets |
|---|------|---------|
| 1 | Project setup & infrastructure | 4 |
| 2 | PDF ingestion | 3 |
| 3 | Async processing pipeline | 7 |
| 4 | Mistral integration | 3 |
| 5 | REST API | 4 |
| 6 | Demo page | 3 |
| 7 | Hardening & limits | 3 |

---

## Epic 1 — Project setup & infrastructure

### MVP-001 · Symfony project bootstrap

**Description**  
Initialise the Symfony project with the required dependencies and folder structure matching the defined domain layout.

**Acceptance criteria**
- Symfony 7.x project created
- Dependencies installed: `symfony/messenger`, `symfony/http-client`, `nelmio/api-doc-bundle`, `smalot/pdfparser`, `doctrine/orm`
- Folder structure in place: `src/Domain/Parsing`, `src/Infrastructure/Ai`, `src/UI/Api`
- `.env` and `.env.test` configured with placeholders
- `make` commands available: `start`, `stop`, `test`, `lint`

**Notes**  
Use `symfony/skeleton`, not `symfony/website-skeleton`. No Twig needed for the API layer.

---

### MVP-002 · Docker Compose setup

**Description**  
Create the local development Docker Compose stack.

**Acceptance criteria**
- Services: `php` (8.3-fpm), `nginx`, `postgres` (16), `rabbitmq` (3-management)
- PHP container includes required extensions: `pdo_pgsql`, `intl`, `fileinfo`
- RabbitMQ management UI accessible on port 15672 locally
- `APP_ENV=dev` by default
- Volumes mounted for live code reload

---

### MVP-003 · VPS deployment config

**Description**  
Create production Docker Compose and Traefik labels for deployment on the existing VPS.

**Acceptance criteria**
- Separate `docker-compose.prod.yml`
- Traefik labels configured: HTTPS, correct domain, Let's Encrypt resolver
- Messenger worker runs as a separate container (`bin/console messenger:consume async`)
- Environment variables injected via `.env.prod` (gitignored)
- Basic `deploy.sh` script: pull → build → up with zero-downtime restart

---

### MVP-004 · Database schema & migrations

**Description**  
Create Doctrine entities and initial migration for the two MVP tables.

**Entities**
- `ParseJob`: `id (uuid)`, `status (enum: pending/processing/done/failed)`, `webhook_url (nullable string)`, `webhook_status (nullable enum: pending/delivered/failed)`, `original_filename`, `created_at`, `updated_at`
- `ParseResult`: `id (uuid)`, `job_id (FK)`, `payload (json)`, `created_at`

**Acceptance criteria**
- Entities created with correct types and constraints
- Migration generated and tested (`doctrine:migrations:migrate`)
- Indexes on `parse_job.status` and `parse_job.created_at`

---

## Epic 2 — PDF ingestion

### MVP-010 · PDF upload endpoint

**Description**  
`POST /api/parse` — accepts a multipart PDF upload, validates it, stores the file, and dispatches an async job.

**Request**
```
POST /api/parse
Content-Type: multipart/form-data

file: <binary PDF>
webhook_url: https://ats.example.com/webhooks/resume (optional)
```

**Response `202 Accepted`**
```json
{
  "job_id": "018f1a2b-...",
  "status": "pending",
  "poll_url": "/api/parse/018f1a2b-..."
}
```

**Acceptance criteria**
- Validates: `Content-Type` must be `application/pdf`, max size 5MB
- If `webhook_url` provided: validates it is a valid HTTPS URL, stores on `ParseJob`
- Stores uploaded file to local storage (e.g. `var/uploads/{job_id}.pdf`)
- Creates `ParseJob` record with status `pending`
- Dispatches `ParseResumeCommand` message to Messenger async transport
- Returns `202` with job metadata
- Returns `422` with error detail on validation failure

---

### MVP-011 · PDF text extraction service

**Description**  
`PdfExtractor` service: extracts raw text from a PDF file using `smalot/pdfparser`.

**Acceptance criteria**
- Extracts text from standard text-based PDFs correctly
- Detects scanned/empty PDFs: if extracted text is under 200 characters, throws `ScannedPdfException`
- Strips excessive whitespace and normalises line breaks
- Returns raw text string

**Out of scope**  
OCR for scanned PDFs — return a clear error message only.

---

### MVP-012 · Text cleaning service

**Description**  
`TextCleaner` service: normalises and truncates extracted text before sending to the AI.

**Acceptance criteria**
- Removes repeated blank lines (max 2 consecutive)
- Removes common PDF artefacts (page numbers patterns, repeated headers/footers)
- Truncates to a hard cap of **3000 characters**, appending a note if truncated
- Returns cleaned string and a boolean `was_truncated`

---

## Epic 3 — Async processing pipeline

### MVP-020 · Messenger transport configuration

**Description**  
Configure Symfony Messenger with RabbitMQ as the async transport.

**Acceptance criteria**
- `async` transport configured with RabbitMQ DSN
- `ParseResumeCommand` routed to `async` transport
- Retry strategy: 3 attempts, exponential backoff (1s, 5s, 25s)
- Failed messages routed to `failed` transport (dead letter queue)
- Worker command documented in README

---

### MVP-021 · ParseResumeCommand message

**Description**  
Define the `ParseResumeCommand` Messenger message and its `ParseResumeHandler`.

**Message payload**
```php
class ParseResumeCommand
{
    public function __construct(
        public readonly string $jobId,
        public readonly string $filePath,
    ) {}
}
```

**Handler steps** (in order)
1. Load `ParseJob`, set status to `processing`
2. Extract text via `PdfExtractor`
3. Clean text via `TextCleaner`
4. Call `MistralProvider::extract()`
5. Validate JSON output against schema
6. Persist `ParseResult`
7. Set `ParseJob` status to `done`
8. If `webhook_url` is set: dispatch `NotifyWebhookCommand`

**Acceptance criteria**
- Any exception in steps 2–6 sets job status to `failed`, stores error message, and still dispatches `NotifyWebhookCommand` if `webhook_url` is set
- Handler is idempotent: re-processing a `done` job is a no-op
- Job `updated_at` is refreshed at each status transition

---

### MVP-022 · Job status polling endpoint

**Description**  
`GET /api/parse/{id}` — returns the current status and result of a parse job.

**Response — pending**
```json
{
  "job_id": "018f1a2b-...",
  "status": "pending"
}
```

**Response — done**
```json
{
  "job_id": "018f1a2b-...",
  "status": "done",
  "result": { ... }
}
```

**Response — failed**
```json
{
  "job_id": "018f1a2b-...",
  "status": "failed",
  "error": {
    "code": "SCANNED_PDF",
    "message": "No text could be extracted from this PDF."
  }
}
```

**Acceptance criteria**
- Returns `404` for unknown job IDs
- Returns `200` in all other cases with appropriate body
- `job_id` is always present in every response
- `webhook_status` included in response when a webhook_url was provided (`pending`, `delivered`, `failed`)
- No authentication required for MVP

---

### MVP-023 · Cleanup command

**Description**  
Symfony console command to purge old jobs and their uploaded files.

**Acceptance criteria**
- Command: `app:parse:cleanup --older-than=24h`
- Deletes `ParseJob` + `ParseResult` records older than the given threshold
- Deletes corresponding files from `var/uploads/`
- Logs count of deleted records
- Designed to be called from a cron or Symfony Scheduler (not wired up in MVP)

---

### MVP-024 · NotifyWebhookCommand & handler

**Description**  
After a parse job completes (done or failed), POST the result to the ATS webhook URL if one was provided.

**Payload sent to ATS**
```json
{
  "job_id": "018f1a2b-...",
  "status": "done",
  "result": { ... }
}
```

**For failed jobs**
```json
{
  "job_id": "018f1a2b-...",
  "status": "failed",
  "error": {
    "code": "SCANNED_PDF",
    "message": "No text could be extracted from this PDF."
  }
}
```

**Acceptance criteria**
- Dispatched by `ParseResumeHandler` after job reaches `done` or `failed` — ATS must know about both outcomes
- HTTP POST with `Content-Type: application/json`, timeout 10s
- Includes `X-Signature` header: HMAC-SHA256 of raw JSON body signed with `WEBHOOK_SECRET` env var
- On 2xx response: set `ParseJob.webhook_status = delivered`
- On non-2xx or timeout: let Messenger retry (see MVP-025), do not mark as delivered
- `ParseJob.webhook_status` set to `pending` when command is dispatched

---

### MVP-025 · Webhook retry strategy

**Description**  
Configure Messenger retry behaviour for the `NotifyWebhookCommand` transport.

**Acceptance criteria**
- 3 retry attempts with exponential backoff: 30s → 5min → 30min
- After 3 failures: message moves to dead letter queue, `ParseJob.webhook_status = failed`
- Failed webhook jobs remain queryable via `GET /api/parse/{id}` indefinitely — polling is always the fallback
- Each retry attempt logged with `job_id`, attempt number, and HTTP response code received

---

### MVP-026 · Webhook signature verification doc

**Description**  
Document how ATS clients should verify incoming webhook payloads to confirm they originate from the parser.

**Acceptance criteria**
- README section explaining the `X-Signature` header format
- Verification code example provided in PHP and Python
- `WEBHOOK_SECRET` env var documented in the env vars table with generation instructions
- Explicit note that polling via `GET /api/parse/{id}` is always available as fallback if webhook delivery fails

---

## Epic 4 — Mistral integration

### MVP-030 · MistralProvider service

**Description**  
`MistralProvider` service that calls the Mistral API with JSON mode and returns structured data.

**Acceptance criteria**
- Uses `symfony/http-client` (no SDK dependency)
- Calls `https://api.mistral.ai/v1/chat/completions`
- Model configurable via env var `MISTRAL_MODEL` (default: `mistral-small-latest`)
- API key injected from env var `MISTRAL_API_KEY`
- JSON mode enabled (`response_format: { type: "json_object" }`)
- Timeout: 30s
- Throws `AiProviderException` on HTTP error or malformed response

---

### MVP-031 · Extraction prompt & output schema

**Description**  
Define the system prompt and expected JSON schema for résumé extraction.

**Target JSON schema**
```json
{
  "personal": {
    "name": "string",
    "email": "string",
    "phone": "string",
    "location": "string",
    "linkedin": "string | null",
    "website": "string | null"
  },
  "summary": "string | null",
  "experiences": [
    {
      "title": "string",
      "company": "string",
      "start": "YYYY-MM | null",
      "end": "YYYY-MM | null",
      "current": "boolean",
      "description": "string | null"
    }
  ],
  "education": [
    {
      "degree": "string",
      "institution": "string",
      "start": "YYYY-MM | null",
      "end": "YYYY-MM | null"
    }
  ],
  "skills": ["string"],
  "languages": [
    {
      "language": "string",
      "level": "string | null"
    }
  ],
  "certifications": ["string"]
}
```

**Acceptance criteria**
- System prompt instructs the model to extract only, never infer or hallucinate
- System prompt includes the full schema definition
- Unknown or missing fields return `null`, never fabricated values
- Prompt tested against at least 5 sample résumés (EN + FR)

---

### MVP-032 · JSON output validation

**Description**  
`SchemaValidator` service: validates Mistral's JSON output against the expected schema before persisting.

**Acceptance criteria**
- Validates required top-level keys are present
- Validates `experiences` and `education` are arrays
- Coerces minor issues silently (e.g. missing optional keys → null)
- Throws `InvalidAiOutputException` if output is structurally broken
- On exception: job is marked `failed`, raw Mistral response logged for debugging

---

## Epic 5 — REST API

### MVP-040 · Nelmio API documentation

**Description**  
Configure NelmioApiDocBundle to auto-generate OpenAPI docs from controllers and DTOs.

**Acceptance criteria**
- `/api/doc` serves the Swagger UI
- `/api/doc.json` serves the raw OpenAPI spec
- Both endpoints documented: `POST /api/parse` and `GET /api/parse/{id}`
- Request/response schemas annotated with descriptions
- Available in `dev` env, disabled in `prod` (or protected)

---

### MVP-041 · Rate limiting on demo endpoint

**Description**  
Limit demo usage to prevent abuse and uncontrolled cost on the shared Mistral key.

**Acceptance criteria**
- Max **5 parse requests per IP per day** on `POST /api/parse`
- Uses Symfony Rate Limiter component (token bucket, Redis or APCu)
- Returns `429 Too Many Requests` with `Retry-After` header when exceeded
- Limit configurable via env var `DEMO_RATE_LIMIT` (default: 5)

---

### MVP-042 · Error response format

**Description**  
Standardise all API error responses to a consistent JSON shape.

**Format**
```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "The uploaded file is not a valid PDF.",
    "details": {}
  }
}
```

**Error codes to implement**
| Code | HTTP | Trigger |
|------|------|---------|
| `VALIDATION_ERROR` | 422 | Bad file type, size exceeded, invalid webhook URL |
| `SCANNED_PDF` | 422 | No text extractable |
| `NOT_FOUND` | 404 | Unknown job ID |
| `RATE_LIMITED` | 429 | IP limit exceeded |
| `PROCESSING_ERROR` | 500 | Unexpected internal failure |

---

### MVP-043 · Health check endpoint

**Description**  
`GET /api/health` — lightweight endpoint for Traefik and uptime monitoring.

**Response `200`**
```json
{
  "status": "ok",
  "db": "ok",
  "queue": "ok"
}
```

**Acceptance criteria**
- Checks DB connectivity (simple query)
- Checks RabbitMQ connectivity (management API ping)
- Returns `503` if any check fails
- No authentication required
- Response time under 200ms

---

## Epic 6 — Demo page

### MVP-050 · Demo page HTML/CSS

**Description**  
Single static HTML page served by Nginx (or Symfony) as the public demo.

**UI elements**
- PDF drag-and-drop zone + file picker button
- "Parse résumé" submit button
- Loading state with a progress indicator while polling
- Result panel displaying the extracted JSON in a readable tree format
- Error state displaying user-friendly messages

**Acceptance criteria**
- No JS framework — vanilla JS only
- Works on Chrome, Firefox, Safari (latest)
- Mobile-responsive layout
- Displays the extracted JSON in a collapsible tree (not raw text)
- Links to the OpenAPI doc (`/api/doc`)

---

### MVP-051 · Polling logic

**Description**  
JS polling loop that calls `GET /api/parse/{id}` after upload and displays the result when ready.

**Acceptance criteria**
- Polls every **1500ms**
- Stops polling on status `done` or `failed`
- Stops polling after **60 seconds** and displays a timeout error
- Displays partial progress feedback ("Analysing your résumé…")
- No duplicate requests if previous poll hasn't resolved yet

---

### MVP-052 · Demo page rate limit UX

**Description**  
Handle `429` responses gracefully on the demo page.

**Acceptance criteria**
- Displays a friendly message: "You've reached the daily limit of 5 parses. Come back tomorrow!"
- Does not expose raw API error to the user
- Limit counter is not shown (avoid gaming)

---

## Epic 7 — Hardening & limits

### MVP-060 · File validation hardening

**Description**  
Add server-side validation beyond Content-Type header checking.

**Acceptance criteria**
- Validate PDF magic bytes (`%PDF`) regardless of declared MIME type
- Reject files over 5MB before processing
- Reject files with 0 pages
- Sanitise `original_filename` before storing (strip path traversal, limit to 255 chars)
- Uploaded files stored outside the web root

---

### MVP-061 · Logging & observability

**Description**  
Structured logging for all key events in the pipeline.

**Acceptance criteria**
- Log on: job created, worker started, Mistral call dispatched, job completed/failed
- Log fields: `job_id`, `duration_ms`, `status`, `was_truncated`, `mistral_model`
- Use Monolog with JSON formatter in production
- No PII logged (no résumé content, no extracted names or emails)
- Log level configurable via env var

---

### MVP-062 · README & local setup guide

**Description**  
Write a complete README so the project is immediately usable by anyone cloning the repo.

**Sections to include**
- Project description (1 paragraph)
- Architecture overview (reference the async pipeline)
- Prerequisites
- Local setup (`make start`, seed data)
- Environment variables table with descriptions and defaults
- How to run tests
- How to deploy (brief, links to `deploy.sh`)
- API reference (link to `/api/doc`)
- Known limitations (scanned PDFs, language support)
- Roadmap (multi-provider, tenants, billing)

---

## Out of scope for MVP

The following are explicitly deferred to post-MVP iterations:

- Authentication / API keys
- Multi-tenancy
- Billing and usage metering
- BYOK (bring-your-own-key)
- Multi-provider support (OpenAI, Anthropic)
- OCR for scanned PDFs
- Backoffice UI
- Skill taxonomy / normalisation
- CV scoring or ranking features
