# VAT-MSA dual subscription and self-service onboarding package

This folder is the ordered architecture gate for strict separation between Government Tax Authority subscriptions/taxpayer authorization and Company Commercial SaaS subscriptions/internal users. Review artefacts `01` through `29` in numeric order.

The package is approved only as the local/staging implementation baseline: synthetic data, configurable plans without prices, payment disabled, live ITAS disabled, production tax activation disabled and unapproved statutory rules disabled. It neither asserts national-scale proof nor authorizes production operation.

The normative decision is [ADR-030](../adr/ADR-030-dual-subscription-authority-separation.md). Automated package completeness and cross-domain invariants are checked before implementation/release.
