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
| 8 | Stats & deduplication | 3 |
| 9 | GDPR compliance & data lifecycle | 8 |

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

## Epic 8 — Stats & deduplication

### MVP-070 · Token usage tracking

**Description**
Capture the token counts returned by the AI provider on every successful extraction and persist them on `ParseResult`.

**DB changes**
- `parse_result`: add `tokens_prompt INT NULL`, `tokens_completion INT NULL`, `tokens_total INT NULL`, `ai_provider VARCHAR(32) NULL`

**Acceptance criteria**
- Both `MistralProvider` and `OpenAiProvider` extract `usage.prompt_tokens`, `usage.completion_tokens`, `usage.total_tokens` from the API response
- `AiProviderInterface::extract()` return type updated to carry token data alongside the JSON payload (e.g. a typed `ExtractionResult` DTO)
- `ParseResumeHandler` persists all four fields on `ParseResult`
- Fields are `NULL` only when the provider returns no usage data (defensive, not expected)
- Migration is reversible

---

### MVP-071 · Processing duration tracking

**Description**
Record when the worker actually starts processing a job so processing time can be measured.

**DB changes**
- `parse_job`: add `started_at TIMESTAMP NULL`

**Acceptance criteria**
- `ParseResumeHandler` sets `started_at` on the `ParseJob` when it transitions to `processing`
- Duration is derivable as `updated_at - started_at` on `done`/`failed` jobs (no redundant column)
- Migration is reversible

---

### MVP-072 · Resume deduplication via content hash

**Description**
Avoid re-parsing an identical PDF by hashing its content at upload time and reusing an existing result if one exists.

**DB changes**
- `parse_job`: add `content_hash CHAR(64) NULL` (SHA-256 hex of raw PDF bytes)

**Acceptance criteria**
- `ParseUploadController` computes SHA-256 of the uploaded file bytes and stores it on the new `ParseJob`
- Before persisting the new job, the controller queries for an existing `ParseJob` with `status = done` and the same `content_hash`
- If a match is found: the uploaded file is discarded, no new job is created, and the existing job ID is returned with `200 OK` (instead of the usual `202`)
- No `UNIQUE` constraint on `content_hash` — only completed jobs are reusable; pending/failed jobs with the same hash are ignored
- Migration is reversible

---

## Epic 9 — GDPR compliance & data lifecycle

> **Context:** The platform processes personal data (CV content) on behalf of customers who are data controllers under GDPR. This epic removes the deduplication feature (which conflated customer data across independent upload events), implements payload-only data retention (CV payload wiped after 30 days; job metadata kept indefinitely for audit and billing), adds the legal pages required for any data processor relationship (Privacy Policy, ToS, DPA), and adds a GDPR-compliant consent banner to the demo page.

---

### MVP-080 · Remove resume deduplication

**Description**
The content-hash deduplication introduced in MVP-072 silently reuses parse results across independent upload events. This is legally and semantically wrong: two customers uploading the same PDF must receive independent jobs with independent lifecycles. Remove the feature entirely — the `content_hash` column, the early-return lookup in the upload controller, and the corresponding migration.

**DB changes**
- Drop `parse_job.content_hash` column via a new reversible migration.

**Code changes**
- `ParseUploadController`: remove the SHA-256 computation, the `ParseJobRepository::findDoneByContentHash()` call (or equivalent), and the `200 OK` early-return branch. Every upload must reach the `dispatch(ParseResumeCommand)` call.
- `ParseJob` entity: remove the `contentHash` property, its getter, and any Doctrine mapping for the column.
- `ParseJobRepository` (domain interface + Doctrine implementation): remove the `findByContentHash` / `findDoneByContentHash` method.
- Delete or update any unit/functional tests that assert deduplication behaviour.

**Acceptance criteria**
- Uploading the same PDF twice always creates two separate `ParseJob` records, each processed independently.
- The `content_hash` column no longer exists in the database after the migration runs.
- The upload controller never returns `200 OK`; it always returns `202 Accepted`.
- No test references deduplication behaviour as a positive assertion.
- Migration has a working `down()` method that re-adds the column (nullable, no data restoration required).

