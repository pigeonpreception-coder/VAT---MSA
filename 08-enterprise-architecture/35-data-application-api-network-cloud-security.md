# VAT-MSA data, application, API, network and cloud security architecture

## 1. Data classification and handling

The canonical scheme is `PUBLIC`, `INTERNAL`, `CONFIDENTIAL`, `RESTRICTED` and `HIGHLY RESTRICTED`. Classification is attached to data products, records, fields, documents, events, logs, backups, indexes and exports. A derived copy inherits the highest applicable source classification unless an approved transformation demonstrably lowers risk.

| Class | Examples | Minimum handling |
|---|---|---|
| PUBLIC | approved public verification result and published guidance | integrity, availability and publication approval |
| INTERNAL | service inventory, non-sensitive operating procedure | authenticated access, controlled sharing and retention |
| CONFIDENTIAL | ordinary organisation business records and employee administration | tenant/purpose access, encryption, monitored export |
| RESTRICTED | taxpayer identifiers, invoices, VAT returns, bank/payment metadata, audit cases | strong authorization, masking, DLP, enhanced evidence, approved residency |
| HIGHLY RESTRICTED | credentials, private keys, recovery secrets, sensitive risk intelligence, protected government security data | dedicated vault/HSM or isolated store, JIT access, dual control where applicable, never ordinary logs |

The detailed record and retention mapping remains in `data-classification-retention.csv`; statutory periods and lawful disposal require country legal/records approval.

## 2. Data lifecycle controls

1. **Discover and register:** owner, steward, purpose, source, jurisdiction, classification and system of record are known before use.
2. **Collect minimally:** schemas reject unnecessary attributes; optional analytics collection is separately justified.
3. **Process with authority:** every use is purpose and tenant scoped; secondary use requires a recorded compatibility/lawful-basis decision.
4. **Share safely:** recipients, transfer mechanism, processor/third-party obligations and data residency are approved; exports are manifested and expiring.
5. **Retain:** effective-dated schedules, legal holds and evidence prevent premature deletion.
6. **Dispose:** authorized, verifiable deletion or cryptographic erasure occurs across primary, replica, cache, index and eventual backup expiry; fiscal/audit history is never silently rewritten.

Test data is synthetic by default. Production data in lower environments is prohibited unless a documented exceptional approval, minimization/transformation, re-identification risk assessment, owner, expiry and audit controls exist.

## 3. Encryption and cryptographic architecture

### In transit

External and internal protected traffic uses current approved TLS. Service-to-service traffic uses authenticated workload identity and mTLS where supported by the selected platform. Certificate issuance, renewal, revocation, trust-store change and expiry are automated and monitored. Weak protocols/ciphers and certificate bypass are prohibited.

### At rest

Operational databases, object/document stores, event/message persistence, search/analytics, security logs, backups, replicas and offline device stores use approved storage encryption. Encryption boundaries and key ownership align with tenant, environment, country residency and recovery requirements where risk justifies separation.

### Field protection

Tokenization or field encryption is used for credentials (which belong in a vault), bank/payment references, high-risk identifiers or other fields when access patterns and threat analysis justify it. Searchable protection does not weaken authorization or create deterministic correlation without approval.

### Cryptographic integrity

Signed artifacts, country/security profiles, critical receipts, audit chains and evidence manifests use approved algorithms, canonicalization, timestamps and key identifiers. Digital signature does not by itself establish statutory validity; the signing purpose and legal recognition must be approved per jurisdiction.

## 4. Key and secret management

The key hierarchy separates identity/token signing, TLS/mTLS, country/profile signing, fiscal/document signing, data encryption, backup/recovery, audit/evidence integrity and build/release signing. Keys are not reused across incompatible purposes or environments.

| Lifecycle stage | Required control |
|---|---|
| create/import | approved algorithm/size, KMS/HSM generation where required, provenance and owner |
| activate/use | policy-bound workload/JIT identity, purpose restriction, usage logging and quotas |
| rotate | versioned overlap, tested consumer rollout, expiry monitoring and rollback plan |
| revoke/compromise | immediate deny capability, dependency inventory, reissue/re-sign decision and incident workflow |
| backup/recover | quorum-controlled protected copy only where needed; restoration exercise and custody evidence |
| destroy | authorized verifiable destruction after retention/legal constraints |

