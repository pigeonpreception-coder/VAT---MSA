# Controlled sequential remediation execution addendum

Effective date: 23 August 2026

Status: **ACTIVE CONTROL ADDENDUM**

This addendum corrects execution ambiguities in the supplied 27-issue VAT-MSA remediation prompt. It preserves the one-issue-at-a-time control, evidence threshold and prohibition on false completion. Where the original prompt and this addendum differ, this addendum governs execution safety and acceptance terminology.

## 1. Authority and environment gate

Before an issue changes data, connects to an external service, sends a message, exercises payment, performs load or attack testing, or changes an environment, record:

- the exact environment and data classification;
- accountable system owner and testing authority;
- permitted identities and roles;
- allowed record mutations and recovery method;
- provider sandbox/production mode;
- load, duration and test-window limits;
- prohibited actions and services;
- rollback, evidence-retention and incident contacts.

Absent evidence is not consent. The standing VAT-MSA baseline permits local/staging work with synthetic data, configurable price-free licence plans and local commits. Real payments, live ITAS, unapproved statutory rules, real customer contact and production changes remain disabled.

## 2. Correct issue state machine

Use these states:

```text
LOCKED
  -> NOT STARTED
  -> ASSESSED
  -> IMPLEMENTATION IN PROGRESS
  -> UNIT VERIFIED
  -> INTEGRATION VERIFIED
  -> SECURITY VERIFIED
  -> END-TO-END VERIFIED
  -> OPERATIONALLY VERIFIED
  -> ACCEPTANCE EVIDENCE GENERATED
  -> PASS / FAIL / BLOCKED
  -> CLOSED (PASS only)
```

`FAIL` returns the same issue to implementation. `BLOCKED` stops automatic execution and identifies the dependency, owner and exact evidence needed. Neither state automatically unlocks the next issue. `CLOSED` is a terminal administrative state reached only after `PASS`; it is not a substitute for acceptance.

A named programme authority may explicitly direct work to proceed to a later issue despite a blocked predecessor. That direction is a **sequence exception**, not acceptance: it must be recorded with its date, scope and residual risk; it does not convert the blocked issue to PASS/CLOSED, authorize production, or waive the later issue's own gates. Without an explicit direction, subsequent issues remain locked.

## 3. Evidence and claim rules

- Link every result to an exact source revision, migration set, environment, test time and evidence digest where practical.
- Separate source inspection, automated test, local runtime, sandbox, production-equivalent and production evidence.
- Do not use a screenshot or HTTP 200 as proof of authorization, financial integrity, statutory correctness or successful external integration.
- Redact credentials, tokens, taxpayer identifiers, personal information, payment data and exploitable infrastructure details from evidence.
- Record tests that were not run and why; never silently omit a mandatory gate.
- A local negative test proves only the tested implementation property. It does not prove upstream IdP, edge, provider, authority or operational controls.

## 4. Safe change control

For every implementation increment:

1. preserve existing user work and obtain a clean baseline;
2. identify data and compatibility impact;
3. use forward-only, non-destructive migrations with a tested recovery procedure;
4. keep unavailable or unapproved integrations fail-closed;
5. run proportionate regression, security and contract gates;
6. record residual risk and external acceptance dependencies;
7. commit locally only unless the user explicitly authorizes a remote push or deployment.

No issue authorizes real payments, statutory activation, taxpayer contact, destructive production data changes, unbounded load testing or penetration testing outside a specifically approved environment.

## 5. Duplicate-scope boundaries

The original list contains intentional or accidental overlap. Use the following ownership boundaries to prevent duplicate implementations:

| Primary issue | Owns | Later issue consumes or productionizes |
| --- | --- | --- |
| 2 — Identity proofing | Human/taxpayer proofing and uniqueness lifecycle | 3 validates counterparty tax status; 11 operates reconciliation at scale |
| 5 — Subscription/licence operations | Internal subscription and entitlement state machine | 24 integrates and accepts the external payment provider |
| 8 — ITAS/authority adapter | Namibia authority adapter implementation | 23 governs the reusable external-contract framework |
| 9 — Invitation system | Product invitation workflow and secure acceptance token | 26 supplies production communication providers and delivery operations |
| 15 — Accounting core | Ledger, periods and Finance-approved posting model | 16 binds protected document inputs and operational posting/reversal flows |
| 16 — Accounting/malware protection | Transaction posting gate using scan outcomes | 20 owns cross-platform file security, retention, legal hold and storage operations |
| 25 — Event platform | Reliable event transport | Domain issues publish only approved schemas through that platform |