**Notes**
This is a **breaking change** for any caller relying on the `200 OK` dedup shortcut. Document it in the CHANGELOG. The `down()` migration must not attempt to repopulate hashes — it just re-adds the column as `NULL`-able so the schema is reversible.

---

### MVP-081 · Add `payload_deleted_at` to `ParseResult`

**Description**
Add a nullable timestamp column to `ParseResult` to record when the payload was wiped for data-retention purposes. This is a prerequisite for MVP-082 and MVP-083.

**DB changes**
- `parse_result`: add `payload_deleted_at TIMESTAMP WITH TIME ZONE NULL` (default `NULL`).

**Code changes**
- `ParseResult` entity: add `payloadDeletedAt` nullable `\DateTimeImmutable` property with Doctrine mapping.
- Add a named method `ParseResult::wipePayload(\DateTimeImmutable $at): void` that sets `payload` to `null` and `payloadDeletedAt` to `$at`. This is a domain operation — it must live on the entity, not in the cleanup command.
- `GET /api/parse/{id}` response: if `payload_deleted_at` is set, the `result` key must be omitted and a `result_expired` boolean set to `true` must be included instead.

**Acceptance criteria**
- Migration is reversible: `down()` drops the column.
- `ParseResult::wipePayload()` sets both `payload = null` and `payloadDeletedAt = $now` atomically within the same entity mutation.
- `ParseResult::getPayload()` returns `null` after `wipePayload()` is called.
- Polling a job whose payload has been wiped returns:
  ```json
  {
    "job_id": "...",
    "status": "done",
    "result_expired": true
  }
  ```
- No existing tests break; add a unit test for `ParseResult::wipePayload()`.

**Notes**
`payload_deleted_at` is the authoritative marker for "this result has been intentionally wiped". Do not rely on `payload IS NULL` alone — a result could theoretically be null due to a bug. The timestamp makes intent explicit and auditable.

---

### MVP-082 · Payload retention in the cleanup command

**Description**
Update the existing `app:parse:cleanup` console command to wipe `ParseResult.payload` after a configurable number of days. Job rows and `ParseResult` rows are **never hard-deleted** — they are kept indefinitely for audit, history, and billing purposes. Only the CV payload (personal data) is removed.

| Data | Retention | Action |
|---|---|---|
| `ParseResult.payload` | 30 days from `parse_result.created_at` | Set `payload = NULL`, set `payload_deleted_at = NOW()` |
| `ParseJob` row + `ParseResult` row | **Indefinite** | No deletion |

**New command signature**
```
app:parse:cleanup
    [--payload-retention-days=30]   # days before payload is wiped (default 30)
    [--dry-run]                     # log what would be done without writing
```

**Handler steps (in order)**
1. Query `ParseResult` rows where `created_at < now() - payload_retention_days` AND `payload IS NOT NULL`.
2. For each result: call `ParseResult::wipePayload($now)` and flush. Log count.

**Acceptance criteria**
- The old `--older-than` option is removed (breaking change — document in CHANGELOG).
- `--payload-retention-days` is configurable with a default of 30.
- `--dry-run` logs the count without persisting any change.
- Command logs: `"Wiped payload for N ParseResult records (older than X days)"`.
- No `$em->flush()` inside a loop — use a single DQL `UPDATE` or chunked flushes.
- Designed to be called from a cron job (non-interactive, exit code 0 on success).
- Functional test: seed jobs at T-29 days and T-31 days, run command, assert payload wiped only for T-31 records.

**Notes**
Job rows and ParseResult rows are kept indefinitely — they contain no personal data after the payload is wiped and are needed for audit trails, support, and billing history. Only the payload (the extracted CV content) is personal data under GDPR and must be deleted.

---

### MVP-084 · Privacy Policy page

**Description**
Create a static Privacy Policy page served by the Symfony `Web` layer. The page must be written in plain language and cover all data processed by the platform.

**Route**
`GET /privacy` → `PrivacyController::index()`, route name `web_privacy`

