# Data Governance, Master Data and Tax Rules

## Authority and stewardship

| Data object | System of record | VAT-MSA role | Required confirmation |
|---|---|---|---|
| legal taxpayer registration, TIN and VAT status | ITAS, subject to confirmed interface | consumes verified identity/status; stores source reference and snapshot | **REQUIRES ITAS/NAMRA CONFIRMATION** |
| VAT-MSA organisation, branches and memberships | VAT-MSA | authoritative operational master linked 1:1 to taxpayer identity | legal mapping and lifecycle |
| users and credentials | ITAS when federated; VAT-MSA for approved standalone accounts | identity broker, local recovery/standalone authority | identity-provider and account-recovery policy |
| certified invoice and immutable VAT transaction | VAT-MSA | authoritative fiscal evidence; exports acknowledgements to ITAS if required | certification legal effect |
| filed return, liability and payment status | ITAS is presumed legal authority | prepares/submits; stores immutable submission and acknowledgement | **REQUIRES ITAS/NAMRA CONFIRMATION** |
| SaaS accounting/inventory/project data | originating SaaS unless imported as VAT-MSA managed data | validates, normalizes and records provenance | connector-specific contract |
| consent/delegation | VAT-MSA unless ITAS provides authoritative mandate | authoritative grants, scopes, revocations and usage evidence | legal basis and cross-system revocation |
| reference codes and tax configuration | designated NamRA policy owner | versioned distribution and execution | owner and effective-date approval |

No consumer may silently overwrite an authoritative source. Replicas carry `source_system`, `source_record_id`, `source_version`, `observed_at`, quality state and reconciliation status.

## Governance operating model

The Data Governance Council owns policy; domain Data Owners accept quality and access; Data Stewards resolve definitions and exceptions; Custodians operate stores; Security/Privacy officers approve handling; Records/Legal officers approve retention and legal holds. Every critical field has a glossary definition, owner, classification, lawful purpose, validation rule, lineage and retention rule.

Classification is Public, Internal, Confidential or Restricted Fiscal/Security. Default deny applies to Restricted data. Purpose limitation, data minimisation, field-level masking, export controls and immutable access evidence apply. Retention is policy-configured and suspended by legal hold. Deletion uses cryptographic or verified physical erasure where legally permitted; fiscal records may instead be sealed and access-restricted.

## Master-data model

| Master | Golden key | Matching/merge rule | Downstream controls |
|---|---|---|---|
| taxpayer | ITAS taxpayer ID/TIN | deterministic ITAS verification; no fuzzy automatic merge | 1:1 organisation invariant; status propagation |
| organisation | VAT-MSA organisation UUID + taxpayer ID | created once after verification; merger by approved case only | tenant boundary and invoice ownership |
| branch | organisation + branch UUID/code | unique active code per organisation | invoice series, stock and approval scope |
| user | issuer + immutable subject | link only through verified federation/local proof | roles separate from identity record |
| customer/supplier party | jurisdiction + identifier + provenance | confidence-scored candidates; steward confirms merge | buyer/seller is transaction context, not master type |
| product/service | organisation + SKU/service code | tenant-managed, versioned tax/category attributes | invoice-line snapshot prevents history drift |
| currency/exchange rate | ISO code + effective timestamp + source | approved source and rate type; no overwrite | original and functional values retained |
| tax code/rate | rule-set/version/effective interval | centrally governed; non-overlapping effective windows | fiscal calculations reference exact version |

Survivorship favors legal authority, then verified owner source, then newest approved observation. Merges are reversible mappings with audit evidence; source records are never destroyed. Quality dimensions are completeness, validity, uniqueness, consistency, timeliness and provenance. Threshold breaches quarantine affected automation and create steward work.

## Tax-rule lifecycle

Tax logic is configuration executed by a constrained, deterministic rules engine—not editable application code or arbitrary scripts.

1. Policy owner raises a change with legal citation, affected supplies, dates and expected outcomes.
2. Tax specialist authors rate, threshold, exemption/zero-rating, rounding, apportionment, currency and validation rules in a typed schema.
3. Independent reviewer performs maker-checker approval; Security validates permissions and signing.
4. Automated schema, overlap, boundary, regression, property and golden-case tests run against anonymized history.
5. Release authority signs an immutable rule bundle with semantic version, content hash, jurisdiction and effective interval.
6. Bundle deploys disabled to non-production, then shadow mode, then scheduled activation. Clocks and time zones are explicit.
7. Runtime records rule-set ID/version/hash and calculation trace on every fiscal result.
8. Monitoring compares outcome distributions and reconciliation controls; anomalies trigger pause/rollback.
9. Retirement closes the effective interval; the bundle remains available for replay, correction and audit.

Emergency change requires named legal authority, two-person approval, bounded duration, post-implementation review and the same immutable evidence. Rollback activates a previously signed compatible bundle prospectively. Historical records are not recomputed silently; corrections create linked compensating records under the legally applicable version.

## Data lineage and exchange

Lineage spans source payload/hash, gateway request, normalized command, rule decision, database record, event, warehouse transformation, report/return and external acknowledgement. Batch and stream contracts include owner, schema version, classification, allowed purposes, freshness, quality SLO, reconciliation control totals and deletion/retention obligations. Schema changes are backward compatible or use an approved dual-read/dual-write migration.

## Decisions requiring confirmation

- Which ITAS objects and acknowledgements are legally authoritative.
- Whether VAT-MSA certification itself has legal fiscal effect.
- Statutory retention, data-residency and cross-border transfer requirements.
- Approved exchange-rate sources and VAT treatment of imported services/goods.
- Legal basis for risk analytics, profiling, automated decisions and data sharing.
- Rules for correction, cancellation, objection, refund and evidence disclosure.

Until confirmed, affected components are **APPROVED WITH CONDITIONS** or **REQUIRES LEGAL/REGULATORY CONFIRMATION**, never assumed production-ready.

