# ADR-006: Relational system of record with fit-for-purpose adjuncts

**Status:** Proposed — requires Architecture, Data and Infrastructure approval.

## Context

Fiscal records demand transactions, constraints and reproducibility, while documents, search, events and analytics have different access patterns.

## Decision

Use a strongly consistent relational database as the authoritative operational store, owned schemas per bounded domain, UUID/ordered identifiers, explicit tenant keys, append-only fiscal/ledger records and point-in-time recovery. Use object storage for documents, a search index for discovery, durable event log for integration, cache for safe ephemeral/reference data and a governed warehouse/lakehouse for analytics.

## Consequences

Integrity is enforced close to data; polyglot stores are projections, not hidden authorities. Partition by time/tenant at measured thresholds; shard only with proven need and a routing strategy.

## Alternatives rejected

A shared schemaless store for all workloads and analytics queries on the transactional primary are rejected.

