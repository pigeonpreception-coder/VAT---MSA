# ADR-025: unified security, privacy and compliance control framework

- Status: Proposed
- Date: 2026-08-11
- Decision owners: Architecture Board, CISO, Privacy Authority, Legal, Enterprise Risk

## Context

VAT-MSA must apply international security/privacy frameworks and jurisdiction-specific obligations without treating management standards as software features, mechanically imposing every framework or making false certification claims. Separate mappings would duplicate controls and obscure evidence.

## Decision

Adopt one stable control catalogue that maps each applicable source to control objective, scope, architecture, implementation, telemetry, test, evidence, owner and status. Maintain separate applicability decisions per deployment and country. Use NIST CSF 2.0 to organize operating outcomes while ISO/IEC, NIST SP, OWASP, PCI and legal sources contribute requirements/guidance according to their actual nature.

Architecture may state “designed to align.” Certification, attestation, compliance and accreditation claims require their own independent processes and evidence.

## Consequences

- overlapping obligations share implementation and evidence while retaining source traceability;
- conflicts and unique jurisdictional duties remain explicit;
- control status distinguishes design, implementation and operating effectiveness;
- licensed normative standards and competent legal/independent review are still required.

## Rejected

- claiming ISO/PCI/SOC compliance from architecture documents;
- one matrix per standard with duplicated controls and inconsistent status;
- treating NIS2 or other law as an ISO standard;
- making PCI DSS global when cardholder-data scope is absent.
