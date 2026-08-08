# Security Controls Matrix

The control baseline aligns the operating model to NIST Cybersecurity Framework 2.0, NIST SP 800-207 zero-trust principles, OWASP ASVS and the OWASP API Security Top 10. Final mappings to NamRA and Government of Namibia policy are required during discovery.

| ID | Control objective | Required implementation | Verification evidence | Owner |
|---|---|---|---|---|
| GOV-01 | Govern cyber and data risk | Named system owner, data owner, CISO authority, risk register, quarterly control review and exception process | Approved charter, risk minutes, exception register | Executive Sponsor / CISO |
| IAM-01 | Strong human identity | Central OIDC identity provider, phishing-resistant MFA for privileged/NamRA roles, conditional access and session risk | IdP configuration export, MFA coverage report, access tests | IAM Team |
| IAM-02 | Machine identity | mTLS, private-key client authentication, short-lived credentials, workload identity and automated rotation | Certificate inventory, rotation logs, negative tests | IAM / Platform |
| IAM-03 | Least privilege | RBAC plus taxpayer/case/resource attributes; deny by default; quarterly certification | Policy tests, access-review evidence, denied-access logs | Business Owners / IAM |
| IAM-04 | Privileged access | PAM vault, just-in-time elevation, dual approval for HSM/production data, recorded sessions and break-glass review | PAM logs, session recordings, post-use reviews | Security Operations |
| API-01 | Object-level authorisation | Every object request validates caller, role, taxpayer scope and case assignment | Automated BOLA tests, policy decision logs | API Platform |
| API-02 | Resource and flow protection | Per-client/taxpayer limits, payload/line/batch limits, quotas, timeouts and abuse detection | Load/abuse tests, gateway policy export | API Platform |
| API-03 | Contract validation | Strict JSON Schema, unknown-property rejection, content-type enforcement and semantic validation | Contract-test report, fuzz-test results | Engineering |
| API-04 | Safe partner integration | Partner onboarding, conformance sandbox, scoped credentials, endpoint inventory and retirement policy | Partner dossier, conformance result, inventory | Integration Governance |
| DAT-01 | Encrypt tax-confidential data | TLS 1.3 where supported; approved TLS 1.2 fallback; AES-256-equivalent storage encryption; separate key domains | Scanner output, KMS policy, cryptographic inventory | Platform / Security |
| DAT-02 | Minimise and isolate data | Purpose-based views, field masking, tokenised identifiers, restricted exports and separate analytical keys | Query-policy tests, export logs, data-flow map | Data Governance |
| DAT-03 | Govern residency and transfer | Approved hosting locations, transfer register, vendor/subprocessor controls and sovereign backup locations | Contracts, architecture evidence, transfer approvals | Legal / Procurement |
| DAT-04 | Retain and dispose lawfully | Record classes, legal holds, immutable retention, approved disposal and destruction evidence | Retention schedule, hold tests, destruction certificates | Records Manager |
| CRY-01 | Protect fiscal signatures | HSM-protected non-exportable keys, approved algorithms, key ceremony, rotation, revocation and public verification | HSM attestation, ceremony records, verification tests | PKI Authority |
| APP-01 | Secure software lifecycle | Threat modelling, secure coding, peer review, SAST, SCA, secret scanning, DAST and release attestations | Pipeline evidence, SBOM, signed provenance | Engineering / AppSec |
| APP-02 | Prevent injection and unsafe processing | Parameterised queries, output encoding, safe parsers, allowlists and sandboxing for uploaded content | ASVS tests, code review, penetration test | Engineering |
| APP-03 | Protect business integrity | Idempotency, duplicate detection, transaction/outbox atomicity, balanced ledger and immutable corrections | Invariant/property tests, reconciliation report | Domain Owners |
| LOG-01 | Complete audit trail | Who/what/when/where/outcome/reason, correlation IDs, before/after hashes, append-only store and clock synchronisation | Event samples, tamper tests, NTP monitoring | Security / Internal Audit |
| LOG-02 | Detect abuse and fraud | Central SIEM, use cases for credential abuse, high-volume access, export anomalies, sequence breaks and signature failures | Alert tests, use-case coverage, incident metrics | SOC |
| OPS-01 | Harden infrastructure | Approved images, CIS-aligned configuration, patch SLAs, vulnerability scanning, network policy and admission control | Baseline scans, patch report, cluster policy | Platform Operations |
| OPS-02 | Protect supply chain | Locked dependencies, trusted registries, signed artifacts, SBOM, provenance and promotion by digest | Signature verification, SBOM archive | DevSecOps |
| RES-01 | Assure availability | Multi-zone services, graceful degradation, backpressure, circuit breakers, tested capacity and dependency isolation | Resilience tests, load report, SLO dashboard | SRE |
| RES-02 | Recover from disaster | Encrypted replicated data, isolated backups, immutable copies, documented failover/failback and quarterly exercises | Restore logs, DR exercise report, RTO/RPO evidence | SRE / Business Continuity |
| INC-01 | Respond to incidents | Severity model, on-call, evidence preservation, legal/comms playbooks, taxpayer-impact assessment and lessons learned | Exercise report, incident records, action tracking | SOC / Incident Commander |
| PRI-01 | Protect privacy and rights | Privacy-by-design review, purpose/authority register, data-subject workflow where applicable, breach assessment and DPO oversight | DPIA, processing inventory, workflow tests | Privacy / Legal |
| AI-01 | Govern analytical models | Documented purpose, data lineage, validation, explainability, drift/bias monitoring, human decision and appeal | Model card, validation report, override/appeal log | Risk Governance |

## Severity-based remediation targets

| Finding | Internet-exposed production | Internal production | Exception authority |
|---|---:|---:|---|
| Critical exploitable vulnerability | 24 hours or compensating isolation | 48 hours | CISO |
| High vulnerability | 7 days | 14 days | Security Director |
| Medium vulnerability | 30 days | 60 days | System Owner |
| Low vulnerability | 90 days or planned release | 120 days | Product Owner |

Security exceptions must state scope, business justification, compensating controls, accountable owner and expiry. They may not silently convert into permanent design.
