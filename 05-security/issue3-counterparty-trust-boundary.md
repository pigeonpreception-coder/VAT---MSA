# Issue 3 counterparty-trust boundary

Effective date: 23 August 2026

Environment: local/staging foundation with synthetic data

Production authority status: **DISABLED — NamRA/ITAS/BIPA contracts and acceptance evidence required**

## Objective

Customers and suppliers must not become eligible for new VAT-MSA business transactions merely because an operator typed a plausible VAT number, TIN, company registration number or legal name. VAT-MSA stores a tenant-scoped party record separately from its time-bounded trust posture and tax-registration status.

## Implemented lifecycle

```text
party captured
  -> PENDING_PROVIDER trust profile
  -> authority/synthetic snapshot reconciled field by field
  -> TRUSTED / MISMATCH / INVALID / EXPIRED posture
  -> transaction gate checks relationship + trust + freshness
  -> tax-bearing supplier use additionally requires ACTIVE tax registration
  -> identity-field change returns the profile to PENDING_PROVIDER
```

Creation remains useful as intake but does not make the party transaction-eligible. New quotations and projects require a current trusted customer. Expenses with a supplier require current trust, and tax-bearing expenses additionally require current `ACTIVE` tax-registration evidence. Historical transactions remain intact when evidence expires or a party is deactivated.

## Evidence and cache model

- One current trust profile exists per business party.
- Provider snapshots are immutable and freshness-bounded by `checked_at` and `expires_at`.
- Events are append-only and record state changes without copying raw provider responses.
- VAT number, TIN and company registration number are independently unique among active parties within one organisation.
- Legal/tax identity changes require the profile to be invalidated before the party record can change.
- Current projections expose status, identifier outcomes, confidence, environment and expiry, not evidence hashes or raw authority payloads.

`AUTHORITY_VERIFIED` requires a production-equivalent/production provider environment, evidence digest, source reference, validity interval and independent reviewer. `SYNTHETIC_VALID` requires a synthetic environment, evidence digest and validity interval. Production application paths never accept synthetic trust; explicitly enabled staging and local development may accept it for disposable workflow testing only.

## Explainable reconciliation

The bounded evaluator compares normalized VAT number, TIN, company registration number and legal name. It records matched/conflicting field names and a 0–10,000 basis-point confidence. A conflict produces `MISMATCH`; missing all usable identifiers produces `INVALID`. Tax-registration status remains a separate fact so a legally identified but suspended/cancelled/not-registered party is not falsely described as an identity mismatch.

The synthetic UI action intentionally replays local party data as a labelled test authority record. It proves workflow, storage, expiry and enforcement only; it is not evidence that the counterparty exists, owns the identifiers or is registered with any authority.

## External acceptance required

PR-012 requires Business Master Data, Tax/Finance, Privacy and NamRA/ITAS/BIPA owners to approve identifier formats/precedence, lawful attributes, provider contracts, response signatures/provenance, status semantics, freshness, caching, rate/error policy, mismatch handling, non-VAT counterparties, deregistration/merger, monitoring and production-equivalent rejection cases.

Until PR-012 and the applicable PR-003 integration package are signed and tested, Issue 3 remains `BLOCKED — EXTERNAL DEPENDENCY REQUIRED`. The local controls are a safe integration foundation, not production counterparty verification.
