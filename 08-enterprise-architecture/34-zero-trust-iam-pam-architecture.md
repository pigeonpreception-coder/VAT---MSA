# VAT-MSA zero-trust, IAM and PAM architecture

## 1. Objective

Every protected request receives an explicit, contextual and continuously re-evaluated decision. Identity is the primary policy anchor, but identity alone never proves authority. Network location, device ownership, employment, tenant membership, licence state or possession of a token is insufficient by itself.

The architecture aligns with NIST SP 800-207/207A and uses NIST SP 800-63-4 as risk-based digital-identity guidance. Assurance levels and authenticators are selected through a documented service-specific risk assessment; VAT-MSA does not claim US federal conformance merely by applying the guidance.

## 2. Zero-trust logical components

See `diagrams/zero-trust-decision-flow.mmd`.

| Component | Responsibility | Availability/failure behavior |
|---|---|---|
| identity provider/trust broker | proofing and federation; issuer and lifecycle authority | unknown/untrusted issuer denied; no silent local elevation |
| authenticator service | MFA, passkeys/passwordless where approved, recovery and binding | privileged/high-risk flows stop if required assurance cannot be met |
| device/risk signal service | device posture, velocity, impossible travel, threat and session signals | absence raises risk; mandatory device controls fail closed |
| policy engine | RBAC/ABAC, tenant/resource, SoD, licence and obligation decision | protected write denied on unavailable/stale mandatory policy |
| policy information points | identity, membership, organisation, country, licence, workflow, case, classification and risk attributes | provenance/version required; conflicting authority opens exception |
| policy enforcement points | edge, portal BFF, API, service, event consumer, data, search, object and export | cannot trust a prior UI decision; server and resource checks mandatory |
| telemetry/evidence | decision, obligation, enforcement, anomaly and review evidence | buffered securely; gaps alert and may restrict high-risk operations |

## 3. Identity classes and lifecycle

| Identity class | Authority source | Lifecycle minimum |
|---|---|---|
| taxpayer/organisation person | approved ITAS federation or approved standalone proofing linked to the canonical person | proof, activate, review, suspend/recover, terminate; preserve actor history |
| NamRA/regulatory person | approved government/enterprise IdP and workforce authority | joiner-mover-leaver, managed device, role/case assignment, quarterly review |
| platform/security operator | dedicated administrative identity separate from daily account | phishing-resistant MFA, JIT eligibility, session monitoring, rapid revocation |
| external practitioner/delegate | canonical person plus explicit organisation grant/consent | purpose/scope/expiry, delegator authority, urgent revocation and review |
| service/workload | workload identity platform and deployment provenance | short-lived identity, audience binding, automated rotation and inventory |
| API client/integration | client registry and organisation/provider approval | environment/tenant/scope/quota/certificate binding, expiry and conformance |
| device/offline client | enrolled device record and device key | attestation where approved, key rotation/revocation, version and sync policy |
| emergency technical identity | pre-created controlled recovery mechanism | sealed eligibility, dual authorization, narrow action, expiry and full review |

Identity linking is collision-resistant and append-only. Merges, re-links and recovery preserve previous identifiers, evidence and affected sessions. Credentials, tokens, private keys and recovery material never appear in application logs or source control.

## 4. Authentication policy

| Risk tier | Examples | Minimum outcome |
|---|---|---|
| standard | low-risk read of own ordinary business records | approved authentication, session controls, tenant/resource authorization |
| elevated | exports, sensitive data, new device, unusual location, high-value workflow | step-up MFA and/or managed-device obligation, shorter session, enhanced evidence |
| privileged | roles, policies, integrations, keys, country packs, production/recovery administration | phishing-resistant MFA, dedicated admin identity, JIT/PAM, approval and recording |
| statutory/signing | VAT return submission, fiscal-rule activation, signature/key use | approved legal assurance, step-up, explicit intent, SoD, signed receipt/evidence |

Passwords, if supported, follow the current approved authenticator standard: block compromised/common secrets, permit password managers and paste, avoid arbitrary composition rules, rate-limit attempts and protect recovery at least as strongly as authentication. Passwordless authenticators are preferred where lifecycle, accessibility and recovery risks are governed.

Adaptive controls can challenge, throttle or shorten sessions. Risk scores cannot silently weaken a minimum control or autonomously make a statutory decision.

## 5. Federation and protocol rules

- OAuth 2.x/OIDC are used only with approved current profiles, authorization-code flow, PKCE where applicable, strict redirect registration, state/nonce, issuer/audience/time validation and sender-constrained credentials where justified.
- SAML is supported only for approved enterprise/government federation with signed assertions, strict audience/recipient/time checks, replay cache and metadata/key rollover.
- Access tokens are short-lived and audience-restricted; refresh tokens are rotated and reuse-detected where used.
- Browser sessions use secure, HTTP-only, same-site cookies; CSRF and fixation controls are mandatory. Sensitive tokens are not stored in browser local storage.
- Federation assertions are mapped through an allowlisted claim contract. External roles/groups do not directly become VAT-MSA privileges.
- ITAS identity integration remains a disabled adapter until issuer, assurance, claims, revocation, recovery, support and sandbox contracts are officially confirmed.

## 6. Authorization decision contract

Every decision includes:

