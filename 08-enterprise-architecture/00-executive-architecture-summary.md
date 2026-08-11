# Deliverable 01 — Executive architecture summary

## Global security, privacy and compliance extension

VAT-MSA now has a unified, evidence-oriented control architecture that maps applicable standards, frameworks and jurisdictional obligations to architecture, implementation requirements, telemetry, tests, evidence, owners and review. It uses NIST CSF 2.0 for operating outcomes; applicable ISO/IEC, NIST SP and OWASP materials for management/control/verification guidance; PCI DSS only if payment-card scope is established; and country law only after formal applicability and interpretation.

Zero-trust decisions cover people, workloads, devices, tenants, resources, countries, classifications, workflows, licences and risk. Privileged changes require phishing-resistant step-up, JIT/PAM and independent approval. Tenant isolation, authorization, audit integrity, secret protection, SoD and fiscal integrity remain non-bypassable. Lower-level policy may tighten but not weaken the signed global baseline.

The architecture package is documents 33-38 plus the security control, standards, CSF, regional, threat, test, evidence, SLO and traceability catalogues. It is designed to align with applicable standards; it is not a certification, attestation, accreditation or production-readiness claim. Production security controls and country profiles require the formal gate, platform choices, implementation, exercises and independent assurance.

## What VAT-MSA is

### Globalisation extension

The target now comprises a jurisdiction-neutral global core plus independently governed country compliance packs. Namibia is the first reference pack, using `NAD` as the currency code and `N$` for human presentation. The pack is non-executable until regulatory confirmation, signature, readiness and bounded activation succeed. See `31-globalisation-country-compliance-architecture.md` and `32-namibia-country-compliance-pack.md`.

### Workspace, licensing and organisation-control extension

The taxpayer experience evolves from static Buyer/Seller menus to one licence- and permission-aware workspace over the same organisation. A verified licence activation may provision one primary Organisation Portal Administrator, who can model employees, titles, positions, organisation roles, permission sets and versioned workflows within protected system ceilings. Buyer and Seller remain organisation capabilities, not accounts.

The new License and Entitlement, Workspace/Navigation, Organisation Administration, Workflow and Access Governance components remain downstream of canonical identity and central policy. They cannot alter taxpayer identity, VAT rules, certified records, tenant boundaries, mandatory security, audit evidence or licence authority. Commercial plans, provider selection, expiry/statutory-continuity rules, administrator proofing and workflow policy remain `PROPOSED - REQUIRES APPROVAL`.

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
