# Business Process Catalog

Each process below is an architectural flow. Detailed BPMN, statutory timing and officer authorities require business/NamRA validation. All fiscal and privileged steps emit audit evidence; notifications are consequences, never the source of truth.

| ID | Process | Trigger and logical flow | Exceptions, controls and completion evidence |
|---|---|---|---|
| BP-01 | taxpayer registration | start -> capture minimum identity -> verify ITAS/legal source -> create one organisation -> assign owner -> accept terms/consents -> activate | duplicate/failed verification enters review; evidence: identity reference, approvals, organisation ID |
| BP-02 | standalone account | approved need -> identity proof -> create account -> MFA enrolment -> recovery codes -> activate | high-risk proof escalates; federation later links immutable subject without duplicating taxpayer |
| BP-03 | federated sign-in | redirect ITAS -> validate issuer/signature/nonce/state -> map subject -> policy decision -> session | unavailable ITAS invokes approved continuity mode; token and mapping evidence retained |
| BP-04 | user invitation | owner selects role/scope -> invite -> recipient verifies identity -> policy checks conflicts -> activate membership | expired/revoked invite cannot be replayed; maker-checker for privileged roles |
| BP-05 | role/access change | authorized request -> SoD/ABAC evaluation -> approval -> effective-dated grant -> notify/review | emergency access time-bound; prior sessions/tokens revoked where required |
| BP-06 | consent/delegation | taxpayer defines delegate, purpose, scope and period -> delegate accepts -> grant activated -> usage logged | revoke immediately; downstream caches receive revocation event; expired scope denies |
| BP-07 | branch setup | organisation proposes branch -> verify code/address -> approve invoice series/stock scopes -> activate | duplicate code or unsupported jurisdiction blocked; branch closure preserves history |
| BP-08 | quotation-to-invoice | draft quotation -> approve/send -> customer accepts -> snapshot lines -> issue invoice command | changed price/tax after acceptance requires new version; conversion is idempotent |
| BP-09 | tax invoice issue | validate seller/buyer/context -> allocate series -> evaluate tax rules -> certify -> persist invoice/VAT transaction -> publish event | rule/numbering/identity failure rejects before certification; immutable receipt returned |
| BP-10 | invoice correction/cancellation | reference original -> select lawful reason -> approvals -> issue credit/debit/correction record -> adjust VAT ledger | no in-place edit; closed/filed period follows adjustment/objection policy |
| BP-11 | invoice verification | scan/enter verification token -> resolve certified record -> show minimal authenticity/status | enumeration/rate abuse blocked; personal lines masked unless authorized |
| BP-12 | supplier input capture | receive certified invoice or upload/import -> identify buyer -> match supplier record -> validate evidence -> post candidate input VAT | duplicate, mismatch or invalid supplier quarantined; no automatic claim until eligible |
| BP-13 | offline invoice | authenticate device -> reserve signed range/policy -> create encrypted signed invoice locally -> queue sync | expiry/range/conflict/tamper quarantines item; local ledger and custody retained |
| BP-14 | offline synchronization | reconnect -> mutually authenticate -> upload ordered idempotent commands -> server validates -> return signed receipts/conflicts | partial batch resumes from checkpoint; server authority wins except explicit mergeable drafts |
| BP-15 | bank reconciliation | import consented feed/file -> normalize -> match ledger candidates -> user confirms/splits -> post reconciliation | uncertain match remains suggestion; duplicate import hash suppressed |
| BP-16 | expense management | capture receipt -> malware/OCR processing -> classify -> approve -> post accounting and VAT candidate | OCR never final authority; policy/limit exceptions route to reviewer |
| BP-17 | inventory movement | sale/purchase/transfer/adjustment command -> validate branch/stock -> post immutable movement -> update projection | negative stock policy configurable; corrections use reversing movement |
| BP-18 | project accounting | create project/budget -> authorize spend/time -> allocate ledger/invoice lines -> monitor variance -> close | over-budget approvals and cross-project access controlled |
| BP-19 | period opening/closing | calendar opens period -> collect transactions -> reconcile -> resolve/accept exceptions -> approval -> close snapshot | late items become approved adjustment; closed snapshot immutable |
| BP-20 | VAT reconciliation | compare sales/purchase VAT, counterparty, ledger and return controls -> classify mismatches -> resolve/escalate | unresolved material exception blocks or conditions filing according to policy |
| BP-21 | VAT return generation | select closed period -> pin rule/data versions -> calculate boxes -> controls -> create draft | every value traceable to transactions/adjustments; recalculation creates new draft version |
| BP-22 | return approval/submission | maker reviews -> approver signs -> submit idempotently to ITAS/authority -> receive acknowledgement -> seal | timeout queried before retry; rejection creates resolvable task; no assumed success |
| BP-23 | refund review | credit return -> eligibility/risk checks -> evidence/review -> maker-checker outcome -> payment authority -> reconcile | see refund architecture; adverse outcome explainable and appealable |
| BP-24 | tax calendar/reminder | authoritative obligation dates -> calculate taxpayer deadlines -> reminders/escalations -> acknowledge | notification failure does not change obligation; timezone and rule version explicit |
| BP-25 | audit case | authorized referral -> scope/assignment -> evidence custody -> analysis -> taxpayer response -> reviewed finding -> close | conflicts, reassignment, disclosure and appeal controlled; full custody chain |
| BP-26 | dispute/objection | taxpayer references decision -> eligibility/timeliness check -> lodge evidence -> independent review -> outcome/appeal | original decision preserved; statutory timing **requires legal confirmation** |
| BP-27 | compliance/risk | ingest governed indicators -> calculate explainable score -> review -> recommend treatment/case | sensitive logic restricted; model alone cannot issue final adverse decision |
| BP-28 | document upload | authorize purpose -> pre-signed upload -> checksum/type/size -> quarantine scan -> classify/encrypt -> release | malicious/unsupported content isolated; download requires reauthorization |
| BP-29 | SaaS integration | register client -> consent/scopes -> exchange token -> validate/import/export -> receipt/reconcile | per-tenant circuit/quota; revoked consent stops use; source provenance retained |
| BP-30 | API onboarding | developer registers -> agreement/identity -> sandbox keys -> conformance/security tests -> production approval | keys scoped/rotated; unsafe client suspended; usage and webhook evidence retained |
| BP-31 | taxpayer communication | compose approved template/message -> audience/purpose check -> send -> delivery receipt -> retain | sensitive data excluded from insecure channel; opt-out applies where legally allowed |
| BP-32 | data export/portability | request -> identity/purpose/format checks -> approval -> async package -> checksum/encrypt -> expiring delivery | excessive/sensitive export stepped-up; export and download audited |
| BP-33 | incident response | detect -> classify -> contain -> investigate -> eradicate -> recover -> notify -> learn | SOC custody, severity and legal notification clocks preserved |
| BP-34 | tax-rule change | policy proposal -> author -> independent approve -> test/shadow -> signed activation -> monitor/rollback | no retroactive silent change; emergency path bounded and reviewed |

## Cross-process invariants

- One verified taxpayer identity maps to one active taxpayer organisation, subject only to approved legal lifecycle operations.
- Buyer and seller are roles of an organisation in a transaction, never permanent taxpayer types.
- Commands carry tenant, actor, correlation and idempotency context; authorization is re-evaluated server-side.
- Certified fiscal records are append-only; correction uses linked version or compensating transaction.
- External timeout is `unknown`, not `failed`; status is queried before a retry.
- Every automated adverse recommendation is reviewable, explainable and attributable to a rule/model version.

