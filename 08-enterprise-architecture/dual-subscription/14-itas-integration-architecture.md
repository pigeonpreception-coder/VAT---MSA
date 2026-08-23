# ITAS integration architecture

## Status and boundary

The Namibia adapter is a disabled reference design. It does not assert an ITAS protocol, endpoint, claim, legal basis or production readiness. Live exchange remains blocked until NamRA supplies an approved federation and taxpayer-verification contract, security keys, environments, data-minimisation rules and acceptance evidence.

## Identity-linking flow

1. A taxpayer chooses **Sign in through ITAS**.
2. VAT-MSA resolves Namibia's enabled authority adapter and creates a short-lived, single-use, PKCE-bound transaction.
3. The browser is redirected only to an allow-listed ITAS issuer.
4. The callback verifies issuer, audience, signature, nonce, state, time claims, assurance level and replay state.
5. The adapter maps the approved opaque subject and verified taxpayer identifiers to an existing canonical identity link.
6. If no safe deterministic match exists, access enters `IDENTITY_LINK_REVIEW`; VAT-MSA does not create a second taxpayer automatically.
7. The Government Tax Authorization Service independently verifies the active NamRA subscription, taxpayer authorization, VAT status, tax feature and user scope.
8. Only a tax-scoped session is issued. Commercial features remain subject to the commercial licence path.

## Required contract before activation

- OIDC/SAML/API protocol and exact issuer/audience/redirect URIs.
- Authoritative claim dictionary and assurance levels.
- Key discovery, rotation, revocation and compromise procedure.
- Taxpayer identifier matching and contested-link process.
- Consent, privacy, retention and disclosure rules.
- Availability, timeout, retry and status-notification semantics.
- Test tenant, synthetic credentials and signed security acceptance.

## Failure posture

When ITAS is unavailable or disabled, VAT-MSA returns the stable safe code `ITAS_INTEGRATION_DISABLED`, fails closed for new federation and must not treat cached commercial identity as government authorization. Existing short-lived tax sessions follow an approved continuity TTL; expired authority evidence cannot be silently extended. Direct VAT-MSA tax access is offered only if NamRA separately approves that path and its equivalent identity assurance.
