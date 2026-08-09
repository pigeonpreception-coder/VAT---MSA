# Development Environment, DevSecOps and Test Architecture

This is an architecture and control specification only. It does not authorize application, migration or deployment implementation.

## Repository and dependency architecture

Recommended target monorepo after approval:

```text
apps/                 # taxpayer, NAMRA, super-admin, desktop, developer portal
services/             # independently deployable bounded services when justified
modules/              # modular-monolith domain/application/adapters
packages/             # UI, contracts, policy client, observability, test kits
schemas/              # OpenAPI, AsyncAPI, event and rule schemas
data/                 # migrations, seed metadata and governed reference definitions
platform/             # infrastructure definitions, policy and deployment descriptors
tests/                # contract, integration, journey, performance, security and DR
docs/architecture/    # this package, ADRs and diagrams
```

Domain packages expose public contracts only. UI cannot access databases; domains cannot query another domain's tables; integration adapters do not contain tax policy. Dependency checks prohibit cycles and unapproved shared models.

## Environments

| Environment | Purpose | Data | Access/promotion |
|---|---|---|---|
| local | deterministic developer loop and unit/component tests | synthetic | developer; no production credentials |
| integration | shared contracts, adapters and ephemeral dependencies | synthetic/generated | CI and engineers; auto-reset |
| security | DAST, fuzz, attack simulation and malware tests | synthetic hostile corpus | Security/CI isolated |
| performance | representative topology and scale | generated/anonymized by approval | performance team; controlled load |
| sandbox | SaaS/developer conformance | synthetic taxpayer identities | registered partners with quotas |
| UAT | business/NamRA acceptance | masked or synthetic | named testers; change-controlled |
| pre-production | production-equivalent release rehearsal and DR | synthetic/masked | release/SRE/security |
| production | live national service | authoritative classified data | JIT least privilege; no developer standing access |
| recovery | isolated restore/clean room | encrypted restored copy | quorum-approved DR/Security personnel |

Accounts, networks, keys, identity tenants and data are isolated. Configuration is typed, versioned and validated; secrets come from a vault and never repository/environment dumps. Feature flags have owner, expiry, targeting constraints and audit; flags cannot bypass authorization or legal tax-rule approval.

## Delivery pipeline

1. Developer signs commit; pre-commit formatting, unit, secret and dependency-boundary checks.
2. Pull request requires review, linked requirement/ADR, threat impact and tests.
3. CI produces reproducible build, SBOM, provenance and signed immutable artifact.
4. Gates run lint/type/unit, SAST, SCA/license, secrets, schema/API/event compatibility, policy tests, integration and migration rehearsal.
5. Security environment runs DAST/API fuzz/container/IaC/config and abuse cases based on risk.
6. UAT/pre-production run journeys, accessibility, reconciliation, performance, failover and rollback.
7. Independent approvers promote the same artifact; no rebuild between stages.
8. Progressive production release uses canary/blue-green, SLO/business controls and automated rollback.
9. Evidence is retained: source, reviews, test results, SBOM, signatures, approvals, deployment and observed health.

Critical/high exploitable findings block release unless accountable, expiring risk acceptance satisfies NamRA policy. Emergency changes are time-bounded, peer-approved, observed and retrospectively reviewed.

## Test architecture

| Layer | Scope | Required examples |
|---|---|---|
| unit/property | deterministic domain rules | tax boundaries, rounding, identifiers, state transitions, invariant generation |
| component | module/service with controlled adapters | authorization, validation, outbox, safe errors, document quarantine |
| contract | provider/consumer and schema | OpenAPI, events, ITAS/SaaS stubs, compatibility, signature validation |
| integration | real database/broker/object store | transactions, locking, partitions, replay, retention/legal hold |
| journey | portal/API/desktop workflows | registration, invoice, return, audit, refund, delegation, offline sync |
| security/privacy | STRIDE/abuse and handling | tenant escape, IDOR, injection, token abuse, export/DLP, audit tamper |
| performance/resilience | SLO and failure | peak deadline, spike/soak, chaos, dependency outage, backlog recovery |
| DR/BCP | restore and continuity | PITR, regional recovery, clean room, offline reconciliation |
| accessibility/UX | WCAG and service usability | keyboard, screen reader, contrast, error recovery, low bandwidth/mobile |
| financial assurance | ledger/control integrity | double entry, VAT-to-GL, correction, return trace, control totals |

Test data is generated by approved factories with realistic edge cases. Production data is prohibited by default. Any authorized masked subset must pass re-identification review, have owner/purpose/expiry and remain access-audited.

## Developer workstation and supply chain

Managed devices use disk encryption, EDR, MFA, least privilege, patched toolchains and signed packages. Dependency sources are allowlisted and locked; private registries proxy external packages and retain integrity metadata. Build workers are ephemeral, network-restricted and cannot deploy using pull-request credentials. Protected branches, two-person review, CODEOWNERS for fiscal/security areas and secret scanning are mandatory.

## Definition of ready for coding

Coding begins only after the approval gate identifies an approved scope, relevant ADRs are accepted, authoritative interfaces are confirmed or stub policy approved, schemas and acceptance tests exist, security/privacy threats have owners, and no critical component is `NOT READY`. This package intentionally stops at that gate.

