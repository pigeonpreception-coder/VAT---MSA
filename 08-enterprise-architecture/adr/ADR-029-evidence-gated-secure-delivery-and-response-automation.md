# ADR-029: evidence-gated secure delivery and response automation

- Status: Proposed
- Date: 2026-08-11
- Decision owners: CISO, Engineering, Release Authority, SOC, SRE

## Context

Security controls must reach code, infrastructure and operations. Manual release attestations and unrestricted SOAR actions create unverified or excessive authority.

## Decision

Gate release with requirement/control traceability, threat/privacy assessment, peer/CODEOWNER review, SAST/SCA/secret/IaC/container/DAST/API/abuse tests, SBOM, provenance, artifact signature, admission, migration/rollback, telemetry/detection and approval evidence. Promote the same digest between environments.

Automated response is limited to versioned, tested, high-confidence, reversible and bounded actions with expiry and rollback. Broad suspension, statutory/payment action, regional failover, irreversible deletion and external notification require named human authority.

## Consequences

- pipeline and evidence services become critical controlled infrastructure;
- critical exploitable findings block release unless the formal authority grants a lawful expiring exception;
- production requires independently tested tenant, business-logic, incident and recovery controls;
- emergency changes remain peer-approved, observed and retrospectively reviewed without SoD bypass.

## Rejected

- rebuilding artifacts per environment;
- deploying unsigned or unprovenanced artifacts;
- production release based only on unit tests;
- fully autonomous destructive containment or regulatory notification.