Later issues must reuse accepted controls. They may extend a control for their stated production boundary but must not create competing payment, identity, messaging, event, document or accounting mechanisms.

## 6. Stale-fact rule

Statements such as “currently failing page” are hypotheses until reproduced against the current revision. Record the route, environment, timestamp, correlation identifier and observed failure before remediation. If the defect no longer exists, mark it `NOT REPRODUCED` and assess the remaining lifecycle gap; do not rewrite working code to satisfy stale wording.

## 7. External dependency rule

When production IdP, identity proofing, national registry, tax authority, payment provider, messaging provider, malware/CDR provider, broker, HSM/KMS, production cloud account or independent assessor evidence is unavailable:

- implement only approved local interfaces and fail-closed controls;
- use synthetic fixtures for automated tests;
- label mock/sandbox evidence accurately;
- create a named acceptance backlog item with an accountable owner;
- set the current issue to `BLOCKED — EXTERNAL DEPENDENCY REQUIRED` if its gate requires that evidence;
- keep all subsequent issues `LOCKED` under the strict sequential programme.

Architecture documents, code, mocks and locally signed fixtures cannot close an operational acceptance gate.

## 8. Issue 1 interpretation for the current baseline

Issue 1 can locally implement input validation, fail-closed trust modes, signed assertion validation, issuer/audience/time/session/action/origin binding, single-use privileged evidence and negative tests. It cannot locally prove a production IdP contract, actual phishing-resistant MFA enrollment, recovery governance, managed-edge direct-origin denial, revocation propagation or independent attack resistance.

Therefore Issue 1 remains blocked until the external PR-004 identity-and-origin evidence package is accepted.

## 9. Authorised Issue 2 sequence exception

On 23 August 2026, the programme authority explicitly instructed implementation to move to Issue 2 because Issue 1 cannot presently be completed. This unlocks only Issue 2 for bounded local/staging implementation with synthetic data. Issue 1 remains `BLOCKED — EXTERNAL DEPENDENCY REQUIRED`; its risk and PR-004 evidence requirement are unchanged.

Issue 2 may implement proofing cases, deterministic reconciliation, uniqueness and mismatch controls, fail-closed provider boundaries, contracts, migrations, UI projections and automated tests. It may not claim a national-registry result, activate a taxpayer/organisation/account/licence, or enable live ITAS. Issue 3 and later issues remain locked unless Issue 2 passes or the programme authority records another explicit sequence exception.

## 10. Authorised Issue 3 sequence exception

On 23 August 2026, the programme authority explicitly instructed implementation to move to Issue 3 and stated that Issue 2 was resolved and completed. The instruction is recorded as a sequence exception that unlocks only Issue 3. It does not create the missing PR-011/PR-003 authority evidence and therefore does not convert Issue 2 from `BLOCKED — EXTERNAL DEPENDENCY REQUIRED` to PASS or CLOSED.

Issue 3 may implement counterparty identifiers, fail-closed trust profiles, synthetic verification clearly separated from authority evidence, freshness and tax-registration gates, immutable verification history, database enforcement, contracts, UI projections and automated tests. It may not claim a NamRA, ITAS or BIPA validation result or enable live authority calls. Issue 4 and later issues remain locked unless Issue 3 passes or the programme authority records another explicit sequence exception.

## 11. Authorised Issue 4 sequence exception

On 23 August 2026, the programme authority explicitly instructed implementation to move to Issue 4 and stated that Issue 3 was resolved and completed. The instruction is recorded as a sequence exception that unlocks only Issue 4. It does not create the missing PR-012/PR-003 authority evidence and therefore does not convert Issue 3 from `BLOCKED — EXTERNAL DEPENDENCY REQUIRED` to PASS or CLOSED.

Issue 4 may implement a synthetic local/staging authority hierarchy, protected administrative roles, governed federation-registration records, independently reviewed onboarding cases, immutable decisions, quarterly authority-access reviews, production-activation database gates, contracts, UI projections and automated tests. It may not establish a live federation, provision a real authority, activate a production authority, claim NamRA/ITAS acceptance or grant taxpayer/financial access merely through authority membership. Issue 5 and later issues remain locked unless Issue 4 passes or the programme authority records another explicit sequence exception.
