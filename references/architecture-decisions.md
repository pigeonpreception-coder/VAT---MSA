# VAT-MSA Architecture Decision Register

## Workspace, licensing and workflow extension decisions

| ID | Decision | Status | Consequence |
|---|---|---|---|
| ADR-016 | Build dynamic workspace/navigation as a backend-authorized projection. | Proposed | Configurable UX without treating the client as an authorization boundary. |
| ADR-017 | Establish a separate licence/entitlement authority with server-side usage enforcement and non-destructive expiry. | Proposed | Commercial integrity while preserving statutory/security/retention controls. |
| ADR-018 | Allow organisation-specific roles/permissions only under protected system policy ceilings. | Proposed | Dynamic workforce configuration without privilege escalation. |
| ADR-019 | Use typed immutable workflow versions with decision-time SoD. | Proposed | Flexible approvals with historical and segregation integrity. |

| ID | Decision | Status | Consequence |
|---|---|---|---|
| ADR-001 | VAT-MSA is the system of record for certified invoice transactions, VAT sub-ledger entries, matching outcomes and related audit evidence; ITAS remains authoritative for taxpayer accounts and statutory return outcomes. | Proposed | Avoids duplicating the tax administration system while enabling transaction-level control. |
| ADR-002 | Use canonical immutable internal identifiers (UUIDv7) and retain TIN/VAT/company numbers as effective-dated external identifiers. | Proposed | Prevents key changes from breaking historical relationships and supports identity resolution. |
| ADR-003 | Use domain modules with explicit APIs and events; begin with a modular deployment and extract independent services when justified. | Proposed | Preserves bounded contexts without paying premature microservice complexity. |
| ADR-004 | Use PostgreSQL for operational records and double-entry VAT sub-ledger postings; use append-only audit/event storage and a separate analytical platform. | Proposed | Separates transactional integrity, forensic evidence and analytical workloads. |
| ADR-005 | Make invoice acceptance and ledger posting atomic; publish downstream events through a transactional outbox. | Proposed | Prevents accepted invoices without ledger evidence or events without committed state. |
| ADR-006 | Use at-least-once event delivery with idempotent consumers, stable event IDs and replay-safe handlers. | Proposed | Avoids unrealistic exactly-once claims while preventing duplicate business effects. |
| ADR-007 | Use versioned, effective-dated tax rules and preserve the rule version and calculation evidence on every transaction. | Proposed | Enables retrospective audit and controlled regulatory change. |
| ADR-008 | Never update accepted fiscal documents in place. Corrections use credit notes, debit notes, cancellation/reversal events or replacement documents linked to the original. | Proposed | Preserves legal and audit history. |
| ADR-009 | Use OAuth 2.1/OIDC for people, mTLS plus client credentials/private-key JWT for organisations and systems, and short-lived workload identities internally. | Proposed | Establishes strong identities across human, machine and service access paths. |
| ADR-010 | Sign certification receipts with keys protected by an HSM; expose a privacy-minimised public verification endpoint through QR codes. | Proposed | Makes certification independently verifiable without disclosing full taxpayer data. |
| ADR-011 | Default offline invoices to PENDING certification. Pre-authorised token pools are an optional policy-controlled mode, not a technical assumption. | Proposed | Avoids representing an offline event as centrally certified before NamRA has received it. |
| ADR-012 | Encrypt data in transit and at rest; separate encryption domains and keys for tax-confidential data, audit evidence and backups. | Proposed | Reduces blast radius and strengthens sovereignty and privileged-access controls. |
| ADR-013 | Use OpenAPI 3.1 for synchronous APIs, JSON Schema 2020-12 for payload validation and CloudEvents 1.0-compatible envelopes for business events. | Proposed | Creates portable, testable integration contracts. |
| ADR-014 | Use a cell-based deployment model for national scale: partition taxpayer traffic deterministically while maintaining a global identity/control plane. | Future target | Limits failure domains and enables horizontal expansion after demand is measured. |
| ADR-015 | Treat risk scores as decision support. Refund holds, audit selection and taxpayer sanctions require explainable rules and authorised human workflow. | Proposed | Reduces automated-decision risk and preserves accountability. |

## Decision workflow

Each proposed ADR must be reviewed by the Architecture Review Board. Decisions affecting law, taxpayer rights, invoice validity, evidence, retention, data residency or inter-agency exchange also require legal/privacy/records approval. Superseded ADRs remain in the register and link to the replacement.
