# VAT-MSA globalisation and country-compliance architecture

## 1. Decision status and implementation boundary

This document extends the approved VAT-MSA baseline into a global, multi-jurisdiction, multi-currency platform built from one global core plus signed country compliance packs and licensed deployment profiles.

**Architecture status:** `PROPOSED FOR APPROVAL`

**Country production status:** `NO COUNTRY PRODUCTION ACTIVATION AUTHORISED BY THIS DOCUMENT`

**Namibia pack status:** `UNDER REGULATORY REVIEW`
**Implementation boundary:** architecture, contracts, governance, logical models and non-executable reference manifests only.

No tax rate, deadline, identifier, invoice rule, government interface or residency rule becomes executable merely because it appears in this package. Regulatory facts require source evidence, independent approval, signed version publication, an effective date and a Country Readiness Gate decision.

## 2. Mandatory principles

1. One global core serves every jurisdiction; countries are not forks of the application.
2. A country pack is versioned regulatory configuration, not ordinary tenant configuration and not executable source code.
3. Jurisdiction comes from verified taxpayer/legal-entity evidence and an authorised licence entitlement, never from IP, GPS or browser locale.
4. Every country-sensitive transaction is pinned to legal entity, jurisdiction, compliance-pack version, tax-rule version and monetary context at commit time.
5. Original transaction amounts and currencies are immutable. Conversion produces additional attributed values; it never overwrites originals.
6. Security, tenant isolation, audit integrity, licence enforcement, cryptography, privileged access and input validation remain system-enforced.
7. Ordinary organisation administrators cannot create, edit, approve, activate or bypass national rules.
8. Historical calculations remain bound to the version effective for the transaction. Later rules apply only through a lawful adjustment process.
9. A regulator-specific requirement may refine global business behaviour, but cannot weaken the mandatory security baseline without a formally approved security exception.
10. Namibia is the first reference profile and uses `NAD` with `N$` presentation. A bare `$` is forbidden for Namibian monetary display.

## 3. Target C4 architecture

### Level 1: system context

VAT-MSA serves licensed organisations, their employees, authorised tax practitioners, regulator users and integration clients. It consumes authoritative identity, taxpayer, company, customs, exchange-rate and tax-filing evidence through country adapters. Regulators approve country packs; platform security approves integrity and deployment controls. No external system is assumed to exist until its official contract is verified.

### Level 2: containers

| Container | Responsibility | Country coupling |
|---|---|---|
| Global Web/API Edge | authentication, rate limits, request validation, locale negotiation | none; metadata only |
| Identity and Jurisdiction Resolver | verified identity, legal entity, registration and licence resolution | adapter and identifier catalogue |
| Country Pack Registry | signed manifests, versions, approvals, hashes, readiness state | owns country configuration |
| Global Tax Rule Engine | deterministic rule selection and calculation | consumes approved pack rules |
| Monetary and FX Service | ISO currency metadata, conversion, rate versions and rounding | consumes currency and country policy |
| Document Composition Service | versioned country/language/currency-aware templates | consumes pack templates and rules |
| Compliance and Return Service | obligations, periods, return definitions and submissions | consumes pack framework and adapters |
| Government Integration Gateway | tax, customs, registry, identity and signature adapters | one verified adapter contract per authority |
| Organisation Control Plane | employees, roles, workflows and licensed features | bounded by country and licence policy |
| Reporting and Evidence Service | jurisdiction-separated reports, exports and audit evidence | preserves entity/currency/rule metadata |
| Data Platform | global master data plus jurisdiction-partitioned operational data | policy-driven region and retention |

### Level 3: country framework components

- Pack Registry and Signature Verifier.
- Regulatory Change Workflow.
- Country Readiness Evaluator.
- Jurisdiction Resolution Policy.
- Tax Rule Selector and Deterministic Calculator.
- Currency Catalogue, Monetary Formatter and FX Rate Ledger.
- Identifier Definition and Validator.
- Tax Period and Business Calendar Resolver.
- Invoice/Document Rule Validator and Template Resolver.
- Residency, Retention and Privacy Policy Resolver.
- Government Adapter Registry.
- Rule Golden-Case Test Runner.
- Pack Activation and Rollback Controller.

