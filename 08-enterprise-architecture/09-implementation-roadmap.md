# Phased implementation roadmap and acceptance boundaries

## Globalisation sequencing

The detailed roadmap adds G0-G7: approve the global model; implement global primitives; establish the signed pack supply chain; verify the Namibia pack; run synthetic Namibia execution; test only approved sandbox/stub adapters; make the country-readiness decision; and perform a separately authorized bounded activation. No production statutory rule or live ITAS integration is inferred from architecture completion. See `28-detailed-roadmap.md`.

## Architecture extension sequence

After architecture approval, add a gated organisation-control runway before broad business-module expansion: (1) protected licence/entitlement and organisation-admin foundations; (2) permission catalogue and organisation-specific role model; (3) backend workspace projection and Administration Command Centre; (4) typed workflow/version/SoD runtime; (5) access request/certification/offboarding; and (6) permission-aware enterprise search and scale validation. Payment activation, production ITAS verification and statutory continuity behavior remain separately gated.

This roadmap follows the approved sequence. Existing pilot capabilities are retained but do not justify skipping foundational acceptance.

| Phase | Deliverable | Exit gate |
|---:|---|---|
| 1 | A-T architecture, decisions, ERD, API/events, security/scale/DR, UX and testing | architecture/control owners approve; gaps/risk register owned |
| 2 | identity provider adapter, internal identity links, assurance, session/revocation, RBAC/ABAC and standalone-auth selection | ITAS capability contract; threat/auth testing; no duplicate identity |
| 3 | taxpayer registration/proofing, canonical organisation, branches, users/memberships and dynamic buyer/seller capabilities | duplicate/mismatch tests; lifecycle/recertification; tenant isolation |
| 4 | Buyer, Seller, NamRA, NamRA Admin and Super Admin portals | portal authorization and separation; usability/accessibility UAT |
| 5 | parties, quotation-to-invoice, accounting, inventory, expense and project bounded contexts | balanced accounts, approval/audit, functional UAT |
| 6 | configurable VAT rules, transactions, ledger, periods, returns, reconciliation and adjustments | NamRA rule/form approval; integrity/property/reconciliation tests |
| 7 | ITAS/SaaS adapters, developer portal, sandbox, machine identities and sync | conformance, sandbox isolation, quota/replay and operational SLA |
| 8 | compliance, communication, audit case, internal risk and controlled refund workflow | legal/policy approval; case/privacy and human-decision controls |
| 9 | document wallet, consent/delegation, calendar, offline, multi-branch/entity/currency, portability and approved regulated integrations | security/privacy/integration approval per capability |
| 10 | national scale/security validation | independent load/stress/penetration/DDoS/DR/integrity/failover evidence |
| 11 | controlled pilot | selected participants, support/SOC, monitored outcomes and remediation |
| 12 | national deployment | UAT, legal, security, performance, integration, operations and DR sign-off |

## Current increment

This release completes Phase 1 artifacts and implements a controlled Phase 2-4 foundation: canonical organisation records, branches, provider/link metadata, memberships, buyer/seller capabilities, registration verification state, tax-rule governance metadata, integration registry and separated portal/admin summaries. It does not claim live ITAS federation, public standalone MFA or production tax-rule authority.

## Dependency decisions requiring external confirmation

- ITAS identity protocol, issuer/claims/assurance and taxpayer/period APIs.
- Official VAT invoice, numbering, return workbook/formulas and effective tax rules.
- Namibian legal requirements for privacy, evidence, retention, data residency, disputes and accessibility.
- Production hosting/data/event/document/KMS/SIEM/WAF providers and sovereignty controls.
- HSM signature/certificate standard and key custody.
- Banking/payment/customs regulated integration authority.

These are not new features beyond approved scope; they are external specifications and approvals needed to implement the approved capabilities safely.
