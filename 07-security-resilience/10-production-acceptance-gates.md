# Production acceptance gates

The current application is a hardened operational pilot, not a certified national production deployment. Promotion requires objective evidence and accountable approval.

- Applicable Namibian tax, privacy, cybersecurity, evidence, records and data-residency requirements are confirmed by qualified owners; DPIA and threat model approved.
- Independent web/API/cloud/infrastructure/authentication/authorization/database penetration testing has no unresolved critical finding; high findings are remediated or time-bound accepted.
- Load, spike, stress, soak, security-control and zone-loss tests meet signed capacity targets with headroom and correct ledger results.
- Tier-0 backup restore and regional failover/failback meet RTO/RPO with transaction/outbox/ledger reconciliation.
- Managed edge DDoS/WAF/bot, production IAM/MFA/PAM, machine identity/mTLS, KMS/HSM, secret manager, immutable audit and SIEM are deployed and exercised.
- Production data stores are private, replicated, partitioned/indexed and monitored; queue/outbox consumers and dead-letter recovery are operational.
- CI gates include SAST, SCA/license, secret, SBOM/provenance, container, IaC, API/DAST and signature/admission validation; critical findings block.
- 24x7 operational ownership, on-call, SOC, incident authority, communications, supplier escalation and tested runbooks are staffed.
- SLOs, error-budget policy, dashboards, synthetic probes, alert routing and telemetry-loss detection are live.
- Canary/rollback, expand-contract migration, feature/route kill switches and emergency change procedures are demonstrated.

Acceptance evidence is versioned, signed by control owners, linked to the exact artifact digest and expires after material architectural change.
