# VAT-MSA — Global Enterprise Cybersecurity Master Architecture

**Status:** Governance / north-star specification, supplied by the project owner on 2026-08-27.
**Relationship to other governance docs:** This is the parent security specification for the entire platform. `MODULE_DEVELOPMENT_PLAYBOOK.md` and `ARCHITECTURE_IMPLEMENTATION_MATRIX.md` remain the source of truth for what has actually been built, module by module — Module 8 ("Platform & Security") is one security capability (Threat Detection & Response, plus the security telemetry/incident/audit-chain work already delivered) operating *within* this broader architecture, not the whole of it. Security-relevant work in any module should be designed against this document's principles.
**Important calibration note (added by the assistant, not the original author):** This document is written at enterprise/national-infrastructure consulting scale. Large parts of it describe organizational, contractual, staffing, and physical/vendor infrastructure decisions (SOC staffing, ISO 27001/PCI DSS/NIS2 certification audits, red-team engagements, HSM/KMS procurement, WAF/DDoS/network appliances, post-quantum migration timing) that cannot be "implemented" by writing application code in this repository — they require real infrastructure, contracts, and organizational decisions. Sections of this document that are directly actionable as code changes in this VAT-MSA codebase (identity/authorization, application/API/data security, cryptographic usage within the app, tax-transaction integrity, audit/telemetry, Module 8's detection/response, dependency/secret scanning) should be worked the same way every other module in this repo has been: gap-assessed against actual code, then implemented in reviewable, tested phases — not assumed complete because a document describes them.

---

<!-- The full text supplied by the project owner follows verbatim, preserved as the canonical specification. -->

## 1. Role and mandate

Act as a world-class Chief Information Security Architect, Enterprise Security Architect, Global Cybersecurity Architect, Zero-Trust Architect, Government Security Architect, Tax-System Security Architect, Cloud Security Architect, Application Security Architect, API Security Architect, Identity & Access Management Architect, Data Security Architect, Cryptographic Architect, DevSecOps Architect, Security Operations Architect, Threat Detection & Response Architect, Digital Forensics Architect, Privacy Architect, AI Security Architect, Fraud-Risk Architect, Cyber-Resilience Architect and Global Regulatory Compliance Architect.

Experience assumed: national tax administration platforms; government digital infrastructure; banking and financial systems; critical national infrastructure; global SaaS platforms; large-scale multi-tenant platforms; high-volume transaction systems; identity federation platforms; financial/accounting systems; international government integrations.

Mandate: establish the FINAL VAT-MSA GLOBAL ENTERPRISE CYBERSECURITY MASTER ARCHITECTURE AND SECURITY ASSURANCE INFRASTRUCTURE, protecting the entire VAT-MSA ecosystem, not merely Module 8.

## 2. Non-negotiable architectural principle

Security is a cross-cutting enterprise architecture, not a standalone module. It must be an inherent property of every portal, module, service, API, database, workflow, transaction, integration, user, organisation, device, infrastructure component, cloud resource, event, queue, file, deployment, and country instance.

Follow: Zero Trust + Defence in Depth + Secure by Design + Privacy by Design + Least Privilege + Assume Breach + Continuous Verification + Continuous Monitoring + Cyber Resilience + Evidence-Based Assurance.

## 3. Security architecture objective

Prevent, detect (including previously unknown patterns), contain, preserve evidence, recover, and continue operating during partial failures — protecting taxpayer/government/financial data, tax transactions, identities, integrations, infrastructure, and the software supply chain, while maintaining compliance and providing measurable evidence controls actually work. Never claim guaranteed detection of every zero-day; design layered controls maximizing prevention/detection/containment/recovery.

## 4. VAT-MSA security reference architecture

```
                         VAT-MSA GLOBAL PLATFORM
                                  |
              +-------------------+-------------------+
              |                   |                   |
        BUYER PORTAL        SELLER PORTAL        NAMRA PORTAL
              |                   |                   |
              +-------------------+-------------------+
                                  |
                         ZERO-TRUST ARCHITECTURE
                                  |
       +--------------------------+--------------------------+
       |                          |                          |
       v                          v                          v
IDENTITY & ACCESS          APPLICATION/API              DATA SECURITY
       |                     SECURITY                       |
       v                          |                          v
DEVICE SECURITY                 |                    DATABASE SECURITY
       |                          |                          |
       +--------------------------+--------------------------+
                                  |
                                  v
                       INFRASTRUCTURE SECURITY
                                  |
                                  v
                         INTEGRATION SECURITY
                                  |
                                  v
                        EVENT/QUEUE SECURITY
                                  |
                                  v
                       SECURITY TELEMETRY
                                  |
                                  v
                    THREAT DETECTION PLATFORM
                                  |
                                  v
                         MODULE 8 CAPABILITY
                                  |
                +-----------------+-----------------+
                v                 v                 v
             DETECT           CORRELATE          RESPOND
                |                 |                 |
                +-----------------+-----------------+
                                  v
                       INCIDENT RESPONSE
                                  |
                                  v
                         CYBER RESILIENCE
```

## 5. Security control plane

A centralized-but-highly-available VAT-MSA Security Control Plane coordinating identity security, access/security/risk policies, security telemetry, threat detection/intelligence, incident management, security response, compliance monitoring, security configuration and assurance — implemented as separated security services with clear trust boundaries, not a monolith. The control plane itself must not become a single point of compromise.

## 6. Zero-trust architecture

Every access decision evaluates (as appropriate): who, what organisation, what role, what permission, what device, what resource, what action, what country, what environment, what risk, what workflow state, what data classification, what transaction value, what authority. Authentication never equals authorization.

## 7. Identity architecture

**Taxpayers:** buyer, seller, buyer/seller, suppliers, employees, company administrators.
**NamRA:** tax administrators, auditors, managers, investigators, system administrators, security administrators.
**Platform:** super administrators, support personnel, service accounts, machine identities.

Implement: strong authentication, MFA, step-up authentication, identity proofing, account recovery, session management, device trust, risk-based authentication, privileged access management, machine identity, credential/token lifecycle management, session revocation.

## 8. Authoritative taxpayer identity

One verified taxpayer identity → one organisation account → multiple authorised users → dynamic Buyer/Seller capabilities. No duplicate taxpayer identities merely because an organisation can be both buyer and seller. Identity hierarchy: VAT Registration Number, TIN, Company Registration Number. Identity reconciliation must detect duplicate organisations, conflicting identities, incorrect identifiers, suspicious identity changes, unverified identities, registry discrepancies.

## 9. Production identity proofing

Production-grade identity-proofing supporting authoritative verification against applicable government/official registries. For Namibia: Taxpayer → Identity Proofing → Authoritative Registry → ITAS → VAT-MSA → Verified Taxpayer Identity. Define identity matching, duplicate detection, reconciliation, conflicting records, failed verification, manual review, evidence, re-verification, identity lifecycle. A UI registration is never proof of authoritative identity.

## 10. ITAS federation

Treat ITAS as the potential authoritative identity provider for Namibia. Support SSO, federation, assertion/token/signature/issuer/audience verification, expiration, nonce/state, origin isolation, certificate/key validation, logout, session management, account linking. Never blindly trust identity assertions.

## 11. RBAC + ABAC

RBAC based on job title/organisational role/functional role/administrative role. ABAC based on organisation, country, department, resource, data classification, device, risk, transaction value, location, workflow state, time, regulatory authority. All authorization enforced server-side.

## 12. Privileged access management

Just-in-time privilege, MFA, approval, time-limited access, privileged-session monitoring, audit logging, break-glass access, emergency access procedures, separation of duties across: Platform Administration, Tax Administration, Security Administration, Database Administration, Infrastructure Administration, Audit.

## 13. Government trust boundary

A dedicated Tax Authority Trust Zone: VAT-MSA → Government Integration Boundary → ITAS / Tax Authority Systems / Government Registries / Government APIs. Define trust relationships, authentication, authorization, network controls, API controls, data exchange, signing, monitoring, failure handling, reconciliation.

## 14. Multi-tenant security

Tenant isolation is a critical security boundary. Prevent cross-tenant reads/writes/exports/search/cache leakage/API access/analytics leakage/background-job leakage. Continuously test tenant isolation.

## 15. Portal security

Secure Buyer/Seller/NamRA/Company Administrator/Super Administrator portals and ITAS-integrated access with MFA, secure sessions, CSRF/XSS protection, secure headers, input validation, authorization, rate limiting, bot protection, abuse detection, session revocation.

## 16. Application security

Protect against SQL/command injection, SSRF, XSS, CSRF, path traversal, unsafe deserialization, authentication/authorization bypass, race conditions, business-logic abuse, file attacks, privilege escalation. Apply secure coding standards and applicable OWASP guidance.

## 17. API security

Every API: authentication, authorization, schema validation, rate limiting, quotas, replay protection, idempotency, request-size limits, versioning, correlation IDs, logging, abuse detection — across public, internal, ITAS, SaaS, partner, webhook, and machine APIs.

## 18. Bot and API abuse protection

Protect against credential stuffing, automated account creation, scraping, API enumeration, excessive requests, automated invoice submission, automated tax-return abuse. Use adaptive controls, not only fixed thresholds.

## 19. Data security

Classification: PUBLIC / INTERNAL / CONFIDENTIAL / RESTRICTED / HIGHLY RESTRICTED. Protect taxpayer data, TIN, VAT registration data, financial information, tax invoices, VAT returns, accounting data, personal information, authentication information. Implement encryption at rest/in transit, field-level encryption where required, tokenization, masking, pseudonymization, access controls.

## 20. Cryptographic architecture

Encryption, digital signatures, key hierarchy, HSM/KMS, key rotation, certificate lifecycle/revocation, signing/verification keys, secure key storage, cryptographic policy, key-compromise procedures. Never hard-code production cryptographic secrets.

## 21. Cryptographic agility & post-quantum readiness

Algorithm abstraction, key versioning, certificate agility, migration mechanisms, hybrid cryptography readiness where appropriate. Prepare for future post-quantum migration.

## 22. Tax transaction integrity

CREATE → VALIDATE → AUTHORIZE → SIGN → SUBMIT → ACKNOWLEDGE → RECORD → AUDIT. Apply integrity/non-repudiation mechanisms where legally and technically appropriate to tax invoices, VAT returns, amendments, submissions, assessments, refunds, payments, approvals.

## 23. Fraud + cybersecurity correlation

Cyber Risk + Identity Risk + Transaction Risk + Tax Risk = Enterprise Risk Signal. Do not merge cyber detection and tax enforcement into one uncontrolled decision engine; maintain separate governance and evidence trails.

## 24. Insider-threat security

Monitor privileged-user abuse, unauthorized data access, bulk downloads, unusual exports, suspicious permission changes, abnormal administrator activity, excessive database access, potential collusion indicators — using behavioural analytics while respecting privacy and applicable law.

## 25. Runtime application protection

Where appropriate, detect exploitation attempts, suspicious execution, abnormal requests, runtime manipulation, application-layer attacks. Complements, never replaces, secure development and infrastructure controls.

## 26. File & malware security

File validation, MIME/content validation, malware scanning, CDR where appropriate, size restrictions, isolation, secure storage. Never trust user-controlled file names/extensions/MIME types/metadata.

## 27. Offline application security

Protect local databases, cached information, credentials, tokens, encryption keys, offline transactions in the desktop/offline app: device trust, local encryption, secure synchronization, replay protection, tamper detection where appropriate, offline authorization limitations.

## 28. Synchronization security

Offline-to-online sync must resist replay, duplication, manipulation, stale data, conflict attacks, unauthorized devices — via signed transaction envelopes, idempotency, versioning, conflict detection, secure synchronization, audit trails.

## 29. Event and queue security

Protect message brokers, topics, queues, producers, consumers, dead-letter queues against message injection, replay, forgery, tampering, poison messages, unauthorized consumption.

## 30. Security telemetry platform

Centralized, scalable, tamper-resistant, privacy-aware telemetry from identity, portals, APIs, databases, infrastructure, networks, endpoints, ITAS, SaaS, queues, tax engines, accounting, workflows.

## 31. Security data lake / analytics

Historical analysis, behavioural baselines, threat correlation, security investigation, anomaly detection, threat hunting, forensics — hot operational telemetry separated from long-term analytical storage.

## 32. Module 8 — advanced threat detection

Module 8 is a major security capability within the enterprise architecture, consuming signals from across VAT-MSA: behavioural anomaly detection, threat intelligence, deception technology, velocity/volume analysis, correlation, risk scoring, threat hunting, incident management, automated/policy-controlled response.

## 33. Adaptive threat detection

Detect account takeover, credential abuse, privilege escalation, data exfiltration, API abuse, abnormal transaction behaviour, automated attacks, novel behavioural patterns — using multiple independent detection methods, not exclusively hard-coded rules.

## 34. Deception technology

Safe honeytokens/decoy resources only. Never create real-looking secrets/credentials/production cloud keys for deception. Any interaction with a decoy should generate a high-confidence security signal.

## 35. Automated response

For high-confidence malicious activity: session/token revocation, temporary account restriction, network blocking, API throttling, device isolation, incident creation. Must be policy controlled, auditable, reversible where possible, rate limited, resistant to attacker-triggered abuse.

## 36. Security operations centre

SOC capabilities for monitoring, alert management, incident response, threat hunting, threat intelligence, investigation, evidence, reporting. Support SIEM/SOAR integration where appropriate.

## 37. Incident response

DETECT → TRIAGE → INVESTIGATE → CONTAIN → ERADICATE → RECOVER → VERIFY → CLOSE → LESSONS LEARNED. Maintain complete evidence throughout.

## 38. Digital forensics

Preserve event timelines, authentication records, API activity, administrative activity, relevant system state, evidence hashes, chain of custody — protected from unauthorized modification.

## 39. Immutable audit

Tamper-resistant records for: login, MFA changes, permission changes, taxpayer changes, invoice creation/modification, VAT return changes, submission, approval, refund, payment, administrative actions, security incidents.

## 40. Retention, WORM & legal hold

Retention policies, WORM storage where appropriate, legal hold, regulatory retention, security investigation retention, secure deletion — configurable per applicable country law.

## 41. Software supply-chain security

SBOM, dependency inventory/scanning, SCA, signed artifacts, trusted repositories, secret scanning, container scanning, IaC scanning, build integrity.

## 42. DevSecOps

PLAN → CODE → BUILD → TEST → SCAN → SIGN → DEPLOY → MONITOR → RESPOND. No production deployment bypasses mandatory security gates.

## 43. Cloud & infrastructure security

Network segmentation, WAF, DDoS protection, API gateway, private databases, secure workloads, container/Kubernetes security if applicable, workload identity, firewall controls, secure administration. No production database directly exposed to the public Internet.

## 44. Network security

Zero-trust networking, segmentation, east-west/north-south controls, IDS/IPS where appropriate, DDoS protection, secure DNS, secure administration channels.

## 45. Endpoint security

Protect administrator/employee devices, desktop VAT-MSA applications, operational endpoints: device identity, device posture, endpoint security, patch controls, secure authentication, remote revocation.

## 46. Backup security

Protect backups from ransomware, insider attacks, credential compromise, destructive attacks: encryption, immutable backups, isolated copies, access separation, restore testing.

## 47. Disaster recovery & cyber recovery

Define and test RTO, RPO, regional/cloud/identity/database/queue failure, cyberattack, ransomware, credential compromise.

## 48. Security against the security system itself

The security infrastructure must not become a single point of failure. If Module 8, SIEM, or adaptive analytics becomes unavailable, core authentication, authorization, encryption, tenant isolation, and mandatory security controls must continue operating.

## 49. Scale

Support millions of users, high-volume tax invoices, large event volumes, high API traffic, large security telemetry volumes, multiple countries/regions — via horizontal scaling, distributed processing, streaming, queues, partitioning, regional processing, elastic infrastructure.

## 50. Security performance

Measure authentication/authorization latency, API security overhead, security event ingestion, detection/response latency, queue processing, analytics performance. Security must scale with the platform without becoming its bottleneck.

## 51. Threat model

Formal threat modelling covering external attackers, taxpayer compromise, insider threats, privileged insiders, compromised SaaS/integrations, supply-chain compromise, cloud compromise, API attacks, identity attacks, data theft, ransomware, DDoS, fraud, AI attacks — each mapped to PREVENT/DETECT/CONTAIN/RESPOND/RECOVER.

## 52. Security testing

Continuous SAST, DAST, SCA, API security testing, penetration testing, infrastructure/cloud security testing, identity testing, tenant isolation testing, authorization testing, race-condition testing, abuse-case testing, load testing, failover testing, disaster-recovery testing.

## 53. Red team / purple team

Controlled exercises: account takeover, privilege escalation, API compromise, data exfiltration, insider abuse, supply-chain compromise, integration compromise — verifying whether the architecture actually detects and responds.

## 54. Security compliance framework

Map controls against ISO/IEC 27001:2022, ISO/IEC 27017, ISO/IEC 27018, ISO/IEC 27701:2025, NIST Cybersecurity Framework 2.0, applicable OWASP guidance, PCI DSS (only where payment-card data is actually in scope), EU NIS2 (as a jurisdictional regulatory requirement where applicable, not an ISO standard), plus applicable national cybersecurity/privacy/tax/electronic-transaction laws, government security requirements, data-residency requirements.

## 55. Global security + country security

GLOBAL SECURITY BASELINE, with country profiles (Namibia, Country B, Country C, …) that may strengthen but must never silently weaken the mandatory global baseline.

## 56. Security policy-as-code

Version-controlled, tested, approved, machine-enforced, audited, rollback-capable security policies where technically appropriate.

## 57. Vulnerability management

DISCOVER → ASSESS → PRIORITIZE → REMEDIATE → VERIFY → CLOSE, prioritized by severity, exploitability, exposure, asset criticality, taxpayer impact, government impact.

## 58. Security governance

Clear ownership for platform security, tax security, data security, privacy, infrastructure security, identity security, application security, security operations, incident response, compliance.

## 59. Security evidence

Never accept "the feature exists." Require: Implemented + Enforced + Tested + Monitored + Evidenced (test results, audit records, security logs, configuration evidence, penetration-test results, runtime evidence, compliance evidence).

## 60. Security maturity

Assess every domain: LEVEL 0 Absent, 1 Initial, 2 Defined, 3 Managed, 4 Measured, 5 Adaptive — current and target maturity.

## 61. Security control catalogue

Master control matrix: Domain | Control | Asset | Threat | Standard | Enforcement | Monitoring | Evidence | Owner | Status — for every major VAT-MSA component.

## 62. Required architecture deliverables

Enterprise Architecture (Global Cybersecurity Architecture, Security Context Diagram, C4 Security Architecture, Zero-Trust Architecture, Trust-Boundary Architecture, Security Control Plane); Identity (IAM, ITAS Federation, Identity Proofing, MFA, Privileged Access, Machine Identity); Application (Application Security, API Security, Portal Security, Runtime Security, Workflow Security); Data (Classification, Data Security, Encryption, Cryptographic Architecture, Key Management, Database Security, Backup Security); Infrastructure (Cloud, Network, Workload, Endpoint Security, Disaster Recovery); Detection (Security Telemetry, Security Data Platform, SIEM/SOC, Threat Intelligence, Module 8, Behavioural Analytics, Threat Hunting); Response (Incident Response, Automated Response, Digital Forensics, Cyber Recovery); Development (DevSecOps, Supply-Chain Security, SBOM, CI/CD Security); Governance (Compliance Architecture, Privacy Architecture, Country Security Architecture, Security Governance, Security Assurance).

## 63. Security implementation rule

STEP 1 Inspect the existing VAT-MSA → STEP 2 Map existing security controls → STEP 3 Identify missing controls → STEP 4 Identify insecure controls → STEP 5 Identify partially implemented controls → STEP 6 Produce the target architecture → STEP 7 Create the dependency map → STEP 8 Create the implementation roadmap → STEP 9 Implement according to dependencies. Do not immediately implement everything.

## 64. Security implementation gate

DESIGN → IMPLEMENT → UNIT TEST → INTEGRATION TEST → SECURITY TEST → PERFORMANCE TEST → FAILURE TEST → PENETRATION TEST → EVIDENCE → ARCHITECTURE REVIEW → SECURITY ACCEPTANCE → NEXT CAPABILITY. Never silently move to the next critical security capability with unresolved critical/high-risk defects.

## 65. Production security acceptance

Not production-ready merely because login/MFA/a firewall/Module 8/a single pentest exist. Requires evidence across identity, authorization, application, API, data, database, infrastructure, network, endpoint, integration, tax transactions, financial transactions, DevSecOps, threat detection, incident response, backup, disaster recovery, compliance, operational security.

## 66. Final security acceptance principle

PREVENTION, PROTECTION, DETECTION, CONTAINMENT, RESPONSE, RECOVERY, RESILIENCE, CONTINUOUS IMPROVEMENT.

## 67. Final architectural directive

```
                         VAT-MSA
                            |
             +--------------+--------------+
             |                             |
       BUSINESS CAPABILITIES        SECURITY ARCHITECTURE
             |                             |
       +-----+------+                +-----+------+
       |     |      |                |     |      |
     Buyer Seller  NamRA          Identity Data Infrastructure
       |     |      |                |     |      |
       +-----+------+                +-----+------+
             |                             |
             v                             v
        VAT-MSA MODULES             SECURITY CONTROL PLANE
                                           |
                     +----------------------+--------------------+
                     v                      v                    v
              Prevention & IAM      Detection & Analytics     Response
                     |                      |                    |
                     +----------------------+--------------------+
                                           v
                                  MODULE 8 SECURITY
                                  CAPABILITY LAYER
                                           |
                                           v
                                  INCIDENT RESPONSE
                                           |
                                           v
                                  CYBER RESILIENCE
```

**Final directive to the development system:** do not merely document cybersecurity — establish the actual VAT-MSA Enterprise Cybersecurity Architecture and implementation blueprint. Where the existing implementation contradicts this architecture: identify the contradiction, explain the security risk, classify severity, identify dependencies, propose remediation, implement only after the applicable architectural and security gate is satisfied, and produce evidence the remediation works. Do not unnecessarily rebuild a control that already exists — assess, test, harden and integrate it instead. Do not classify a control represented only by UI, configuration, placeholder code, or database structure as implemented until runtime evidence proves it is actually enforced.

The final VAT-MSA must be secure by architecture, secure by implementation, secure at runtime, continuously monitored, independently testable, auditable, resilient, globally deployable, and capable of adapting to evolving threats and country-specific regulatory requirements.

---

## Addendum — consolidated re-statement (as supplied)

The project owner subsequently supplied a consolidated restatement of the same architecture (sections renumbered 1–72, same substance) explicitly noting: *"The earlier Module 8 Phase C Adaptive Threat Detection prompt should sit underneath this architecture as a detailed implementation specification for the Threat Detection & Response capability, rather than being treated as the overall security infrastructure."* Its closing directive is the operative instruction for how to proceed:

> Do not proceed directly to production implementation. First produce the complete security architecture, current-state assessment, threat model, security gap analysis, target-state architecture, control matrix, dependency map, implementation roadmap and security acceptance criteria. Then implement sequentially through controlled architecture and security gates.

Both versions are functionally identical in substance; this file preserves the first (numbered 1–72 in the original, condensed above) as the canonical reference and records this closing directive as the standing instruction for how future security work in this repository should be sequenced: **assess and gap-analyze before implementing**, matching the gap-assessment-first convention already used for every module in `MODULE_DEVELOPMENT_PLAYBOOK.md`.
