# Offline and synchronization architecture

Offline operation never creates, purchases, activates, upgrades or downgrades a subscription; provisions administrators/users; consumes a finite seat; changes plan catalogues; or authorizes/suspends/reinstates a taxpayer. Those commands require online authoritative transactions.

An approved offline client may hold encrypted, minimum, read-only snapshots for explicitly supported workflows. Each item carries tenant/taxpayer/jurisdiction, authority domain, record version, decision/evidence version, issued/expiry times and device binding. Government and commercial snapshots use separate cryptographic/scoping contexts.

Queued offline business drafts are not authoritative transactions. On synchronization the server authenticates the device/user, checks replay/idempotency, re-evaluates the current domain-specific entitlement, verifies record versions and applies an approved merge rule. Financial/statutory conflicts become corrections or review items; last-write-wins cannot rewrite immutable evidence.

Expired, revoked, suspended or mismatched authority evidence causes quarantine and online revalidation. Remote wipe, device revocation, encryption, local attempt audit and minimum retention are required. Offline government tax functionality remains disabled until the Tax Governing Authority and legal/security owners approve the exact operations, data classes, TTLs and incident procedures.
