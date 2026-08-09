# Deliverables 14-16 — VAT transaction, return and offline architecture

## Seller-to-buyer success sequence

See `diagrams/vat-transaction-sequence.mmd`.

1. Seller/user or machine is authenticated; organisation, Seller capability, branch and permission resolve.
2. Gateway enforces schema/byte/quota/replay/idempotency and correlation.
3. Invoice service resolves seller VAT/TIN/company and buyer identifiers against approved freshness.
4. Number/reservation is validated; effective VAT rule version and period are selected.
5. Exact line/tax/total validation completes; approval state is checked.
6. Certification creates immutable invoice/transaction/certificate identities using approved HSM profile.
7. One authoritative transaction commits invoice, items, seller output VAT, registered buyer input candidate, idempotency outcome, audit and outbox.
8. API returns durable receipt; event consumers perform matching, notification, analytics and external synchronization.
9. Reconciliation evaluates invoice ↔ seller/buyer ↔ ledger ↔ return ↔ NamRA and creates exceptions.
10. Eligible postings accumulate into the governed period/return snapshot.

## Exception paths

| Scenario | System action | User/integration result | Recovery/audit |
|---|---|---|---|
| duplicate exact retry | return original durable receipt | success/idempotent | no new transaction; retry evidence |
| key reused with changed payload | reject 409 | conflict and original reference | security/business event; new key only after correction |
| invalid/inactive seller VAT | do not certify/post | 422 or dependency state | registration/ITAS verification case |
| unidentified buyer | post seller output only | certified with generic-buyer status | no input claim; buyer cannot be attached silently later |
| invalid amount/rule | reject before commit | field-level 422 | correction and resubmit; failed validation telemetry |
| network failure after submit | client repeats same key | original receipt or retryable status | reconcile by source/idempotency/correlation |
| database commit failure | no success and no partial posting | retryable 503 with same key | transaction rollback, alert/SLO incident |
| queue unavailable | commit invoice+pending outbox if authoritative DB healthy | receipt succeeds; async delayed | relay retry/backpressure/oldest-age alert |
| cancellation | approved linked cancellation/reversal | state becomes cancelled/reversed | original remains; reversing VAT entries and events |
| credit/debit note | validate original, reason, approval and rule | new linked fiscal document/transaction | period/return adjustment and full lineage |
| synchronization conflict | quarantine provisional work item | conflict task, not statutory success | user/official resolution; device/source audit |

## VAT return lifecycle

1. ITAS-authorized configuration opens period with from/to/close/due dates.
2. Certified VAT transactions allocate by approved rule and date.
3. Input candidates progress Recorded → Matched → Validated → Eligible → Claimed or Rejected/Under Review.
4. Output VAT accumulates from seller/generic-buyer transactions.
5. Approved credit/debit/reversal/adjustment entries append with lineage.
6. Reconciliation compares seller/buyer/invoice/transaction/ledger/return and external receipts.
7. Unresolved exceptions are visible and may block/qualify generation/submission by approved rule.
8. Return generator creates immutable, reproducible snapshot with rule/form versions and source hashes.
9. Taxpayer reviews totals, evidence, exceptions and approval responsibilities.
10. Authorized submitter sends idempotent submission; VAT-MSA records its receipt attempt, not invented NamRA acceptance.
11. ITAS/NamRA adapter reconciles official receipt/status/rejection.
12. System presents payable/refund candidate; refund remains NamRA review/approval/payment workflow.
13. Authorized close freezes snapshot; later changes enter next/adjustment process according to approved rules.
14. Next period opens from authoritative cycle.

## Offline desktop architecture

See `diagrams/offline-sync.mmd`.

The offline client contains UI/application modules, encrypted local relational store, OS-keystore-bound device key, append-only signed event log, local rule/reference cache with expiry, sequence reservations, sync queue and conflict task view. It is a registered machine/device identity and a hostile endpoint from the server's perspective.

Offline authentication uses previously provisioned, time-bounded local unlock and device/user key; no long-lived production bearer token. Sensitive local fields are encrypted; database and events are integrity-protected; keys can be revoked. Rules/registrations outside freshness cannot produce “certified” state.

### Sequence and legal recognition

Server issues signed bounded ranges by organisation+branch+series+period+device with expiry. Client never invents ranges. Offline invoices are `PROVISIONAL_OFFLINE` and include reservation, local ID, event-chain hash and source time. Upon connectivity, server verifies device/user, signatures/hash chain, reservation, schema, identifiers, current/allowed rule version, idempotency and conflicts. Only a successful server commit/certification returns VAT-MSA statutory IDs/status. Rejected/quarantined work remains visible and auditable.

### Sync and recovery

Incremental cursor uploads commands/events in dependency order. Server response maps local↔server IDs and authoritative version/checkpoint. Automatic merge is limited to safe draft metadata; fiscal amount, number, party, approval, certified state and ledger conflicts require controlled resolution. Retries use same command ID. Tamper, duplicate reservation or impossible chain quarantines device/batch and alerts security. Device loss triggers revocation; encrypted backup/recovery policy requires approval and never bypasses server controls.
