# Dual Signup Architecture

**Sequence:** 05 of 29

## Landing decision

The public landing presents three non-interchangeable actions:

1. **Tax Authority / Taxpayer — Access Tax Services**
   - sign in through the configured Tax Authority;
   - sign in through ITAS where the Namibia adapter is approved;
   - use linked VAT-MSA identity and await authority verification.
2. **Company SaaS — Company Administrator: Start Subscription**
   - restricted to a person attesting and later proving Company System Administrator authority;
   - creates a commercial onboarding application only;
   - no business workspace before approved activation.
3. **Employee — Sign in or Accept Invitation**
   - cannot create an organisation, plan request or subscription;
   - requires a valid organisation invitation and available seat.

## Server rules

- The selected path is server-derived and stored as `GOVERNMENT_TAX_ACCESS`, `COMMERCIAL_SUBSCRIPTION` or `EMPLOYEE_INVITATION`.
- Company signup requires `company_system_administrator_attested=true`; the API rejects ordinary-employee intent.
- Tax access never accepts plan, price or payment input.
- Commercial signup never accepts tax role, VAT-status, tax-authorization or government-authority fields.
- A workspace identity assertion is evidence only. It does not provision access.
- All public paths use strict payload allowlists, bounded JSON, idempotency, rate budgets, duplicate controls and minimized security events.

## Pre-subscription scope

An onboarding subject may edit only its pending application, identity evidence, organisation-verification data, plan/capacity request and approved payment handoff. It cannot access accounting, employee creation, tax records or licensed workspace APIs.

## Activation gates

Tax access activates only through the Government Tax Authorization Service. Commercial access activates only after approved payment evidence, verified organisation and administrator identity, active commercial plan, capacity configuration and controlled administrator provisioning.
