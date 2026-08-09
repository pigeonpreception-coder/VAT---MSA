# Risk Register, Gap Assessment and Maturity Model

## Enterprise risk register

Probability/impact: Low, Medium, High, Critical. Residual rating assumes listed treatment is implemented and evidenced.

| ID | Category | Risk | P | I | Primary treatment | Accountable owner | Residual |
|---|---|---|---|---|---|---|---|
| R-01 | regulatory | assumed VAT rules or authority produce unlawful outcomes | H | C | legal rule inventory; signed versioned bundles; policy owner approval | NamRA Tax Policy | M |
| R-02 | integration | ITAS capabilities/SLAs/claims are unknown | H | C | formal discovery and contract; adapter; continuity; conformance suite | NamRA/ITAS Owner | M |
| R-03 | identity | duplicate or wrongly linked taxpayer identity | M | C | deterministic authoritative lookup; quarantine; maker-checker merge | Identity/Data Owner | L |
| R-04 | privacy | unauthorized taxpayer or cross-tenant disclosure | M | C | tenant enforcement, ABAC/purpose, masking, DLP, tests, audit | CISO/Privacy | L |
| R-05 | fraud | fabricated/duplicate input VAT or collusive refund | H | C | immutable certification, graph/reconciliation, explainable risk, human review | Compliance/Fraud | M |
| R-06 | integrity | invoice/ledger/return history altered or cannot reconcile | M | C | append-only records, hashes, double entry, control totals, custody | CFO/Data Owner | L |
| R-07 | availability | filing deadline or national invoice service outage | H | C | multi-zone, capacity reserve, quotas, offline/queue, DR exercises | SRE Owner | M |
| R-08 | cyber | credential theft, privileged misuse or lateral movement | H | C | phishing-resistant MFA, JIT PAM, zero trust, segmentation, SOC | CISO | M |
| R-09 | supply chain | compromised dependency/build artifact | M | C | lock/proxy, SBOM, provenance, signatures, ephemeral build, SCA | DevSecOps | L |
| R-10 | data loss | ransomware/corruption defeats online replicas | M | C | immutable isolated backups, clean room, PITR and recovery tests | DR/Data Owner | L |
| R-11 | offline | stolen/tampered device issues invalid invoices | M | H | device binding, encryption, signed ranges/rules, expiry, quarantine | Product/CISO | M |
| R-12 | performance | hot tenant/partition causes national degradation | H | H | fairness quotas, stable partitioning, telemetry, cell extraction | SRE/Architecture | M |
| R-13 | third party | SaaS/bank/payment compromise or excessive access | M | C | scoped consent, provider isolation, signature/allowlist, circuit/DLP | Integration Owner | M |
| R-14 | AI/model | biased/opaque risk recommendation harms taxpayer | M | H | lawful purpose, explainability, drift/bias, human decision and appeal | Risk/Privacy | M |
| R-15 | delivery | scope overwhelms governance and assurance | H | H | phased gates, thin vertical slices, architecture runway, acceptance evidence | Programme Owner | M |
| R-16 | skills | insufficient fiscal/security/SRE capacity | H | H | named capability plan, pairing, training, vendor transfer, runbooks | Programme Owner | M |
| R-17 | audit | logs contain secrets/PII or are tampered with | M | C | minimization/tokenization, controlled access, immutable signed checkpoints | CISO/Internal Audit | L |
| R-18 | vendor lock-in | platform dependency prevents sovereignty/portability | M | H | open contracts, portable data, abstraction at boundaries, exit tests | Architecture/Procurement | M |
| R-19 | accessibility | service excludes users/devices/low bandwidth | M | H | WCAG AA, responsive/low-bandwidth design, assisted/offline channels | Product Owner | L |
| R-20 | change | weak adoption/support drives incorrect filings | H | H | research, training, staged rollout, help centre, telemetry and feedback | Change Owner | M |

Risk acceptance must state owner, rationale, compensating controls, expiry and review date. Critical risks cannot be accepted below the designated executive/NamRA authority.

## Gap assessment

