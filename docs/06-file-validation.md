# 06 — File validation hardening

How uploaded PDFs are validated before any domain logic runs, and why each check lives where it does.

---

## The two-phase validation model

File validation happens in two places: the **upload endpoint** (synchronous, before the job is created) and the **worker** (asynchronous, during extraction). The split is intentional.

```
POST /api/parse
  └─ UI layer: magic bytes, MIME type, file size       ← fast, synchronous, cheap
        └─ if OK: store file, create job, dispatch worker
              └─ Worker: 0-page check, text extraction  ← heavier, async
```

Validations that are cheap and can save a round-trip belong at the boundary. Validations that require actually parsing the file belong in the processing pipeline.

---

## Upload-time checks (UI layer)

### 1. File size — `#[Assert\File(maxSize: '5M')]`

Symfony's built-in constraint, applied in `ParseUploadRequest`. The file is rejected before it is even read further. Nothing domain-specific here — this is pure infrastructure protection.

### 2. MIME type — `#[Assert\File(mimeTypes: ['application/pdf'])]`

Symfony uses PHP's `finfo` extension to detect the MIME type from the file content (not the browser-supplied header). This catches files renamed to `.pdf` but with a different format. Still not sufficient on its own — see magic bytes below.

### 3. Magic bytes — `#[PdfMagicBytes]`

`finfo` MIME detection can be fooled by crafted files. Magic bytes are the authoritative signal: a valid PDF file always begins with `%PDF` (hex: `25 50 44 46`).

`PdfMagicBytesValidator` reads the first 4 bytes of the uploaded file's temporary path and rejects anything that doesn't match. This runs after `#[Assert\File]` so null and oversized files are caught first.

**Why is this in the UI layer and not the Domain?**

Magic bytes are a transport-level concern — they validate that the raw bytes arriving over HTTP are structurally a PDF. The domain doesn't care about bytes; it works with extracted text. If you put this check in the domain you'd be coupling business logic to the format of HTTP uploads, which makes no sense when the domain has no concept of HTTP.

The full constraint pair on `ParseUploadRequest.file`:

```php
#[Assert\NotNull(message: 'A PDF file is required.')]
#[Assert\File(maxSize: '5M', mimeTypes: ['application/pdf'], ...)]
#[PdfMagicBytes]
```

Each constraint has a single responsibility. They compose, they don't overlap.

---

## Worker-time checks (Infrastructure → Domain)

### 4. Zero-page detection — `PdfExtractor::extract()`

A structurally valid PDF (correct magic bytes, parseable) can still have 0 pages. There is no way to detect this cheaply at upload time without parsing the file — so it belongs in the worker.

`PdfExtractor` calls `$pdf->getPages()` immediately after parsing. If the page count is zero it throws `ScannedPdfException::fromEmptyDocument()` before attempting text extraction.

**Why `ScannedPdfException` and not a new exception?**

A 0-page PDF and a scanned/image-only PDF produce the same outcome for the user: no text can be extracted, the job fails, and the error code `SCANNED_PDF` is returned. Introducing a new exception class would add a new type of error that the API surface doesn't distinguish — premature complexity.

### 5. Minimum text threshold — `PdfExtractor::extract()`

If the extracted text is under 200 characters, the PDF is treated as scanned (image-based). This rule is a domain rule — "what counts as extractable text" is a business decision, not a framework decision — so it lives inside `PdfExtractor` (a domain service) rather than in the handler.

---

## Filename sanitisation — `OriginalFilename` value object

The client-supplied filename is untrusted input. `OriginalFilename` sanitises it in its constructor:

- Strips null bytes (`\0`)
- Calls `basename()` to remove any path component (prevents path traversal like `../../etc/passwd`)
- Truncates to 255 characters while preserving the extension

This is a Domain concern because "a filename must be safe and bounded" is a business invariant — it protects data integrity regardless of how the filename arrived (HTTP, CLI, future import pipeline).

---

## Where files are stored

Uploaded files go to `var/uploads/{job_id}.pdf`. This directory is outside the webserver's document root — Nginx serves only `public/`. A file stored there is never directly accessible via a URL, even if the filename were manipulated.

---

## Summary

| Check | Where | Why there |
|---|---|---|
| File size | UI (`#[Assert\File]`) | Cheap boundary rejection, no domain meaning |
| MIME type (finfo) | UI (`#[Assert\File]`) | Transport-level format check |
| Magic bytes (`%PDF`) | UI (`#[PdfMagicBytes]`) | Authoritative format signal, transport concern |
| Zero pages | Infrastructure/Domain (`PdfExtractor`) | Requires parsing the file |
| Minimum text length | Domain (`PdfExtractor`) | Business rule on what counts as extractable |
| Filename sanitisation | Domain (`OriginalFilename` VO) | Data integrity invariant |
| Storage location | Infrastructure (filesystem path) | Security hardening, no domain logic |
