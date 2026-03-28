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

- [ ] MVP-050 · Demo page HTML/CSS
- [ ] MVP-051 · Polling logic
- [ ] MVP-052 · Demo page rate limit UX

## Epic 7 — Hardening & limits

- [ ] MVP-060 · File validation hardening
- [ ] MVP-061 · Logging & observability
- [ ] MVP-062 · README & local setup guide