| Severity | Gap | Required closure evidence |
|---|---|---|
| CRITICAL | ITAS identity, taxpayer, return and acknowledgement interfaces/authority not confirmed | signed interface/authority agreement, sandbox, conformance and outage tests |
| CRITICAL | statutory tax rules, rounding, numbering, correction, refund and retention not formally baselined | legal/policy catalogue with approved owners and golden cases |
| CRITICAL | production security, data protection and operational authority not approved | security/privacy impact assessments, threat treatments, CISO/DPO approval |
| CRITICAL | no approved production capacity/SLO/DR budget or validated topology | funded design, performance/failover/restore evidence and runbooks |
| HIGH | present pilot authentication is not a national identity implementation | approved IdP integration and standalone identity design evidence |
| HIGH | current pilot storage/schema is not the target enterprise data platform | approved logical/physical design, migration and reconciliation rehearsal |
| HIGH | offline fiscal legal validity and device governance unconfirmed | NamRA/legal approval and device/range/sync conformance tests |
| HIGH | SOC, PAM, SIEM, incident and vulnerability operations not operationalized | tool/process integration, staffed rotations and exercises |
| HIGH | accounting, refund and payment authority boundaries need business ownership | signed RACI, process controls and authoritative integration decisions |
| HIGH | detailed business process/BPMN and acceptance criteria need taxpayer/NamRA validation | workshops, approved BPMN and scenario tests |
| MEDIUM | enterprise glossary, MDM stewardship and quality thresholds not staffed | owners/stewards, catalogue, scorecards and issue workflow |
| MEDIUM | event/API governance tooling and consumer compatibility gates not selected | standards implementation decision and conformance pipeline |
| MEDIUM | analytics/AI lawful-purpose and certified metric catalogue incomplete | privacy review, model governance, certified semantic definitions |
| MEDIUM | accessibility, multilingual content and assisted-service research incomplete | inclusive research, WCAG audit and content governance |
| MEDIUM | vendor portability and exit testing not yet contracted | exit clauses, export formats, restore/relocation exercise |
| LOW | naming, diagram styling and document automation can be standardized | documentation lint/render and board-approved templates |

## Capability maturity assessment

Scale: **Initial** (ad hoc/pilot), **Developing** (design and partial controls), **Defined** (approved repeatable), **Advanced** (measured/automated), **Enterprise-ready** (independently evidenced at national scale).

| Capability | Current | Target before national production | Rationale / next evidence |
|---|---|---|---|
| architecture governance | Developing | Defined | package/ADRs exist; board decisions and exception process needed |
| taxpayer identity/federation | Initial | Advanced | pilot path exists; ITAS federation and recovery proof absent |
| authorization/tenant isolation | Developing | Advanced | model documented; central policy and penetration evidence needed |
| tax rule governance | Initial | Advanced | target lifecycle designed; authoritative catalogue/tooling absent |
| invoice/VAT ledger integrity | Developing | Advanced | pilot capability; enterprise numbering, certification and assurance needed |
| return/reconciliation/refund | Initial | Advanced | architecture exists; statutory interfaces/process validation pending |
| integration/API/event | Developing | Advanced | contracts/catalogues defined; managed gateway/broker/conformance needed |
| data governance/MDM | Initial | Defined | authority model drafted; stewards/catalogue/quality operations pending |
| security engineering/SOC | Developing | Advanced | threat/control design exists; platform, staffing and exercises pending |
| availability/performance | Initial | Advanced | targets proposed; capacity tests and multi-zone evidence absent |
| DR/business continuity | Initial | Advanced | plan exists; isolated recovery and repeated RTO/RPO proof absent |
| observability/SRE | Developing | Advanced | signal model exists; production telemetry/error-budget practice pending |
| DevSecOps/supply chain | Developing | Advanced | pilot pipeline controls; signed provenance and gated promotion needed |
| UX/accessibility/offline | Developing | Defined | portal/pattern design exists; inclusive research and offline assurance pending |
| reporting/BI/AI | Initial | Defined | governed target exists; warehouse, certified metrics and model governance pending |
| programme/change/support | Initial | Defined | phased roadmap exists; staffed operating model and adoption plan needed |

Overall current maturity is **Developing**: the pilot and architecture establish direction, but national production is not approved. Reassessment occurs at every phase gate using test, operational and audit evidence rather than document completion alone.

