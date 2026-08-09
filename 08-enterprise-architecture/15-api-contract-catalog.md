# Deliverable 12 — API catalogue and contract standard

The machine-readable logical route register is `api-catalog.yaml`. Detailed schemas are authored per implementation phase from this standard; endpoints marked gated are not implementation-ready.

## Common contract

| Item | Standard |
|---|---|
| Authentication | human federated session/token or named machine identity; public verification is explicitly anonymous/minimal |
| Authorization | permission + organisation/taxpayer/resource + capability + department/region/case + classification/workflow/purpose |
| Headers | `X-Correlation-Id`; `Idempotency-Key` for commands; content type; API/version; conditional version where updating drafts |
| Request | bounded canonical JSON schema; UTC/ISO dates; money as decimal string at edge then exact minor units; no unknown critical fields |
| Response | resource/receipt ID, state, version, links and correlation; lists are bounded cursor pagination |
| Error | `application/problem+json`: stable type/code, title, status, safe detail, correlation, field errors, retryable/retry-after |
| Idempotency | key scoped to actor/client+route+tenant and canonical payload hash; exact retry returns original receipt; changed reuse is 409 |
| Rate class | CriticalWrite, InteractiveWrite, InteractiveRead, Search, Bulk/Report, PublicVerify; adaptive multi-level quotas |
| Audit | all commands, sensitive reads/search/exports, authorization denial, policy/admin and credential operations |

## API groups