The diagrams `global-country-context.mmd`, `country-pack-components.mmd` and `multi-country-deployment.mmd` are normative C4 views.

## 4. Domain architecture

### Global core bounded contexts

| Context | Global invariant |
|---|---|
| Identity | one authenticated subject is linked to verified legal-entity authority; no email-as-identity shortcut |
| Organisation | every operating unit belongs to one legal entity; legal entities are never merged for convenience |
| Licensing | country and compliance-pack entitlements are server-enforced and effective-dated |
| Jurisdiction | authoritative country, registration and establishment facts are resolved and snapshotted |
| Monetary | amount, currency, precision, conversion and rate provenance are explicit |
| Tax Determination | rules are selected by jurisdiction, supply facts, registrations and transaction time |
| Fiscal Document | invoice, credit/debit note and template versions are country-aware and immutable after issue |
| Compliance | periods, obligations, returns and deadlines come from approved country rules |
| Integration | authority contracts are isolated behind verified country adapters |
| Evidence | audit, approval, source and rule hashes are append-only and attributable |

### Country pack aggregates

- `CountryCompliancePack` is the aggregate root.
- `CompliancePackVersion` is immutable after approval.
- `RegulatoryRuleSet` contains effective-dated rule versions and source evidence.
- `CurrencyProfile`, `LocaleProfile`, `IdentifierProfile`, `InvoiceProfile`, `ReturnProfile`, `BusinessCalendar`, `ResidencyPolicy`, `PrivacyProfile` and `IntegrationProfile` are independently versioned pack modules.
- `RegulatoryApproval` records maker, reviewers, authority, decision and signature evidence.
- `CountryDeploymentProfile` binds a licensed legal entity to exactly one active pack version per jurisdiction/effective interval.

## 5. Authoritative jurisdiction resolution

Resolution is deterministic and fail-closed:

1. resolve authenticated subject and authorised organisation;
2. resolve the verified legal entity and taxpayer registration;
3. resolve the registration jurisdiction and effective interval;
4. resolve the organisation licence country entitlement;
5. require the registration and licence country to agree;
6. load the approved, production-enabled pack version effective at transaction time;
7. snapshot the resolution evidence on the transaction.

Conflicts, missing evidence, expired verification or a non-production pack produce `JURISDICTION_UNRESOLVED`; no fiscal certification or statutory filing may continue. Network geography can only create a risk signal.

Country change is a regulated migration case, never a normal settings mutation. It requires legal-entity verification, tax deregistration/registration evidence, impact assessment, dual approval, an effective date, immutable history and rollback/continuity planning.

## 6. Licensing and deployment profiles

A country entitlement contains:

```text
organisation_id
legal_entity_id
country_code
jurisdiction_id
compliance_pack_id
minimum_pack_version
functional_currency_code
allowed_transaction_currencies
effective_from / effective_to
licence_state / licence_version
```

The entitlement does not activate an unapproved pack. Activation requires the intersection of an active licence, verified registration, `PRODUCTION ENABLED` country readiness state and a signed pack version. Licence expiry remains non-destructive: historical records, authorised export, compliance and lawful corrections remain available under the continuity policy.

## 7. Country compliance-pack contract

Every pack follows a common schema:

```text
country-pack/
  manifest
  country/{identity,currency,locale,timezone,identifiers}
  tax/{framework,rates,categories,thresholds,filing,adjustments}
  invoicing/{rules,numbering,certification,templates}
  accounting/{mapping,reporting}
  compliance/{retention,privacy,residency,regulatory}
  integrations/{tax-authority,customs,registry,identity,government}
  calendars/{business-days,holidays,deadlines}
  tests/{schema,golden-cases,regression,conformance}
```

Pack content is declarative and schema-validated. No arbitrary script, SQL, expression language, network address, secret or executable binary is accepted. All rule operators, fields and actions come from a global allow-list.

### Pack lifecycle

