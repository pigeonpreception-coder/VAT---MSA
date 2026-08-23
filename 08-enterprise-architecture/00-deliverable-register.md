# VAT-MSA baseline and extension deliverable register

## Dual-subscription and self-service onboarding extension

| # | Required extension output | Primary evidence | Document status |
|---:|---|---|---|
| 96 | Strict two-authority enterprise architecture | `dual-subscription/01-updated-enterprise-architecture.md`, ADR-030 | Approved for local/staging implementation baseline |
| 97 | C4 context/container/component views | dual-subscription artefacts `02`-`04` | Complete and architecture-gated |
| 98 | Dual signup, identity and subscription architectures | dual-subscription artefacts `05`-`09` | Complete; payment/live ITAS remain disabled |
| 99 | RBAC/ABAC, hierarchy and ERD | dual-subscription artefacts `10`-`12` | Complete logical/deep-control baseline |
| 100 | API and authority adapter architecture | dual-subscription artefacts `13`-`15` | Complete reference; external contracts gated |
| 101 | Security and sequence architecture | dual-subscription artefacts `16`-`17` | Complete for local/staging implementation |
| 102 | Eighteen end-to-end and enforcement workflows | dual-subscription artefacts `18`-`20` | Complete and traceable |
| 103 | Failure, audit, scale and offline architecture | dual-subscription artefacts `21`-`24` | Complete reference; scale not asserted |
| 104 | Threat model, strategy and executable test catalogues | dual-subscription artefacts `25`-`28` | Complete; automated gate required |
| 105 | Acceptance criteria | `dual-subscription/29-acceptance-criteria.md` | Local/staging scope only |

These artefacts establish two independent authority decisions. A commercial subscription never grants government tax functionality, and government taxpayer authorization never grants commercial SaaS functionality.

## Global security, privacy and compliance architecture extension

| # | Required security output | Primary evidence | Document status |
|---:|---|---|---|
| 76 | A. Complete enterprise security architecture | `33-global-security-privacy-compliance-architecture.md` | Complete for architecture review; implementation gated |
| 77 | B. Zero Trust architecture | `34-zero-trust-iam-pam-architecture.md`, zero-trust diagram, ADR-026 | Complete for review; platform decision gated |
| 78 | C-D. IAM and PAM architecture | document 34, `rbac-abac-matrix.csv` | Complete for review; IAM/PAM contracts gated |
| 79 | E. Data security architecture | `35-data-application-api-network-cloud-security.md` | Complete for review; platform/legal decisions gated |
| 80 | F. Network security architecture | document 35, defence-in-depth diagram | Complete for review; provider/topology gated |
| 81 | G-H. Application and API security architecture | document 35, `security-test-catalog.csv` | Complete for review; implementation/testing gated |
| 82 | I. Cloud security architecture | document 35 and SEC-CLD controls | Complete provider-neutral design; provider gated |
| 83 | J. SOC architecture | `36-soc-incident-forensics-fraud-security-operations.md` | Complete reference; platform/staffing gated |
| 84 | K. Incident-response architecture | document 36 and incident lifecycle diagram | Complete reference; contacts/exercises gated |
| 85 | L. Disaster-recovery security architecture | `38-secure-delivery-resilience-testing-roadmap.md`, document 20 | Complete reference; topology/exercises not ready |
| 86 | M. Privacy architecture | `37-privacy-regional-compliance-architecture.md` | Complete reference; country legal review required |
| 87 | N. Compliance and regional architecture | document 37, `regional-compliance-applicability.csv`, profiles | Complete templates; no regional auto-activation |
| 88 | O. Security governance | document 33, ADR-025 through ADR-029 | Proposed; formal approval required |
| 89 | P. Global security control matrix | `security-control-matrix.csv` | Complete logical catalogue; operation not asserted |
| 90 | Q. Formal domain threat model | `security-threat-register.csv`, document 17 | Complete initial model; recurring review required |
| 91 | R. Security testing strategy | `security-test-catalog.csv`, document 38 | Designed; independent production tests outstanding |
| 92 | S. Security operations model | document 36, `security-slo-catalog.csv` | Designed; 24x7 tier/staffing decision outstanding |
| 93 | T. Security roadmap | document 38 | Complete gated sequence; production not authorized |
| 94 | Standards, NIST CSF and legal applicability crosswalks | `standards-applicability-crosswalk.csv`, `nist-csf-2-crosswalk.csv` | Complete for review; licensed/legal validation required |
| 95 | Compliance evidence and end-to-end traceability | `compliance-evidence-catalog.csv`, `security-requirements-traceability.csv` | Complete logical model; evidence operation pending |

This extension is an architecture and control baseline. It does not claim certification or operating effectiveness and does not authorize production implementation.

## Globalisation and country-compliance extension

