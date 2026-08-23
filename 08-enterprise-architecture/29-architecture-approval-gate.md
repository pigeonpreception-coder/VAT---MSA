# Formal Architecture Approval Gate

## Global security, privacy and compliance decision register

| Component / decision | Proposed status | Conditions / decision required | Required approver |
|---|---|---|---|
| unified security/privacy/compliance control framework | REQUIRES DECISION | accept ADR-025, control ownership, risk method, licensed standards register and claims policy | Architecture Board + CISO + Privacy + Legal |
| continuous zero-trust decision/enforcement | APPROVED WITH CONDITIONS | accept ADR-026; select resilient identity/policy/workload trust and prove negative authorization | Architecture Board + CISO + Identity Authority |
| IAM assurance, federation and recovery | REQUIRES DECISION | approve NIST-informed assurance selection, IdP, authenticator, recovery, accessibility and provider contracts | Identity Authority + CISO + Privacy |
| PAM/JIT and break-glass boundary | REQUIRES DECISION | select PAM, approve session/privacy policy and retain no emergency SoD override | CISO + Operations + Legal/Privacy |
| security profile hierarchy and signing | REQUIRES DECISION | accept ADR-028; approve schema, signer/HSM, quorum, anti-downgrade and conflict authority | Architecture Board + CISO + Country Authorities |
| audit and compliance evidence architecture | APPROVED WITH CONDITIONS | accept ADR-027; select immutable store, time, retention/hold and independent access model | CISO + Records + Legal + Internal Audit |
| application/API security baseline | APPROVED WITH CONDITIONS | adopt ASVS 5.0.0 verification and API catalogue; implement tests and independent assessment | AppSec + Engineering + CISO |
| cloud/network/data/key/secret control platforms | REQUIRES DECISION | select provider/topology/KMS/HSM/vault/WAF/SIEM and approve shared-responsibility/residency | Architecture + CISO + Data + Procurement |
| SOC/CSIRT operating tier and automated response | REQUIRES DECISION | approve staffing/on-call, severity, contacts, playbooks and bounded reversible automation | CISO + Executive + Legal/Privacy |
| privacy management and regional applicability | REQUIRES LEGAL/REGULATORY CONFIRMATION | approve entity roles, processing, rights, DPIA, retention, residency, transfers and breach duties per country | Privacy + Legal + Country Authority |
| Namibia security/privacy profile | REQUIRES NAMRA CONFIRMATION | verify gazetted law, NamRA/ITAS security contracts, government PKI, incident contacts and independent review | Namibia Legal + NamRA + ITAS + CISO + Privacy |
| PCI DSS and payment security | NOT READY | payments disabled; complete formal CDE scope and architecture before any card/payment work | Finance + Payment Security + Legal + CISO |
| AI security and ISO/IEC 42001 applicability | NOT READY | AI disabled; separate impact, security, privacy, model and human-oversight architecture required | AI Governance + CISO + Privacy + Legal |
| security testing and independent penetration | NOT READY | implement mandatory catalogue; authorize and pass web/API/cloud/identity/tenant/business-logic tests | CISO + Independent Assessor + Release Authority |
| security SLO, scale, SOC and recovery evidence | NOT READY | approve targets and prove representative load, detection, incident, restore and regional/cyber recovery | CISO + SRE + DR + Executive |
| production security architecture implementation | NOT READY | ADR-025 through ADR-029 approved; scoped controls implemented/tested; no critical gate failure | Architecture Board + CISO + Steering Committee |

**Security package status:** `REQUIRES DECISION`. The architecture artifacts and profiles are review-ready but non-executable and make no certification or compliance claim.

## Globalisation and country-compliance decision register

