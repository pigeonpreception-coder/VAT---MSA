# ADR-009: Policy-bounded offline capability

**Status:** Proposed — requires Architecture, Security and legal/regulatory approval.

## Context

Connectivity failures must not necessarily stop lawful commerce, but unsupervised offline fiscal issuance increases tamper and duplicate risk.

## Decision

Provide an approved desktop/PWA client with device-bound identity, encrypted local store, signed policy/rule bundles, limited pre-authorized invoice number ranges, append-only local journal and idempotent ordered synchronization. Server validation is authoritative; conflicts quarantine rather than overwrite.

## Consequences

Resilience improves at the cost of device management, revocation, reconciliation and custody requirements. Offline scope, duration, values and operations are configurable and expire closed.

## Alternatives rejected

Unlimited offline issuance, plaintext storage and last-write-wins fiscal synchronization are rejected.

## Gate

Legal validity of offline invoices and numbering rules **require NamRA/legal confirmation**.