Secrets come only from the centralized secret manager through workload or bounded administrator identity. Source code, images, configuration repositories, CI logs, support bundles and environment dumps must not contain durable secrets. Secret scanning runs before commit and in CI; discovered secrets are rotated, not merely deleted from the latest revision.

## 5. Multi-tenant data security

Tenant isolation is enforced independently at request, domain, database, object path, cache key, queue/partition, search document, analytics row, log view, backup/restore and support tooling layers.

- Tenant is derived from trusted membership/resource ownership, not accepted from an unverified request header.
- Composite keys and foreign keys include tenant/organisation scope where appropriate.
- Database roles and/or row-level security provide a second line of defense; elevated bypass roles are JIT and monitored.
- Cache, idempotency, rate-limit and object keys are namespaced and tested for collision.
- Events carry minimal tenant routing metadata and consumers re-authorize sensitive actions.
- Search and analytics indexes store authorization attributes and filter before aggregation; counts/facets must not leak hidden records.
- Backup restore is environment/tenant authorized and tested against cross-tenant disclosure.

A cross-tenant access finding is release-blocking and treated as a potential critical incident.

## 6. Secure application architecture

OWASP ASVS 5.0.0 is the verifiable baseline; OWASP Top 10:2025 is an awareness/risk taxonomy, not a claim of complete coverage.

| Risk area | Required design |
|---|---|
| injection | parameterized data access, contextual encoding, typed commands/templates, no dynamic evaluation of tenant rules |
| access control/IDOR | server-side action and resource authorization on every path, deny by default, bulk/search tests |
| authentication/session | architecture in document 34; secure cookies, CSRF, fixation, logout/revocation and reauthentication |
| SSRF/egress | no arbitrary server fetch; scheme/host/IP resolution policy, egress proxy, redirect and metadata-service controls |
| file upload | quarantine, type/content/size validation, malware/CDR where justified, random object ID, non-executable serving |
| deserialization/integrity | schema-bound formats, safe parsers, signed trusted artifacts and no untrusted object instantiation |
| XSS/content | framework-safe rendering, contextual encoding, CSP, trusted template pipeline and sanitization where HTML is allowed |
| exceptional conditions | safe failure, bounded retry, idempotency, no fail-open authorization, generic client errors and detailed protected telemetry |
| business abuse | state-machine invariants, amount/velocity/resource limits, SoD, replay/duplicate controls and reconciliation |

Security functions are centralized as reviewed libraries/services where consistency matters, but each domain remains responsible for business authorization and invariants. Client-side hiding is usability only, never enforcement.

## 7. API security architecture

All APIs, including internal and administrative APIs, require inventory, owner, classification, authentication model, authorization, schemas, version/support period, resource budgets, telemetry and decommission date.

Request path:

`edge protection -> gateway authentication/route budget -> BFF/API authorization -> schema and business validation -> domain invariant/transaction -> audit/outbox -> minimized response`

Mandatory controls include:

- object/function/property-level authorization and safe field projection;
- strict request/response schema, content type, byte/depth/array/page/query/time budgets;
- tenant, client, user, route and expensive-business-flow quotas;
- idempotency keys and durable duplicate outcomes for retryable financial writes;
- nonce/timestamp/request signing for interfaces whose threat/contract requires it;
- replay cache and canonical payload/hash where signing applies;
- outbound allowlist, timeout, size limit, redirect policy and validation for consumed APIs;
- versioned OpenAPI/AsyncAPI contracts and compatibility tests;
- safe problem details without secrets, hidden resources or internal topology;
- correlation IDs that are non-secret and validated/generated at trust boundaries.

Government adapters use independent environment credentials, mTLS/private-key authentication where contracted, message integrity, non-repudiation only where legally/technically agreed, strict schema validation, durable request/response evidence, idempotent reconciliation and a kill switch. Ordinary users never receive government credentials.

## 8. Tax invoice and fiscal-record security

