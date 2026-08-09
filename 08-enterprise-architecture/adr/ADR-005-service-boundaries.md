# ADR-005: Modular core with evidence-driven service extraction

**Status:** Proposed — requires Architecture Board approval.

## Context

National scale needs isolation and independent scaling, but premature microservices add distributed failure and consistency cost.

## Decision

Begin with rigorously separated bounded modules plus independently deployable edge/integration/high-risk workers. Extract a domain into a service only when scale, availability, security isolation, ownership or change-rate evidence justifies it. Public contracts and events exist before extraction.

## Consequences

Initial delivery is simpler without sacrificing domain ownership. Cross-domain writes use commands/events and no domain reads another's tables. Extraction later adds operational overhead by deliberate choice.

## Alternatives rejected

One unconstrained monolith and a service per entity are rejected.