**Required content sections**
1. **Who we are** — name/contact of the data controller (placeholder if not yet known; mark clearly as `[TO FILL]`).
2. **What data we collect** — uploaded PDF files (temporary), extracted CV payload (structured JSON), IP addresses (rate limiting, logs), job metadata.
3. **Why we collect it** — to provide the parsing service; IP for rate limiting and abuse prevention.
4. **How long we keep it**
   - Uploaded PDF files: deleted immediately after text extraction (not stored beyond processing).
   - Parsed CV data (payload): deleted after **30 days** from the date of parsing.
   - Job metadata (status, timestamps, errors): retained indefinitely — contains no personal data.
   - IP addresses in logs: retained for up to **30 days** in log files.
5. **Data sharing** — no CV data is shared with other customers; no third-party analytics; AI provider (Mistral/OpenAI) receives the extracted text to perform parsing (list providers, link to their privacy policies).
6. **Data subject rights** — right to access, erasure, portability under GDPR; contact address (placeholder).
7. **Cookies** — no tracking cookies; session cookie used only for locale preference (explain purpose and lifetime).
8. **Contact / DPO** — placeholder contact address.

**Acceptance criteria**
- Page is accessible at `/privacy` without authentication.
- Page is linked from the demo page footer (see MVP-087).
- The 30-day payload retention period is stated explicitly and matches the value in MVP-082.
- No tracking pixels, no external fonts or scripts that could leak visitor data.
- Page renders correctly on mobile (Tailwind utility classes, same layout as the rest of the site).
- All `[TO FILL]` placeholders are visible and clearly marked so they cannot be overlooked before going live.

**Notes**
This page is a legal document. Do not use AI-generated legalese that obscures meaning. Plain language is required. The DPO contact placeholder must use a realistic format (e.g. `privacy@[domain]`) so it is obviously a placeholder and not a real address.

---

### MVP-085 · Terms of Service page

**Description**
Create a static Terms of Service page served by the Symfony `Web` layer.

**Route**
`GET /terms` → `TermsController::index()`, route name `web_terms`

**Required content sections**
1. **Service description** — what the API does, what it does not do (no OCR, no data storage beyond retention windows, no guarantee of extraction accuracy).
2. **Acceptable use** — must not upload documents you are not authorised to process; must not attempt to reverse-engineer the extraction model; rate limits apply.
3. **Disclaimer** — service is provided as-is; extraction results may contain errors; not suitable as the sole basis for employment decisions.
4. **Liability limitation** — to the maximum extent permitted by law, liability is limited to the amount paid for the service (zero for the free demo tier).
5. **Data handling** — reference the Privacy Policy for full details; restate that CV payload is deleted after 30 days and job metadata is kept indefinitely (it contains no personal data).
6. **Governing law** — placeholder (`[TO FILL]`).
7. **Changes to these terms** — we may update these terms; continued use constitutes acceptance.
8. **Contact** — placeholder.

**Acceptance criteria**
- Page is accessible at `/terms` without authentication.
- Page is linked from the demo page footer (see MVP-087).
- The retention period stated here matches the Privacy Policy and the cleanup command default (30 days for payload).
- Page renders correctly on mobile.
- All `[TO FILL]` placeholders are visible and clearly marked.

---

### MVP-086 · Data Processing Agreement (DPA) page

**Description**
Create a DPA template page that positions the platform as a **data processor** under Article 28 GDPR and the customer as the **data controller**. The DPA must be a downloadable or printable page — it is not a click-through flow.

**Route**
`GET /dpa` → `DpaController::index()`, route name `web_dpa`

**Required content sections**
1. **Parties** — Data Processor: [platform name, address — `[TO FILL]`]; Data Controller: the customer (identified by their API usage).
2. **Subject matter and duration** — processing CV/résumé data on behalf of the controller; duration equals the term of service.
3. **Nature and purpose of processing** — extracting structured data from PDF résumés using AI models; results returned to the controller via API.
4. **Categories of personal data** — name, contact details, employment history, education, skills, languages as extracted from résumés.
5. **Categories of data subjects** — job applicants and candidates whose CVs are submitted by the controller.
6. **Obligations of the processor** (Article 28(3) GDPR checklist):
   a. Process data only on documented instructions from the controller.
   b. Ensure persons authorised to process data are bound by confidentiality.
   c. Implement appropriate technical and organisational security measures.
   d. Not engage sub-processors without prior written consent; list current sub-processors (AI providers: Mistral / OpenAI — with links to their own DPAs).
   e. Assist the controller in responding to data subject requests.
   f. Delete or return all personal data at end of service; restate that CV payload is wiped after 30 days and job metadata (no personal data) is retained indefinitely.
   g. Make available all information necessary to demonstrate compliance.