| Group / representative endpoints | Purpose | Auth and authorization | Validation/response/errors | Idempotency/rate/audit |
|---|---|---|---|---|
| Auth: `POST /auth/token-exchange`, `POST /auth/sessions/revoke`, `GET /identity/me` | federation/session/identity context | provider/machine proof; session owner/PAM | issuer/audience/nonce/assurance; identity context; 401/403/409/503 | exchange replay protection; CriticalWrite; auth/security audit |
| Taxpayer: `POST /registration-applications`, `GET /taxpayers/{id}`, `POST /taxpayers/{id}/verify` | onboarding and authoritative status | applicant or NamRA registrar; scoped official | VAT/TIN/company formats/source/freshness; 409 duplicates, 422 mismatch, 503 ITAS | submission idempotent; strict write quota; all verification audited |
| Organisation: `GET/POST /organisations`, `/branches`, `/memberships`, `/capabilities` | legal organisation and access/capability | member admin or NamRA admin | one taxpayer, unique company/branch, valid roles/effective dates | commands idempotent; interactive; lifecycle audit |
| Users/Roles: `/users`, `/roles`, `/permissions`, `/delegations`, `/consents` | user lifecycle/entitlements | org admin, NamRA admin, PAM as appropriate | segregation/expiry/approval; 403/409/422 | commands idempotent; admin quota; enhanced audit |
| Parties: `/customers`, `/suppliers`, `/products` | organisation master/business parties | tenant membership and business role | tenant unique codes, VAT verification snapshot | interactive; changes and sensitive exports audited |
| Quotations: `/quotations`, `/{id}/accept`, `/{id}/convert` | full quote lifecycle | sales/approval + resource scope | state transition, totals/version; 409 stale/invalid transition | commands idempotent; InteractiveWrite; audit acceptance/conversion |
| Invoices: `POST/GET /invoices`, `/{id}`, `/{id}/credit-notes`, `/debit-notes`, `/cancel` | fiscal document receipt/correction | invoice scope/capability; machine scopes | fiscal schema, sequence, parties, rules, totals; receipt/certificate; 409/413/422/429/503 | mandatory key; CriticalWrite; complete fiscal/security audit |
| Public verify: `GET /verify/{token}` | minimal certificate state | anonymous token | opaque bounded token; minimal valid/cancelled/reversed state; 404 | PublicVerify quota/cache; privacy access telemetry |
| VAT: `/vat/transactions`, `/vat-ledger`, `/tax-rules`, `/vat-periods` | immutable postings/config/period | taxpayer or NamRA scoped; rule admin restricted | rule version/effective dates, exact money, append-only | posting commands idempotent; critical; audit all changes |
| Returns: `/vat-returns`, `/{id}/generate`, `/submit`, `/status` | assemble/review/submit returns | taxpayer approval or NamRA workflow | official rule/form version, period state, unresolved exceptions; official receipt | mandatory key; CriticalWrite; enhanced audit |
| Reconciliation: `/reconciliation/matches`, `/exceptions`, `/{id}/resolve` | compare and resolve | tenant or assigned official | resolution reason/evidence/version | command idempotent; work-queue quota; audit resolution |
| Accounting: `/accounts`, `/journals`, `/periods`, `/financial-statements` | business accounting | finance role/org scope | balanced journals, period status, currency; 409 closed period | postings idempotent; audit journals/close/export |
| Inventory: `/products`, `/warehouses`, `/stock-movements` | stock lifecycle | inventory role/branch | quantity/version/source; 409 insufficient/stale | movement key required; audit adjustments |
| Expense: `/expenses`, `/{id}/approve`, `/categories`, `/budgets` | capture/approval/cost control | employee/approver segregation | category, amount, evidence, workflow | commands idempotent; audit approval/rejection/export |
| Project: `/projects`, `/budgets`, `/costs`, `/profitability` | budgets/cost/revenue | project/finance role | dates/currency/approval/version | commands idempotent; audit budget/financial export |
| Payments: `/payments`, `/allocations`, `/bank-reconciliation` | approved receipt/payment records | finance + regulated connector scopes | tokenized source, amount/currency/settlement | mandatory key; critical; full audit; provider errors stable |
| Compliance/Audit/Risk/Refund: `/compliance`, `/audit-cases`, `/risk`, `/refund-reviews` | NamRA controlled workflows | region/case/clearance/approval | legal states, assignment, evidence; masked outputs | command key; enhanced quota/audit; taxpayer risk isolation |
| Documents: `POST /documents/uploads`, `GET /documents/{id}`, `/versions`, `/holds` | safe object lifecycle | owner/case scope/classification | size/type/hash/scan/retention; 423 quarantined | upload session idempotent; bounded; every access/change audit |
| Communications/Notifications: `/communications`, `/notifications` | secure conversation/delivery | participant/case/tenant scope | sanitized content/object refs/preferences | send key; rate/anti-abuse; delivery and case audit |
| Integrations/Developer: `/integrations`, `/developer/apps`, `/clients`, `/credentials`, `/usage`, `/conformance` | app lifecycle/sandbox | provider owner/reviewer/security | environment/scopes/ownership; secret values never returned after issuance | admin key; strict; credential/conformance audit |
| Reports/Analytics: `/reports`, `/exports`, `/analytics/metrics` | governed reads and jobs | owner/NamRA scope + purpose/export approval | allowlisted filters/columns/format; async job receipt | report request key; Bulk quota; DLP/export audit |
| Operations/Security: `/health/*`, `/operations/*`, `/security/*` | probes, SLO, incidents/config | probes or technical/SOC roles; no tax privilege inheritance | no secrets/payloads; 403/503 | admin commands keyed; strict; immutable technical/security audit |

## Standard problem codes

`AUTHENTICATION_REQUIRED`, `ASSURANCE_INSUFFICIENT`, `ACCESS_DENIED`, `RESOURCE_OUT_OF_SCOPE`, `VALIDATION_FAILED`, `DUPLICATE_RESOURCE`, `IDEMPOTENCY_CONFLICT`, `INVALID_WORKFLOW_TRANSITION`, `DEPENDENCY_UNAVAILABLE`, `RATE_LIMITED`, `PAYLOAD_TOO_LARGE`, `VERSION_CONFLICT`, `QUARANTINED`, `NOT_FOUND`, `INTERNAL_ERROR`. Retry is false unless explicitly stated.

## API governance

API review verifies domain owner, threat model, classification, scope semantics, schemas/examples, limits, idempotency, concurrency, error and audit. Published contracts live in source with compatibility/conformance tests. Breaking changes require new major version, consumer inventory, migration and sunset approval.
