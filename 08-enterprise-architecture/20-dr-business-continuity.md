# Disaster Recovery and Business Continuity

**Status:** architecture-board draft. Proposed RTO/RPO values require business-impact, NamRA, legal, ITAS and infrastructure approval.

## Continuity priorities

1. Protect people, credentials, cryptographic material and evidence.
2. Preserve certified invoice, VAT transaction, return and audit integrity.
3. Sustain or safely queue taxpayer fiscal activity.
4. Restore identity, policy and fiscal write paths before convenience functions.
5. Reconcile every recovered state and communicate transparently.

| Tier | Capabilities | Proposed RTO | Proposed RPO | Continuity mode |
|---|---|---:|---:|---|
| 0 | security containment, keys, evidence, incident command | immediate | zero for custody records | isolated control plane |
| 1 | identity, authorization, taxpayer, invoice, VAT ledger, return receipt | 30 min | <= 5 min | secondary region or validated offline/queued path |
| 2 | documents, reconciliation, NAMRA cases, consent/delegation | 2 h | <= 15 min | queued writes/read-only fallback |
| 3 | accounting, inventory, projects, notifications, developer portal | 8 h | <= 4 h | delayed processing |
| 4 | BI, non-critical exports and historical reprocessing | 24 h | <= 24 h | restore after core validation |

## Recovery architecture

Primary production spans multiple failure domains. An isolated secondary region holds continuously replicated critical data, deployable immutable artifacts and independently controlled secrets. Encrypted backups are immutable, versioned, cross-account/cross-region and protected by separation of duties. A clean-room recovery environment supports forensic validation and malware-free restoration. Event logs, object metadata, policy bundles, tax-rule versions, configuration, infrastructure definitions and audit evidence are recoverable together; a database alone is insufficient.

Restore order is: network/security control plane; identity and keys; primary databases; event backbone; fiscal services; gateway/portals; integrations; documents; secondary domains; analytics. Each stage has automated checksums, record counts, ledger/control-total reconciliation and approval evidence.

## Business continuity plan

| Disruption | Business response | Technical response | Exit criteria |
|---|---|---|---|
| ITAS unavailable | use approved standalone or cached-federation continuity rules; publish service notice | open circuit; queue exchange; preserve signed request/response evidence | ITAS stable and queued exchanges reconciled |
| Primary region lost | declare major incident; prioritize fiscal deadlines | fail traffic to secondary after authority approval | Tier 1 SLOs and consistency checks pass |
| Cyber/ransomware | suspend risky writes; invoke crisis/SOC/legal team | isolate, rotate, clean-room restore; preserve evidence | compromise removed; independent assurance approves |
| Database corruption | stop affected aggregate writes | point-in-time restore to isolated environment; replay verified events | control totals and audit chain pass |
| National network outage | enable approved desktop/offline policy | encrypted local queue and signed sync packages | duplicates/conflicts resolved and receipts issued |
| Key compromise | revoke affected trust and notify relying parties | rotate hierarchy, re-sign where legally permitted, invalidate tokens | new trust chain validated and exposure assessed |
| Deadline-scale overload | activate filing-deadline command centre | reserved capacity, fairness queues, degrade non-critical features | backlog cleared within approved window |

Manual workarounds must be legally approved, uniquely numbered, access-controlled and later captured with maker-checker review. They never permit silent alteration of certified fiscal history.

## Disaster recovery plan

Incident Commander declares DR based on verified regional loss, unrecoverable local corruption, extended critical outage or containment need. The DR Lead records decision time, recovery point, recovery environment and authorized operators. Communications Lead coordinates NamRA, taxpayers, ITAS/SaaS providers, regulators and executive stakeholders. Security Lead controls evidence and threat eradication. Data Lead owns reconciliation and business acceptance.

Runbook phases:

1. Detect, triage, contain and preserve volatile evidence.
2. Determine safe recovery point and legal/accounting implications.
3. Establish clean control plane and restore keys from quorum-controlled backup.
4. Restore/activate stores in dependency order; verify checksums and access policy.
5. Deploy signed artifacts and compatible configuration/tax rules.
6. Replay idempotent events and integration queues from recorded checkpoints.
7. Run fiscal control totals, sample invoices/returns, tenant isolation and audit-chain tests.
8. Obtain business, security and data-owner authorization before reopening writes.
9. Communicate restoration, reconcile offline/queued work and monitor intensively.
10. Conduct post-incident review, record gaps and refresh plan.

Failback is a separate approved change, not an automatic reversal.

## DR test plan

| Frequency | Exercise | Minimum evidence |
|---|---|---|
| monthly | backup restore sample and key-recovery simulation | checksums, restore duration, access log |
| quarterly | service failover, event replay and integration outage | RTO/RPO, duplicates, queue recovery, SLOs |
| semi-annual | regional Tier 1 recovery and offline reconciliation | full timing, control totals, business sign-off |
| annual | unannounced cyber/region scenario and executive tabletop | crisis decisions, communications, legal and SOC evidence |
| after material change | targeted recovery regression | changed dependency restored and verified |

Tests use anonymized/synthetic data unless formally authorized. A pass requires achieved RTO/RPO, no unauthorized access, matched ledger/control totals, verified audit continuity, stakeholder sign-off and tracked remediation. Critical failures block production expansion.

