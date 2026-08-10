# Assumptions and Open Decisions

## Workspace, licensing and workflow decisions required

- Commercial plan catalogue, feature bundles, seat/branch/transaction/API/storage limits and plan-version authority.
- Subscription/payment provider, sandbox contract, billing tax treatment, refund/dispute/reconciliation and provider failure semantics.
- Trial, grace, pending-renewal, suspension, expiry, cancellation, upgrade and downgrade effective behavior.
- Statutory continuity, record retention, retrieval/export and legally required corrective actions after licence restriction.
- Primary Organisation Administrator proofing, replacement, recovery, quorum and escalation.
- Grantable permission catalogue, protected roles, financial ceilings, SoD catalogue and emergency-override decision.
- Workflow expression/transition catalogue, publication approval, version migration and durable timer recovery.
- Access review cadence, dormant threshold, excessive privilege rules, offboarding task reassignment and revocation SLO.
- Canonical workspace labels, restricted-state disclosure, multilingual terminology and licence-upgrade messaging.
- ITAS taxpayer/VAT verification attributes, federation assurance, organisation status events, SLAs and sandbox.

## Confirmed public context (review date: 8 August 2026)

- NamRA's ITAS already provides taxpayer e-filing, taxpayer account access and VAT return functions.
- Namibia's Value-Added Tax Act, 2000 is the governing primary legislation and must be interpreted with all effective amendments, regulations, schedules and notices.
- NamRA publicly described an e-invoicing initiative intended to support real-time invoice generation/validation and previously announced an April 2026 target. The current operational rollout, mandate, technical specification and transition schedule must be confirmed directly with NamRA before this blueprint is treated as an implementation mandate.
- A Data Protection Bill was introduced in Parliament in June 2026. Final enacted obligations and commencement status must be checked before procurement and again before production.

## Design assumptions

| ID | Assumption | Impact if false | Owner |
|---|---|---|---|
| A-01 | ITAS can expose or accept secure government integration APIs. | A managed file exchange or intermediary integration pattern will be required. | NamRA Enterprise Architecture |
| A-02 | NamRA will allocate a legal owner for the canonical invoice schema and versioning process. | Integration partners cannot safely certify against a stable national contract. | NamRA Domestic Taxes |
| A-03 | Taxpayer and VAT-registration master data can be synchronised with stable identifiers and effective dates. | VAT-MSA must add manual onboarding and stronger identity-matching workflows. | NamRA Data Governance |
| A-04 | Seller systems can supply invoice-line detail and stable source identifiers. | Reconciliation and risk detection will be limited to totals and lower assurance. | Integration Programme |
| A-05 | NamRA will approve a PKI/HSM trust model for certification. | Signed fiscal receipts and independent verification cannot be productionised. | NamRA Security |
| A-06 | Required data must be hosted in approved Namibian or sovereign environments. | Cloud and disaster-recovery topology, support model and procurement change materially. | Legal / Security / Procurement |
| A-07 | Return forms and calculation workbooks will be supplied as authoritative mappings. | The return engine remains a draft aggregator and cannot claim statutory equivalence. | NamRA VAT Policy |

## Decisions required before build approval

1. Legal definition and status of a certified, rejected, provisional and corrected electronic invoice.
2. Mandatory vs voluntary rollout cohorts, thresholds, exemptions and transition dates.
3. Invoice numbering, QR content, digital signature, retention and evidence requirements.
4. Treatment of consumers, non-VAT buyers, government entities, imports, exports and self-billing.
5. Supported currencies, exchange-rate source and tax-point rules.
6. Offline grace period, whether pre-authorised token pools are permitted and consequences of late synchronisation.
7. ITAS system-of-record boundaries and return/refund submission interface.
8. Authoritative taxpayer matching rules, data stewardship and duplicate-resolution process.
9. Target throughput, seasonal peak, maximum invoice size and onboarding volumes.
10. RTO/RPO, availability class, data-residency boundary and disaster-recovery region/site.
11. Records schedules, legal holds, taxpayer access/correction rights and disposal approval.
12. Risk-model governance, explainability, appeal, bias testing and human decision thresholds.

## Non-negotiable discovery inputs

- Current VAT return forms, schedules and calculation workbooks.
- Sample valid/invalid invoices and credit/debit-note cases.
- ITAS, customs/import, payments/refunds and taxpayer-registry interface specifications.
- Legal and records-management interpretation memo.
- National hosting, network, IAM, PKI/HSM and SOC constraints.
- Measured or forecast transaction volumes by taxpayer segment and channel.
- NamRA operating model, segregation-of-duties policy and audit/refund workflows.
