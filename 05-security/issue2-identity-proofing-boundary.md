# Issue 2 identity-proofing trust boundary

Effective date: 23 August 2026

Environment: local/staging foundation with synthetic data

Production authority status: **DISABLED — ITAS/NamRA contract and acceptance evidence required**

## Security objective

VAT-MSA must detect duplicate or conflicting taxpayer identity evidence before any registration application can create or link a canonical taxpayer, organisation, membership, authorization, subscription or licence. A confidence score is explainable reconciliation evidence, not identity authority. Only an accepted ITAS/NamRA response in an approved production-equivalent or production environment may enter `AUTHORITY_VERIFIED`.

## Implemented lifecycle

```text
registration application
  -> PENDING_PROVIDER proofing case
  -> zero or more append-only reconciliation candidates
  -> candidate / mismatch / manual-review / synthetic-test posture
  -> independent mismatch resolution where required
  -> AUTHORITY_VERIFIED only behind provider evidence guards
  -> separate future authorization/provisioning command
```

The current application implements the intake, durable case, candidate-evaluation model, mismatch evidence and read projection. The live provider transition and downstream authorization command remain unavailable. Registration submission continues to stop at `PENDING_VERIFICATION`; it cannot activate a legal entity.

## Explainable reconciliation

The deterministic local evaluator normalizes identifiers and legal names, then records matched and conflicting field names. Its bounded weights are VAT number 4,000 basis points, TIN 3,500, company registration number 1,500 and normalized legal name 1,000. It produces `NO_CANDIDATE`, `CANDIDATE_FOUND`, `DUPLICATE_CONFIRMED`, `MISMATCH` or `MANUAL_REVIEW`.

The score does not prove authenticity, ownership, current registration status or legal authority. Name-only evidence cannot verify a taxpayer. Any mixed identifier match/conflict becomes a mismatch. Synthetic exact matches may be recorded only as `SYNTHETIC_MATCHED` with `provider_environment=SYNTHETIC_TEST` and never change the registration or create canonical/access/licence records.

## Database enforcement

- VAT number and TIN are independently unique in the canonical taxpayer table.
- A registration application can own at most one proofing case.
- A provider/reference pair and a proofing-case/candidate-taxpayer pair are unique.
- Confidence is restricted to 0–10,000 basis points.
- Requester and reviewer must differ.
- `AUTHORITY_VERIFIED` requires ITAS, a production-equivalent/production provider environment, a matched canonical taxpayer, a non-empty evidence digest and an independent reviewer timestamp.
- `SYNTHETIC_MATCHED` requires the synthetic provider environment and an evidence digest.
- Authority decisions, proofing histories, reconciliation evidence and proofing events cannot be destructively rewritten or deleted.
- A mismatch cannot be resolved or rejected by the proofing requester.

The schema stores evidence digests and minimized field-name lists, not raw identity-provider payloads. API projections do not return the evidence digest or provider payload. Access is centrally licensed and permission-scoped; national users receive the national queue, while other users see only cases they requested.

## Failure and outage posture

Missing provider contract, credential, authoritative format catalogue, issuer semantics, freshness policy or response evidence leaves the case `PENDING_PROVIDER`. ITAS adapter calls fail closed. The system must not infer verification from identifier syntax, a successful form submission, a local score, an administrator assertion, email ownership or an existing commercial licence.

## External acceptance required

PR-011 requires the Identity/Data owner and NamRA/ITAS authority to accept identifier precedence, query and response semantics, lawful/minimal attributes, evidence retention, match and mismatch thresholds, provider freshness/expiry, merge/deregistration rules, rate/error behaviour, independent review, sandbox and production separation, conformance cases and operational monitoring. PR-003 remains the broader live ITAS integration acceptance package.

Until both applicable packages are signed and tested in an authorized production-equivalent environment, Issue 2 remains `BLOCKED — EXTERNAL DEPENDENCY REQUIRED` and no runtime path may claim authority verification.