| Component / decision | Proposed status | Conditions / decision required | Required approver |
|---|---|---|---|
| global core plus isolated country-pack model | APPROVED WITH CONDITIONS | accept ADR-020 and retain fail-closed pack selection | Architecture Board + CISO |
| authoritative jurisdiction resolution | REQUIRES DECISION | approve evidence precedence, conflicts, migrations and cross-border responsibility | Tax Policy + Legal + Data |
| exact monetary model using ISO 4217 codes | APPROVED WITH CONDITIONS | accept ADR-021 and prohibit binary floating point and ambiguous currency symbols | Architecture Board + Finance Controls |
| exchange-rate source and VAT conversion policy | REQUIRES NAMRA CONFIRMATION | approve sources, timestamps, fallbacks, corrections and audit evidence | NamRA Tax + Finance + Legal |
| Namibia pack `VAT-MSA-NAM` | REQUIRES NAMRA CONFIRMATION | complete every confirmation item in the Namibia pack and approve source evidence | NamRA Tax + Legal + Records |
| Namibia presentation `N$`; interchange `NAD` | APPROVED WITH CONDITIONS | confirm document-specific display rules; persist currency code on every amount | Product + Finance + NamRA Tax |
| Namibia tax, invoice, period, return and retention rules | REQUIRES LEGAL/REGULATORY CONFIRMATION | approve effective versions and golden cases; legislation references alone do not activate code | NamRA Tax + Legal + Records |
| ITAS/NamRA country adapter | REQUIRES ITAS CONFIRMATION | obtain official API, sandbox, identity, receipt, retry and support contracts | ITAS + Integration Owner + CISO |
| signed country-pack lifecycle and readiness gate | APPROVED WITH CONDITIONS | accept ADR-023; select signing authority/HSM and evidence retention | Architecture Board + CISO + Release Authority |
| regulatory administration separation | APPROVED WITH CONDITIONS | accept ADR-024; appoint independent roles and approve quorum/review policy | CISO + NamRA + Internal Audit |
| Namibia residency/privacy/cross-border transfer profile | REQUIRES LEGAL/REGULATORY CONFIRMATION | approve lawful basis, hosting, residency, transfer, retention and disposal rules | Privacy + Legal + Records + CISO |
| production activation of any country pack | NOT READY | all country readiness controls, signed ADRs, acceptance evidence and explicit release decision required | Architecture Board + Country Regulatory Authority + Steering Committee |

**Globalisation package status:** `REQUIRES DECISION`. The Namibia pack is documentation-only, non-executable and not authorized for production activation.

## Workspace, licensing and workflow extension decision register

### ADR-030 dual-subscription implementation baseline

| Component / decision | Status | Scope/condition | Authority |
|---|---|---|---|
| Separate Government Tax Authorization Service and commercial License & Entitlement Service | APPROVED WITH CONDITIONS | synthetic local/staging only; authority-domain database constraints mandatory | Architecture owner-approved requirement |
| Company administrator commercial self-service intake | APPROVED WITH CONDITIONS | pending verification only; no payment, activation, email or SMS | Architecture owner-approved requirement |
| Explicit finite/unlimited capacity and non-destructive exception | APPROVED WITH CONDITIONS | API/service/database enforcement and automated negative tests | Architecture owner-approved requirement |
| Live ITAS/payment/government activation/statutory rules | NOT READY | named external contracts, evidence and production approval absent | NamRA/ITAS/Finance/Legal/CISO |

The complete ordered evidence is `dual-subscription/01` through `29` and ADR-030. This approval does not change any production `NOT READY` decision below.

| Component / decision | Proposed status | Conditions / decision required | Required approver |
|---|---|---|---|
| canonical organisation with dynamic Buyer/Seller capabilities | APPROVED WITH CONDITIONS | retain one taxpayer identity and validate capability terminology | Architecture + NamRA Tax |
| Organisation Portal Administrator mapping | REQUIRES DECISION | confirm compatibility mapping from Taxpayer Administrator and proofing/change quorum | Product + CISO + NamRA |
| administrator hierarchy and grantable permissions | REQUIRES DECISION | approve scopes, protected permissions, ceilings and recertification | CISO + Business Owners |
| backend workspace/navigation engine | APPROVED WITH CONDITIONS | approve taxonomy, sensitive hiding, cache invalidation and WCAG evidence | Product + UX + CISO |
| licence/entitlement bounded context | REQUIRES DECISION | approve plan authority, provider, state machine, metering and separation | Commercial + Finance + CISO |
| expiry/suspension/downgrade statutory continuity | REQUIRES LEGAL/REGULATORY CONFIRMATION | define read/write/export/retention and legally required action policy | NamRA Tax + Legal + Records |
| typed workflow designer/versioning | APPROVED WITH CONDITIONS | approve expression/transition catalogue, publication and migration policy | Architecture + Domain Owners + CISO |
| mandatory SoD and no emergency override | APPROVED WITH CONDITIONS | approve rule catalogue and bounded technical break-glass procedures that preserve independent duties | CISO + Finance Controls + Legal |
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
| Internal Audit/Independent assurance |  |  |  |  |
| Identity/PAM authority |  |  |  |  |
| Cryptographic/Key authority |  |  |  |  |
| SOC/Incident-response authority |  |  |  |  |
| Data owner |  |  |  |  |
| Operations/DR owner |  |  |  |  |
| Programme/Product owner |  |  |  |  |

Any material change to identity, tenant isolation, tax calculation, certified record semantics, external authority, security zones, recovery targets or deployment topology reopens this gate and the affected ADRs.
