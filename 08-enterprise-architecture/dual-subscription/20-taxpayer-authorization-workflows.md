# Government taxpayer authorization workflows

## Provision

Only an appointed Tax Authority Administrator for the same jurisdiction can provision access. The command requires step-up, an active government tax subscription, existing canonical organisation/taxpayer, verified active VAT registration, enabled government-tax features, reason and effective dates. The database constrains the authorization to the authority subscription and rejects commercial actors/features.

## Evaluate

For every tax page, API, search, export or command, the Government Tax Authorization Service checks:

`authority subscription -> jurisdiction -> taxpayer authorization -> VAT status -> feature enabled -> user identity link -> tax role -> taxpayer/office scope -> session assurance -> resource/workflow policy`

The service returns a short-lived evidence-bound decision. A commercial licence is neither queried nor accepted as a substitute.

## Suspend, revoke and reinstate

- `SUSPENDED` is reversible after an immutable authority decision and current verification.
- `REVOKED` requires a new authorization rather than mutation of the old decision.
- Effective dates prevent backdating that would rewrite historical truth.
- Session revocation/short expiry propagates access loss.
- These transitions never disable unrelated commercial business access.

## Authority subscription lapse

New tax mutations fail closed unless an explicitly approved government continuity rule applies. Statutory evidence remains readable/exportable only under legal retention and authorized scope. The system never asks taxpayers to buy a commercial plan to restore government tax access.

## Identity mismatch

Conflicting ITAS/direct identifiers enter a human-reviewed `IDENTITY_LINK_REVIEW`. No duplicate taxpayer is created; neither identity is granted tax access until the authoritative link is resolved and audited.
