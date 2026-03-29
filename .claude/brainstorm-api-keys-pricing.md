# Brainstorm — API Keys, Auth & Pricing

> Written 2026-03-29. Raw ideas + open questions for later decision.

---

## Context

The MVP is done. Next big feature is opening the API to external users with authentication (X-API-Key header), a backoffice to manage keys, and a pricing/BYOK model.

---

## What we agreed on

- **X-API-Key header** for authentication
- **Users can create N API keys**, each with a name + per-key usage stats (parse count, tokens)
- **No distinction between ATS and individual users** — everyone gets the same key model. An ATS just creates one key per client.
- **Per-key AI config** (provider + model + optional BYOK api key) — not per-user
- **BYOK = free** (user brings their own Mistral/OpenAI key, stored encrypted)
- **Backoffice** needed for users to manage keys + AI config
- **Per-usage billing** (per parse) as the main paid model — makes account sharing self-policing

---

## Data model (current thinking)

```
User
  id, email, passwordHash
  plan (see options below)
  createdAt

ApiKey
  id, userId (FK)
  name                      ← "Production", "Client A – Mistral", etc.
  keyHash                   ← stored hashed (bcrypt or SHA-256), never plain
  prefix                    ← first 8 chars shown in UI ("sk-pars_abc123...")
  isActive
  lastUsedAt
  createdAt

  # AI config (per key)
  aiProvider                ← mistral | openai | null (null = platform default)
  aiModel                   ← nullable string (e.g. "gpt-4o-mini")
  encryptedBYOKApiKey       ← nullable, AES-256, encryption key in env

ParseJob (add field)
  apiKeyId (FK)             ← which key triggered this job, enables per-key stats
```

Stats per key = `GROUP BY api_key_id` on `ParseJob`. No extra table needed.

---

## Open question 1 — BYOK granularity

### Option A — BYOK is per-key
Each key independently decides: use platform credits OR bring your own AI key.
A user can have one BYOK key (free) and one platform key (paid) on the same account.

**Pro:** Maximum flexibility. ATS can have some clients on BYOK, some on paid.
**Con:** Complex billing — you need to track which keys used your credits vs theirs.
**Con:** Harder to reason about plan boundaries.

### Option B — BYOK is per-account (plan-level)
The whole account is either BYOK or paid. All keys on the account inherit the account's AI config (but can still override model/provider).

**Pro:** Simple billing — BYOK account = never charge them.
**Con:** ATS can't mix. Forces them to create multiple accounts if they want both.

### Option C — BYOK is per-key but gated by plan
Free plan = BYOK only (must provide their own key on every key they create).
Paid plan = can use platform credits, can also still BYOK if they want.

**Pro:** Clean upsell path. BYOK is the free tier, paid unlocks platform convenience.
**Con:** Slightly more complex plan enforcement logic.

> **Leaning toward Option C** — it maps cleanly to a pricing page and doesn't block the ATS use case.

---

## Open question 2 — Pricing model

### Option A — Pure per-parse
$X per successful parse. Simple, predictable for customers.
- Works well with BYOK = free (they pay Mistral/OpenAI directly, not you).
- Paid users pay you per parse, you pay the AI provider from your credits.

**Challenge:** margins depend on resume length (token count varies). A 10-page CV costs you more than a 1-pager but bills the same.

### Option B — Per-token
Charge based on `tokensTotal` on `ParseResult`. Already tracked.
More accurate margins but hard to explain to customers. "How much will this cost me?" is unanswerable without testing first.

### Option C — Subscription tiers with included parses
| Tier | Price | Parses/mo | Overage |
|---|---|---|---|
| Free (BYOK) | $0 | unlimited | — (their cost) |
| Starter | $X/mo | 500 | $Y/extra |
| Pro | $X/mo | 5000 | $Y/extra |
| Enterprise | custom | custom | — |

**Pro:** Predictable revenue, easy to reason about.
**Con:** Quota management complexity. What happens when they hit the limit mid-month?

### Option D — Credit packs (prepaid)
Buy 1000 parses for $X. Credits never expire (or expire after 1 year).
Used by many dev-tool SaaS (e.g. Cloudinary, Deepgram).

**Pro:** No subscription friction, good for irregular usage (ATS with seasonal hiring).
**Con:** No recurring revenue predictability.

> **No decision yet.** Needs a pricing page wireframe to pressure-test.

---

## Open question 3 — Account sharing

With per-usage billing (Option A/D above), sharing is self-policing:
the account owner pays for all usage on their keys. No one shares willingly.

Additional levers if needed later:
- **IP allowlist per key** — restrict key to specific IPs/CIDR (useful for ATS server-to-server)
- **Rate limit per key** — not a sharing deterrent but useful for quota management
- **Key expiry** — optional, enterprise compliance ask

Org/seats model (multiple users per account) is explicitly **out of scope for now**. If an ATS wants multiple team members to manage keys, they share login credentials — accepted tradeoff for v1.

---

## Open question 4 — Backoffice screens needed

Minimum viable backoffice:
1. **Account page** — email, plan, change password
2. **API Keys list** — name, prefix, last used, status, per-key usage stats
3. **Create/revoke key** — name, AI provider, model, optional BYOK key input
4. **Usage dashboard** — parses this month, tokens used, cost estimate (paid plans)

Nice to have later:
- Per-key usage chart (daily/monthly)
- Webhook delivery history
- Invoice history (if Stripe)

---

## Open question 5 — Self-serve vs manual onboarding

### Option A — Self-serve (Stripe)
User signs up, enters card, gets access immediately.
Requires Stripe integration, email verification, password reset flow — significant scope.

### Option B — Manual onboarding (v1)
Accounts created by you. User gets credentials by email.
No payment integration yet.

**Leaning toward Option B for v1** — ship the auth + key infrastructure first, bolt on Stripe later without touching the core model.

---

## Possible implementation order

1. User auth (registration/login, session or JWT)
2. API key CRUD + hashing + X-API-Key middleware
3. Attach `apiKeyId` to `ParseJob`, resolve AI config from key at runtime
4. Backoffice (account + key management)
5. BYOK encryption + storage
6. Pricing / Stripe (later)

---

## Challenges / things to stress-test later

- **Encrypted BYOK key storage**: AES-256 with key in env is fine, but key rotation strategy?
- **Key display UX**: shown once on creation, prefix only after. Need to decide on prefix format (`sk-pars_XXXXXXXX`?).
- **What happens to BYOK jobs if the user's AI key is revoked/expired?** Error surfaced on `ParseJob` or hard fail?
- **Model validation**: if user sets `aiModel = "gpt-99-turbo"` (doesn't exist), fail at job time or at key save time?
- **Free tier abuse**: BYOK is free but you still pay for infra (DB, worker, storage). Need some minimum friction (email verification? captcha?).
- **ATS sub-key pattern**: ATS creates one key per client — should `clientRef` (a free-text label on the key or on the request) be first-class to help them filter usage reports?
