# VAT-MSA global security, privacy and compliance architecture

## 1. Status and implementation boundary

**Architecture status:** `PROPOSED - REQUIRES FORMAL APPROVAL`
**Production security implementation:** `NOT AUTHORIZED BY THIS DOCUMENT`
**Baseline review date:** 2026-08-11

This package translates security and compliance sources into a single control chain:

`source obligation -> applicability decision -> control objective -> architecture mechanism -> implementation requirement -> telemetry -> test -> evidence -> owner -> review`

It extends the global-core/country-pack architecture in `31-globalisation-country-compliance-architecture.md`. It does not claim ISO certification, PCI DSS compliance, SOC 2 assurance, government accreditation or legal compliance. Such claims require the applicable independent assessment, legal determination and formal authorization.

Real payments, production card processing, live ITAS/NamRA integration, unapproved statutory rules, destructive automated response and production country activation remain disabled. Existing rules prohibiting self-approval and emergency SoD overrides remain mandatory. Privileged changes require step-up authentication and independent approval; access reviews are at least quarterly; licence expiry is non-destructive.

## 2. Governing principles

1. **Security by design:** security requirements and abuse cases are defined before a capability is accepted for build.
2. **Privacy by design and default:** purposes, lawful bases, minimization, retention, disclosure and subject-right handling are explicit per jurisdiction.
3. **Zero trust:** no identity, device, workload, network, integration or administrator receives implicit trust from location or ownership.
4. **Defence in depth:** identity, application, data, network, infrastructure, monitoring, response and recovery controls remain independently useful when another layer fails.
5. **Secure by default:** missing, stale, conflicting or unverifiable security/compliance policy fails closed for protected actions.
6. **Non-bypassable boundaries:** tenant isolation, authorization, cryptographic trust, audit integrity, secure session handling, secret protection, input validation, privileged-access safeguards, licence enforcement and fiscal-data integrity cannot be weakened by tenant or country configuration.
7. **Higher policy wins:** lower-level policy may tighten a mandatory parent control but cannot weaken it.
8. **Evidence over assertion:** a control is not effective until its design, operation and test evidence is attributable, immutable enough for its risk, and independently reviewable.
9. **Human-governed impact:** automated response is limited to approved, reversible, bounded actions. Tax determinations, broad suspensions, irreversible deletion, statutory actions and regional failover require named human authority.
10. **One global core:** regional and country requirements are versioned security/compliance profiles, not code forks.

## 3. Standards and framework baseline

The authoritative applicability record is `standards-applicability-crosswalk.csv`; detailed controls are in `security-control-matrix.csv`. The baseline uses:

- ISO/IEC 27001:2022 with Amendment 1:2024 and ISO/IEC 27002:2022 for the ISMS and control system.
- ISO/IEC 27017:2015 for cloud controls while Edition 2 remains under publication and until a controlled gap review accepts it.
- ISO/IEC 27018:2025 for public-cloud PII processor guidance and ISO/IEC 27701:2025 for the PIMS.
- ISO 22301:2019 with Amendment 1:2024 for business continuity, pending review of its in-development revision.
- ISO/IEC 20000-1:2018 with Amendment 1:2024 where service-management assurance is in scope.
- ISO/IEC 27005:2022, ISO/IEC 27035-1:2023, the applicable ISO/IEC 27033, 27034, 27036 and 27037/41/42/43 families, and ISO/IEC 27040:2024.
- NIST CSF 2.0; NIST SP 800-53 Rev. 5 release 5.2.0; SP 800-57 current final parts; SP 800-61 Rev. 3; SP 800-63-4; SP 800-161 Rev. 1 update 1; SP 800-171 Rev. 3 where CUI is contractually in scope; SP 800-207/207A; and SP 800-218 SSDF 1.1.
- OWASP ASVS 5.0.0 as the verifiable application-security baseline, OWASP Top 10:2025 for awareness, and OWASP API Security Top 10:2023 for API abuse coverage.
- PCI DSS 4.0.1 only if the actual cardholder-data environment or a security-impacting connected system is in scope.
- GDPR, NIS2 and other laws only after the relevant entity, service, data, sector, territorial and national-law applicability assessment.
- ISO/IEC 42001:2023 and an approved AI security profile only if AI/ML is introduced.

Standards content used for detailed assessment must come from licensed or otherwise authorized copies. Public summaries are contextual sources, not substitutes for normative text.