`DRAFT -> VALIDATED -> COMPLIANCE_REVIEWED -> SECURITY_REVIEWED -> APPROVED -> SCHEDULED -> ACTIVE -> RETIRED`

Publication requires different maker and approver identities, source evidence, legal review, deterministic golden cases, global regression, signature verification, activation window and rollback target. `APPROVED` does not equal `PRODUCTION ENABLED`; the country readiness decision is separate.

## 8. Global tax-rule engine

### Selection input

- seller and buyer legal entity/jurisdiction;
- seller and buyer effective tax registrations;
- supply type, place-of-supply facts and product/service classification;
- transaction/document type and tax point;
- import/export/customs evidence;
- transaction and tax currencies;
- active country-pack and tax-rule versions.

### Deterministic output

```text
jurisdiction_id
pack_version_id
tax_framework_id
rule_version_id
tax_category
rate/amount/rounding result
tax_currency
legal explanation code
source evidence references
decision hash
```

No country rate is embedded in UI or general application code. Calculations use integer minor units or exact decimal arithmetic, explicit rounding mode and bounded precision. Floating-point arithmetic is prohibited for fiscal results. Recalculation loads the original version; a lawful correction creates linked adjustment evidence.

Cross-border rules are not guessed. An unresolved supply fact or unapproved rule creates a human-review exception.

## 9. Currency and monetary configuration engine

### Currency catalogue

The catalogue supports any ISO 4217 code with name, symbol, minor units, rounding increment, presentation pattern and effective interval. A symbol is never used as identity; APIs and storage use the code.

### Monetary record

Every foreign-currency fiscal amount retains:

```text
original_amount_minor + transaction_currency
exchange_rate_decimal + rate_direction
exchange_rate_source + source_reference
rate_effective_at + rate_version
functional_amount_minor + functional_currency
tax_amount_minor + tax_currency
conversion_rounding_mode + conversion_hash
```

The original pair is immutable. Rates are append-only, effective-dated and approved. Manual rates require a permission, step-up authentication, justification, second-person approval and scope; they cannot silently amend historical records.

For a Namibia deployment the default presentation is `N$1,000.00` and API currency code is `NAD`. Bare `$` is prohibited. CSV exports include separate amount and currency-code columns; PDFs and spreadsheets use the pack format and still carry the ISO code where ambiguity is possible.

## 10. Multi-country legal-entity model

A global enterprise may own several VAT-MSA organisations, but each legal entity retains its own registration, pack, functional/tax currency, records, policy, reporting and access boundary. Consolidated views are projections with explicit conversion and elimination metadata; they never merge statutory ledgers or returns.

An employee needs an explicit assignment per legal entity and jurisdiction. Cross-entity access is purpose-bound, time-bound and reviewed. Country deployment profiles can select different hosting regions without changing global domain contracts.

## 11. Country-specific identity and identifiers

`TaxIdentifierType` defines country, authority, format/version, validation method, effective dates, sensitivity and allowed use. `TaxIdentifier` retains original value, normalised value, verification status, verification evidence, primary/secondary rank and history. Format validation is not authority verification.

No core code assumes `VAT number`, `TIN` or `company number` exists in all countries. The active pack defines terminology and required combinations. Identifier values are masked outside authorised operational need.

## 12. Documents, localisation and calendars

### Document architecture

Templates are country-, language-, currency-, document-type- and rule-version aware. A rendered artifact stores template version, pack version, data hash, locale, currency and signature/certification evidence. Template publication follows regulatory dual control. An organisation may add branding only in approved extension zones.

### Localisation

Country defaults, organisation preference and user preference are resolved separately. Legal terminology always comes from the approved pack and cannot be replaced by a casual translation. Locale modules cover language, date/time, numbers, addresses, telephone format and terminology.

### Business calendar

Deadlines use a versioned jurisdiction calendar with business days, holidays, submission windows and adjustment rules. Generic weekend assumptions are prohibited. Unverified holidays or deadline-shift rules are not activated.

## 13. IAM, RBAC and ABAC

New protected roles:

