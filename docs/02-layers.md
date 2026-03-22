# 02 — The four layers

The project is split into four layers. The rule is: **outer layers can depend on inner layers, never the other way around**.

```
┌─────────────────────────────┐
│           UI                │  HTTP controllers, DTOs
├─────────────────────────────┤
│       Application           │  Use cases, handlers, commands
├─────────────────────────────┤
│      Infrastructure         │  Doctrine, RabbitMQ, Mistral HTTP client
├─────────────────────────────┤
│         Domain              │  Business logic — no dependencies
└─────────────────────────────┘
```

---

## Domain (`src/Domain/`)

The innermost layer. Has **zero knowledge** of Symfony, Doctrine (aside from mapping attributes), HTTP, Messenger, or any external service.

```
src/Domain/Parsing/
├── Model/         → ParseJob, ParseResult (entities with identity and lifecycle)
├── ValueObject/   → WebhookUrl, OriginalFilename (immutable typed wrappers)
├── Repository/    → ParseJobRepositoryInterface (interface — not the implementation)
├── Service/       → PdfExtractor, TextCleaner, SchemaValidator (pure business rules)
└── Exception/     → InvalidWebhookUrlException, ScannedPdfException…
```

What belongs here: anything that expresses a **business rule**.
- "A webhook URL must be HTTPS" → `WebhookUrl` VO
- "Text under 200 chars means it's a scanned PDF" → `PdfExtractor` service
- "A job can only go from processing → done or failed" → `ParseJob` methods

What does NOT belong here: anything about how data is stored, how it's transported, or what framework is running.

---

## Application (`src/Application/`)

The orchestration layer. Its job is to **coordinate domain objects to fulfil a use case** — nothing more.

```
src/Application/Parsing/
├── Command/   → ParseResumeCommand, NotifyWebhookCommand (Messenger messages)
└── Handler/   → ParseResumeHandler, NotifyWebhookHandler
```

A handler reads like a recipe: "load this, call that, save this, dispatch that". It does not contain business logic — that lives in domain services and entities.

**Why is this separate from Domain?**

Because handlers know about Messenger (a framework concern). The domain should not. If you put handlers in `Domain/`, you've coupled your core business logic to Symfony's messaging system. Tomorrow if you swap Messenger for a different queue library, you'd be touching domain code — and that's wrong.

The Application layer is allowed to depend on the Domain. It is not allowed to depend on Infrastructure directly (it calls domain repository interfaces, not Doctrine classes).

---

## Infrastructure (`src/Infrastructure/`)

Connects the domain to the outside world. Knows about Doctrine, HTTP clients, RabbitMQ.

```
src/Infrastructure/
├── Ai/                      → MistralProvider (calls Mistral API via HttpClient)
├── Persistence/
│   ├── Repository/          → DoctrineParseJobRepository (implements domain interface)
│   └── Type/                → Custom DBAL types for value objects
└── Http/                    → HTTP client wrappers if needed
```

Infrastructure knows about the domain. The domain does NOT know about infrastructure.

This is why `ParseJobRepositoryInterface` lives in `Domain/` — the domain defines what it needs, infrastructure provides it. See `04-repository-pattern.md` for the full explanation.

---

## UI (`src/UI/`)

The outermost layer. Handles HTTP only.

```
src/UI/Api/
├── Controller/   → ParseUploadController, ParseStatusController
└── DTO/          → Request/response objects with OpenAPI annotations
```

Controllers are intentionally thin:
1. Read and validate the HTTP request
2. Build domain value objects from raw input (`new WebhookUrl(...)`)
3. Dispatch an Application command or call an Application service
4. Return an HTTP response

If you find yourself writing a business rule in a controller, it belongs in the domain.

---

## Dependency flow

```
UI           → Application (dispatches commands, calls handlers)
UI           → Domain (builds VOs from request data)
Application  → Domain (orchestrates entities and services)
Application  → Domain interfaces (calls repository interfaces)
Infrastructure → Domain (implements interfaces, persists entities)
Domain       → nothing
```

The Domain never imports from Application, Infrastructure, or UI. That boundary is what makes it testable in isolation and framework-independent.

---

## Why four layers instead of three?

Many Symfony projects skip the Application layer and put handlers directly in `Domain/`. That works until your handlers start importing Messenger, HTTP clients, or other framework services — at which point your "domain" depends on infrastructure, and the separation becomes meaningless.

The Application layer is the correct home for "use case" code: it knows the steps of a use case but delegates all decisions to the domain.