## 4. Target security context

The target context is shown in `diagrams/global-security-context.mmd`.

| Actor or system | Trust posture | Required boundary controls |
|---|---|---|
| taxpayer and organisation users | untrusted until each request is authenticated and authorized | phishing-resistant MFA for privileged/high-risk actions, tenant/resource policy, session and device risk |
| NamRA and regulatory users | privileged, purpose-bound and case/region scoped | stronger assurance, managed device, PAM/JIT where privileged, restricted-data monitoring |
| platform/security operators | no inherited taxpayer or regulatory authority | separate admin identity, step-up, JIT, approval, recorded session, immutable audit |
| SaaS/POS/accounting clients | untrusted machine principals | registered workload/client identity, narrow scopes, quotas, replay protection, conformance |
| ITAS/NamRA systems | high-impact external authority, never implicitly trusted | verified contract, mTLS/private-key client where agreed, schema/signature/replay validation, reconciliation |
| cloud/service suppliers | shared-responsibility third parties | due diligence, contractual controls, isolated identities, telemetry, exit and incident obligations |
| country security/compliance profiles | untrusted until verified | canonicalization, hash/signature, compatibility, effective period, approval quorum, rollback protection |

## 5. Logical security architecture

### 5.1 Control planes

| Plane | Responsibilities | Isolation rule |
|---|---|---|
| identity and trust | proofing, federation, authenticators, workload identity, certificates and session risk | separate keys, admin roles and issuer trust per environment |
| policy decision | global/regional/country/organisation policy merge, RBAC/ABAC, SoD and obligations | policy decisions are centrally observable; applications cannot silently allow on decision failure |
| policy enforcement | edge, BFF/API, service, queue, data, object, search, analytics and export enforcement | every protected path has an enforcement point and negative test |
| key and secret | KMS/HSM, vault, rotation, revocation, recovery and usage evidence | key custodians cannot approve the transactions protected by those keys |
| audit and evidence | append-only application audit, security telemetry, evidence manifests and legal hold | separate administration; business/system admins cannot alter evidence |
| security operations | detection, correlation, incident, threat intelligence, case management and governed response | SOC access is purpose-limited and does not grant business authority |
| recovery | immutable backup, isolated recovery control plane, clean room and reconciliation | independent credentials and dual control; production compromise cannot erase all copies |

### 5.2 Policy hierarchy

The merge order is:

`non-bypassable platform invariants -> global security baseline -> regional profile -> country profile -> organisation policy -> user/session risk obligations`

For each control key, the engine records source profile, version, effective period, decision, obligations and evidence correlation. A child profile may set a stronger value (for example, shorter sessions or stronger MFA) but cannot reduce the parent minimum. Conflicts, expired signatures or unknown mandatory fields deny the protected action and open a governed exception.

Draft machine-readable profiles live under `security-profiles/`. They are `executable: false` until schema validation, independent security/privacy/legal review, signing and environment-specific activation are approved.

## 6. Defence-in-depth zones

| Zone | Allowed exposure | Mandatory controls | Failure containment |
|---|---|---|---|
| global edge | public internet to protected edge only | authoritative DNS, DDoS, CDN/WAF, bot controls, TLS, request budgets, origin allowlist | shed abusive/non-critical traffic while preserving health and critical receipt paths |
| identity | only defined authentication/federation paths | MFA, anti-replay, risk engine, signed short-lived tokens, revocation, isolated admin realm | deny new sessions; preserve bounded verified continuity only where approved |
| API and application | gateway/service mesh only | schema and business validation, resource authorization, quotas, idempotency, safe errors, workload mTLS | circuit breaking, bulkheads, restricted egress and per-domain compromise containment |
| integration | allowlisted partners and internal domain services | separate machine identities, validation, malware quarantine, egress proxy, replay defense | quarantine one connector without granting direct data-plane access |
| operational data | named workloads; never public | encryption, least-privilege roles, tenant predicates/RLS defense, integrity, activity monitoring | fence writers, point-in-time restore and transaction reconciliation |
| evidence and security | telemetry ingress; restricted investigator access | WORM/retention lock where justified, hashes, synchronized time, chain of custody | independent replicas and immutable legal holds |
| analytics/search | governed event/CDC inputs and approved queries | minimization, masking, index authorization, DLP, export workflow | revoke views/indexes without affecting source-of-record integrity |
| management and delivery | separate administrative path | PAM/JIT, signed artifacts, protected branch, isolated build, admission policy | revoke signing/deployment trust and rebuild from clean provenance |
| recovery | isolated backup/clean-room paths | immutable copies, independent keys, dual control, restore testing | recover without relying on potentially compromised production control plane |

