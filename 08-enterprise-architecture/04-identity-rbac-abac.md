# E-F. Identity architecture and RBAC/ABAC

## Global zero-trust, IAM and PAM addendum

`34-zero-trust-iam-pam-architecture.md` is the controlling identity/security extension. It adds NIST SP 800-207/207A-aligned continuous decisions; risk-based NIST SP 800-63-4 guidance; human, workload, API and device identity classes; strict federation; phishing-resistant privileged step-up; JIT/PAM; decision-time SoD; no self-approval or emergency SoD override; quarterly access reviews; and bounded break-glass that cannot grant fiscal authority.

New security/privacy/evidence/profile roles are listed in `rbac-abac-matrix.csv`. They do not inherit taxpayer, NamRA, platform, key, evidence or fiscal authority outside their explicit ABAC scope.

## Country regulatory administration addendum

Regulatory administration uses separate Country Pack Author, Reviewer, Approver, Country Release Authority, Jurisdiction Case Officer, Currency Policy Reviewer and Country Readiness roles. ABAC includes assigned country, pack/version, environment, evidence purpose and effective interval. Pack approval, activation, jurisdiction migration and manual FX decisions require step-up authentication and independent approval; authors cannot approve or activate their own work. Privileged access remains subject to quarterly recertification with no emergency SoD override.

## Organisation-configured access extension

Employee and JobTitle are not identities or grants. The organisation chain is `Employee -> Position -> OrganisationRole -> PermissionSet -> Capability -> record/amount/workflow scope`. Organisation-owned roles may use only grantable permissions and cannot include protected NamRA, platform, security-policy, licence-state or tax-rule actions.

The authorization decision is extended with `active licence state + feature entitlement + reserved/actual usage + workflow authority + SoD`. The strictest denial wins. Primary, finance, user/access, branch, workflow and integration administrators are scoped appointments; none inherits taxpayer-financial, NamRA or platform rights automatically.

Primary-admin changes, privileged grants, MFA/security-policy changes, workflow hierarchy publication, tax-sensitive grants and API credential creation require policy-defined step-up, reason, approval and immutable evidence. Offboarding revokes sessions/tokens/credentials, reassigns pending tasks under policy and preserves historical actor attribution.

## One-person and one-taxpayer resolution

A user identity and a taxpayer identity are different. A person may authenticate through ITAS or standalone VAT-MSA and both provider subjects link to one internal user. That user receives time-bounded membership in one or more organisations. Each organisation maps one-to-one to one VAT-registered legal taxpayer; buyer/seller are capabilities on that organisation.

Identity resolution: validate provider token and assurance → locate provider+subject link → load active internal user → load active memberships/delegations → load canonical organisation/taxpayer and buyer/seller capability → evaluate RBAC/ABAC → record decision. Email is not the authoritative join key.

## Federation framework

| Mode | Target | Current implementation boundary |
|---|---|---|
| ITAS integrated | OIDC/SAML/token exchange chosen after capability confirmation; ITAS subject and authoritative attributes linked | provider registry and identity-link model; no unverified protocol hard-coded |
| VAT-MSA standalone | approved public identity service, MFA and account recovery; link after taxpayer proofing | architecture only; starter does not invent a public auth stack |
| Workspace pilot | dispatcher-provided authenticated identity headers | implemented for controlled pilot; production does not allow local fallback |
| Machine | OAuth client credentials/private-key JWT and mTLS for approved integrations | integration identity model and scoped API architecture; managed gateway required |

Provider metadata stores issuer/configuration references, not private keys. Token validation includes issuer, audience, signature, expiry, nonce/state where applicable and replay/session policy. Assurance is carried into authorization; privileged and statutory actions require policy-approved MFA assurance.

## Authorization decision

`allow = active user AND active identity link/session AND role permission AND active membership/delegation AND organisation/taxpayer/resource match AND capability match AND department/region/case constraint AND classification clearance AND allowed workflow transition AND purpose/approval conditions`.

Default is deny. The UI hides unavailable functions for clarity, but every server page/API/query repeats authorization. National search returns only fields and regions/cases allowed to the official. Super Admin has platform configuration/health scope but not taxpayer ledgers unless separately approved through a monitored, time-bound role.

## Role families

- Organisation: Director, Taxpayer Administrator, Finance Manager, Accountant, Finance Officer, Procurement Officer, Sales Officer, Auditor/Reviewer, Read Only.
- Delegated: Accountant, Tax Practitioner with explicit taxpayer scope, actions, expiry and revocation.
- NamRA: Compliance Officer, Auditor, Risk Analyst, Case Reviewer, Refund Reviewer, System Administrator.
- Technical: Super Administrator, Infrastructure Administrator, Security Administrator/Analyst, Database Administrator, Developer Support.

The detailed matrix is `rbac-abac-matrix.csv`. Organisation roles never override NamRA-controlled permissions; technical roles never inherit tax authority.

## ABAC attributes

| Subject | Resource | Environment/action |
|---|---|---|
| user ID, provider, assurance, role, organisation, department, branch, region, clearance, delegation expiry | taxpayer/organisation owner, branch, classification, period, case assignment, transaction type, workflow state | action, purpose, device posture, risk, channel, time, approval count, environment |

Policy decisions return allow/deny, policy ID/version, reason and obligations such as masking, approval, step-up authentication or audit enrichment. High-risk decisions and policy changes are exported to SIEM.

## Lifecycle and privileged access

Joiner/mover/leaver events flow from authoritative sources, require approval and generate evidence. Access is recertified at least quarterly for privileged/NamRA scope and on material role change. Delegations expire automatically. Privileged access is separate, JIT, MFA-bound, approved, recorded and revocable; technical break-glass has narrow expiry and retrospective review but cannot override SoD or grant fiscal authority.

## Failure and recovery

Identity/provider outage: validate cached signed configuration and existing short-lived sessions only within approved bounds; new high-risk/privileged actions fail closed. Compromise: revoke subject/token family, disable link, preserve event evidence, re-proof and issue new credentials. Duplicate identity: quarantine links, do not merge taxpayer financial history automatically, and use an approved identity-resolution case.
