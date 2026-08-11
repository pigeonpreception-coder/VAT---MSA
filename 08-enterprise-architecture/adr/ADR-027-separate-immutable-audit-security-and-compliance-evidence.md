# ADR-027: separate immutable audit, security and compliance evidence

- Status: Proposed
- Date: 2026-08-11
- Decision owners: CISO, Internal Audit, Records Authority, Legal, Data Owners

## Context

Application logs alone are mutable, incomplete and often overexpose data. VAT-MSA needs evidence for fiscal integrity, security operations, privacy, incident investigation, control assurance and recovery without giving operators the ability to alter evidence about themselves.

## Decision

Use structured append-only audit events copied to separately administered tamper-evident/immutable storage according to risk. Compliance evidence is a first-class object containing control/version, scope, producer/source, collection interval, hash/signature/provenance, classification, retention/hold, reviewer and outcome.

Security/audit administration is separated from business and platform administration. Logs exclude secrets and unnecessary PII. Evidence access is purpose-bound, monitored and read-only for assurance. Legal holds and chain-of-custody procedures govern investigations.

## Consequences

- evidence gaps, clock drift and integrity failures become detections;
- storage and access costs increase and require lawful retention schedules;
- screenshots do not substitute for continuous source evidence;
- recovery must restore or reconnect audit continuity before reopening critical writes.

## Rejected

- relying on local application logs;
- allowing platform administrators to delete evidence;
- recording complete payloads or credentials “for forensics”;
- overwriting audit history after correction or offboarding.
