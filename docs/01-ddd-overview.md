# 01 — What is DDD and why do we use it here?

## The core idea

Domain-Driven Design is about making your code reflect the real-world problem you're solving — not the framework you're using.

In most Symfony tutorials, you'll see controllers that call the database directly, entities that are just bags of getters/setters, and business logic scattered across services. That works fine for small apps. But as complexity grows, it becomes hard to answer the question: **"where does this logic live?"**

DDD answers that question with a strict rule: **business logic lives in the Domain, everything else is infrastructure**.

## The domain is the heart

The domain is the part of your code that models the real problem. For this project, the real problem is:

> "A user uploads a résumé. We extract text from it, send it to an AI, and return structured data."

The domain concepts that emerge from that problem are:
- A **ParseJob** — the unit of work with a lifecycle (pending → processing → done/failed)
- A **ParseResult** — the structured output once the job is done
- A **WebhookUrl** — not just a string, but a validated HTTPS address that carries meaning
- **JobStatus** — an explicit set of states a job can be in

These concepts exist whether you use Symfony, Laravel, or plain PHP. They don't know about HTTP, databases, or RabbitMQ. That's the point.

## What DDD is NOT

- It's not a framework or a library — it's a way of thinking about code organisation
- It's not always the right choice — for a simple CRUD app, DDD is overkill
- It doesn't mean you can't use Doctrine attributes on domain entities (we do — see `03-entities-vs-value-objects.md`)

## Why it fits this project

This project has a non-trivial processing pipeline with multiple states, failure modes, retry logic, and external integrations (Mistral, webhooks). That complexity is exactly where DDD pays off — the domain expresses the rules clearly, and the infrastructure just wires things together.
