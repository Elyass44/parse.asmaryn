# 05 — What happens when you upload a PDF

A concrete walkthrough of the full request lifecycle, layer by layer.

---

## The happy path

```
POST /api/parse  (multipart, file + optional webhook_url)
```

### 1. UI layer — ParseUploadController

The controller receives the HTTP request. Its job is purely to translate HTTP into domain concepts:

```
Request arrives
  ├─ Validate file: PDF magic bytes, size ≤ 5MB
  ├─ Build value objects from raw input:
  │     new OriginalFilename($file->getClientOriginalName())
  │     new WebhookUrl($request->get('webhook_url'))   ← throws 422 if invalid HTTPS URL
  ├─ Generate a UUID for the job
  ├─ Store the file to var/uploads/{job_id}.pdf
  ├─ Call ParseJob::create(id, filename, webhookUrl)   ← Domain entity
  ├─ $repository->save($job)                           ← Domain interface, Doctrine underneath
  └─ Dispatch ParseResumeCommand(jobId, filePath)      ← Application command → RabbitMQ

Response: 202 Accepted  { job_id, status: "pending", poll_url }
```

The controller does not know how the job is processed. It creates it and hands it off. No business logic here.

---

### 2. Back to the client

The client gets a `job_id` and starts polling `GET /api/parse/{id}` every 1.5 seconds.

Meanwhile, the Messenger worker is processing the job asynchronously.

---

### 3. Infrastructure — RabbitMQ delivers the message

The worker container running `messenger:consume async` picks up `ParseResumeCommand` from RabbitMQ and routes it to the handler.

---

### 4. Application layer — ParseResumeHandler

The handler orchestrates domain services to fulfil the "parse a résumé" use case. It reads like a recipe — no business rules here, just coordination:

```
ParseResumeCommand received
  │
  ├─ Load ParseJob from repository          (Domain interface)
  ├─ job->markAsProcessing()                (Domain entity — state transition)
  ├─ $repository->save($job)
  │
  ├─ PdfExtractor::extract(filePath)        (Domain service)
  │     ↳ uses smalot/pdfparser internally  (Infrastructure detail, hidden behind service)
  │     ↳ if text < 200 chars → throws ScannedPdfException  (Domain rule)
  │
  ├─ TextCleaner::clean(rawText)            (Domain service)
  │     ↳ removes artefacts, truncates to 3000 chars        (Domain rule)
  │
  ├─ MistralProvider::extract(cleanText)    (Infrastructure — external API call)
  │     ↳ POST to Mistral API, returns raw JSON
  │
  ├─ SchemaValidator::validate(json)        (Domain service)
  │     ↳ validates structure               (Domain rule)
  │     ↳ throws InvalidAiOutputException if broken
  │
  ├─ ParseResult::create(id, jobId, payload) (Domain entity)
  ├─ $resultRepository->save($result)
  ├─ job->markAsDone()                      (Domain entity — state transition)
  ├─ $repository->save($job)
  │
  └─ if job->hasWebhook():
        Dispatch NotifyWebhookCommand       (Application command → RabbitMQ)
```

If anything throws between extract and validate, the catch block calls `job->markAsFailed($error)` and still dispatches `NotifyWebhookCommand` so the ATS knows the job failed.

---

### 5. Client polls and gets the result

```
GET /api/parse/{id}
  ├─ $repository->findById($id)   ← null → 404
  ├─ status = "done"
  └─ Response: { job_id, status: "done", result: { ... } }
```

---

### 6. Application layer — NotifyWebhookHandler

```
NotifyWebhookCommand received
  ├─ Load ParseJob to get result and webhook URL
  ├─ Build JSON payload { job_id, status, result }
  ├─ Sign with HMAC-SHA256 → X-Signature header    (Infrastructure — crypto)
  ├─ POST to webhook_url (timeout 10s)             (Infrastructure — HTTP)
  ├─ 2xx → job->markWebhookDelivered()             (Domain entity)
  └─ non-2xx / timeout → let Messenger retry
                          After 3 failures → job->markWebhookFailed()  (Domain entity)
```

---

## What each layer owns in this flow

| Step | Layer | Why |
|---|---|---|
| Read HTTP request, validate file | UI | HTTP concern |
| Build VOs (`WebhookUrl`, `OriginalFilename`) | Domain | Validation is a domain rule |
| Create `ParseJob` | Domain | Business entity creation |
| Dispatch `ParseResumeCommand` | UI → Application | Triggering a use case |
| Orchestrate extract → clean → AI → validate → persist | Application | Use case coordination |
| "Text < 200 chars = scanned PDF" | Domain (Service) | Business rule |
| "Truncate to 3000 chars" | Domain (Service) | Business rule |
| Call Mistral API | Infrastructure | External system |
| "Job can go pending → processing → done" | Domain (Entity) | Business rule |
| Persist entities | Infrastructure | Storage concern |
| Sign and POST webhook | Infrastructure | External system |

---

## Failure modes

| Failure | Layer that catches it | What happens |
|---|---|---|
| Invalid file / bad webhook URL | UI | 422 before job is created |
| Scanned PDF | Domain (`PdfExtractor`) | Job → `failed`, error `SCANNED_PDF` |
| Mistral API error | Infrastructure (`MistralProvider`) | Job → `failed`, error `AI_ERROR` |
| Invalid AI output | Domain (`SchemaValidator`) | Job → `failed`, error `AI_INVALID_OUTPUT` |
| Webhook delivery fails | Infrastructure | Messenger retries 3×, then `webhook_status = failed`. Job result still available via polling. |
