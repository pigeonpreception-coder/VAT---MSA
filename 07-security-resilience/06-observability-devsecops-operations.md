# Observability, monitoring, DevSecOps and deployment architecture

## Telemetry architecture

OpenTelemetry-compatible instrumentation emits metrics, structured logs and traces through redundant regional collectors. Collectors redact/tokenize sensitive fields and route operational data to the monitoring platform and security events to an access-isolated SIEM/security lake. Correlation and trace identifiers propagate API → outbox/queue → worker → database/audit. Payloads, tokens, raw secrets and unnecessary personal data are forbidden in logs.

Golden signals are request rate, errors, latency and saturation. Business signals include invoice acceptance/processing, duplicate conflicts, ledger reconciliation and oldest outbox age. Security signals include failed authentication, denied authorization, rate/payload blocks, privileged changes, bulk reads and automated response. Alert on symptoms and multi-window SLO burn; dashboards support investigation but are not the only alert channel.

Telemetry failure is itself an alert. Collectors buffer durably within bounded storage and replay to redundant sinks. Sampling never drops errors, security events or Tier-0 transaction spans; normal traces may be tail-sampled. Clock synchronization and schema/version ownership are operational controls.

## SOC and SIEM

The `/security` pilot view proves security-event and incident workflows. Production SIEM ingests identity, edge/WAF, API, application, database activity, cloud/cluster audit, EDR/runtime, KMS and CI/CD events. Correlation rules and behavioural baselines create incidents with evidence, severity, owner and playbook. Detection-as-code changes require review, test fixtures and false-positive monitoring. High-impact containment requires controlled approval and rollback.

## DevSecOps gates

Source protection and review precede automated lint/unit tests, SAST, dependency and license scan, secret scan, SBOM/provenance, build, container and IaC scan, API security tests, DAST in an authorized ephemeral environment, signature and policy admission. Critical findings block promotion unless a named authority accepts a time-limited residual risk with compensating control.

This repository supplies a portable `pnpm security:ci` gate, local secret scanner and CycloneDX SBOM generator. Production CI adds enterprise scanners, isolated ephemeral credentials, signed provenance, immutable registry, image signing/verification and environment approvals. Scan tools cannot be treated as complete penetration testing.

## Deployment architecture

Artifacts are built once, signed, promoted without rebuild and verified at admission. Default deployment is canary or rolling across failure domains under readiness/SLO/security gates; schema changes are expand-migrate-contract and backward compatible. Automated rollback uses the previous signed image and feature/route kill switches, while irreversible data migrations require a tested forward-recovery plan.

Minimum release evidence: reviewed change, threat impact, tests/scans, SBOM, image digest/signature, migration/rollback, capacity impact, dashboards/alerts, owner and approval. Emergency changes are narrow, logged and retrospectively reviewed.

## Operating model

Platform/SRE owns availability, capacity and deployment; application teams own service SLOs and secure code; data owns integrity/recovery; IAM owns identity policy; SOC owns monitoring and containment; incident command coordinates cross-team response. Runbooks are versioned and exercised. On-call handoffs include active incidents, error-budget status, capacity and pending changes.
