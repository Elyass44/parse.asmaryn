# 03 — Entities vs Value Objects

These are the two fundamental building blocks of a DDD domain model. They look similar (both are PHP classes) but they represent completely different concepts.

---

## Entities — identity and lifecycle

An **entity** is something that has a unique identity and changes over time.

`ParseJob` is an entity:
- It has an `id` that uniquely identifies it forever
- It changes state: `pending` → `processing` → `done`
- Two `ParseJob` objects with the same data but different IDs are **different things**

```php
// These are two different jobs, even if they have the same filename
$job1 = ParseJob::create('uuid-1', new OriginalFilename('cv.pdf'));
$job2 = ParseJob::create('uuid-2', new OriginalFilename('cv.pdf'));
```

Entities are **never immutable** — they have methods that transition their state (`markAsDone()`, `markAsFailed()`).

---

## Value Objects — immutable typed wrappers

A **value object** has no identity. Two value objects are equal if their values are equal. They are always immutable — once created, they never change.

`WebhookUrl` is a value object:
- There's no "id" for a URL — `https://example.com/hook` just IS that URL
- Two `WebhookUrl` objects with the same value are **the same thing**
- It cannot be modified after creation

```php
$url1 = new WebhookUrl('https://example.com/hook');
$url2 = new WebhookUrl('https://example.com/hook');
// $url1 and $url2 are equal — they represent the same concept
```

### The real power: validation at construction

The key insight is that **a value object is always valid**. You cannot construct an invalid one:

```php
// This throws InvalidWebhookUrlException immediately
$url = new WebhookUrl('http://not-https.com');

// This is impossible to represent — OriginalFilename strips it at construction
$filename = new OriginalFilename('../../etc/passwd');
echo $filename; // "passwd" — sanitised
```

This means once a `WebhookUrl` exists in your system, you know it's valid. You don't need to validate it again when you use it. Compare that to a plain string, where you'd have to validate it everywhere it's used — or forget to.

---

## Why not just use strings?

```php
// With plain strings — validation is scattered and easy to forget
class ParseJob {
    public static function create(string $webhookUrl): self {
        // Do we validate here? In the controller? In a service?
        // What if someone calls this without going through the controller?
    }
}

// With a VO — validation is guaranteed at the type level
class ParseJob {
    public static function create(WebhookUrl $webhookUrl): self {
        // If this method was called, the URL is already valid. Period.
    }
}
```

The type system becomes your first line of defence.

---

## In this project

| Class | Type | Why |
|---|---|---|
| `ParseJob` | Entity | Has an ID, changes state over time |
| `ParseResult` | Entity | Has an ID, belongs to a specific job |
| `WebhookUrl` | Value Object | Immutable, valid by construction, no identity |
| `OriginalFilename` | Value Object | Immutable, sanitised by construction, no identity |
| `JobStatus` | Enum | A fixed set of valid states (not quite a VO, but similar spirit) |
| `WebhookStatus` | Enum | Same |

---

## Doctrine and value objects

Doctrine doesn't know about value objects natively. We bridge this with **custom DBAL types** in `Infrastructure/Persistence/Type/`.

When Doctrine reads `webhook_url` from the database (a plain string), the custom type converts it back into a `WebhookUrl` object. When it writes, it converts back to a string. The domain always works with typed objects, never raw strings.
