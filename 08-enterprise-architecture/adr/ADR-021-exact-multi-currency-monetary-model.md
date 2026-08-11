# ADR-021: exact multi-currency monetary records and controlled FX ledger

- Status: Proposed
- Date: 2026-08-11
- Decision owners: Architecture Board, Finance Controls, Tax Governance

## Context

Transactions may use a currency different from functional or statutory tax currency. Overwriting original values or using floating-point calculations destroys evidence and can change tax outcomes.

## Decision

Store original, functional and tax currency amounts separately using integer minor units or exact decimals. Store ISO code, precision, rate direction, exact rate, source, effective time, version, rounding and conversion hash. Rates are append-only; manual rates require step-up and maker-checker approval. Presentation symbols never identify currency.

For Namibia, storage/API identity is `NAD` and UI/document presentation is `N$`; bare `$` is prohibited.

## Consequences

- all monetary APIs carry currency metadata;
- reports cannot silently add different currencies;
- historical conversions remain reproducible;
- finance must approve source/timing/rounding policy per country.

## Rejected

- JavaScript floating point for fiscal calculations;
- storing only converted amounts;
- mutable daily-rate tables without version history.
