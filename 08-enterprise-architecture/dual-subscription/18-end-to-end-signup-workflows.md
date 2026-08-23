# End-to-end signup and subscription workflows

Every workflow uses an idempotency key, correlation ID, authenticated/verified actor where required, immutable audit evidence and authority-domain checks. “Enabled” below never overrides the local/staging safety gates.

## 1. Tax Authority subscribes to VAT-MSA

An appointed Tax Authority Administrator authenticates with MFA and step-up, selects its recognized jurisdiction and a `GOVERNMENT_TAX` plan, completes contractual review through the approved external process, and submits activation. The service verifies appointment, jurisdiction ownership, separation of duties and plan-domain compatibility. A government tax subscription becomes `ACTIVE` only after an authorized activation record; commercial modules remain absent. In local/staging the terminal state is `PENDING_EXTERNAL_APPROVAL`.

## 2. Tax Authority activates taxpayer access

The authority administrator selects an existing canonical taxpayer, verifies current VAT registration evidence, assigns permitted tax features and effective dates, and records a reason. A transaction binds the authorization to the authority's active subscription and jurisdiction. The taxpayer gains tax access only after status `ACTIVE`; no commercial licence changes.

## 3. VAT taxpayer accesses through ITAS

The taxpayer starts the Namibia adapter, completes approved federation, and is linked by opaque ITAS subject to the canonical taxpayer identity. The tax service evaluates authority subscription, taxpayer authorization, VAT status, feature, user role and scope. A tax-scoped session is issued. The local/staging path returns `ITAS_INTEGRATION_DISABLED` because no live contract is approved.

## 4. VAT taxpayer accesses directly

The taxpayer uses VAT-MSA identity with MFA. Direct identity must already be linked to the same canonical taxpayer and meet authority-approved assurance. The same Government Tax Authorization decision applies; direct login never substitutes for taxpayer authorization.

## 5. Company administrator creates commercial SaaS account

A person chooses the explicit **Company Administrator — Start Subscription** path, attests and verifies their authority relationship, supplies organisation identifiers, and passes duplicate/conflict checks. VAT-MSA creates a pending application and limited pre-subscription access only. An employee is directed to sign-in/invitation acceptance and cannot call the application command.

## 6. Company administrator purchases licence

The verified administrator chooses an active `COMMERCIAL_SAAS` plan, modules and explicit capacity (`FINITE` with a positive limit or `UNLIMITED`). Review shows terms but no tax functions. Real purchasing remains disabled locally; no price or real payment instruction is stored.

## 7. Payment succeeds

An approved provider sends a signed, replay-protected callback matching amount/currency/order and environment. The payment record transitions once and emits an outbox event. Browser claims alone cannot confirm payment. In the current baseline the adapter is disabled and this transition cannot occur.

## 8. Licence activates

After confirmed payment and required review, one database transaction activates the commercial subscription, licence, plan entitlements and capacity, then upgrades the verified administrator's limited session. If any step fails, the transaction rolls back and retries idempotently. Tax authorization is untouched.

## 9. Administrator creates employee

The administrator submits an invitation, organisation scope, commercial role and optional department/branch. The service verifies the same organisation, active commercial licence, licensed modules, inviter authority and seat availability. The database transaction reserves a seat and creates an expiring invitation. Acceptance links the user to the existing organisation.

## 10. Administrator reaches licence limit

When the final finite seat is reserved/activated, usage equals capacity. Existing users continue operating, while the UI and API report zero remaining seats and offer non-destructive deactivation or an approved upgrade.

## 11. Administrator attempts to exceed licence limit

Every concurrent request enters serialized database enforcement. The request that would produce `active + reserved > maximum` is rolled back with `USER_LICENSE_LIMIT_REACHED`; no partial user, invitation or role remains. Direct API calls receive the same result.

## 12. Administrator upgrades licence

The administrator selects a larger active commercial capacity and completes the approved activation process. Only after activation does a transaction change the effective entitlement/version; new seat checks immediately read it. Pending or failed payment never expands capacity.

## 13. Administrator downgrades licence

The administrator requests a smaller capacity. If current use exceeds the target, VAT-MSA activates a `LICENSE_CAPACITY_EXCEPTION`, preserves every user and transaction, blocks additional seat consumption and asks the administrator to deactivate memberships or reverse/upgrade the plan. Resolution is audited when use fits capacity.

## 14. Administrator deactivates employee

The administrator confirms a non-destructive membership transition. Sessions are revoked, new access is denied, one reserved/active seat is released, and historical ownership/audit/financial records retain the user reference. The last required administrator cannot be removed without an approved successor.

## 15. Unlimited licence is activated

The activated plan carries `capacity_mode=UNLIMITED` and `limit_value=NULL`; it is never inferred from a missing or oversized number. Seat transactions still validate membership, role, organisation, security and audit policies, but skip the numeric ceiling.

## 16. Taxpayer authorization is suspended

The authority administrator performs step-up, supplies reason/effective time and transitions the authorization to `SUSPENDED`. New tax decisions fail and active tax sessions are revoked/expire under policy. Commercial business access remains independently evaluated and unchanged.

## 17. Taxpayer authorization is restored

An authorized authority administrator verifies current VAT status and the cause of suspension, then creates an immutable reinstatement decision. Tax access resumes only after the authorization becomes active and other tax checks pass. No commercial subscription is required or altered.

## 18. One company operates as Buyer and Seller

The one canonical organisation receives effective-dated `BUYER` and `SELLER` business capabilities. Users receive scoped roles/workflows under that organisation; no duplicate company, taxpayer, commercial subscription or tax authorization is created. Each feature still follows its correct authority-domain decision.
