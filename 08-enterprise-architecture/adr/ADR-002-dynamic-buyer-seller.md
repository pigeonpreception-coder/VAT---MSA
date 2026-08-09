# ADR-002: Dynamic buyer and seller roles

**Status:** Proposed — requires Architecture Board approval.

## Context

The same taxpayer buys in one transaction and sells in another. Permanent role types create duplicated parties and inconsistent ledgers.

## Decision

Buyer and seller are roles on an invoice/transaction edge. An organisation may act as either or both, with effective-dated capabilities and policy constraints. Invoice snapshots retain both parties' verified identifiers and names at certification time.

## Consequences

A single graph and ledger support input/output VAT; party master changes do not rewrite history. Authorization uses the acting organisation and transaction relationship. UI labels may say customer/supplier but do not change identity.

## Alternatives rejected

Separate customer and supplier legal-entity tables, or irreversible party typing, were rejected.