7. **Security measures** — HTTPS in transit; data not stored beyond retention periods; no cross-customer data sharing; uploaded PDFs deleted after extraction.
8. **Sub-processors** — list Mistral AI and OpenAI as sub-processors; note that the controller's choice of `AI_PROVIDER` determines which is active; link to each provider's DPA.
9. **Governing law & jurisdiction** — placeholder (`[TO FILL]`).
10. **Signatures** — placeholder block for controller name, date, and signature (print/download intent).

**Acceptance criteria**
- Page is accessible at `/dpa` without authentication.
- Page is linked from the demo page footer (see MVP-087).
- Article 28 compliance checklist is complete — no required clause is omitted.
- Sub-processors section names both Mistral AI and OpenAI with external links to their processor agreements.
- Retention period (30-day payload wipe) matches the Privacy Policy and cleanup command default exactly.
- Page renders correctly on mobile and is print-friendly (a `@media print` CSS block hiding navigation is sufficient).
- All `[TO FILL]` placeholders are visible and clearly marked.

**Notes**
This is a template, not a signed agreement. Its purpose is to give customers a document they can countersign, not to constitute a binding agreement on its own. Add a prominent notice at the top: "This is a template for review. Contact us to obtain a countersigned copy."

---

### MVP-087 · Demo page footer with legal links & GDPR consent banner

**Description**
Two distinct UI changes to the demo page: (1) add a footer linking to all three legal pages, and (2) add a GDPR-compliant consent banner that informs visitors of cookie usage and allows them to acknowledge it.

**Footer requirements**
- Persistent footer visible on the demo page (and ideally on all `Web` pages including the new legal pages).
- Links: Privacy Policy (`/privacy`), Terms of Service (`/terms`), Data Processing Agreement (`/dpa`).
- Brief tagline alongside the links: "CV data is deleted after 30 days. Job metadata is kept for audit purposes."
- Implemented using Tailwind utility classes in the existing Twig base layout so all web pages inherit it.

**Consent banner requirements**
- Appears on first visit (controlled by a `gdpr_consent` session cookie or `localStorage` flag).
- Dismissed permanently once the user clicks "OK" / "I understand" (sets the flag; banner does not reappear on reload).
- Banner text must be accurate and not vague. Because the platform currently uses **no tracking cookies and no third-party analytics**, the banner must say so explicitly:
  > "This site uses one session cookie to remember your language preference. No tracking cookies or analytics are used."
- Must include a link to the Privacy Policy for users who want more detail.
- If any analytics or third-party scripts are added in future, this banner text and the Privacy Policy must be updated before the scripts are enabled — add a `TODO` comment in the template to that effect.
- Banner must be keyboard-accessible (focusable dismiss button, `role="dialog"`, `aria-modal="true"`).
- Vanilla JS only — no consent management platform SDK.

**Acceptance criteria**
- Footer is present on `/`, `/privacy`, `/terms`, `/dpa`.
- Footer links all resolve to correct routes.
- Consent banner appears on first load of the demo page for a new visitor.
- Clicking the dismiss button hides the banner and it does not reappear on page reload.
- Banner states truthfully that no tracking cookies are used.
- Banner contains a working link to `/privacy`.
- Banner is keyboard-accessible: Tab reaches the dismiss button; Enter/Space activates it.
- Banner does not block interaction with the page body (positioned as a non-modal bottom bar, not a full-screen overlay).
- All acceptance criteria for MVP-084, MVP-085, MVP-086 footer links are satisfied by this ticket.

**Notes**
The consent banner is required under ePrivacy / GDPR even when no tracking cookies are used — transparency is mandatory regardless. A banner that accurately says "no tracking" is both legally safer and better UX than a vague cookie wall.

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
