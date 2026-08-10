# Formal Architecture Approval Gate

## Workspace, licensing and workflow extension decision register

| Component / decision | Proposed status | Conditions / decision required | Required approver |
|---|---|---|---|
| canonical organisation with dynamic Buyer/Seller capabilities | APPROVED WITH CONDITIONS | retain one taxpayer identity and validate capability terminology | Architecture + NamRA Tax |
| Organisation Portal Administrator mapping | REQUIRES DECISION | confirm compatibility mapping from Taxpayer Administrator and proofing/change quorum | Product + CISO + NamRA |
| administrator hierarchy and grantable permissions | REQUIRES DECISION | approve scopes, protected permissions, ceilings and recertification | CISO + Business Owners |
| backend workspace/navigation engine | APPROVED WITH CONDITIONS | approve taxonomy, sensitive hiding, cache invalidation and WCAG evidence | Product + UX + CISO |
| licence/entitlement bounded context | REQUIRES DECISION | approve plan authority, provider, state machine, metering and separation | Commercial + Finance + CISO |
| expiry/suspension/downgrade statutory continuity | REQUIRES LEGAL/REGULATORY CONFIRMATION | define read/write/export/retention and legally required action policy | NamRA Tax + Legal + Records |
| typed workflow designer/versioning | APPROVED WITH CONDITIONS | approve expression/transition catalogue, publication and migration policy | Architecture + Domain Owners + CISO |
| mandatory SoD and emergency override | REQUIRES DECISION | approve rule catalogue; decide whether tightly controlled override exists | CISO + Finance Controls + Legal |
| access request/certification/offboarding | APPROVED WITH CONDITIONS | approve review cadence, dormant threshold, task reassignment and revocation SLO | CISO + HR + Business Owners |
| permission-aware enterprise search | APPROVED WITH CONDITIONS | approve indexing, masking, inference controls and purpose logging | CISO + Data + Privacy |
| activation through ITAS/VAT verification | REQUIRES ITAS CONFIRMATION | authoritative attributes, assurance, lifecycle, SLA and sandbox | ITAS + NamRA |
| payment/subscription activation | NOT READY | provider, sandbox, contracts, tax/refund/dispute and reconciliation absent | Commercial + Finance + Legal + CISO |
| production implementation of extension | NOT READY | preceding applicable rows and ADR-016 to ADR-019 not signed | Architecture Board + Steering Committee |

**Package status:** `REQUIRES DECISION` — architecture complete for review; production coding is not authorized by this document.

## Status vocabulary

- `APPROVED`: governing authority accepts design and implementation may proceed within stated scope.
- `APPROVED WITH CONDITIONS`: implementation may proceed only while listed controls/assumptions are tracked and before production.
- `REQUIRES DECISION`: a material design/ownership/funding choice is outstanding.
- `REQUIRES ITAS CONFIRMATION`: external ITAS capability or contract is unknown.
- `REQUIRES NAMRA CONFIRMATION`: NamRA policy/operating authority must decide.
- `REQUIRES LEGAL/REGULATORY CONFIRMATION`: lawful basis or fiscal effect must be verified.
- `NOT READY`: critical design/evidence is insufficient; coding/deployment of that component is blocked.

## Component decision register

