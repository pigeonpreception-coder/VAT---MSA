# NamRA e-VAT MS: current-state assessment and change impact matrix

Status: **DRAFT — presented for owner review before any implementation begins**, per the master prompt's own change-control rule (§3: identify current state, proposed change, reason, impact, recommendation before restructuring; STOP for approval outside expressly approved changes).

This document answers §33 (current-state assessment) and §34 (change impact matrix) of the "NamRA's e-VAT Management System" master prompt, grounded in the actual repository state as of this assessment (both the root TS/Next.js/Cloudflare app and the `php-app/` Laravel port, which mirror each other feature-for-feature). It does not re-derive architecture that already exists in `08-enterprise-architecture/` — it points to it and states what's genuinely missing.

## 0. Headline finding

Most of the master prompt's *governance and country-specific architecture* asks are already built as documentation in this repo, in depth:

| Master prompt section | Already covered by |
|---|---|
| §5 ITAS integration | `08-enterprise-architecture/14-itas-saas-integration.md` |
| §14 Offline resilience | `08-enterprise-architecture/16-vat-transaction-return-offline.md` |
| §10–12 VAT Audit/Refund/Adjustment reporting | `08-enterprise-architecture/22-audit-refund-reporting.md` |
| §26, §30, §31–32 currency, identifiers, country pack, "do not invent law" | `08-enterprise-architecture/32-namibia-country-compliance-pack.md` (already uses exactly the `SOURCE VERIFIED` / `REGULATORY CONFIRMATION REQUIRED` / `UNKNOWN` discipline §32 of the prompt demands) |
| §31 global vs. country layering | `08-enterprise-architecture/31-globalisation-country-compliance-architecture.md` |
| §37 business process flows | `08-enterprise-architecture/23-business-process-catalog.md` |

The genuine gap is narrower than the prompt implies: it's concentrated in (a) UI/navigation structure, (b) a first-class taxpayer-ERP/POS registration record, (c) local/foreign invoice auto-classification, and (d) packaging existing data into the three named reports — not a from-scratch rebuild of the architecture or its governance model. Branding the product "NamRA E-VAT MS" is a naming/labelling decision, not an architectural one.

## 1. Current-state assessment (§33)

**1–2. Six portals.** Confirmed present and identical in both apps: Buyer, Seller, NamRA, NamRA Admin, Super Admin, Developer (`app/portal/{buyer,seller,namra,namra-admin,super-admin,developer}` and `php-app/app/Http/Controllers/Portal/*PortalController.php` + matching Blade views). None removed or combined by this assessment.

**3. Existing modules.** Invoicing, quotations, expenses/projects, accounting (GL), inventory, VAT periods/returns, refunds, disputes, audit cases, compliance, organisations/administration, documents, reports, licensing, workflows, developer platform (API clients), security/SOC, taxpayers, risk indicators. Both apps expose ~217 API operations (`03-api/openapi.yaml`, fully documented this session) and matching UI pages/Blade views.

**4. Accounting capabilities.** `chart_of_accounts`, `journal_entries`, `journal_lines`, `ledger_entries` exist and post double-entry. `GET /v1/accounting/trial-balance`, `/v1/accounting/statements`, `/v1/accounting/accounts` exist. **Gap**: no dedicated "Financial Ratios" output (§18) — would compose from existing trial-balance/statement data, not a new ledger concept.

**5. Operations modules.** Expenses, inventory movements, projects (budget/costs/profitability) exist and are wired into navigation already. HR, immovable/movable asset management, logistics, full ERP/POS-grade inventory, and dedicated CRM are **not present** — consistent with §16(E)'s instruction to reserve, not build, these.

**6. API integrations (general).** `integration_connections` (generic org-scoped connector registry: provider_key, category, capabilities, credential_reference, configuration/operational status) and `api_clients`/`developer_accounts` (OAuth client-credential issuance for third-party SaaS, Module 10) both exist. **Gap**: neither captures the specific fields §6 requires for a taxpayer's own ERP/POS registration — VAT number, TIN, company registration number, system/vendor name, last synchronization, security status all in one record. This is a genuinely new, well-bounded data model, not a rename of an existing one.

