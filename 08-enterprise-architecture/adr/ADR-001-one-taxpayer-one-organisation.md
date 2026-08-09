# ADR-001: One taxpayer identity and one organisation

**Status:** Proposed — requires Architecture Board and NamRA approval.

## Context

Duplicate buyer/seller accounts fragment compliance history and create reconciliation, privacy and fraud risk.

## Decision

One verified VAT-registered legal entity has one canonical taxpayer record and one active organisation account. VAT number is the primary tax identifier, TIN secondary and company registration number tertiary, subject to NamRA confirmation. Separate legal entities remain separate. Buyer and seller are dynamic transaction roles, not identities.

## Consequences and controls

All portals, ledgers, memberships and integrations resolve to the canonical taxpayer. The database enforces uniqueness and a 1:1 active organisation invariant; mismatches, mergers and lifecycle exceptions enter an approved review workflow. This improves traceability but makes master-data and ITAS verification availability critical.

## Alternatives rejected

Separate buyer/seller accounts and email-based identity were rejected because they create duplicates and cannot prove a legal taxpayer.

## Gate

Identifier precedence, merger/deregistration rules and ITAS lookup semantics **REQUIRE ITAS/NAMRA CONFIRMATION**.
