# Commercial SaaS Licensing Architecture

**Sequence:** 08 of 29

## Purchaser and scope

Only the verified Company System Administrator may initiate, review or activate a commercial subscription for the organisation. A commercial plan contains only `COMMERCIAL_SAAS` features. Placeholder plans have no prices in the local/staging baseline.

## Flow

`administrator application -> identity proof -> organisation verification -> commercial plan -> FINITE/UNLIMITED capacity -> terms review -> payment pending -> approved provider confirmation -> licence active -> administrator provisioned -> employee invitations`

Payment confirmation is accepted only from an approved provider adapter or independently authorized back-office command. Client-supplied success flags are ignored. No card data is stored. The current local/staging implementation stops before payment confirmation and licence activation.

## Capacity

Capacity mode is explicit:

- `FINITE`: positive integer `limit_value`; active plus invited seat-consuming employees cannot exceed it.
- `UNLIMITED`: no numeric ceiling, but identity, tenant, role, workflow and security controls remain.
- `NOT_APPLICABLE`: feature does not represent seats.

Invitations reserve a seat atomically. Termination/deactivation releases it without deleting history. Upgrade becomes effective only after approved activation evidence. Downgrade below consumption creates a non-destructive capacity exception and blocks new seats.
