# Phase 0 production-readiness evidence backlog

Status: **OPEN — production release prohibited until every required approval is signed**

Created: 23 August 2026

Scope: VAT-MSA production acceptance dependencies that cannot be truthfully completed in the local implementation environment.

## Acceptance register

| ID | Evidence package | Accountable owner | Required approver | Minimum acceptance evidence | Status |
| --- | --- | --- | --- | --- | --- |
| PR-001 | Namibia statutory rule package | Tax Policy / Finance | Designated Tax and Finance authorities | Legal authority references; effective dates; categories; rounding; correction rules; signed golden vectors; withdrawal procedure | OPEN |
| PR-002 | Production certificate signing | Security / Cryptography | CISO and legal/statutory authority | HSM/KMS design; key ceremony; dual control; non-exportability; rotation; revocation; public verification; compromise exercise | OPEN |
| PR-003 | ITAS integration acceptance | Tax Authority Integration | NamRA/ITAS authority | Signed contract; federation and API conformance; mTLS/key controls; acknowledgements; reconciliation; error taxonomy; security and DR acceptance | OPEN |
| PR-004 | Identity and origin assurance | IAM and Cloud Platform | CISO | Direct-origin denial; dispatch authentication; header strip/re-injection proof; MFA recovery; revocation; clock controls; spoofing/replay penetration results | OPEN |
| PR-005 | Tenant isolation | Product Security | Independent security assessor | BOLA/IDOR tests across APIs, pages, jobs, exports, search and documents; direct-data-control assessment; closed findings | OPEN |
| PR-006 | Observability and incident response | SRE / SOC | Operations executive and CISO | Metrics, logs and traces; SLO dashboards; alert routing; on-call evidence; SIEM ingestion; incident exercise and retained evidence | OPEN |
| PR-007 | Backup, restore and disaster recovery | Data Platform / SRE | Business continuity owner | Approved RPO/RTO; encrypted backup; isolated restore; integrity reconciliation; regional failure exercise; signed results | OPEN |
| PR-008 | Production infrastructure and supply chain | Cloud Platform / DevSecOps | Platform owner and CISO | Reproducible environment; WAF and origin rules; signed image/provenance; protected promotion; SAST/SCA/DAST/IaC/container evidence; rollback test | OPEN |
| PR-009 | Production document protection | Security / Records | Privacy, security and records owners | Malware/CDR provider; quarantine-to-clean/reject evidence; retention; legal hold; deletion governance; provider failure tests | OPEN |
| PR-010 | Residual dependency risk | DevSecOps | Product Security | Removal or formal time-bounded acceptance of the development-only esbuild advisory; confirmation that development service is not exposed | OPEN |

## Signature record

Signatures must identify the evidence version or immutable digest. Empty fields mean **not approved**.

| Acceptance ID | Evidence version/digest | Owner name and signature | Approver name and signature | Signed date | Expiry/review date |
| --- | --- | --- | --- | --- | --- |
| PR-001 |  |  |  |  |  |
| PR-002 |  |  |  |  |  |
| PR-003 |  |  |  |  |  |
| PR-004 |  |  |  |  |  |
| PR-005 |  |  |  |  |  |
| PR-006 |  |  |  |  |  |
| PR-007 |  |  |  |  |  |
| PR-008 |  |  |  |  |  |
| PR-009 |  |  |  |  |  |
| PR-010 |  |  |  |  |  |

## Release rule

No local application result, automated test, architecture document or user-interface demonstration substitutes for the signatures above. Production promotion requires all applicable records to be complete, current, independently verifiable and linked to the exact release artefact.