## 7. Control model

Controls are classified as preventive, detective, responsive, corrective, recovery or governance; most critical risks require at least three distinct types. Each control record contains:

- stable control ID and version;
- source mappings and applicability rationale;
- global/region/country scope;
- component and data classification;
- enforceable requirement and configuration floor;
- accountable owner and independent assurance owner;
- implementation state distinct from design state;
- telemetry, evidence object and retention class;
- automated/manual test and test frequency;
- exception path, expiry and compensating controls.

`security-control-matrix.csv` is the master logical control set. It is not a Statement of Applicability, certification audit workbook or proof that a control operates in production.

## 8. Identity, authorization and privileged access

The detailed architecture is `34-zero-trust-iam-pam-architecture.md`. All access decisions evaluate authenticated subject/workload, organisation, jurisdiction, resource tenant, business capability, role, permission, department/branch/region/case, classification, purpose, workflow state, transaction value, licence, device posture, network/risk signals, authentication strength, time and privilege level.

Privileged changes require step-up authentication, bounded privilege, ticket/reason, independent approval, tamper-evident activity evidence and expiry. No actor may approve its own access, policy, country pack, security exception, audit deletion, key operation or protected fiscal action. There is no emergency SoD override. Break-glass is limited to pre-defined technical containment/recovery actions and cannot grant fiscal approval or hide evidence.

## 9. Data, application, API, network and cloud security

The detailed architecture is `35-data-application-api-network-cloud-security.md`. Its mandatory outcomes include classification at creation, purpose-aware access, encryption in transit and at rest, justified field protection, centralized secret/key management, private data services, secure sessions, server-side authorization, input/schema/resource budgets, upload quarantine, egress control, dependency and artifact integrity, environment isolation, tenant isolation at every persistence/processing layer and independent recovery.

## 10. Audit, monitoring, fraud and response

The detailed operating model is `36-soc-incident-forensics-fraud-security-operations.md`. Security events are correlated without conflating cyber compromise with tax/financial fraud. The system records detection and decision lineage; explainable risk signals may open a case but cannot autonomously determine legal liability, deny a statutory right or complete a payment/refund.

Audit events use synchronized time, actor and acting authority, tenant/resource, action, outcome, before/after references where lawful, policy/rule version, authentication context, correlation IDs and evidence integrity metadata. Secrets, credentials and unnecessary PII never enter logs.

## 11. Privacy and regional compliance

The detailed PIMS and applicability design is `37-privacy-regional-compliance-architecture.md`. Global privacy capabilities support data inventory, purpose/lawful-basis records, processing records, notices, consent where appropriate, rights/case workflows, DPIA, retention/legal hold, secure disposal, transfer/residency decisions, processor governance and breach assessment.

Regional/country profiles are never automatically active. Each deployment receives a signed applicability decision identifying the legal entity, role (controller/processor or local equivalent), service, data subjects, data categories, sector, territory, hosting/transfer, regulators and authoritative legal opinion. Namibia's data-protection legislation status and final obligations require fresh legal verification before every production gate.

## 12. Resilience, secure delivery and testing

`38-secure-delivery-resilience-testing-roadmap.md` defines secure SDLC, supply-chain, vulnerability, penetration, security test, SLO, continuity and phased implementation architecture. It binds to `20-dr-business-continuity.md` and `25-development-environment-architecture.md`.

No production release bypasses critical gates. Exceptions are documented, risk-owned, compensating, time-limited and independently approved; they cannot waive tenant isolation, authorization, evidence integrity, secret protection, SoD, country-readiness or statutory-data integrity.

## 13. Security dashboards and posture score

Dashboards are projections of authoritative evidence, not editable control sources.

| Audience | Scope | Prohibited inference |
|---|---|---|
| Super Administrator | platform health, vulnerability, control and recovery posture | no automatic invoice/taxpayer content |
| NamRA Security/Administration | national tax-service security and approved case indicators | no unrestricted cross-case business access |
| Organisation Administrator | organisation users, MFA, roles, integrations, reviews and alerts | no platform, NamRA-risk or other-tenant detail |
| SOC | detections, incidents, assets, telemetry and response status | no fiscal approval authority |
| Auditor | read-only control/evidence/exception history | no control mutation or operational credentials |

