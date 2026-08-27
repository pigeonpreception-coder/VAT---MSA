# VAT-MSA — Code-Verified Security Gap Assessment (2026-08-27)

**Assessed against:** [`CYBERSECURITY_MASTER_ARCHITECTURE.md`](CYBERSECURITY_MASTER_ARCHITECTURE.md)
**Method:** direct code read of `lib/`, `app/api/v1/**`, `db/runtime.ts`, `worker/index.ts`, `tools/`, `tests/`. Claims in `ARCHITECTURE_IMPLEMENTATION_MATRIX.md` / `MODULE_DEVELOPMENT_PLAYBOOK.md` were treated as hypotheses and independently checked; where they overstate reality this document says so explicitly.

**Explicitly out of scope** (cannot be closed by application code in this repo, per the master architecture's own calibration note): SOC staffing (Sec 36), ISO/PCI/NIS2 certification (Sec 54), red/purple team engagements (Sec 53), HSM/KMS procurement (Sec 20), WAF/DDoS/network appliances and segmentation (Sec 43, 44), penetration testing engagements (Sec 52), backup/DR infrastructure (Sec 46, 47), endpoint/device management (Sec 45).

---

## 1. Identity & Access (Sec 7, 11, 12)

**What exists:** Authentication delegated to platform-injected headers, resolved through `identity_links` (`lib/auth.ts:71-99`). RBAC is a 22-role static table (`lib/domain/access.ts:23-45`) unioned with tenant-defined `organisation_role_permissions`. ABAC/scope (`isNationalScope`, `requireTaxpayerScope`) is used pervasively — 143 scoping call sites. **Server-side gating is genuinely complete**: every one of 165 route files is permission-gated; the only unauthenticated routes are deliberately public (certificate verification, invitation claim). Session revocation is real and effective immediately.

**Gaps:**
1. ~~**CRITICAL — step-up/MFA is client-asserted and forgeable.**~~ **FIXED 2026-08-27.** `requireStepUp` (`lib/security/step-up.ts`) previously trusted two request headers the *caller* supplied (`x-vat-msa-auth-assurance`, `x-vat-msa-reauthenticated-at`) verbatim, with no server-side backing at all — no application code anywhere ever set those headers on a genuine step-up event, only test fixtures did. Replaced with a real, standards-compliant RFC 6238 TOTP implementation (`lib/domain/mfa.ts`, built entirely from Web Crypto — no external MFA provider required) and a genuine server-verified step-up record: `EnrollTotp`/`VerifyTotpEnrollment` establish a credential, `ConfirmStepUp` (`POST /api/v1/identity/step-up`) verifies a fresh 6-digit code and writes a real `step_up_events` row with anti-replay (`last_used_counter`), and `requireStepUp` now checks that row instead of any header. All 28 "step-up gated" commands are genuinely gated. Proven in `tests/mfa-domain.test.ts` and `tests/routes/security-mfa-step-up.test.ts` (the latter also exercises item #1's fix in the same end-to-end flow via `LinkIdentity`).
2. ~~**CRITICAL — full account takeover via `LinkIdentity`.**~~ **FIXED 2026-08-27.** `linkIdentity`/`revokeIdentityLink` (`lib/data/identity-repository.ts:564-588`, `:615-633`) previously performed no tenant-scope check on the target `app_users` row/link — a `TAXPAYER_OWNER`/`TAXPAYER_ADMIN` could link a platform subject they control to any user (including a national-scope account) and authenticate as it, or revoke any other user's session. Both now require a non-national actor's target to share their own `taxpayer_id`; a genuinely national-scope actor remains unrestricted. Proven in `tests/routes/security-identity-link-scope.test.ts`.
3. ~~**HIGH — the "protected-permission ceiling" is a denylist that protects almost nothing.**~~ **FIXED 2026-08-27.** `PROTECTED_PERMISSION_PREFIXES` (`lib/domain/control-plane.ts`) only ever actually blocked `platform:` — `createOrganisationRole` would happily grant a tenant-defined role `audit:read`, `security:read`, `reconciliation:manage`, `refunds:review`, `administration:manage`, etc. Replaced with `TENANT_GRANTABLE_PERMISSIONS` (`lib/domain/access.ts`) — an allowlist derived directly from the union of every real tenant-facing role's own permissions (`ROLE_PERMISSIONS`/`CONTROL_PLANE_PERMISSIONS`, excluding national/platform-only roles), so a tenant-defined role can never exceed what an existing built-in tenant role already has. Proven in `tests/routes/security-tenant-role-permissions.test.ts`.
4. **MEDIUM** — no PAM/JIT elevation; grants are permanent until revoked.

**Severity: MEDIUM** (downgraded from CRITICAL/HIGH now that items #1, #2 and #3 are fixed; only the PAM/JIT gap remains, which is out of scope for this pilot's remediation pass).

## 2. Authoritative taxpayer identity (Sec 8, 9, 10)

Duplicate detection on VAT number/TIN is real (`lib/data/identity-repository.ts:143-217`). ITAS is an **honest, fail-closed unconfigured-adapter stub** (`lib/integrations/itas.ts:54-73`) — correct posture, not a gap in itself. Gaps: no authoritative identity proofing exists (**HIGH**, by design — every identity is `MANUAL_REVIEW`); the identity hierarchy is two-tier not three (`company_registration_number` is validated but never persisted to `taxpayers`/checked for duplicates — **MEDIUM**); duplicate detection is exact-string-match only, no reconciliation of registry discrepancies (**MEDIUM**).

## 3. Multi-tenant isolation (Sec 14) — the highest-value check

**The bulk of the tenant boundary is genuinely solid.** No missing `WHERE taxpayer_id=?` found in any invoice, return, ledger, party, quotation, expense, project, inventory, accounting, compliance, or control-plane query across all eleven `lib/data/*.ts` files (business, compliance, vat-lifecycle, repository/invoices, platform, control-plane all clean).

**Real gaps found:**
- **HIGH — `audit_events` has no tenant dimension and is read unscoped.** `searchAuditTrail` filters only by resource/action/actor. `audit:read` is a seeded, tenant-grantable permission (compounds with domain 1, gap 3).
- ~~**MEDIUM — `reconciliation-repository.ts`'s `assignException`/`resolveException`** perform no tenant check~~ **FIXED 2026-08-27** — both now call `requireTaxpayerScope`, matching the sibling `runMatch` in the same file.
- **LOW** — `security_incidents` has no tenant dimension (correct for a SOC surface, but `security:read` is tenant-grantable).
- **MEDIUM — no systematic tenant-isolation test suite** exists (Sec 14 requires "continuously test").

## 4. Application & API security (Sec 16, 17, 18)

Input validation is comprehensive (a pure domain validator in front of every write); SQL injection resistance is complete (100% parameterized); secure headers are real and applied at the edge (`worker/index.ts:60-84`, full CSP, HSTS, COOP/CORP); rate limiting is a real D1-backed atomic counter; idempotency exists via two mechanisms.

**Gaps:**
- **HIGH — rate limiting covers roughly a third of command routes.** Identity, control-plane, reconciliation, and vat-rules route families have none. `POST /api/v1/invitations/claim` (token-guessing surface) and the cached public `GET /api/v1/verify/[token]` (certificate enumeration) have no rate limit at all.
- **HIGH — idempotency-key protection is absent from the same four repositories** (`control-plane`, `identity`, `reconciliation`, `vat-rule`) — zero `command_idempotency` references, contradicting the pattern established in five other repositories.
- **MEDIUM** — no CSRF token (mitigated by JSON-only body requirement + header-based auth, but undocumented as a decision); CSP allows `unsafe-inline` script-src.
- **HIGH — no bot/abuse protection exists** (Sec 18 explicitly wants adaptive controls; only fixed thresholds exist, on a third of endpoints).

## 5. Data security & classification (Sec 19)

Four disjoint, unrelated `classification` vocabularies exist across `access_permissions`, `document_metadata`, `report_definitions`, `integration_connections` — none matches Sec 19's five-level scheme, and `access_permissions.classification` is entirely dead data (never read). Report-export classification gating (step-up + maker-checker for sensitive tiers) is a genuine, working exception — **NONE** severity there. Overall **MEDIUM**.

## 6. Cryptographic architecture (Sec 20, 21)

**Zero keyed cryptography exists anywhere in the repo.** Every "signature"/hash is unkeyed SHA-256 (`crypto.subtle.digest`) — no `sign`/`importKey`/HMAC/asymmetric primitive anywhere. No encryption at rest for any D1 column (everything plaintext: TINs, VAT numbers, addresses). The repo is honest about this (`signature_profile='DEV-SHA256'`, an explicit `component-hsm` DISABLED row stating "Development signatures are not production legal signatures"). No hard-coded secrets found. **Severity: HIGH** — largely blocked on an out-of-scope KMS/HSM decision, but the in-scope fix is introducing an algorithm-abstraction seam now (see remediation #10).

## 7. Tax transaction integrity & non-repudiation (Sec 22)

The CREATE→VALIDATE→AUTHORIZE→SIGN→SUBMIT→ACKNOWLEDGE→RECORD→AUDIT pipeline is real, but the "SIGN" step (`lib/data/repository.ts:601`) is a content digest (`DEV.${sha256(...)}`), not a signature — it provides integrity, not non-repudiation, since nothing binds it to a key held only by the submitting identity. The invoice API response literally names this field `signature`, which a consumer would misread. **Severity: HIGH**, with the same KMS caveat as domain 6. Tamper-evidence and lineage (immutable correction chains, atomic batched writes, independent re-derivation via `runMatch`) are genuinely strong — **NONE** severity there.

## 8. Audit & immutable logging (Sec 39, 40)

`appendAuditEvent`'s hash chain has genuinely broad coverage (56+29+25+20+20+9+8+7+6 call sites across repositories) and `VerifyAuditChain` is real — re-derives every hash, detects breaks, escalates to a CRITICAL incident automatically. This is one of the strongest areas of the codebase.

**Gaps:**
- **HIGH — no retention, WORM, or legal-hold on the audit trail itself** (the existing `legal_hold` mechanism covers uploaded documents only). Essentially unimplemented — an honest, expected pilot gap.
- **MEDIUM — the "single canonical writer" consolidation claim in the matrix is inaccurate.** Two more hand-rolled hash-chain copies exist in `lib/data/repository.ts` (`cancelInvoice:199-207`, `submitInvoice:657-671`) using plain `JSON.stringify` instead of `stableStringify` — same formula, but undocumented as an exception.
- **MEDIUM — a real concurrency race**: the predecessor-hash lookup (`lib/data/audit-repository.ts:52`) has no tiebreak/lock, so concurrent commands can produce a false `PREVIOUS_HASH_MISMATCH` that auto-escalates to a CRITICAL incident.

## 9. Module 8 threat detection & incident response (Sec 32-38)

What exists is precisely: **a 3-rule fixed-threshold catalogue over the system's own event log**, with a real, working, tested incident lifecycle (create/contain/revoke/close, genuine technical containment via session revocation). What Sec 33 describes is adaptive, multi-method, behavioural detection — a different class of thing, and the distance is large but expected for a pilot.

**Gaps:**
- ~~**HIGH — detection input coverage is much thinner than the rule catalogue implies.**~~ **FIXED 2026-08-27.** `AUTHORISATION_DENIED` was missing from ~60 routes (identity/control-plane/reconciliation/vat-rules families), and `RATE_LIMIT_EXCEEDED` from every already-partially-wired dispatcher's `RequestGuardError` branch (business/compliance/platform/security/vat-lifecycle/audit) — so two of the three detection rules structurally could not fire across most of the API. Fixed by two new shared, best-effort recorders (`lib/security/request.ts`'s `recordAuthorizationDenial`/`recordRateLimitBreach`, which re-resolve the actor rather than requiring every route to thread one through) wired into each family's shared `*Problem()`/`failure()` error-mapping helper, so no individual route needed touching. Proven in `tests/routes/security-event-emission.test.ts`, which shows `REPEATED_AUTHORISATION_DENIALS` now genuinely opens an incident from a previously-blind route family — not just that an event row gets written.
- **HIGH — none of Sec 33's named threats (account takeover, credential abuse, privilege escalation, exfiltration) or Sec 24's insider-threat set (bulk downloads, unusual exports, permission changes) have any rule**, despite the underlying audit events already existing.
- **MEDIUM** — no behavioural baseline of any kind; automated response is one action (incident creation) and is not reversible; incident lifecycle is compressed to 3 states vs. Sec 37's 9.

## 10. Software supply-chain (Sec 41, 44, 45)

Real, working tools exist: `secret-scan.mjs` (verified clean), `generate-sbom.mjs` (real CycloneDX 1.5 output), `pnpm audit` script, an aggregate `security:ci` script.

**Gap — HIGH, and the cheapest fix in the whole assessment: none of it runs automatically.** No `.github/` directory, no CI config of any kind, no git hooks. `security:ci` is a script nobody invokes — Sec 42's "no deployment bypasses mandatory security gates" is currently true only because there are no gates at all.

## 11. File/upload security (Sec 26)

MIME allowlist, 10 MiB size cap, SHA-256 checksum, path-traversal-safe object keys, quarantine-before-availability, audited downloads — all genuinely enforced (`lib/data/platform-repository.ts:127-162`).

**Gaps:** **HIGH — no malware scanning exists at all.** `CompleteDocumentScan` records a verdict *asserted by a human admin* — that click is the entire control. The matrix's "VERIFIED QUARANTINE" label is accurate for the workflow, not for scanning. ~~**MEDIUM** — MIME validation trusts the client-supplied `Content-Type` with no magic-byte check~~ **FIXED 2026-08-27** — `validateAndHashFile` (`lib/data/platform-repository.ts`) now sniffs the file's own leading bytes against real PDF/PNG/JPEG/XLSX magic numbers (CSV, having no signature, instead fails a bounded NUL-byte sniff test), refusing a file whose content doesn't match its declared type before it's ever stored. This is content-sniffing, not malware scanning — the HIGH gap above remains.

---

## Prioritised remediation list

Ranked by severity × cheapness within this repo's existing patterns (S = days, M ≈ a sprint, L = multiple sprints):

| # | Item | Severity | Effort |
|---|---|---|---|
| 1 | ~~Scope-check `linkIdentity`/`revokeIdentityLink` (closes full account-takeover path)~~ | CRITICAL | **DONE 2026-08-27** |
| 2 | ~~Make step-up server-verified (new `step_up_events` table + command)~~ | CRITICAL | **DONE 2026-08-27** |
| 3 | Add a CI security gate (one workflow file; tools already exist and pass) | HIGH | S |
| 4 | ~~Emit `RATE_LIMIT_EXCEEDED`/`AUTHORISATION_DENIED` from every handler (wiring only)~~ | HIGH | **DONE 2026-08-27** |
| 5 | ~~Replace the protected-permission denylist with an allowlist~~ | HIGH | **DONE 2026-08-27** |
| 6 | ~~Scope `assignException`/`resolveException`~~; add a tenant-isolation test suite | MEDIUM→HIGH | **assignException/resolveException DONE 2026-08-27**; systematic isolation suite still open (M) |
| 7 | ~~Magic-byte content validation on upload~~ | HIGH | **DONE 2026-08-27** |
| 8 | Rate-limit + idempotency-key the 4 uncovered route families | HIGH | M |
| 9 | Fix audit-chain concurrency race; consolidate the 2 remaining hand-rolled writers | MEDIUM | S |
| 10 | Introduce a `SignatureProvider` seam behind existing `certificates.signature` columns | HIGH | M |
| 11 | Unify data classification onto Sec 19's five levels; enforce on document download | MEDIUM | M |
| 12 | Add Sec 24 insider-threat detection rules over existing events | MEDIUM | M |

**Not recommended for this pilot:** deception/honeytokens (high risk of doing it badly), behavioural ML baselining (no data volume to baseline against), application-layer field encryption (blocked on the out-of-scope KMS decision — #10 is the correct preparation for it).