- Global Pack Registry Operator: technical packaging only; cannot approve tax content.
- Regulatory Configuration Officer: drafts country rules for an assigned jurisdiction.
- Regulatory Reviewer: validates source and legal interpretation; cannot review own change.
- Regulatory Approver: approves version and effective date; cannot deploy own change.
- Country Release Operator: deploys already-approved signed versions.
- Country Compliance Auditor: read-only evidence and history.
- FX Rate Administrator and FX Rate Approver: separate maker/checker roles.

ABAC includes `jurisdiction_id`, `country_code`, `legal_entity_id`, `pack_status`, `rule_status`, `effective_time`, `authority`, `data_region`, `classification`, `purpose`, `step_up_age` and `approval_chain_complete`.

Technical administrators cannot change regulatory content. Regulator roles cannot change infrastructure, cryptography or global security policy. No emergency SoD override is introduced.

## 14. Regulatory administration and change control

Jurisdiction Configuration Administration is a separate portal and service boundary from Organisation Administration. Regulatory changes require source attachment, structured diff, impact assessment, backwards-compatibility review, test cases, maker/checker approvals, signature and scheduled activation. Activation is idempotent, emits an event and invalidates caches by pack version.

Rollback activates a previously approved compatible version for new transactions. It never rewrites transactions already pinned to another version.

## 15. API and event architecture

Every fiscal API response includes, when applicable:

```json
{
  "countryCode": "NA",
  "jurisdictionId": "jurisdiction-na-vat",
  "currencyCode": "NAD",
  "currencySymbol": "N$",
  "taxFramework": "NAMIBIA_VAT",
  "compliancePackVersion": "NAM-DRAFT-1.0.0",
  "taxRuleVersion": "pending-authority-approval",
  "taxPeriod": "authority-assigned"
}
```

Clients never infer this metadata. Catalogue additions cover jurisdiction resolution, pack inspection, rule validation/approval, currency/FX lookup, document templates, readiness and migration cases. Events carry IDs, versions, effective times and hashes—not full sensitive pack content.

## 16. Data architecture and ERD

New logical entities:

- `Country`, `Jurisdiction`, `LegalEntityJurisdiction`;
- `CountryCompliancePack`, `CompliancePackVersion`, `PackModule`, `PackSignature`;
- `RegulatoryAuthority`, `RegulatorySource`, `RegulatoryApproval`, `ComplianceChange`;
- `Currency`, `CurrencyFormat`, `CurrencyRate`, `CurrencyRateApproval`, `MonetaryConversion`;
- `TaxFramework`, `TaxRule`, `TaxRuleVersion`, `TaxRate`, `TaxCategory`;
- `TaxIdentifierType`, `TaxIdentifierVerification`;
- `InvoiceRule`, `DocumentTemplate`, `DocumentTemplateVersion`;
- `Locale`, `BusinessCalendar`, `PublicHoliday`, `DeadlineRule`;
- `GovernmentIntegration`, `CountryIntegrationContract`;
- `DataResidencyPolicy`, `PrivacyPolicy`;
- `CountryReadinessAssessment`, `CountryReadinessEvidence`;
- `JurisdictionMigrationCase`.

Country-sensitive operational rows add `legal_entity_id`, `jurisdiction_id`, `pack_version_id`, `tax_rule_version_id`, `transaction_currency_code`, `tax_currency_code` and immutable monetary/rule snapshots. Foreign keys and tenant predicates enforce legal-entity scope. The normative logical ERD is `diagrams/global-compliance-erd.mmd`.

## 17. Security, privacy and residency

Pack artifacts are signed outside the application database with keys held by an approved authority/KMS, verified on ingest and again before activation. Hashes, signatures, schema version, approvals and provenance are immutable. Pack registries are deny-by-default and cannot load arbitrary code.

Country privacy and residency profiles may strengthen collection, retention, transfer and hosting requirements. They cannot disable encryption, audit, tenant isolation, least privilege or secure deletion controls. Deployment placement is resolved before data creation; cross-region replication and support access must satisfy the active policy. Unknown residency law yields `NOT READY`.

## 18. Infrastructure architecture

