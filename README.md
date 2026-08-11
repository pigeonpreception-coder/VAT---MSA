# VAT-MSA VAT Management System

This repository contains the working VAT-MSA operational pilot and its governed enterprise architecture for a national-scale VAT transaction, reconciliation, compliance and audit platform for Namibia.

## Working system

The application currently provides protected operations, taxpayer and organisation identity, registration intake, commercial/accounting operations, quotation conversion, invoice certification and correction, seller/output and buyer/input VAT ledger entries, reconciliation, governed VAT returns and refunds, evidence quarantine, integration/offline/reporting controls, public certificate verification, audit evidence, security operations and separated role-based portals. See `IMPLEMENTATION.md` for the working-system guide and `ARCHITECTURE_IMPLEMENTATION_MATRIX.md` for the domain-by-domain evidence and production boundary.

The identity foundation enforces one canonical organisation per taxpayer and models buyer/seller as dynamic transaction capabilities. ITAS federation and authoritative verification are represented by a disabled integration boundary; the application does not fabricate government protocols or decisions.

## Authoritative deliverable

- `08-enterprise-architecture/VAT-MSA-ENTERPRISE-ARCHITECTURE-BLUEPRINT.md` - consolidated 27-part, 95-deliverable enterprise architecture and extension package.
- `08-enterprise-architecture/29-architecture-approval-gate.md` - formal decisions, external confirmations and production blockers.
- `08-enterprise-architecture/33-global-security-privacy-compliance-architecture.md` - unified zero-trust, privacy, resilience and evidence-based security control architecture.
- `08-enterprise-architecture/security-control-matrix.csv` - master mapping from applicable sources to controls, implementation, evidence, tests, owners and status.
- `VAT-MSA_Enterprise_Architecture_Blueprint.docx` - visually reviewed master blueprint.
- `01-blueprint/VAT-MSA_Enterprise_Architecture_Blueprint.md` - maintainable source edition.

## Implementation artefacts

- `02-diagrams/` - editable Mermaid sources for the principal architecture views.
- `03-api/openapi.yaml` - versioned OpenAPI contract for the implemented identity, business, VAT, compliance and platform-edge APIs.
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

This is working pilot software plus a design baseline, not legal advice or statutory production authorization. All tax rules, ITAS contracts, return mappings, retention periods, invoice particulars, rollout mandates and cross-border/data-sovereignty obligations must be approved by NamRA policy, legal, security and records-management authorities before production.
