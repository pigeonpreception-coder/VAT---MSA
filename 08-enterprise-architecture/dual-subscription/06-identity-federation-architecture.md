# Identity and Federation Architecture

**Sequence:** 06 of 29

Global identity stores one provider subject per issuer and may link it to multiple bounded memberships without merging their authorities.

| Link type | Required issuer/evidence | Scope | Grants by itself |
|---|---|---|---|
| Tax Authority Administrator | approved government IdP, MFA/step-up, authority appointment | authority + jurisdiction | no taxpayer or company rights |
| Taxpayer tax user | ITAS/authority federation or approved VAT-MSA identity proof | taxpayer + jurisdiction | no commercial modules |
| Company System Administrator | approved enterprise/consumer IdP plus organisation authority proof | organisation | onboarding scope until commercial activation |
| Employee | approved identity plus signed organisation invitation | organisation/branch/role | only licensed commercial permissions |
| Super Administrator | platform workforce IdP, PAM/JIT, device posture | platform control plane | no tax or organisation data authority |

Federation uses issuer, subject, audience, nonce, state, PKCE where applicable, signature, token age, assurance and revocation checks. Email is not a stable subject. Identity linking is idempotent, conflict-reviewed and audit recorded. Recovery cannot silently change taxpayer or administrator ownership.

ITAS remains a disabled adapter until NamRA approves protocol, claims, assurance, lifecycle, consent, logout and incident contracts. Direct VAT-MSA tax access still requires authority verification and creates no duplicate taxpayer.
