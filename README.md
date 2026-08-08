# VAT-MSA Enterprise Architecture

This repository is the implementation blueprint for a national-scale VAT transaction, reconciliation, compliance and audit platform for Namibia. It converts the VAT-MSA concept into governed architecture, contracts, data definitions, controls and delivery gates that a product, engineering, security, data and operations team can use.

## Authoritative deliverable

- `VAT-MSA_Enterprise_Architecture_Blueprint.docx` - visually reviewed master blueprint.
- `01-blueprint/VAT-MSA_Enterprise_Architecture_Blueprint.md` - maintainable source edition.

## Implementation artefacts

- `02-diagrams/` - editable Mermaid sources for the principal architecture views.
- `03-api/openapi.yaml` - starter OpenAPI contract for invoice submission, status and verification.
- `03-api/schemas/vat-msa-invoice.schema.json` - canonical invoice JSON Schema.
- `03-api/event-catalog.yaml` - versioned business-event catalogue.
- `04-data/core-schema.sql` - PostgreSQL reference schema for core VAT-MSA records.
- `04-data/data-dictionary.md` - entity ownership, identifiers, retention class and sensitivity.
- `05-security/rbac-matrix.csv` - starter role-to-capability matrix.
- `05-security/security-controls-matrix.md` - security control baseline and evidence expectations.
- `06-delivery/non-functional-requirements.md` - measurable service-level and quality targets.
- `06-delivery/testing-strategy.md` - test layers, environments, evidence and release gates.
- `06-delivery/roadmap.md` - MVP through production and national-scale adoption roadmap.
- `references/architecture-decisions.md` - Architecture Decision Record (ADR) register.
- `references/assumptions-and-open-decisions.md` - facts, assumptions, dependencies and decisions requiring NamRA validation.

## Architecture position

VAT-MSA is not an ERP or the taxpayer account system of record. It is the controlled VAT transaction platform between taxpayer source systems and NamRA tax administration. ITAS remains authoritative for taxpayer accounts, filing and statutory account outcomes unless NamRA formally changes that boundary.

The target is a modular, event-driven platform. The initial implementation should use independently governed domain modules with explicit contracts and a transactional outbox. Services should be separated into independent deployments only when scale, security isolation, availability or team ownership justifies the operational cost.

## Status and use

This package is a design baseline, not legal advice or a final production specification. All tax rules, return mappings, retention periods, invoice particulars, rollout mandates and cross-border/data-sovereignty obligations must be approved by NamRA policy, legal, security and records-management authorities before production.

