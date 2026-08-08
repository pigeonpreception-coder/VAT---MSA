# Core Data Dictionary

## Ownership and classification

| Entity | Business owner | System-of-record position | Classification | Retention baseline |
|---|---|---|---|---|
| Taxpayer | NamRA Taxpayer Services / Data Stewardship | ITAS authoritative; VAT-MSA controlled replica and resolution index | Tax Confidential | Source/legal schedule |
| Taxpayer identifier | NamRA Data Stewardship | Effective-dated reference; tokenised lookup and masked display | Highly Restricted | Source/legal schedule |
| Invoice and line | VAT-MSA Invoice Domain | Authoritative for the submitted/certified canonical fiscal document | Tax Confidential | Legal schedule; no in-place deletion |
| VAT transaction | VAT-MSA VAT Domain | Authoritative linkage between invoice, taxpayer positions and period | Tax Confidential | Legal schedule; immutable corrections |
| VAT ledger entry | VAT-MSA VAT Ledger Domain | Authoritative VAT-MSA sub-ledger evidence | Tax Confidential | Legal schedule; append-only |
| Certificate | VAT-MSA Certification Domain | Authoritative receipt, signature and status | Tax Confidential; public subset | Invoice lifetime plus legal schedule |
| Match result | Reconciliation Domain | Versioned decision evidence | Tax Confidential | Invoice/return/audit schedule |
| Exception case | Compliance Operations | Authoritative workflow record | Tax Confidential | Case schedule and legal holds |
| VAT return draft | Return Domain | VAT-MSA draft; ITAS authoritative after statutory submission/acceptance | Tax Confidential | Statutory return schedule |
| Risk alert | Risk Governance | Decision-support signal, not a final adverse decision | Highly Restricted | Model/risk schedule |
| Audit case | Audit Directorate | Authoritative VAT-MSA case workspace; final legal outcome boundary to confirm | Highly Restricted | Audit/legal schedule |
| Audit event | Security / Internal Audit | Immutable evidence stream | Highly Restricted | Security and legal schedule |
| Offline batch | Integration Operations | Authoritative synchronisation evidence | Tax Confidential | Invoice/evidence schedule |

## Identifier rules

- Internal primary identifiers are application-generated UUIDv7 values and never reused.
- VAT number, TIN, company number and identity numbers are external identifiers, not database primary keys.
- Sensitive identifiers are encrypted at rest. Online equality lookups use keyed tokens; user interfaces display masked values unless the role and purpose permit full access.
- All identifier changes are effective-dated. Merges retain aliases and full lineage.
- Source-system document identity is `(source_system_id, source_document_id)`; the API idempotency key protects the operation, not the long-term business identity.
- Invoice duplicate controls additionally compare supplier, invoice number, issue date, document type and canonical document hash.

## Monetary rules

- Monetary amounts use `numeric(20,6)` or decimal strings in APIs. Binary floating point is prohibited for tax calculation.
- Every calculation stores currency, exchange rate and source, rounding mode, tax rule, rule-set version and unrounded intermediate evidence.
- Currency totals must reconcile to the line and tax breakdown within an approved tolerance; differences become explicit rounding entries or validation failures.
- VAT ledger postings balance per transaction and currency. Reversal entries reference the original entry; posted entries are never overwritten.

## Time rules

- Store instants in UTC with timezone-aware timestamps and retain the source offset where legally relevant.
- Tax-point and invoice dates use civil dates under the approved Namibian tax calendar.
- Effective-dated rules use half-open intervals `[effective_from, effective_to)` to prevent overlap ambiguity.
- Period closure captures a ledger cutoff instant. Later adjustments enter the legally appropriate open/amended period and preserve the original return snapshot.

## Data quality controls

| Control | Outcome |
|---|---|
| Taxpayer identity match | Verified, ambiguous, unknown or invalid with steward workflow. |
| Schema conformance | Exact version and JSON Pointer errors. |
| Invoice arithmetic | Recalculated totals, approved rounding and variance evidence. |
| Duplicate detection | Exact source duplicate, fiscal-number duplicate, content duplicate or probable duplicate. |
| Referential integrity | Credit/debit notes reference an eligible original; buyer/seller roles are valid at tax point. |
| Period assignment | Deterministic period and rule version with override approval evidence. |
| Event completeness | Transactional outbox and consumer reconciliation identify missing/duplicate events. |
| Warehouse reconciliation | Source-to-target counts, sums, hashes and late-arrival watermarks. |

## Analytical separation

Operational databases serve certification, posting and case workflows. Change-data capture or outbox events feed a governed warehouse/lakehouse using tokenised taxpayer keys. Risk and BI workloads do not query the primary certification database. Re-identification is restricted to named, audited workflows.
