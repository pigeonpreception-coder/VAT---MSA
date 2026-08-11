# VAT-MSA SOC, incident, forensics and fraud-security operations architecture

## 1. Operating objective

VAT-MSA requires continuously available detection and response appropriate to the risk and deployment stage. A 24x7 SOC is mandatory before any deployment whose service tier, regulator, contractual obligation or risk assessment requires it. Until staffing and platform are approved and exercised, production national-scale operation remains `NOT READY`.

The operating model aligns incident risk management with NIST CSF 2.0 and NIST SP 800-61 Rev. 3, and uses the applicable ISO/IEC 27035 and digital-evidence guidance. Alignment is not certification.

## 2. Telemetry architecture

See `diagrams/security-event-evidence-flow.mmd`.

| Source | Minimum security signals | Data handling |
|---|---|---|
| identity/PAM | login, failure, MFA/recovery, token, federation, privilege request/session/revocation | no secrets; protected device/risk attributes minimized |
| edge/WAF/API | attack, bot, rate, schema, route/client/tenant budget and response class | truncate/redact payload; hash or reference evidence when required |
| application/domain | authorization, SoD, state invariant, duplicate/replay, fiscal integrity and admin change | business identifiers minimized; policy/rule versions retained |
| data/storage | privileged query, role/config, bulk read/export, integrity, backup/restore and retention action | HIGHLY RESTRICTED for sensitive query/evidence detail |
| integration | authentication, certificate, schema, replay, delivery/receipt, reconciliation and provider outage | credentials excluded; payload evidence by protected reference/hash |
| cloud/network/endpoint | control-plane, network, workload/runtime, posture, malware and EDR/XDR | access restricted to security purpose |
| CI/CD/supply chain | commit/review, scan, SBOM, provenance, signing, admission and deployment | immutable release linkage |
| privacy/DLP | data flow, rights request, export, transfer, retention/disposal and suspected breach | privacy case separation and need-to-know access |

Collectors use mutually authenticated transport, durable bounded buffering, backpressure, health metrics and schema validation. Source clocks synchronize to approved time authority. Ingestion gaps and clock drift are themselves detections.

## 3. Event and evidence integrity

Security/audit events include event ID, event type/version, occurrence and observation time, source, environment, actor/workload and acting authority, tenant/resource reference, action/outcome, authentication and policy context, correlation/trace/transaction IDs, severity/confidence, classification and integrity metadata.

Events are append-only at the application interface and copied to separately administered immutable/tamper-evident storage. Sequence/hash mechanisms are used where their risk reduction is justified. Retention lock and legal hold are controlled by approved records schedules; administrators cannot silently shorten them.

## 4. Detection engineering

Detection-as-code has an owner, hypothesis, mapped threat/control, source dependencies, severity, confidence, suppression logic, test fixtures, runbook, change review, version and performance metrics. Detections cover:

- credential attack, account takeover, federation/token abuse and recovery anomalies;
- new device/impossible travel/risky session plus privilege or high-value action;
- protected-role changes, privilege escalation, SoD conflict and PAM deviation;
- tenant escape, IDOR, bulk enumeration, export and exfiltration indicators;
- injection, SSRF, unsafe upload, malware, web/API abuse and resource exhaustion;
- invoice duplication/replay/tamper, abnormal VAT activity, rule drift and reconciliation failure;
- integration/certificate compromise, schema/replay anomaly and provider drift;
- cloud/workload lateral movement, destructive admin/ransomware and backup attacks;
- secret exposure, malicious dependency/build/provenance/admission failure;
- audit gap/tamper, country/security-profile downgrade or unauthorized activation.

Detection quality is measured by coverage, source health, test pass rate, precision, recall where known, false-positive burden, mean time to acknowledge/triage/contain, and lessons incorporated.

## 5. Cyber and fraud risk convergence

Cyber and tax/financial fraud remain distinct case types with controlled sharing.

| Signal family | Cyber interpretation | Fraud/tax interpretation | Joint action |
|---|---|---|---|
| account/device anomaly | possible compromise | identity misuse enabling fraudulent filing | challenge/revoke and quarantine affected action for human review |
| invoice duplication/network | API/client abuse or compromised integration | false input claim/circular trading indicator | preserve transaction, reconcile counterparties, open linked cases |
| privilege/approval anomaly | insider or role compromise | collusive refund/return manipulation | suspend JIT privilege and independently review decisions |
| bulk export | exfiltration | concealment/intelligence abuse | stop export, preserve evidence, privacy/security assessment |
| rule/config drift | control-plane compromise | incorrect tax result at scale | halt activation, compare signed version, recompute impact |

Risk models provide evidence-linked signals and explainable reason codes. They do not autonomously establish fraud, tax liability, guilt, penalties, refunds or access to statutory rights. Model access, changes, bias/performance and appeals are governed separately; AI/ML remains disabled unless its specific architecture is approved.

## 6. Correlation model

Correlation joins non-secret identity, device/session, tenant, API client, workload, resource, transaction, invoice/return, IP/network, rule/profile, deployment and time-window keys. For example:

`unusual login + new device + impossible travel + JIT privilege + country-policy change + abnormal API volume -> critical correlated detection`

Correlation stores source event references and logic version, not merely a score. Analysts can reproduce the decision from preserved evidence.

