# Licence and Entitlement Architecture

**Sequence:** 09 of 29

The authoritative engine exposes:

- `IsCommercialLicenceActive`
- `IsCommercialModuleLicensed`
- `IsCapacityAvailable`
- `IsUnlimitedCapacity`
- `CanInviteEmployee`
- `IsTaxAuthoritySubscriptionActive`
- `IsTaxpayerAuthorized`
- `IsVatRegistrationActive`
- `CanAccessTaxFeature`

## Decision routing

1. Resolve permission policy and feature.
2. Read immutable `authority_domain`.
3. For `GOVERNMENT_TAX`, evaluate tax subscription, jurisdiction, taxpayer authorization, VAT status and tax feature grant. Never consult a commercial plan as a grant.
4. For `COMMERCIAL_SAAS`, evaluate organisation licence, module entitlement, state continuity and capacity. Never consult authority authorization as a grant.
5. For `PLATFORM_CONTROL`, require platform workforce/PAM policy; neither subscription can grant it.
6. Apply record scope, workflow, SoD, session assurance, security context and obligations.
7. Emit stable allow/deny code and minimized evidence.

Database constraints bind plan type to feature authority domain. Unknown plans, features, policies, authority states or missing tenant/jurisdiction context fail closed. Frontend visibility is advisory; every API and command repeats the server decision.

## Explicit capacity contract

Every entitlement records one capacity mode. `FINITE` requires a positive numeric limit and uses transactional usage enforcement. `UNLIMITED` requires a null numeric limit and skips only the numeric ceiling. `NOT_APPLICABLE` requires a null numeric limit and is used when the feature has no capacity measure. A missing, null or very large number never implicitly means unlimited.
