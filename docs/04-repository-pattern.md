# 04 — The Repository pattern

## The problem it solves

The domain needs to save and retrieve entities. But it can't know *how* — whether that's PostgreSQL, MySQL, an in-memory array (for tests), or a remote API.

If you write this in the domain:

```php
// BAD — the domain now depends on Doctrine
use Doctrine\ORM\EntityManagerInterface;

class ParseResumeHandler {
    public function __construct(private EntityManagerInterface $em) {}
}
```

…you've coupled your business logic to a specific database library. You can't test the handler without a real database. You can't swap Doctrine for something else without touching domain code.

The repository pattern solves this with a two-step approach.

---

## Step 1: the domain defines what it needs (interface)

```php
// src/Domain/Parsing/Repository/ParseJobRepositoryInterface.php

interface ParseJobRepositoryInterface
{
    public function save(ParseJob $job): void;
    public function findById(string $id): ?ParseJob;
}
```

This interface lives in the **Domain**. It expresses what the domain needs in pure business terms — save a job, find a job by ID. No mention of SQL, Doctrine, or databases.

The domain only ever depends on this interface.

---

## Step 2: infrastructure provides the implementation

```php
// src/Infrastructure/Persistence/Repository/DoctrineParseJobRepository.php

class DoctrineParseJobRepository implements ParseJobRepositoryInterface
{
    public function __construct(private EntityManagerInterface $em) {}

    public function save(ParseJob $job): void
    {
        $this->em->persist($job);
        $this->em->flush();
    }

    public function findById(string $id): ?ParseJob
    {
        return $this->em->find(ParseJob::class, $id);
    }
}
```

This class lives in **Infrastructure**. It knows about Doctrine. The domain doesn't know it exists.

---

## Step 3: Symfony wires them together

In `config/services.yaml`:

```yaml
App\Domain\Parsing\Repository\ParseJobRepositoryInterface:
    class: App\Infrastructure\Persistence\Repository\DoctrineParseJobRepository
```

Symfony's DI container sees "someone needs a `ParseJobRepositoryInterface`" and injects the Doctrine implementation. The handler never knows what it got.

---

## Why this matters

**For testing:** you can write an `InMemoryParseJobRepository` that stores jobs in an array, inject it in tests, and test your handler at full speed with zero database setup.

```php
// tests/Stub/InMemoryParseJobRepository.php
class InMemoryParseJobRepository implements ParseJobRepositoryInterface
{
    private array $jobs = [];

    public function save(ParseJob $job): void
    {
        $this->jobs[$job->getId()] = $job;
    }

    public function findById(string $id): ?ParseJob
    {
        return $this->jobs[$id] ?? null;
    }
}
```

**For flexibility:** if you ever needed to switch from Doctrine to a different ORM, you'd write a new implementation — the domain code is untouched.

**For clarity:** the interface documents exactly what persistence operations the domain cares about. No leaking of query methods that shouldn't be called from business logic.

---

## The dependency inversion principle

This pattern is an application of the **D** in SOLID: Dependency Inversion.

> High-level modules (domain) should not depend on low-level modules (Doctrine). Both should depend on abstractions (the interface).

The interface is that abstraction. The domain defines it. Infrastructure implements it. Symfony connects them.
