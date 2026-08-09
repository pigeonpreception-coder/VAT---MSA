# ADR-011: Horizontal scale, cell-ready isolation and measured SLOs

**Status:** Proposed — numerical targets require Architecture/Operations approval.

## Context

Filing deadlines and national invoice traffic are bursty; a single failure must not halt the countrywide fiscal path.

## Decision

Keep application services stateless, scale horizontally across failure domains, isolate workloads with bulkheads/queues, partition durable streams and data by stable keys, and reserve capacity for fiscal paths. Use cell-based regional/tenant isolation when measured scale or blast radius warrants it. SLOs and error budgets drive release and capacity decisions.

## Consequences

The platform can scale incrementally, but idempotency, observability, load testing and automated failover are non-negotiable. Proposed targets live in the HA architecture and are not contractual until approved.

## Alternatives rejected

Vertical scaling as the sole strategy and universal active-active database writes are rejected.

