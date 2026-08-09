# ADR-007: Event-driven integration with transactional outbox

**Status:** Proposed — requires Architecture Board approval.

## Context

Reconciliation, notifications, analytics and external exchange must not extend the fiscal transaction or lose outcomes.

## Decision

Use versioned domain/integration events published through a transactional outbox. Delivery is at least once; consumers use inbox/idempotency and aggregate ordering keys. Synchronous calls remain for immediate commands/queries that require a response. No event is treated as globally ordered.

## Consequences

Producers and consumers decouple and replay becomes possible, but eventual consistency, schema governance, monitoring, DLQ and reconciliation are mandatory.

## Alternatives rejected

Dual database/broker writes, best-effort webhooks and synchronous chains across many services are rejected.

