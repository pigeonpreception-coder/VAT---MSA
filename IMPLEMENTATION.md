# VAT-MSA Operational Pilot

This repository now contains an executable operational pilot of the VAT-MSA platform. It implements the first controlled vertical slice from fiscal-document submission to certification, VAT sub-ledger posting, reconciliation, return aggregation and audit evidence.

## Implemented capabilities

- Role-aware operations dashboard and protected application routes.
- Canonical taxpayer registry with VAT-number and TIN identities.
- Electronic invoice submission through the web portal and `POST /api/v1/invoices`.
- Exact integer-cent VAT calculation, document-total validation and duplicate controls.
- Idempotent submission handling: an exact retry returns the original outcome; a changed payload is rejected.
- Pilot certificate issuance with a public privacy-minimised verification route.
- Linked seller output VAT and registered-buyer input VAT sub-ledger entries.
- Configured risk classification and reconciliation exception creation.
- Transaction-derived VAT period summaries.
- Hash-chained, append-only business audit events.
- D1/SQLite schema, generated migration, indexes and synthetic pilot data.
- Responsive, keyboard-accessible operational interface.
- Taxpayer-scoped repository queries and server-side resource authorization.
- Bounded streaming JSON ingestion and multi-level actor, device, source, tenant and global rate controls.
- Correlated structured security events, incident records and a Security Operations view.
- Transactional outbox records for reliable asynchronous processing hand-off.
- Liveness/readiness endpoints and consistent edge security/cache headers.
- Strict release gate: lint, TypeScript, unit/security tests, secret scan, CycloneDX SBOM and production build.
- A complete security, hyperscale, HA/DR, observability and incident architecture pack under `07-security-resilience/`.

## Local operation

The project uses the pinned pnpm lockfile.

```powershell
pnpm install
pnpm dev
```

The local runtime creates the pilot schema idempotently and seeds synthetic taxpayers and fiscal records on first use. Development mode uses the explicitly labelled `PILOT_ADMIN` local identity. Production mode never enables that fallback.

Run the complete hardened release gate with:

```powershell
pnpm security:ci
```

## Identity and authorisation

Production access expects the authenticated identity headers supplied by the Sites/ChatGPT sign-in dispatcher. The identity must also exist as an active row in `app_users`; an authenticated but unprovisioned user is denied. Server-side permission checks protect both pages and API handlers.

The pilot role exists only for local development. Production roles must be provisioned from the approved RBAC matrix and constrained to their taxpayer, portfolio or case scope before operational use.

## Database and integrity model

The platform uses D1-compatible SQLite for the operational pilot. Monetary values are stored as integer cents. Fiscal submission uses one D1 batch to commit the invoice, lines, certificate, seller and buyer ledger entries, period aggregation, exception, idempotency outcome and audit evidence together.

The generated migration is stored in `drizzle/`. The runtime initializer is an onboarding convenience for the local pilot; controlled hosted environments should apply reviewed migrations through the deployment pipeline.

The pilot additionally commits an outbox event in the same transaction as each accepted invoice. A production relay and idempotent queue consumers must publish and process these records; the local pilot deliberately exposes pending/published outbox posture rather than pretending that a national message platform exists locally.

## Production gates

This is working application software, but it is not yet authorised for statutory or national production use. Before production, NamRA must approve or supply:

- the legal invoice conformance pack and official VAT-return mapping;
- enterprise IAM, MFA, user provisioning and portfolio/case scoping;
- approved certificate signature format and HSM-backed non-exportable keys;
- ITAS and partner integration credentials and conformance environments;
- hosting, residency, encryption, retention, monitoring and disaster-recovery controls;
- penetration, performance, resilience, accessibility and operational acceptance evidence.

The current `DEV-SHA256` certificate profile is deliberately labelled and must never be represented as a production legal signature.

The national-scale planning targets, proposed SLO/RTO/RPO values and Kubernetes manifests are testable reference hypotheses, not achieved capacity claims. Production acceptance requires the objective evidence listed in `07-security-resilience/10-production-acceptance-gates.md`.