**7. ITAS integration status.** A clean anti-corruption-layer port exists (`lib/integrations/itas.ts`): typed `ItasIdentityPort` interface (`status`, `verifyTaxpayer`, `submitVatReturn`), with only a `SandboxItasIdentityAdapter` implementation. Real government connectivity is explicitly `DISABLED_PENDING_AUTHORITY_CONTRACT` (`lib/data/control-plane-repository.ts`) and every capability fails closed with `ItasIntegrationUnavailableError` until a real contract is confirmed. **This is correct behavior per §32 ("do not invent Namibian law/integration") — not a bug to silently "fix" by faking a live connection.**

**8. Existing VAT reconciliation.** A real matching/reconciliation engine exists (Module 3: `RunMatch`, `GetWorkQueue`, `reconciliation_exceptions`, `AssignException`, `ResolveException` — extensively tested this session). **Important nuance**: today it matches an invoice against its *own posted ledger entry* (drift/tamper detection between the certified invoice and the ledger), not the three-party Supplier→Seller→Buyer cross-taxpayer matching §4/§8 describe. Real cross-taxpayer matching requires the counterparty (the other taxpayer's own system) to have also submitted a corresponding invoice — which in turn depends on the §6 ERP/POS registration gap above being closed first, and on real ITAS-mediated taxpayer identity resolution.

**9. Existing invoice functionality.** Real invoice submission, certification, correction/cancellation exist. Credit/debit notes are modeled generically as `invoice_corrections` (original invoice → correction invoice, `correction_type`, `reason_code`), not as distinct "Credit Note"/"Debit Note" entities — functionally equivalent, differently named. **Gap**: no `is_local`/`is_foreign` classification field or country-of-counterparty-driven auto-classification anywhere in the schema or domain logic (§17 requires this).

**10. Database/data model.** SQLite (dev/test) via `db/runtime.ts`, Drizzle migrations under `drizzle/`; the `08-enterprise-architecture/05-data-architecture-and-erd.md` / `13-logical-data-dictionary.md` docs describe the target Postgres-based production data architecture. Offline resilience is further along than the prompt assumes: `offline_devices`, `offline_number_ranges`, `offline_sync_batches`, `offline_conflicts` tables and `/v1/offline/batches` already implement most of §14's queue → sync → duplicate-check → checkpoint flow.

**11. Conflicts with this architecture.** None found that would block implementing the genuine gaps above. The one thing that *would* be a material, prompt-flagged conflict is renaming/rebranding the product to "NamRA E-VAT MS" throughout UI copy, docs and possibly package metadata — cosmetic, low-risk, but touches many files, so it's called out below as its own line in the impact matrix rather than assumed.

## 2. Change impact matrix (§34)

| Existing component | Current state | Requested change | Conflict? | Recommendation | Approval required? |
|---|---|---|---|---|---|
| Sidebar / navigation (both apps) | TS app: DB-backed `navigation_workspaces → folders → items` tree, already collapsible/grouped, 12 workspaces seeded. php-app: flat, ungrouped list of ~18 links. | Regroup into the prompt's named structure (Dashboard, VAT Management, Invoice Management with Local/Foreign split, Accounting & Finance, Operations, Quotation, Project Management, Registered, New Registration). | No — additive/regroup, not a rewrite | Implement once you share your structure (per our earlier exchange) — you said you're still drafting it | No, once you confirm the structure |
| `integration_connections` / `api_clients` | Generic connector/credential registry, no taxpayer-ERP/POS-specific fields | New `taxpayer_system_registrations` concept (§6): VAT#, TIN, company reg#, system/vendor name, API status, last sync, security status | No — new table, additive | Build as its own table + `/v1/taxpayer-systems` API, referencing existing `taxpayers`/`organisations`, not replacing `integration_connections` | Recommended, not blocking |
| Invoice schema (`invoices`, `invoice_corrections`) | No local/foreign flag | Auto-classify LOCAL vs FOREIGN from counterparty's registered country (§17) | No | Add a computed/derived classification at submission time from the counterparty's country on file; needs a "country of record" source (business_parties already has an implicit NA-only assumption today — worth confirming before implementing) | **Yes — confirm whether counterparty country is already captured anywhere today, or needs a new field, before implementing** |
| Reconciliation engine (Module 3) | Own-invoice-vs-own-ledger drift detection | Extend to real three-party Supplier↔Seller↔Buyer cross-taxpayer matching (§4, §8) | Partial — today's matching semantics change meaning | This is the largest, highest-risk item in the whole prompt: it depends on the ERP/POS registration gap being closed AND on a real ITAS/taxpayer-identity resolution path existing. Recommend treating as its own multi-phase workstream, not folded into the sidebar/report work | **Yes — significant architectural extension, needs its own design review** |
| VAT Audit / Refund / Adjustment "reports" | Underlying data exists (reconciliation exceptions, refund claims/reviews/payments, invoice corrections); not packaged as three named report screens/endpoints | Build three dedicated report views/endpoints composing existing data (§10–12) | No | Additive read-model/reporting layer over existing tables — no changes to the tables themselves | No, but report **format** is explicitly "propose first, owner approves" per §10/§24 |
| ITAS integration (`lib/integrations/itas.ts`) | Sandbox adapter only, fails closed, `DISABLED_PENDING_AUTHORITY_CONTRACT` | None requested that changes this — prompt itself says NamRA remains statutory authority and not to invent integration | No | Leave as-is; this is already exactly the "do not invent" behavior the prompt demands | N/A |
| Product naming ("VAT-MSA" → "NamRA E-VAT MS") | "VAT-MSA" throughout UI, docs, `package.json` name, OpenAPI title | Rebrand Namibia-facing surfaces | Low risk, wide surface area | Scope precisely: does this mean the Namibia-facing UI copy only, or literal repo/package renaming too? Different effort levels | **Yes — confirm scope before a repo-wide rename** |
| Future Operations modules (HR, asset mgmt, logistics, ERP/POS-grade inventory) | Not present | Reserve navigation + domain/service interface boundaries only; do not build (§16 explicit) | No | No code changes needed beyond a nav placeholder once the sidebar structure is confirmed | No — prompt already forbids building these now |

## 3. What this assessment deliberately does not do

It does not re-write the 19 architecture sub-documents or 20 process flows the master prompt's §36–37 list — nearly all of them already exist in `08-enterprise-architecture/` (see §0 table) or in `references/architecture-decisions.md`. Duplicating them would fork a single source of truth into two. Where a genuine new artifact is needed (e.g. a Taxpayer System Registration data model, a Local/Foreign classification ADR), it should be added to that existing set as a new numbered/extension document, not written fresh here.

## 4. Recommended next step

Given the size of the full master prompt, implementation should proceed as a sequence of independently reviewable changes, not one pass:

1. **Sidebar/navigation restructuring** — blocked only on your draft structure (already in progress on your side).
2. **Taxpayer ERP/POS system registration framework (§6)** — well-bounded, additive, no conflicts; can start once you confirm.
3. **Local/foreign invoice classification (§17)** — needs one clarification (where counterparty country comes from today) before design.
4. **VAT Audit / Refund / Adjustment report packaging (§10–12)** — additive reporting layer; report format proposed for your approval per the prompt's own rule.
5. **Real cross-taxpayer VAT matching (§4/§8) and any real ITAS connection** — flagged as its own large, separate workstream requiring explicit design review; not something to start alongside 1–4.
6. **"NamRA E-VAT MS" rebranding** — cosmetic, do last, scope to be confirmed.

Tell me which of 2–4 (or the rebrand) to start on first once your sidebar draft is ready, and I'll scope that one item concretely rather than attempting all of them at once.
