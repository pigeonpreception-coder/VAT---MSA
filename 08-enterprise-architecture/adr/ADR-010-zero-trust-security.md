# ADR-010: Zero-trust and evidence-centric security

**Status:** Proposed — requires CISO/Security approval.

## Context

VAT-MSA processes identity, fiscal, payment and enforcement data across users, devices, services and external providers.

## Decision

Never trust by network location. Authenticate workloads/users, authorize every resource action with RBAC+ABAC+purpose, encrypt transport/storage, minimize data, segment zones, use managed keys/secrets, record tamper-evident evidence and continuously monitor risk. Privileged access is just-in-time, step-up, session-audited and segregated.

## Consequences

Security is consistent across portals/APIs/events but requires central policy, asset identity, SOC integration and rigorous availability of control planes.

## Alternatives rejected

Perimeter-only security, shared administrator accounts and application-held long-lived secrets are rejected.

