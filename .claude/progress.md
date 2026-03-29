# MVP Progress

> Update this file as tickets are completed. Every agent should read this before starting work.
> Status: `[ ]` not started · `[~]` in progress · `[x]` done

---

## Epic 1 — Project setup & infrastructure

- [x] MVP-001 · Symfony project bootstrap
- [x] MVP-002 · Docker Compose setup
- [x] MVP-003 · VPS deployment config
- [x] MVP-004 · Database schema & migrations

## Epic 2 — PDF ingestion

- [x] MVP-010 · PDF upload endpoint
- [x] MVP-011 · PDF text extraction service
- [x] MVP-012 · Text cleaning service

## Epic 3 — Async processing pipeline

- [x] MVP-020 · Messenger transport configuration
- [x] MVP-021 · ParseResumeCommand & handler
- [x] MVP-022 · Job status polling endpoint
- [x] MVP-023 · Cleanup command
- [x] MVP-024 · NotifyWebhookCommand & handler
- [x] MVP-025 · Webhook retry strategy
- [x] MVP-026 · Webhook signature verification doc

## Epic 4 — Mistral integration

- [x] MVP-030 · MistralProvider service
- [x] MVP-031 · Extraction prompt & output schema
- [x] MVP-032 · JSON output validation

## Epic 4.5 — OpenAI integration

- [x] MVP-033 · OpenAiProvider service
- [x] MVP-034 · AiProviderSelector (runtime provider switching via AI_PROVIDER env var)

## Epic 5 — REST API

- [x] MVP-040 · Nelmio API documentation
- [x] MVP-041 · Rate limiting on demo endpoint
- [x] MVP-042 · Error response format
- [x] MVP-043 · Health check endpoint

## Epic 6 — Demo page

- [x] MVP-050 · Demo page HTML/CSS
- [x] MVP-051 · Polling logic
- [x] MVP-052 · Demo page rate limit UX

## Epic 7 — Hardening & limits

- [x] MVP-060 · File validation hardening
- [x] MVP-061 · Logging & observability
- [x] MVP-062 · README & local setup guide

## Epic 8 — Stats & deduplication

- [x] MVP-070 · Token usage tracking (tokens_prompt/completion/total + ai_provider on parse_result)
- [x] MVP-071 · Processing duration tracking (started_at on parse_job)
- [x] MVP-072 · Resume deduplication via content hash (SHA-256 on parse_job, reuse on upload)

## Epic 9 — GDPR compliance & data lifecycle

- [x] MVP-080 · Remove resume deduplication (drop content_hash column + lookup, new reversible migration)
- [x] MVP-081 · Add `payload_deleted_at` to ParseResult (nullable timestamp + `wipePayload()` domain method)
- [x] MVP-082 · Payload retention in cleanup command (30-day payload wipe, no hard delete)
- [x] MVP-084 · Privacy Policy page (/privacy, retention periods, no-tracking statement)
- [x] MVP-085 · Terms of Service page (/terms)
- [x] MVP-086 · Data Processing Agreement page (/dpa, Article 28 GDPR, sub-processors listed)
- [x] MVP-087 · Demo page footer with legal links & GDPR consent banner

