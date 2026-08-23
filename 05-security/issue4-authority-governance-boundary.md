# Issue 4 authority-governance boundary

Status: **LOCAL/STAGING FOUNDATION VERIFIED — PRODUCTION PROVISIONING AND FEDERATION DISABLED**

Evidence date: 23 August 2026

## Objective and baseline

Issue 4 establishes the internal control plane needed to govern a participating Tax Authority without pretending that local synthetic data is a real authority appointment or federation. Before this increment the repository held synthetic authority, administrator, subscription and identity-provider records, but it had no durable onboarding case, authority hierarchy, protected authority-role catalogue, independently reviewed decision lifecycle, authority-specific quarterly access review or database activation gate.

## Authority and identity separation

An authority administrator record is a jurisdiction-scoped governance assignment, not an authentication mechanism and not a grant of taxpayer, financial or statutory access. Authentication continues to require the approved identity boundary. Every governance read and command is permission checked and then restricted to authorities explicitly assigned to the current administrator. Authority role assignments additionally carry scope, effective dates, assurance requirements and independent approval evidence.

## Hierarchy, roles and segregation of duties

The model records authority units as a parent-scoped hierarchy and rejects cross-authority parents. Protected duty classes cover onboarding maker, security, privacy, legal, integration, activation, access review, system administration and audit. The database rejects self-approved role assignments and prevents one user from holding both onboarding-maker and protected approval duties for the same authority. Assignments and governance decisions are non-destructive evidence.

## Federation lifecycle

Federation registrations are environment-labelled and use an explicit lifecycle: contract pending, configuration pending, conformance testing, local ready, production approved, suspended or revoked. The repository stores only contract metadata and cryptographic digests; it does not store provider secrets or assert that an unconfirmed issuer, audience, claims mapping or assurance level is authoritative. The synthetic ITAS record remains `CONTRACT_PENDING` and `UNCONFIRMED`.

A production-approved connection must be independently reviewed, current, evidence-backed, use OIDC or SAML and carry issuer, audience, assurance, metadata and claims digests. Direct deletion is prohibited. These controls do not perform a federation handshake and cannot substitute for provider conformance evidence.

## Onboarding and activation

The exposed command surface can create a local-staging review case or record a production-target request in `BLOCKED_EXTERNAL`. It can independently approve a local-staging case or reject a pending case. It exposes no production-activation command.

The database permits production activation only from a production-target blocked case with evidence and readiness references, a current production-approved federation registration, a current completed authority access review, and independently recorded security, privacy, legal, integration and activation approvals from five distinct authorised reviewers. Synthetic seed records satisfy none of these conditions.

## Privileged-change controls

Creation and decision commands require step-up authentication and idempotency. A requester cannot decide their own case. Decision type must match a current protected authority duty, the reviewer must be an active authority administrator, and a current quarterly authority-access review must exist. Decisions and governance events are append-only. Production writes fail closed; staging synthetic writes require an explicit environment switch.

## External acceptance boundary

PR-013 requires the participating authority, IAM/Platform, Security, Privacy/Legal, Integration and Country Readiness owners to provide and sign the real appointment, hierarchy, role, federation, conformance, access-review, activation, rollback and incident evidence. PR-004 remains required for the production identity/origin boundary, and PR-003 remains required for any live ITAS integration contract.

Until those packages are accepted, live federation, production authority provisioning and production activation remain disabled. Issue 4 is therefore locally implemented and verified but remains `BLOCKED — EXTERNAL DEPENDENCY REQUIRED` for production acceptance.
