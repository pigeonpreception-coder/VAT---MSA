# Deliverable 01 — Executive architecture summary

## What VAT-MSA is

VAT-MSA is the proposed national digital VAT transaction, taxpayer business, compliance and audit platform for Namibia. It gives each VAT-registered legal entity one verified taxpayer/organisation identity, lets that organisation act as Buyer, Seller or both, and connects commercial activity to electronic tax invoices, immutable VAT transactions, ledgers, reconciliation, returns and governed NamRA workflows.

The platform solves fragmented invoice evidence, duplicate buyer/seller identities, delayed or inconsistent VAT records, manual reconciliation, weak integration governance and incomplete audit traceability. It also provides an extensible business layer—quotations, accounting, inventory, expense and project capabilities—without transferring statutory authority away from NamRA.

## Users and interactions

- Taxpayers use Buyer and Seller portal experiences over one organisation record; authorised users are constrained by job role, branch, workflow and data scope.
- NamRA officials use compliance, audit, risk, return, reconciliation and communication workflows constrained by department, region, case and classification.
- NamRA System Administrators manage tax access; Super Administrators operate platform configuration and health without automatic taxpayer-financial access.
- Approved POS/ERP/accounting/SaaS providers use registered machine identities, scoped versioned APIs, quotas and isolated sandbox/conformance processes.
- ITAS is the preferred authoritative identity/taxpayer source, subject to confirmed protocols, attributes and service contracts. Standalone access must link to the same canonical identity.

## Fiscal flow

Seller or approved SaaS submits invoice → protected gateway verifies identity/scope/quota/schema/idempotency → VAT-MSA resolves the one taxpayer/organisation identity and effective rule version → authoritative invoice, certificate, VAT transaction, seller output posting, eligible buyer input candidate, audit and outbox commit durably → response returns immutable IDs → asynchronous matching, notifications, analytics and synchronization proceed → reconciliation and exceptions feed an effective-dated VAT period/return → authorized taxpayer submission and NamRA processing determine payable/refund workflow.

Unidentified buyers never receive input-VAT postings. Duplicates are idempotently returned or rejected. Corrections preserve the original and add linked adjustment/reversal/credit/debit records.

## Architecture and scale

The recommended style is hybrid: explicit domain modules and transactional boundaries initially, with independently scalable edge/API, identity, invoice receipt, event processing, documents, analytics and security capabilities. National production uses stateless application capacity, partitioned operational data/event streams, distributed cache for approved data, separate document/evidence/analytics/security stores, multi-zone primary and warm regional recovery.

Initial capacity/SLO/RTO/RPO numbers are proposed engineering hypotheses and require architecture approval and repeatable testing. Critical invoice receipt, transaction identity and security monitoring are preserved before reporting/analytics under overload.

## Security and governance

Every request is identified, authenticated, RBAC/ABAC authorized, tenant/resource-scoped, validated, risk-assessed, rate-controlled, correlated and logged. Defence in depth includes protected edge, private segmented workloads/data, managed keys/secrets, short-lived identities, DLP, immutable audit/security evidence, SIEM/SOC and controlled automated response. High-impact containment, refunds, audits and statutory decisions remain human-governed.

## Key decisions

1. One taxpayer/organisation; Buyer/Seller are capabilities.
2. ITAS-preferred federation plus linked standalone identity.
3. Hybrid domain-modular architecture with evidence-based service extraction.
4. Shared national platform with strict logical tenant partitioning; selected security/evidence stores separately administered.
5. Transactional outbox and versioned at-least-once events.
6. Immutable statutory ledger and effective-dated approved tax rules.
7. Offline work is provisional until secure server synchronization/certification.

## Major risks and dependencies

Critical: unconfirmed ITAS federation/data contracts; official invoice/numbering/return/tax rules; Namibian legal/privacy/retention/residency decisions; production hosting/data/event/KMS/SIEM choices; HSM signature standard; business ownership and national capacity forecast. These are explicit approval-gate blockers, not assumptions.

## Evolution

Architecture/governance → identity/taxpayer foundation → portals → invoice/transaction → VAT engine/returns → business platform → ITAS/SaaS → audit/compliance → security/scale validation → controlled pilot → national deployment. No phase advances across a NOT READY component or unresolved critical dependency.

See `diagrams/executive-architecture.mmd`.
