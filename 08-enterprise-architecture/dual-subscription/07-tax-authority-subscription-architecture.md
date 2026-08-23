# Tax Authority Subscription Architecture

**Sequence:** 07 of 29

## Ownership

Each jurisdiction resolves to exactly one active Tax Governing Authority configuration for a given tax programme and time. Namibia uses a configurable NamRA authority record; the core contains no NamRA-only branching.

## Process

`authority administrator identity -> authority account -> tax plan -> jurisdiction -> environment configuration -> readiness review -> subscription activation -> taxpayer authorization`

Activation requires:

- verified authority administrator appointment and step-up;
- approved country/jurisdiction and signed country pack;
- tax plan classified `GOVERNMENT_TAX`;
- security/privacy/residency readiness;
- adapter configuration and conformance posture;
- maker/checker approval with no self-approval;
- immutable authority evidence.

Local/staging uses `LOCAL_SYNTHETIC_AUTHORITY` and cannot become production evidence. There is no real purchase or commercial payment requirement for taxpayer access.

## Separation

Authority administrators can manage government users, taxpayer authorizations, tax feature grants and tax workflows within their jurisdiction. They cannot change company employees, commercial plans, payments, internal accounting or organisation administrator ownership unless separately invited under a distinct identity membership.