| # | Required extension output | Primary evidence | Document status |
|---:|---|---|---|
| 63 | Global core and country-pack architecture | `31-globalisation-country-compliance-architecture.md`, ADR-020 | Complete for architecture review; activation disabled |
| 64 | Namibia reference compliance pack | `32-namibia-country-compliance-pack.md`, `country-packs/NAM/manifest.yaml` | Under regulatory review; non-executable |
| 65 | Multi-currency and monetary model | globalisation architecture section 8, ADR-021 | Complete for review; FX policy confirmation required |
| 66 | Jurisdiction and tax-rule binding | globalisation architecture sections 5 and 7, ADR-022 | Complete for review; regulatory rules unapproved |
| 67 | Country-pack assurance and readiness | ADR-023, `country-readiness-framework.csv` | Framework complete; Namibia not ready |
| 68 | Regulatory IAM and administration separation | globalisation architecture section 12, ADR-024, `rbac-abac-matrix.csv` | Complete for review; role appointments gated |
| 69 | Global data model and ERD | globalisation architecture section 15, `diagrams/global-compliance-erd.mmd` | Logical model complete; physical migration gated |
| 70 | Global API and event contracts | `api-catalog.yaml`, `event-catalog.csv` | Logical contracts complete; adapters disabled |
| 71 | Global security, infrastructure and residency | globalisation architecture sections 16-17, deployment diagrams | Complete for review; hosting/residency decisions outstanding |
| 72 | Localisation, documents, calendars and reporting | globalisation architecture sections 10-11 and 18 | Complete for review; templates/rules unapproved |
| 73 | Global traceability and control classification | `globalisation-requirements-traceability.csv`, `globalisation-control-classification-matrix.csv` | Complete for review |
| 74 | Globalisation ADR and roadmap update | ADR-020 through ADR-024, `28-detailed-roadmap.md` | Proposed; approval gates outstanding |
| 75 | Country compliance and global regression test contract | `country-compliance-test-catalog.csv`, globalisation architecture section 23 | Designed; automation awaits approved executable packs |

These are architecture deliverables, not country-production authorization. Each country remains unavailable until its readiness decision is `APPROVED` and a signed executable pack is explicitly activated.

## Workspace, organisation, licensing and workflow extension

| # | Required extension output | Primary evidence | Document status |
|---:|---|---|---|
| 46 | Updated C4 architecture | `10-c4-enterprise-architecture.md`, updated L2/L3 and workspace component diagram | Complete for review |
| 47 | Updated domain architecture | `02-domain-architecture.md`, `11-domain-capability-catalog.md`, domain map | Complete for review |
| 48 | Updated organisation architecture | `30-workspace-organisation-licensing-workflow-architecture.md` | Complete for review; admin policy gated |
| 49 | Updated IAM and RBAC/ABAC | `04-identity-rbac-abac.md`, `rbac-abac-matrix.csv`, ADR-018 | Complete for review; permission catalogue gated |
| 50 | Licensing architecture | extension sections 4 and 15, ADR-017 | Complete for review; commercial/legal decisions gated |
| 51 | Workflow architecture | extension section 7, workflow/SoD diagram, ADR-019 | Complete for review; transition catalogue gated |
| 52 | Workspace/navigation architecture | extension section 5, ADR-016 | Complete for review; UX taxonomy gated |
| 53 | Updated ERD and data dictionary | updated enterprise ERD, `13-logical-data-dictionary.md` | Complete logical model; physical migration gated |
| 54 | Updated API catalogue | `api-catalog.yaml`, `15-api-contract-catalog.md` | Logical contract complete for review |
| 55 | Updated event catalogue | `event-catalog.csv`, event architecture addendum | Complete for review |
| 56 | Updated security architecture and threat model | extension sections 11/13, `17-threat-model-stride.md` | Complete initial control model; testing required |
| 57 | Updated UX architecture | `03-portal-ux-design-system.md`, extension sections 5/12 | Complete for design review |
| 58 | Updated requirements traceability | `workspace-licensing-requirements-traceability.csv` | All 42 enhancement sections mapped |
| 59 | Updated ADRs | ADR-016 through ADR-019 | Proposed; explicit approvals required |
| 60 | Updated implementation roadmap | `09-implementation-roadmap.md`, `28-detailed-roadmap.md` | Gated sequence complete for review |
| 61 | Configuration versus enforcement classification | `configuration-system-control-matrix.csv` | Complete for security/governance review |
| 62 | Impact, contradiction and dependency assessment | extension sections 14-16 | Complete; named decisions outstanding |

All statuses describe documentation completeness, not production approval. Approval status is governed separately in `29-architecture-approval-gate.md`.

