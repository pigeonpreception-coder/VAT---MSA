# A-B. Enterprise and solution architecture

## Mission and scope

VAT-MSA is a national tax-transaction network and an integrated taxpayer business platform. It connects verified taxpayers, authorised users, NamRA/ITAS and approved software providers while preserving statutory authority, tax integrity and privacy. It is not one giant CRUD database and not a collection of disconnected portals.

## System context

See `diagrams/system-context.mmd`.

| Actor/system | Purpose | Trust and authority |
|---|---|---|
| VAT taxpayer organisation | buys, sells, accounts and fulfils VAT obligations | owns authorised business data; cannot define statutory rules |
| Authorised organisation user | performs role-approved work | constrained by organisation, branch, job role and workflow |
| NamRA official | registration, compliance, risk, audit and statutory workflow | constrained by department, region, case and data purpose |
| Super administrator/SRE/SOC | operates platform, availability and security | no automatic taxpayer financial scope |
| ITAS/NamRA identity and registry | preferred identity and taxpayer authority | authoritative only for explicitly contracted attributes |
| SaaS/POS/ERP/accounting provider | machine-to-machine transaction integration | registered machine identity, scopes, tenant, quota and conformance |
| Bank/payment/customs provider | regulated future integration | no implementation before legal/technical approval |

## Logical solution layers

1. Protected edge: DNS, CDN, DDoS, WAF, bot and global routing.
2. Access: API gateway, portal routing and identity federation.
3. Policy: authentication, RBAC/ABAC, consent/delegation and risk decisions.
4. Experience: Buyer, Seller, NamRA, NamRA Administration, Super Administration and Developer/Sandbox portals.
5. Domain services: identity, taxpayer/organisation, business operations, VAT, compliance, integration and platform operations.
6. Event layer: transactional outbox, durable event bus, idempotent consumers, dead-letter and replay controls.
7. Data plane: operational relational stores, immutable evidence, documents, cache, analytics and security telemetry with separate access boundaries.
8. Operations: metrics, logs, traces, SIEM/SOC, deployment, resilience, backup and DR.

## Runtime interaction

For an invoice: identify human/machine → resolve canonical organisation/taxpayer → authorize scope and capability → validate schema and current rule version → reserve/validate invoice identity → durably commit invoice, certification state, statutory transaction, ledger, idempotency, audit and outbox → confirm transaction ID → asynchronously match, notify, analyse and synchronize → include eligible results in the correct VAT period.

No asynchronous consumer may create a second authoritative invoice. Every consumer uses the event ID, aggregate version and business idempotency identity.

## Architecture style and evolution

The pilot is a domain-modular application on a Cloudflare-compatible runtime with D1. Domain boundaries, contracts, events and ownership are explicit. National production selects fit-for-purpose regional relational, streaming, document, cache, analytical and evidence services. Domains are extracted into independently scaled services only when throughput, team ownership, resilience or data-isolation evidence justifies the operational complexity.

This avoids locking the initial release to one server or one database while also avoiding premature distributed transactions. Cross-domain consistency uses durable events, sagas/reconciliation and explicit state machines; statutory acceptance remains transactional within its authoritative boundary.

## Environments and control planes

- Development: synthetic data, local pilot identity, non-authoritative signing.
- Integration/Sandbox: isolated test tenants and machine credentials; cannot reach production tax records.
- Test/UAT: controlled identity and production-like contracts/data volumes using synthetic/de-identified data.
- Pilot: selected taxpayers/providers and monitored NamRA users with explicit legal/operational constraints.
- Production/DR: signed promotion, separate administration, resilient managed services, immutable evidence and exercised failover.

## Major quality attributes

| Attribute | Architectural response | Acceptance evidence |
|---|---|---|
| Integrity | immutable IDs, exact money, versioned rules, idempotency, ledger/reversal model | reconciliation and mutation-negative tests |
| Confidentiality | tenant/case ABAC, private data plane, encryption, DLP and minimal public verification | authorization and exfiltration testing |
| Availability | stateless capacity, queues/backpressure, multi-zone and warm DR | SLO, zone-loss and regional exercises |
| Scalability | partitioned data/events, bounded APIs, cache and horizontal workers | repeatable peak/spike/soak results |
| Auditability | append-only business/security evidence and correlation IDs | integrity and evidence-completeness verification |
| Interoperability | versioned APIs/events, canonical schemas and provider adapters | contract/conformance suites |
| Usability/accessibility | portal-specific tasks, shared records, guided workflows and WCAG target | usability and accessibility acceptance |

## Governance

Architecture owners maintain decisions, domain contracts, information classification and control traceability. Material changes require threat, privacy, capacity, integration, migration and operational impact. Tax-rule and legal decisions require NamRA authority; platform teams cannot turn technical configuration into statutory policy.
