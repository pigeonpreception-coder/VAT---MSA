# Dual-subscription sequence diagrams

## Commercial application and gated activation

```mermaid
sequenceDiagram
  actor A as Company System Administrator
  participant UI as Commercial Signup UI
  participant ID as Identity/Organisation Verification
  participant L as Licence & Entitlement Service
  participant P as Payment Adapter
  participant DB as Transactional Store
  participant AU as Audit
  A->>UI: attest authority and submit organisation
  UI->>ID: create pending application (idempotency key)
  ID->>DB: verify no conflicting identity/organisation
  DB-->>ID: PENDING_VERIFICATION
  ID-->>UI: limited pre-subscription session
  A->>UI: select commercial plan and capacity
  UI->>L: validate COMMERCIAL_SAAS plan
  L-->>UI: reviewable selection
  UI->>P: request payment
  alt local/staging baseline
    P-->>UI: PAYMENT_DISABLED
  else approved provider and callback verified
    P->>L: immutable payment confirmation
    L->>DB: atomically activate subscription, licence and capacity
    DB->>AU: COMMERCIAL_LICENSE_ACTIVATED
    L-->>UI: administrator access enabled
  end
```

## Taxpayer federation and independent tax decision

```mermaid
sequenceDiagram
  actor T as Taxpayer User
  participant UI as Tax Access UI
  participant A as Country Authority Adapter
  participant I as ITAS / Authority IdP
  participant ID as Global Identity Linker
  participant G as Government Tax Authorization Service
  participant DB as Tax Authorization Store
  T->>UI: Sign in through authority
  UI->>A: start state+nonce+PKCE transaction
  A->>I: approved federation redirect
  I-->>A: signed assertion/callback
  A->>A: verify signature, issuer, audience, nonce, time, replay
  A->>ID: resolve opaque subject to canonical identity
  alt ambiguous or absent safe match
    ID-->>UI: IDENTITY_LINK_REVIEW
  else canonical link resolved
    ID->>G: evaluate tax feature and scope
    G->>DB: active authority subscription + taxpayer authorization + VAT status
    DB-->>G: evidence and expiry
    G-->>UI: tax-scoped allow/deny
  end
```

## Concurrent finite-seat enforcement

```mermaid
sequenceDiagram
  actor A as Administrator A
  actor B as Administrator B
  participant API as User Provisioning API
  participant L as Licence & Entitlement Service
  participant DB as Database Transaction
  par request seat 100
    A->>API: invite employee (idempotency A)
  and competing request
    B->>API: invite employee (idempotency B)
  end
  API->>L: CanCreateUser?
  L->>DB: begin serialized seat transaction
  DB->>DB: count ACTIVE + reserved INVITED seats
  alt one seat remains
    DB->>DB: create one invitation and consume seat
    DB-->>L: success
    DB-->>L: USER_LICENSE_LIMIT_REACHED for competing transaction
  else unlimited capacity
    DB->>DB: create both invitations; no numeric ceiling
  end
  L-->>API: stable decision codes
```

## Tax suspension leaves commercial access unchanged

```mermaid
sequenceDiagram
  actor TA as Tax Authority Administrator
  participant G as Government Tax Authorization Service
  participant DB as Tax Store
  participant C as Commercial Licence Service
  participant AU as Audit
  TA->>G: suspend taxpayer authorization + reason + step-up
  G->>DB: transition ACTIVE to SUSPENDED
  DB->>AU: TAXPAYER_AUTHORIZATION_SUSPENDED
  G-->>TA: completed
  Note over C: No commercial subscription or user membership is mutated
```

## Downgrade below active use

```mermaid
sequenceDiagram
  actor A as Company System Administrator
  participant L as Licence & Entitlement Service
  participant DB as Commercial Store
  participant AU as Audit
  A->>L: request capacity downgrade 100 to 50
  L->>DB: compare active/reserved seats (87) with target (50)
  DB->>DB: activate target entitlement and create CAPACITY_EXCEPTION
  DB->>AU: LICENSE_CAPACITY_EXCEPTION_OPENED
  L-->>A: mutation/new-user provisioning restricted; no users deleted
  A->>DB: deactivate memberships non-destructively
  DB->>DB: release seats while preserving history
  DB-->>L: active use <= 50
  L->>DB: resolve capacity exception
```