## 7. Severity and response authority

| Severity | Example | Initial authority |
|---|---|---|
| SEV-1 Critical | confirmed cross-tenant disclosure, active broad compromise, tax-integrity loss, ransomware, major exfiltration or Tier-0 national outage | immediate incident command and executive/CISO notification |
| SEV-2 High | confirmed account/integration compromise, privilege escalation, material fraud/control failure | SOC lead with service/security owner escalation |
| SEV-3 Medium | credible suspicious behavior or localized control failure | analyst investigation and control owner |
| SEV-4 Low/Info | weak signal, expected policy denial or tuning item | queue, correlate and tune |

Severity does not determine legal notification by itself. Privacy, sector, contractual, country and regulator assessment is recorded separately with legal/privacy authority.

## 8. Automated response guardrails

Allowed only under an approved, tested, versioned playbook and above defined confidence:

- throttle one client, route or abusive source for a short bounded period;
- require step-up or revoke a specific session/token;
- quarantine one file, batch, device, integration client or export;
- isolate one compromised workload through an already approved control;
- pause activation of an unverified policy/artifact.

Human approval is required for tenant-wide suspension, employee discipline, mass network block, production regional failover, evidence/data deletion, broad endpoint isolation, public notification, statutory action and payment/refund action. All automation records trigger, policy version, evidence, blast radius, duration, rollback, result and reviewer.

## 9. Incident lifecycle

See `diagrams/incident-response-lifecycle.mmd`.

1. **Prepare:** inventory, contacts, authority, communications, playbooks, clean tools and exercises.
2. **Detect and validate:** assess source health, confidence, scope and false-positive possibility.
3. **Classify:** severity, affected countries/tenants/data/services and likely legal/contractual obligations.
4. **Contain:** choose reversible, least-blast action; preserve volatile and durable evidence.
5. **Investigate:** construct timeline, root cause, actor/resource impact and control failure.
6. **Eradicate:** revoke trust, remove persistence, patch/rebuild from signed sources and validate dependencies.
7. **Recover:** restore in clean environment, reconcile fiscal/business state and monitor intensively.
8. **Notify:** execute approved internal, customer, regulator, law-enforcement and public communications.
9. **Review and improve:** lessons, actions, detection/control/test updates and tracked closure.

Incident command roles are Incident Commander, Security Lead, Technical/SRE Lead, Data/Fiscal Integrity Lead, Privacy/Legal Lead, Country Regulatory Liaison, Communications Lead and Scribe/Evidence Custodian. Deputies and escalation paths are pre-assigned.

## 10. Digital forensics and chain of custody

Collection is authorized, minimal, repeatable and read-only where possible. Each item records unique evidence ID, collector, source, time/timezone, method/tool/version, hash, transfer, storage, access and disposition. Original evidence is preserved; analysis uses verified copies. Legal hold prevents alteration/disposal and is released only by authorized records/legal action.

Forensic readiness covers identity, application, database, object/document, event, cloud/network, endpoint, build/release, profile/rule and backup sources. Privacy, privilege, employment and cross-border limits are assessed before collection/export. Investigators do not receive unrestricted business data by default.

## 11. Incident communications and notification

Contact data, regulator/authority destinations, trigger criteria, deadlines and content vary by jurisdiction and contract. The country security profile holds only approved rules and contacts. Unknown notification law or contact blocks automatic submission; the system opens an urgent legal/privacy decision. No external notification is sent automatically.

Communications distinguish confirmed facts, hypotheses, affected scope, containment, customer actions and next update. Evidence and legal privilege are protected; support teams receive approved scripts without sensitive detection logic.

## 12. Vulnerability and exposure operations

All assets, public endpoints, dependencies, images, artifacts and suppliers have owner and inventory linkage. Findings are normalized by exploitability, exposure, asset/fiscal/data criticality, active exploitation, compensating controls and fix availability. Patch/remediation SLOs are defined in `security-slo-catalog.csv`.

Critical exploitable findings block release and trigger emergency remediation unless an authorized executive/CISO acceptance is time-limited, independently reviewed and legally permissible. No acceptance can waive tenant isolation, authorization, evidence integrity or secret compromise response.

External vulnerability disclosure and researcher intake use a published safe channel, acknowledgement, triage, coordinated remediation/disclosure and anti-retaliation terms approved by Legal. Bug-bounty activity requires explicit scope and authorization.

## 13. Third-party security operations

Suppliers/integrations are tiered by data, privilege, availability and systemic risk. Onboarding requires due diligence, data/security schedule, breach notification, subprocessor visibility, evidence/audit rights, vulnerability/patch commitments, BCP/DR, access controls, data return/deletion and exit plan. Continuous monitoring covers certificate/credential health, API behavior, availability, material vendor changes and external intelligence.

Provider compromise can be isolated with per-integration credentials, scopes, quotas and kill switch. Supplier assurances supplement but do not replace VAT-MSA tests and monitoring.

## 14. SOC acceptance and exercises

Before production: telemetry coverage and redaction review; detection unit tests; alert-to-case linkage; severity/on-call drill; account takeover, tenant escape, insider export, malicious build, integration compromise, ransomware and audit-tamper exercises; automated-response rollback; chain-of-custody test; notification tabletop; clean-room recovery; and independent SOC readiness assessment. Critical findings block the affected deployment.
