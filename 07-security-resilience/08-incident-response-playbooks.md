# Incident response and automated playbooks

See `diagrams/incident-lifecycle.mmd`. Every significant event produces an incident record with detection evidence, correlation IDs, classification, impact, timeline, decisions, actions, approvers and recovery verification.

## Lifecycle and authority

SOC triage classifies severity and starts a commander-led record. Containment preserves evidence and taxpayer service. Eradication replaces compromised components and rotates affected trust. Recovery is staged, monitored and reconciled. Closure requires lessons, control updates, affected-party/legal decisions and remediation owners.

Low impact and reversible actions may be automatic at high confidence. Permanent account action, tenant quarantine, broad network block, destructive endpoint isolation or regional failover uses human approval unless the emergency authority matrix explicitly permits it. All automation has an expiry, rollback and anti-lockout safeguards.

## Playbooks

### Credential stuffing / account takeover

Detect failed-login velocity plus bot/device/risk change → throttle/challenge → short-revoke suspicious sessions → notify SOC and owner → suspend only at policy confidence/approval → investigate privileged/data actions → re-proof and rotate → monitor recurrence. Test with authorized synthetic identities; measure false positives and time to containment.

### API abuse or compromised integration

Detect quota, payload, replay or behavioural anomaly → reduce that client/tenant quota → reject replays → alert integration owner/SOC → revoke machine credential and quarantine only when confirmed → issue new credential after root cause → replay verified queued work. Never globally block legitimate automation solely because it is automated.

### Data exfiltration / insider misuse

Detect bulk read/export, unusual hours, purpose mismatch or database anomaly → pause export and time-bound JIT role → preserve query/export evidence → dual-approve broader containment → determine affected data and legal obligations → rotate access and close policy gap → validate no continuing extraction.

### Workload or supply-chain compromise

Stop promotion → isolate service/namespace and egress → preserve logs/image/digest → revoke workload/signing secrets → identify last trusted artifact → rebuild from clean source with new provenance → canary restored service → hunt laterally and reconcile data. Do not repair compromised nodes in place.

### Ransomware / destructive administrator

Separate affected management plane → revoke privileged identities → fence writers → preserve immutable evidence/backups → establish clean-room control plane under independent credentials → restore verified data and signed artifacts → reconcile and stage traffic → retain affected environment for investigation.

### Region outage

Declare DR → freeze changes → fence primary → validate secondary point and security controls → promote and route → prove Tier-0 synthetic transactions → scale priorities → report RPO gap → perform controlled failback only after reconciliation.

## Exercises and learning

Quarterly table-tops and semiannual technical exercises cover the playbooks. Metrics include mean time to detect/acknowledge/contain/recover, taxpayer impact, data/integrity loss, automation success, false-positive rate and overdue actions. Lessons update detection, code, infrastructure, training and risk register.