```text
subject + acting authority + tenant + resource owner + action
+ role/permission + organisation capability + licence entitlement
+ jurisdiction + country policy + classification + purpose
+ branch/department/region/case + workflow state + transaction value
+ authentication strength + device/session risk + time
-> ALLOW | DENY | CHALLENGE | REQUIRE_APPROVAL
+ obligations + policy versions + reason code + evidence correlation
```

The caller supplies resource identifiers, never trusted tenant/owner facts. The enforcement point loads authoritative ownership and evaluates policy. Bulk, search, analytics, event-consumer and export operations apply row/document-level decisions, not merely route permissions.

Denials use safe reason codes. They do not reveal resource existence, hidden navigation, risk logic or another tenant's attributes.

## 7. RBAC and ABAC

RBAC grants a bounded capability set. ABAC constrains each capability and may require step-up, approval, masking, watermarking, read-only behavior, reason capture or export manifest. Custom organisation roles can compose only permissions in the grantable catalogue and can never include protected platform, NamRA, regulatory, key, evidence or licence-authority permissions.

Administrative delegation is non-transitive unless explicitly designed. A branch administrator cannot create organisation-wide authority; an organisation administrator cannot grant NamRA or platform roles; a Super Administrator receives no automatic taxpayer-data privilege.

## 8. Segregation of duties

SoD is evaluated at role design, assignment, request, decision and execution. Mandatory rules include:

- no author/reviewer/approver/activator collapse for country or security policy;
- no self-approval of access, workflow, exception, privileged change, refund, return or protected financial action;
- no create-and-final-approve for protected fiscal/financial workflows;
- key administration is separate from business approval and evidence review;
- production deployment is separate from code authorship and artifact signing policy;
- audit/evidence administrators cannot alter the activity being audited;
- licence administrators cannot set their own entitlement authority or usage records.

VAT-MSA has **no emergency SoD override**. An emergency may change the routing/availability of eligible independent approvers, but never permits one person to satisfy conflicting duties.

## 9. PAM and privileged sessions

Privileged access is deny-by-default and issued just in time for a named environment, resource, action and duration. Eligibility does not equal active privilege.

Required sequence:

1. dedicated administrator authenticates with phishing-resistant MFA on an approved device;
2. request references incident/change, purpose, target, commands/capabilities and duration;
3. policy evaluates eligibility, SoD, risk, maintenance window and approval;
4. independent approver authorizes; high-impact access may require quorum;
5. broker issues ephemeral credentials without revealing durable secrets;
6. session/commands are monitored and recorded according to privacy/labor law;
7. access expires automatically and residual sessions/tokens are revoked;
8. evidence and detected deviations enter review.

Standing production privilege is prohibited except narrowly justified non-human platform functions. Shared named-person accounts are prohibited.

## 10. Break-glass boundary

Break-glass is not an SoD override. It may only restore or contain a predefined technical service where delay creates greater harm. It requires a dedicated identity, phishing-resistant MFA, at least two independent authorizers where technically available, a narrow allowlist, automatic expiry, real-time SOC alert, complete evidence and next-business-day independent review.

Break-glass cannot approve VAT returns/refunds, alter country/tax rules, weaken tenant isolation, delete audit evidence, expose bulk taxpayer data, create licence entitlement, bypass legal hold or suppress monitoring. If quorum technology is unavailable, the action remains blocked until the approved incident authority supplies an alternative independent actor.

## 11. Access reviews and offboarding

- privileged and regulatory access: continuous triggers plus quarterly certification at minimum;
- organisation access: quarterly minimum for privileged/protected permissions and risk-triggered review for other access;
- service/API identities: owner, purpose, scope, last use, credential age and environment reviewed quarterly;
- dormant, orphaned, conflicting, excessive or expired access is revoked, not merely reported;
- termination and urgent security events invalidate interactive sessions, refresh tokens, API credentials and active JIT grants within the approved revocation SLO;
- historical actor identity and approvals are preserved; offboarding never rewrites audit history.

## 12. Identity threat controls

| Threat | Prevent | Detect | Respond |
|---|---|---|---|
| credential stuffing/phishing | phishing-resistant MFA, rate/bot controls, compromised-secret blocking | failure velocity, device and token anomalies | challenge, revoke, re-proof and review fiscal actions |
| federation forgery | strict issuer/audience/signature/nonce/time, metadata pinning | unknown key/issuer, replay and claim drift | deny, revoke trust, coordinate provider incident |
| token theft/replay | short lifetime, secure cookie, sender constraints where justified | refresh reuse, impossible token geography/client | revoke family/session and investigate |
| privilege escalation | grantable catalogue, JIT, SoD, server policy | denied grants, unusual role/policy changes | revoke, isolate and review affected decisions |
| tenant escape | trusted resource ownership, tenant predicates/RLS defense | cross-tenant denials and canary accesses | suspend actor/client, preserve evidence and investigate |
| recovery abuse | equal-or-stronger recovery proof, delay/notification for high risk | recovery velocity and identifier changes | freeze/re-proof and revoke prior authenticators |

## 13. Acceptance evidence

Before production, the IAM/PAM scope requires identity threat model approval; assurance-selection record; federation conformance; recovery abuse tests; session/token tests; tenant/resource negative tests; role/ABAC/SoD property tests; PAM session and expiry evidence; revocation drills; quarterly access-review completion; accessibility/usability review; and independent penetration testing.