The global control plane holds non-sensitive country metadata, signed pack artifacts and readiness state. Country data planes hold jurisdictional taxpayer and transaction data in approved regions. Each plane has isolated keys, queues, storage, backup/restore, logs and disaster-recovery evidence. The deployment orchestrator refuses a country profile when region, key custody, retention or integration prerequisites are unresolved.

Government adapters run in separate trust zones with country-specific egress allow-lists, credentials, schemas, retry policies and legal status mappings. An adapter being technically reachable does not make it authoritative.

## 19. Reporting architecture

Every statutory and operational report identifies legal entity, country, jurisdiction, pack version, tax framework/rule version, period and currency. Mixed-jurisdiction or mixed-currency reports show separate subtotals and explicit conversion metadata. Statutory exports never silently consolidate currencies.

## 20. Conflict resolution

1. resolve applicable law and authority through approved governance;
2. allow a country rule to override configurable business behaviour only inside that jurisdiction;
3. reject any rule that attempts to weaken system-enforced security;
4. require a formal legal/security exception if a genuine conflict remains;
5. preserve the decision, approvers, scope, expiry and compensating controls.

## 21. Country Readiness Gate

Allowed states are `NOT READY`, `IN DEVELOPMENT`, `UNDER REGULATORY REVIEW`, `TECHNICALLY READY`, `APPROVED` and `PRODUCTION ENABLED`. Promotion is monotonic through approvals; rollback can lower state.

Production enablement requires legal/tax evidence, regulator approval, source-complete rules, identifier/invoice/return conformance, currency and FX policy, privacy/residency decision, signed pack, golden cases, global regression, penetration and tenant-isolation evidence, government contract testing, operational ownership, DR exercise and monitoring. Any critical `UNKNOWN` keeps the country below `APPROVED`.

## 22. Implementation roadmap

| Increment | Output | Gate |
|---|---|---|
| G0 | approve ADR-020 through ADR-024 and governance owners | Architecture Board |
| G1 | global schemas, non-executable pack registry and metadata contracts | security/data design review |
| G2 | exact monetary, currency formatting and FX ledger with synthetic rates | finance/security acceptance |
| G3 | deterministic rule selector and signed-pack verification using synthetic country | golden tests; no statutory activation |
| G4 | Namibia pack evidence completion and NamRA legal review | Namibia Readiness Gate |
| G5 | Namibia adapter conformance against official ITAS/NamRA sandbox contract | authority acceptance |
| G6 | Namibia controlled pilot with approved rules and synthetic/authorised test data | production acceptance board |
| G7 | reusable second-country onboarding proves no core fork | global regression and country gate |

No later increment may be used to bypass an earlier regulatory gate.

## 23. Country compliance test architecture

Every executable country pack must provide automated schema, signature, effective-date, deterministic fiscal golden-case, historical replay, identifier, document, period/deadline, return, FX, reporting, API/event, isolation, security and rollback tests. The mandatory test contract is `country-compliance-test-catalog.csv`.

An approved regulator or legal interpretation is the oracle for a statutory test; implementation authors cannot invent expected fiscal outcomes. Test evidence records pack/rule versions, source and approval references, inputs, exact outputs, runner version, artifact digest, execution environment and timestamp. A failing mandatory test prevents signing or activation. Adding a country also runs the global suite against Namibia and every active pack.

The current Namibia test rows are `DESIGNED_NOT_IMPLEMENTED` or `REQUIRES_NAMIBIAN_CONFIRMATION`; this is intentional because its pack is non-executable.

## 24. Explicitly unresolved

- official ITAS/NamRA API, identity federation, acknowledgement and certification contracts;
- complete current Namibia tax category, exemption, zero-rate, import/export and adjustment catalogue;
- official electronic-invoice, QR, digital-signature and numbering requirements;
- regulator-approved exchange-rate sources and operational precision;
- Namibia privacy, data-residency, public-holiday and deadline-adjustment requirements;
- official identifier formats/verification interfaces;
- languages and regulator-approved legal translations;
- production hosting region, KMS/HSM, signing authority and pack-distribution mechanism.

Every item above is `REQUIRES NAMIBIAN REGULATORY CONFIRMATION` and blocks corresponding production capability.
