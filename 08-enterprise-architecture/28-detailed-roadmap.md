# Detailed Implementation Roadmap

## Workspace/licensing/workflow insertion plan

This extension is inserted after Phase 2 canonical taxpayer/organisation foundations and before unrestricted Phase 4 business-module rollout:

| Increment | Scope | Entry dependency | Exit evidence |
|---|---|---|---|
| 2A - authority and policy contract | approve plans/states, administrator proofing, grantable permissions, workflow vocabulary, SoD and retention | signed ADR-016 to ADR-019 | no unresolved critical policy ambiguity for the bounded increment |
| 2B - licence and organisation administration | licence/entitlement service, employees/structure, primary/delegated admins, usage reservation | 2A plus verified identity contract | tenant, privilege, quota, state and retention tests |
| 2C - dynamic authorization and workspace | organisation roles/scopes/capabilities, navigation projection, restricted states and command centre | 2B | frontend manipulation, cache invalidation, accessibility and cardinality tests |
| 2D - workflow and access governance | designer/compiler, versions, tasks, SoD, access request/review/offboarding | 2C | self-approval, history immutability, timer recovery and revocation tests |
| 2E - search and scale | permission-aware search, lazy/paginated administration and performance tuning | 2D | tenant inference, thousands-of-employees/permissions and hundreds-of-workflows load evidence |

Production payment, live ITAS activation and commercial launch are separate gates and cannot be inferred from these increments.

This roadmap starts only after the formal approval gate. Dates and budgets are intentionally absent until scope, procurement, authority and team capacity are confirmed. Each phase uses the same signed artifacts across environments and closes with demonstrable acceptance evidence.

| Phase | Scope and key deliverables | Dependencies | Principal risks | Exit/acceptance gate |
|---|---|---|---|---|
| 0 — authority and governance | Architecture Board; product/domain/data/security owners; legal/tax catalogue; ITAS discovery; architecture/ADR approval; funding and benefits baseline | executive/NamRA sponsorship | decisions assumed; unclear ownership | all critical authorities named; no scoped component `NOT READY`; signed approval record |
| 1 — platform and assurance runway | environment/accounts; identity foundation; CI/CD provenance/SBOM; secrets/keys; gateway; policy engine; observability; operational relational/event/object platforms; synthetic test data | Phase 0; infrastructure/procurement | supply chain or control plane gaps | threat controls tested; signed artifact promotion; restore, tenant and privileged-access evidence |
| 2 — canonical taxpayer and organisation | ITAS adapter/stub under approved contract; registration; one taxpayer/organisation; branches; users; RBAC/ABAC; consent/delegation; admin audit | identity/authority contracts | duplicates, account takeover, ITAS outage | verified end-to-end onboarding; duplicate/quarantine cases; policy/tenant tests; continuity exercise |
| 3 — invoice and VAT transaction core | party master; quotation; numbering; certified invoice; dynamic buyer/seller; immutable VAT transaction; verification; documents; correction/cancellation | approved tax/numbering rules; Phase 2 | unlawful calculation, duplicate issuance | golden fiscal cases, idempotency, signature/verification, append-only and load evidence |
| 4 — accounting and operational modules | double-entry ledger, expenses, inventory, projects, multi-branch/entity/currency/import controls; bank/payment adapters in sandbox | Phase 3 events/contracts | scope expansion; VAT/GL divergence | VAT-to-GL control totals, reversals, branch/tenant isolation and reconciliation tests |
| 5 — period, reconciliation and return | tax calendar; period close; matching/exception; automated draft; approval; ITAS submission/acknowledgement; amendments | confirmed ITAS filing contract; Phases 3-4 | external unknown outcomes; incorrect return | trace every box to source; rejected/timeout/retry cases; sealed acknowledgement; UAT sign-off |
| 6 — NamRA compliance, audit and refund | taxpayer search, compliance centre, explainable risk, case/evidence custody, objection, refund review/payment orchestration, internal controls | legal enforcement/refund policy; Phase 5 | privacy, bias, insider/collusion | SoD/PAM, evidence chain, explainability/appeal, refund reconciliation and red-team evidence |
| 7 — ecosystem and developer platform | developer portal, SaaS sandbox/onboarding, APIs/webhooks, data portability, accounting/payment connectors | stable contracts; consent model | third-party compromise, data leakage | conformance/security tests, quota/circuit/revocation, signed webhook and exit/export tests |
| 8 — offline and inclusive channels | managed desktop/PWA, device enrolment, encrypted journal, signed ranges/rules, sync/conflict, low-bandwidth/accessibility/mobile polish | legal offline approval; stable fiscal core | device theft/tamper, sync duplicates | offline abuse, expiry/revocation, recovery, WCAG and field-pilot acceptance |
| 9 — analytics and national operations | governed CDC/warehouse, certified metrics, executive/NamRA dashboards, open data, model governance, SRE/NOC/SOC health | mature lineage/quality; lawful purpose | re-identification, misleading metrics | reconciliation to source, RLS/disclosure tests, model cards, freshness/SLO evidence |
| 10 — national rollout and continuous assurance | regional/cell scale if required; migration waves; training/support; filing command centre; DR/cyber exercises; independent assurance; optimization | all prior gates | adoption, peak load, operational fatigue | 2x peak, regional DR, security assurance, business continuity, staged rollout KPIs and board authorization |

## Cross-phase workstreams

- **Regulatory:** maintain legal traceability and rule catalogue; block features lacking authority.
- **Security/privacy:** update threat model, privacy impact, abuse tests and risk acceptance at each boundary.
- **Data:** schema/lineage/quality/retention and reconciliation are delivered with every domain, not deferred.
- **Operations:** SLO, capacity, runbook, backup/restore, support and cost evidence are part of definition of done.
- **Experience/change:** taxpayer research, accessibility, content, training, feedback and assisted channels accompany every journey.
- **Migration:** inventory legacy sources, cleanse/match, dry-run, reconcile, cut over in waves and retain rollback/evidence.

## Increment governance

Each phase is decomposed into thin end-to-end increments. Entry requires approved scope, requirements/risks, contracts, data classification, owner and acceptance tests. Exit requires functional, financial, security, privacy, accessibility, performance, recovery and operational evidence proportional to risk. Defects affecting identity, tenant isolation, fiscal correctness, audit integrity or recovery are release blockers.
