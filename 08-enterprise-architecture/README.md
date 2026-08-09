# VAT-MSA complete enterprise architecture package

Status: architecture-board draft for formal review. This package governs the evolution of the operational pilot into Namibia's national digital VAT infrastructure. It distinguishes the approved target, existing pilot evidence and production capabilities that still require NamRA/ITAS confirmation, legal approval, selected platforms and acceptance evidence. No production implementation is authorized by this package until the Architecture Approval Gate permits it.

Start with the authoritative [45-deliverable register](00-deliverable-register.md), then review the consolidated [23-part enterprise blueprint](VAT-MSA-ENTERPRISE-ARCHITECTURE-BLUEPRINT.md) and [formal approval gate](29-architecture-approval-gate.md).

## Architecture principles

1. One canonical taxpayer identity per VAT-registered legal entity; buyer and seller are transaction capabilities, never duplicate accounts.
2. One organisation binds the VAT number, TIN and company registration number to branches, authorised users and business activity.
3. ITAS/NamRA is the preferred authoritative identity and taxpayer-attribute source, subject to verified technical contracts. Standalone access links to the same taxpayer.
4. Buyer, Seller and NamRA experiences are separated at the portal layer while reusing shared domain services and a single VAT transaction network.
5. Super Administration operates technology; NamRA Administration governs tax access. Technical privilege does not imply taxpayer-data privilege.
6. RBAC grants job capability; ABAC constrains it by organisation, taxpayer, department, region, classification, transaction and workflow state.
7. Statutory transactions are immutable. Corrections use adjustment, reversal, credit-note or debit-note records.
8. Tax rules are centrally versioned, effective-dated, approved and auditable. NamRA remains the statutory authority.
9. APIs are versioned and idempotent; events decouple non-critical processing; synchronous receipt never claims success without a durable commit.
10. National scale, zero trust, auditability, privacy, accessibility, graceful degradation and tested recovery are acceptance criteria, not slogans.

## Required A-T deliverable register

| ID | Required output | Authoritative artifact |
|---|---|---|
| A | Enterprise Architecture | `01-enterprise-solution-architecture.md` |
| B | Solution Architecture | `01-enterprise-solution-architecture.md`, `diagrams/system-context.mmd` |
| C | Domain Architecture | `02-domain-architecture.md`, `diagrams/domain-map.mmd` |
| D | Portal Architecture | `03-portal-ux-design-system.md` |
| E | Identity Architecture | `04-identity-rbac-abac.md`, ADR-001/003/004 |
| F | RBAC/ABAC Matrix | `04-identity-rbac-abac.md`, `rbac-abac-matrix.csv` |
| G | Data Architecture | `05-data-architecture-and-erd.md` |
| H | Database Schema | `05-data-architecture-and-erd.md`, `diagrams/enterprise-erd.mmd` |
| I | API Architecture | `06-api-integration-architecture.md`, `api-catalog.yaml` |
| J | Integration Architecture | `06-api-integration-architecture.md` |
| K | Security Architecture | `07-security-scale-recovery.md`, `17-threat-model-stride.md`, `18-security-operations-topology.md` |
| L | Scalability Architecture | `07-security-scale-recovery.md` |
| M | Disaster Recovery Architecture | `07-security-scale-recovery.md` |
| N | UX Architecture | `03-portal-ux-design-system.md` |
| O | UI Design System | `03-portal-ux-design-system.md` |
| P | Event Architecture | `08-event-deployment-devsecops-testing.md`, `event-catalog.csv` |
| Q | Deployment Architecture | `08-event-deployment-devsecops-testing.md` |
| R | DevSecOps Architecture | `08-event-deployment-devsecops-testing.md` |
| S | Testing Strategy | `08-event-deployment-devsecops-testing.md` |
| T | Data Governance | `05-data-architecture-and-erd.md`, `data-classification-retention.csv` |

## Proposed decisions

ADRs 001-012 cover canonical taxpayer identity, dynamic buyer/seller roles, ITAS federation, controlled standalone access, service boundaries, database strategy, event integration, multi-tenancy, offline capability, zero trust, scalability/availability and versioned tax rules. They are proposals until approved by the authorities named in each record and in the formal approval gate.

## Implementation state

Implemented now: operational D1 pilot, canonical taxpayer/organisation mappings, taxpayer identifiers, branches, dynamic buyer/seller capabilities, identity-provider links, membership/RBAC metadata, idempotent registration intake, secure invoice API, certification receipt, seller/buyer ledger entries, VAT-period summaries, exception queue, public verification, audit/security evidence, outbox, role/scoped authorization, health and release gates.

The next proposed increment is controlled membership/branch administration and verified taxpayer activation after the relevant identity, ITAS and NamRA decisions are approved. The current registration flow deliberately stops at `AWAITING_PROVIDER_CONTRACT`; it cannot create a legal taxpayer from an unverified application.

Still gated: live ITAS protocol/SSO, MFA/PAM, statutory return formulas, HSM signature, official invoice numbering, document R2 and malware scanning, message-broker relay/consumers, accounting/inventory/project modules, production WAF/SIEM/KMS, bank/payment/customs integrations, independently proven national capacity and regional DR.

No architecture target is an assertion of statutory compliance or achieved scale. Production acceptance remains governed by the [formal approval gate](29-architecture-approval-gate.md), applicable enterprise security acceptance controls and the [detailed roadmap](28-detailed-roadmap.md).
