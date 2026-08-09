# Audit, Refund, Reporting and Business Intelligence Architecture

## Audit case architecture

Audit management is a bounded domain separated from taxpayer self-service and from risk scoring. A risk signal may recommend a case, but only an authorized officer or approved policy creates/opens it. Case access combines role, assignment, office, sensitivity, conflict checks and purpose; privileged search is logged and periodically reviewed.

Lifecycle: `Proposed -> Authorized -> Assigned -> Planning -> EvidenceCollection -> Analysis -> TaxpayerResponse -> FindingsReview -> Decision -> Closed`, with `Suspended`, `Reopened`, `AppealLinked` and `Cancelled` under controlled transitions. Each transition records actor, reason, time, policy decision and prior/new state. Evidence items have source, hash, classification, custody history, legal hold, disclosure status and immutable versions. Notes are append-only; corrections supersede rather than overwrite.

Segregation of duties prevents the same person from originating the risk referral, approving audit scope and issuing the final decision without explicit exceptional oversight. Bulk downloads, cross-office access and evidence export require step-up authentication and approval.

## Refund architecture

Refund is a high-risk workflow, not a direct payment button. Return submission creates a refund claim only where the legally authoritative calculation indicates credit.

1. Freeze the submitted return version, supporting invoices, reconciliation and rule version.
2. Run eligibility, duplicate, debt-offset, identity, bank/account ownership, sanctions and anomaly checks subject to confirmed law/policy.
3. Risk-score the claim with explainable factors; preserve model/rule versions.
4. Route low/medium/high risk to configured review lanes; no opaque model auto-rejects a taxpayer.
5. Reviewer requests evidence or recommends outcome; maker-checker approves material outcomes.
6. Authorized system sends an approval/payment instruction to the confirmed payment/ITAS authority using idempotency and signed evidence.
7. Record external acknowledgement and reconcile settlement; discrepancies create cases.
8. Notify taxpayer without disclosing protected detection logic; support objection/appeal.

States: `Received`, `Validation`, `RiskReview`, `EvidenceRequested`, `OfficerReview`, `Approved`, `Rejected`, `Offset`, `PaymentPending`, `Paid`, `Failed`, `Reversed`, `Disputed`, `Closed`. Money and state transitions are append-only. Thresholds, approval limits and authority remain **REQUIRES NAMRA/LEGAL CONFIRMATION**.

## Immutable audit trail

Security, administrative and fiscal actions emit canonical audit records containing event ID, time, actor/subject, tenant, session/device, purpose, action, resource, before/after references or hashes, policy decision, correlation ID, source and outcome. Chained hashes, signed checkpoints, immutable storage and independent replication make tampering detectable. Access to audit data is itself audited. Time sources are synchronized and monitored. Legal holds override disposal. Verification jobs regularly recompute chains and alert the SOC and Internal Audit on breaks.

## Reporting architecture

Operational reporting reads purpose-built replicas or read models; national analytics uses a governed warehouse/lakehouse populated by CDC/events. Neither runs unrestricted queries on fiscal write stores. Semantic models define taxpayer, invoice, VAT transaction, return, payment/refund, compliance, case and time dimensions. Slowly changing dimensions preserve history.

| Audience | Products | Freshness | Guardrails |
|---|---|---:|---|
| taxpayer | sales/purchase VAT, reconciliation, draft return, compliance calendar | near real time | own organisation; delegated scope only |
| practitioner | portfolio exceptions and deadlines | minutes | consent/mandate and client-level isolation |
| NamRA operations | filing, revenue, refunds, cases and service health | minutes to daily | office/purpose policy; sensitive field masking |
| executives | aggregate revenue/compliance trends | daily | aggregation, disclosure controls |
| auditors/legal | reproducible evidence packs | point-in-time | case authority, custody and watermark |
| open-data consumers | approved aggregate datasets | scheduled | privacy review, minimum-cell suppression, no re-identification |

Every report states as-of time, source freshness, filters, currency basis, tax-rule version where relevant and whether values are provisional. Exports are asynchronous, size-limited, encrypted, expiring, watermarked and audited. Large or sensitive exports require step-up approval. Totals reconcile to source control accounts; failed reconciliation blocks official publication.

## BI and AI controls

BI workspaces separate development, certified and personal analyses. Certified metrics require owner, definition, lineage, test, refresh SLO and change approval. Row/column security is enforced at the data layer as well as the UI.

AI is advisory for anomaly detection, document assistance, support and workload prioritization. Models require lawful purpose, representative data, documented limitations, explainability, drift/bias monitoring, human review and versioned decisions. AI cannot invent tax rules, silently change liability, issue final adverse decisions or bypass evidence and appeal rights. Prompts, outputs and model versions are protected and retained only as approved.