| # | Deliverable | Primary evidence | Document status |
|---:|---|---|---|
| 01 | Executive Architecture Summary | `00-executive-architecture-summary.md`, executive diagram | Complete for review |
| 02 | C4 Enterprise Architecture L1-L4 | `10-c4-enterprise-architecture.md`, C4 diagrams | Complete for review |
| 03 | Domain-Driven Architecture | `11-domain-capability-catalog.md`, domain map | Complete for review |
| 04 | Modular/Microservice Decision | `12-architecture-style-assessment.md`, ADR-005 | Complete for review |
| 05 | Complete Data Architecture | `05-data-architecture-and-erd.md` | Complete for review |
| 06 | Complete Logical Database/ERD | `13-logical-data-dictionary.md`, enterprise ERD | Complete for review; physical design gated |
| 07 | Partitioning & Multi-Tenancy | `13-logical-data-dictionary.md`, ADR-006/008 | Complete for review |
| 08 | RBAC + ABAC Matrix | `04-identity-rbac-abac.md`, `rbac-abac-matrix.csv` | Complete for review |
| 09 | Identity & Access Architecture | `04-identity-rbac-abac.md`, ADR-001-004 | Conditional on IAM/ITAS decisions |
| 10 | ITAS Integration Architecture | `14-itas-saas-integration.md` | Requires ITAS confirmation |
| 11 | SaaS Integration Architecture | `14-itas-saas-integration.md` | Complete for provider review |
| 12 | API Catalogue | `15-api-contract-catalog.md`, `api-catalog.yaml` | Logical contract complete; schemas phased |
| 13 | Event Architecture | `08-event-deployment-devsecops-testing.md`, `event-catalog.csv` | Complete for review |
| 14 | VAT Transaction Flow | `16-vat-transaction-return-offline.md`, VAT sequence | Requires NamRA rule confirmation |
| 15 | VAT Return Process | `16-vat-transaction-return-offline.md` | Requires official formulas/workflow |
| 16 | Offline Architecture | `16-vat-transaction-return-offline.md`, ADR-009 | Conditional; security/legal review required |
| 17 | Security Architecture | `07-security-scale-recovery.md`, `18-security-operations-topology.md` | Complete reference; platform selection gated |
| 18 | Formal Threat Model | `17-threat-model-stride.md` | Complete initial model; recurring reviews required |
| 19 | Security Operations | `18-security-operations-topology.md` | Complete reference; SOC integration gated |
| 20 | Infrastructure Topology | `18-security-operations-topology.md`, infrastructure diagram | Provider-neutral target complete |
| 21 | High Availability | `19-ha-scalability-performance-observability.md` | Proposed targets require approval/testing |
| 22 | Scalability Architecture | `19-ha-scalability-performance-observability.md` | Capacity hypotheses require testing |
| 23 | Performance Architecture | `19-ha-scalability-performance-observability.md` | Proposed targets require approval |
| 24 | Observability Architecture | `19-ha-scalability-performance-observability.md` | Complete reference |
| 25 | Disaster Recovery | `20-dr-business-continuity.md` | Proposed RTO/RPO require approval/exercise |
| 26 | Data Governance | `21-data-governance-master-tax-rules.md`, classification register | Legal/retention confirmation required |
| 27 | Master Data Architecture | `21-data-governance-master-tax-rules.md` | Authority matrix complete; ITAS contract gated |
| 28 | Tax Rule Engine | `21-data-governance-master-tax-rules.md`, ADR-012 | Requires NamRA rule authority |
| 29 | Audit Architecture | `22-audit-refund-reporting.md` | Complete for review |
| 30 | Refund Architecture | `22-audit-refund-reporting.md` | Requires NamRA legal/business workflow |
| 31 | UX Architecture | `03-portal-ux-design-system.md` | Complete for UX research/design phase |
| 32 | 30 End-to-End Process Flows | `23-business-process-catalog.md` | Complete logical flows; UAT details phased |
| 33 | Error & Exception Architecture | `24-error-api-resilience.md` | Complete for review |
| 34 | API Resilience | `24-error-api-resilience.md` | Complete reference |
| 35 | Development Architecture | `25-development-environment-architecture.md` | Complete target structure |
| 36 | Environment Architecture | `25-development-environment-architecture.md` | Complete reference; platform details gated |
| 37 | DevSecOps Architecture | `08-event-deployment-devsecops-testing.md`, `25-development-environment-architecture.md` | Complete reference |
| 38 | Test Architecture | `08-event-deployment-devsecops-testing.md`, `25-development-environment-architecture.md` | Complete strategy |
| 39 | Architectural Decision Records | `adr/ADR-001` through `ADR-012` | Complete for board decision |
| 40 | Requirements Traceability Matrix | `26-requirements-traceability-matrix.csv` | Complete section-level mapping |
| 41 | Architecture Risk Register | `27-risk-gap-maturity.md` | Complete initial register |
| 42 | Architecture Gap Analysis | `27-risk-gap-maturity.md` | Complete; critical gaps explicit |
| 43 | Implementation Roadmap | `09-implementation-roadmap.md`, `28-detailed-roadmap.md` | Complete; gated sequence |
| 44 | Architecture Maturity Assessment | `27-risk-gap-maturity.md` | Complete current/target ratings |
| 45 | Final Consolidated Blueprint | `VAT-MSA-ENTERPRISE-ARCHITECTURE-BLUEPRINT.md` | Complete for architecture review |

## Approval rule

"Complete for review" does not mean "approved for production coding." Any component marked NOT READY or with an unresolved critical dependency remains blocked. ITAS/NamRA/legal confirmations cannot be manufactured by the architecture team.