| Component / decision | Proposed status | Conditions / decision required | Required approver |
|---|---|---|---|
| architecture principles and C4/domain boundaries | REQUIRES DECISION | accept modular-core evolution, ownership and dependency rules | Architecture Board |
| one taxpayer / one organisation | REQUIRES NAMRA CONFIRMATION | identifier precedence, lifecycle, merger/deregistration | NamRA + Data |
| dynamic buyer/seller role | APPROVED WITH CONDITIONS | legal party snapshots and terminology validated | Architecture + Tax |
| ITAS federation and authoritative attributes | REQUIRES ITAS CONFIRMATION | protocol, claims, assurance, SLAs, lifecycle, sandbox | ITAS/NamRA |
| standalone authentication | REQUIRES DECISION | approved IdP, proofing, recovery, continuity scope and budget | CISO + NamRA |
| RBAC/ABAC, tenant and privileged-access model | APPROVED WITH CONDITIONS | central policy, SoD/PAM and penetration evidence before production | CISO + Business Owners |
| relational/data/event/object/search architecture | REQUIRES DECISION | platform selection, sovereignty, HA, cost and exit plan | Architecture + Data + Procurement |
| API gateway and event integration standard | APPROVED WITH CONDITIONS | technology selection and conformance gates | Architecture + SRE |
| invoice certification and numbering | REQUIRES LEGAL/REGULATORY CONFIRMATION | legal fiscal effect, series allocation, signatures and correction | NamRA + Legal |
| configurable tax rule engine | REQUIRES NAMRA CONFIRMATION | complete rule catalogue, authority, golden cases and release workflow | Tax Policy + Legal |
| VAT transaction/reconciliation/return | REQUIRES ITAS CONFIRMATION | filing payload, status, acknowledgement, amendment and authority | ITAS + NamRA Tax |
| accounting, inventory, expense and project scopes | REQUIRES DECISION | product boundary, GL standards, operating owners | Product + Finance |
| refund and payment orchestration | REQUIRES LEGAL/REGULATORY CONFIRMATION | authority, SoD, thresholds, debt offset, settlement integration | NamRA + Finance + Legal |
| audit/risk/objection workflows | REQUIRES NAMRA CONFIRMATION | officer authorities, evidence/disclosure, appeal and model policy | Compliance + Legal |
| offline invoice and synchronization | NOT READY | legal validity, limits, device platform, number reservation and field pilot absent | NamRA + Legal + CISO |
| SaaS/bank/payment/developer integrations | APPROVED WITH CONDITIONS | provider contracts, consent, sandbox and security conformance per connector | Integration + CISO |
| security/SOC/zero-trust topology | REQUIRES DECISION | control platform, staffing, severity/response and assurance plan | CISO |
| privacy, retention, residency and open data | REQUIRES LEGAL/REGULATORY CONFIRMATION | lawful purposes, periods, transfers, disclosure thresholds | Privacy + Legal + Records |
| SLO, capacity and HA topology | NOT READY | funded topology and representative 2x-peak/failover evidence absent | Architecture + Operations |
| DR and business continuity | NOT READY | secondary/clean-room capability and repeated RTO/RPO evidence absent | Executive + SRE + CISO |
| analytics/BI/AI | APPROVED WITH CONDITIONS | certified metrics, lawful purpose, privacy/model governance | Data + Privacy + Business |
| development/DevSecOps/test architecture | APPROVED WITH CONDITIONS | tool/platform choices and evidence retention implemented | Engineering + CISO |
| phased implementation roadmap | REQUIRES DECISION | funding, teams, procurement, dependencies and rollout authority | Steering Committee |

## Mandatory pre-coding approval

Architecture/design work and disposable technical spikes may continue in isolated non-production environments. Production feature coding begins only for a bounded phase whose applicable rows are `APPROVED` or `APPROVED WITH CONDITIONS`, with conditions owned and dated. A component marked `NOT READY` is blocked. Unknown ITAS behavior must use a documented stub contract and cannot be represented as production integration.

## Sign-off record

| Authority | Name | Decision | Conditions / expiry | Date |
|---|---|---|---|---|
| Executive sponsor |  |  |  |  |
| Architecture Board chair |  |  |  |  |
| NamRA Tax Policy owner |  |  |  |  |
| ITAS owner |  |  |  |  |
| CISO/Security authority |  |  |  |  |
| Privacy/Data Protection authority |  |  |  |  |
| Legal/Regulatory authority |  |  |  |  |
| Data owner |  |  |  |  |
| Operations/DR owner |  |  |  |  |
| Programme/Product owner |  |  |  |  |

Any material change to identity, tenant isolation, tax calculation, certified record semantics, external authority, security zones, recovery targets or deployment topology reopens this gate and the affected ADRs.