Invoice identifiers and source idempotency are unique within their approved authority. Certification creates immutable content and references the seller/buyer identities, jurisdiction, country pack, tax rules, money/currency, schema, source, timestamps and integrity evidence. Changes create linked reversal, credit/debit, adjustment or replacement records; updates/deletes of certified history are prohibited.

QR codes or public verification disclose only an approved minimum and use unguessable/tamper-resistant references. Digital signatures and verification formats are enabled only after legal, cryptographic and interoperability approval. Duplicate, replay, content-change-under-same-ID and out-of-sequence activity is detected and reconciled.

## 9. Network and edge architecture

See `diagrams/defence-in-depth-security-zones.mmd`.

- authoritative DNS and certificate automation are protected as Tier 0 dependencies;
- DDoS scrubbing/CDN/WAF/bot management precede origin access;
- origin accepts only approved edge/private paths and does not expose databases, queues, admin or observability endpoints;
- management traffic uses a separate identity-aware path, managed endpoints and JIT authorization;
- application, integration, data, analytics, security, management and recovery zones have deny-by-default ingress/egress;
- service/workload identity and application authorization remain mandatory despite network segmentation;
- egress is inventoried and controlled to reduce SSRF, exfiltration and supply-chain callbacks;
- network policy changes are versioned, reviewed, tested and logged.

Geo/IP reputation can influence risk or abuse controls only when lawful and cannot be the sole identity or statutory-access decision.

## 10. Cloud and workload security

Cloud selection is provider-neutral until approved. The target control contract requires separate accounts/projects/subscriptions and identity tenants per environment; organization-level guardrails; private data services; centralized cloud audit; posture/configuration monitoring; approved regions; KMS/HSM and vault; signed immutable artifacts; and recoverable infrastructure definitions.

Workloads run non-root and least-privileged, use read-only filesystems where feasible, explicit capabilities, resource limits, hardened minimal images, network policy, workload identity, protected metadata access, runtime detection and rapid replacement. Admission rejects unsigned/untrusted artifacts, critical policy failures, disallowed privilege and unapproved registries.

Cloud console access is privileged PAM access. Provider support access is contractually bounded, time-limited, approved and evidenced. Shared-responsibility duties are mapped per service; a managed-service label never transfers VAT-MSA accountability.

## 11. Database, object, event, search and analytics controls

| Store | Mandatory controls |
|---|---|
| relational/ledger | private endpoint, workload roles, tenant isolation, prepared queries, migration approval, activity/integrity monitoring and PITR |
| object/document | quarantine, malware result, immutable version/retention where required, signed access URL with short expiry, classification metadata |
| event/queue | authenticated producer/consumer, schema registry, tenant partition policy, minimal payload, replay/idempotency and dead-letter governance |
| cache | no authority source, namespaced keys, bounded TTL, encrypted transport, invalidation on urgent policy change |
| search | authorization attributes, pre-query filtering, masked snippets, inference-resistant facets and deletion/retention propagation |
| analytics | approved lineage, minimization/pseudonymization, workload isolation, purpose-based views, DLP/export approval and query audit |
| logs/security lake | schema/redaction, restricted access, time sync, integrity/retention, query and export monitoring |

Ordinary users and developers have no direct unrestricted production database access.

## 12. DLP and exfiltration controls

DLP combines classification metadata, content/identifier detection, volume/velocity, destination trust, actor purpose, device posture, export approval and canary indicators. Response is proportional and preserves evidence: warn/challenge, mask, quarantine an export, revoke one token, or open an incident. Broad suspension or irreversible deletion requires human authority.

Approved exports contain an evidence manifest, requester/purpose, applied filters, record count, classification, watermark where appropriate, expiration and recipient obligations. Public error messages and analytics never expose hidden risk signals.

## 13. Acceptance evidence

Required evidence includes threat-model coverage; ASVS traceability; API inventory/contracts; tenant-isolation tests at every listed layer; secret/key rotation and compromise drill; TLS/certificate posture; database/object/event/search authorization tests; upload malware/quarantine tests; SSRF/egress tests; WAF/bot/DDoS validation; cloud/IaC/container/admission scans; data-flow/classification review; DLP/export abuse tests; signed-artifact verification; and independent application/API/cloud penetration tests.
