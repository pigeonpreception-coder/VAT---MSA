# Global tax authority adapter architecture

## Stable port

Country integrations implement `TaxAuthorityAdapter` without leaking country-specific concepts into the core domain:

```text
discoverAccessOptions(country)
startFederation(transaction)
validateFederationCallback(response)
resolveCanonicalTaxpayer(externalSubject, verifiedAttributes)
verifyVatRegistration(taxpayer, jurisdiction)
fetchAuthorizationStatus(taxpayer, feature)
receiveSignedStatusEvent(event)
healthAndKeyStatus()
```

Every response carries `authority_id`, `jurisdiction_id`, `adapter_version`, `evidence_id`, `observed_at`, `expires_at`, `assurance_level` and a non-secret correlation ID.

## Adapter lifecycle

`DRAFT -> CONTRACT_REVIEW -> SECURITY_TEST -> AUTHORITY_ACCEPTANCE -> ENABLED -> SUSPENDED -> RETIRED`

Only `ENABLED` adapters may receive user traffic. Lifecycle changes require platform catalogue approval, the named Tax Governing Authority's approval, step-up authentication, separation of duties and an immutable audit event. Commercial administrators cannot change adapter state.

## Isolation and global deployment

- One adapter configuration is bound to one authority and one or more explicit jurisdictions.
- Credentials are isolated per country/environment in an approved secret store.
- Each adapter runs with outbound destination allow-lists and minimum scopes.
- Country mapping packages are versioned, signed and effective-dated.
- Unsupported or ambiguous claims fail closed into review.
- Authority events are idempotent and protect against replay and reordering.
- The core taxpayer identity remains canonical; adapters create identity links, not duplicate taxpayers.

The Namibia `ITAS` adapter stays `DRAFT/DISABLED` in local and staging until the external contract is approved.
