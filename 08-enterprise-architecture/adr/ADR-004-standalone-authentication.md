# ADR-004: Controlled standalone authentication

**Status:** Proposed — requires Security, Privacy and NamRA approval.

## Context

Federation may be unavailable or unsuitable for limited users. A continuity path must not become a weaker parallel identity.

## Decision

Provide standalone identity only through an approved identity platform, linked to the same internal user and taxpayer organisation. Require verified enrolment, phishing-resistant MFA where feasible, breached-password protection, secure recovery, device/risk signals, session revocation and privileged step-up. Store password verifiers only in the identity platform using an approved adaptive algorithm.

## Consequences

Continuity improves but operational security, recovery fraud and duplicate linking risks increase. Standalone access is policy-scoped and can be disabled by risk or federation mandate.

## Alternatives rejected

Application-built password authentication, security questions and email-only recovery are rejected.

