# Data security, privacy, key and secrets architecture

Classify taxpayer identity, invoices, returns and credentials as restricted; audit/security evidence as restricted-integrity; public verification output as intentionally public/minimal. Collect only necessary data and bind use to tax purpose, tenant and approved role. Retention, residency, disclosure and subject-right procedures require explicit Namibian legal/tax/privacy validation before production.

All external and service traffic uses modern TLS; high-trust machines and service mesh use mTLS. Platform storage, snapshots, queue, object storage and logs are encrypted at rest. Envelope encryption uses centrally managed KMS/HSM keys with separate keys by environment and data class. Key policy separates use, administration and audit; rotation, revocation and recovery are exercised. Sensitive searchable fields use approved tokenization/field protection where threat analysis justifies it.

Production secrets are never stored in source, images, logs or ordinary CI variables. Workloads receive short-lived identities or runtime-injected versioned secrets from a dedicated manager. Access is least-privilege, audited and alertable; emergency retrieval is dual-controlled. Rotation procedures cover database credentials, signing keys, API/mTLS identities, backup keys and incident compromise.

Data loss prevention monitors bulk lookup/export, unusual API extraction, administrative reports, database activity and file transfer. Exports require purpose, bounded scope, watermark/manifest where appropriate, approval and expiry. Logs use identifiers or irreversible source tokens instead of raw network/personal data. Non-production uses generated or irreversibly de-identified data.

Audit evidence is appended to a separately administered tamper-evident store with retention lock, integrity chaining/signature and legal hold. The operational database copy supports product views but is not the sole authoritative evidence. Restore and evidence-verification drills prove readability, completeness and integrity.