The posture score contains separate domain scores for identity, authorization, data, application, infrastructure, API, compliance, vulnerability, incident, backup/DR and supplier risk. Every score exposes denominator, evidence freshness, failed mandatory controls and uncertainty. A composite score never masks a failed critical control and is never marketed as certification.

## 14. Governance and operating roles

| Role | Accountable decisions | Separation |
|---|---|---|
| Board/Executive Risk Committee | risk appetite, funding, material acceptance and crisis authority | does not operate controls |
| CISO/Security Authority | security policy, control baseline, risk treatment and assurance | cannot certify its own operational evidence alone |
| Privacy/Data Protection Authority | PIMS, DPIA, privacy incident and rights governance | independent escalation to legal/executive authority |
| Architecture Board | trust boundaries, ADRs, control patterns and material changes | cannot waive regulatory approval |
| Country Regulatory/Legal Authority | country law applicability and statutory interpretation | cannot author and approve the same profile version |
| Control Owner | design and operation of assigned controls | produces evidence but does not independently attest it |
| Assurance/Internal Audit | independent design/operating-effectiveness review | read-only; no control operation |
| SOC/CSIRT | detection, triage, containment and incident coordination | automated response limited by approved playbooks |
| SRE/DR | availability, backup, recovery and exercises | recovery actions require approved command authority |
| Product/Engineering | secure design, code and remediation | cannot promote own high-risk change without review |

## 15. Compliance evidence model

Evidence objects are immutable or tamper-evident according to risk and include: evidence ID, control ID/version, scope, environment, producer identity, source system, collection method, observation interval, generated/collected time, content hash, artifact signature/provenance, classification, retention/legal hold, reviewer, assessment outcome and supersession link.

Evidence quality is scored on authenticity, integrity, completeness, scope, freshness, reproducibility and independence. Screenshots alone do not prove continuous control operation. The minimum evidence set is in `compliance-evidence-catalog.csv`.

## 16. Approval and change rules

Security architecture approval requires ADR-025 through ADR-029, the formal gate entries, named control owners and an approved standards register. Production implementation then proceeds only in bounded increments whose applicable controls have acceptance criteria and tests.

The architecture must be reassessed after material change to identity, tenant boundaries, country/profile logic, tax/fiscal integrity, payment scope, cryptography, cloud/provider, public API, evidence, AI use, incident automation, RTO/RPO or deployment region.

## 17. Authoritative public reference register

- [ISO/IEC 27000 family overview](https://www.iso.org/standard/iso-iec-27000-family)
- [ISO/IEC 27017:2015 status and revision](https://www.iso.org/standard/43757.html)
- [ISO/IEC 27018:2025](https://www.iso.org/standard/27018)
- [ISO/IEC 27701:2025](https://www.iso.org/standard/27701)
- [ISO 22301:2019](https://www.iso.org/standard/75106.html)
- [ISO/IEC 27040:2024](https://www.iso.org/standard/80194.html)
- [ISO/IEC 42001:2023](https://www.iso.org/standard/42001)
- [NIST Cybersecurity Framework 2.0](https://www.nist.gov/cyberframework)
- [NIST SP 800-53 Rev. 5](https://csrc.nist.gov/pubs/sp/800/53/r5/upd1/final)
- [NIST SP 800-61 Rev. 3](https://csrc.nist.gov/pubs/sp/800/61/r3/final)
- [NIST SP 800-63-4](https://csrc.nist.gov/pubs/sp/800/63/4/final)
- [NIST SP 800-207](https://csrc.nist.gov/pubs/sp/800/207/final)
- [NIST SP 800-218](https://csrc.nist.gov/pubs/sp/800/218/final)
- [OWASP ASVS](https://owasp.org/www-project-application-security-verification-standard/)
- [OWASP Top 10:2025](https://owasp.org/Top10/)
- [OWASP API Security Top 10:2023](https://owasp.org/API-Security/editions/2023/en/0x03-introduction/)
- [PCI SSC document library](https://www.pcisecuritystandards.org/document_library/)
- [EU GDPR official text](https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:32016R0679)
- [EU NIS2 official text](https://eur-lex.europa.eu/eli/dir/2022/2555/en)
- [Namibia Parliament bill tracker](https://laws.parliament.na/)
