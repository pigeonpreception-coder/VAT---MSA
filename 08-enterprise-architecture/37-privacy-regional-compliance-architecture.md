# VAT-MSA privacy and regional security-compliance architecture

## 1. Privacy management objective

VAT-MSA is architected to align its privacy management system with ISO/IEC 27701:2025 and applicable privacy law. The system is not certified and no jurisdictional compliance claim is made without an approved applicability decision, implemented controls and appropriate independent/legal assessment.

Privacy policy follows the same hierarchy and signing/readiness controls as country compliance packs. A profile cannot create a lawful basis, legal interpretation or cross-border authorization; it can only encode a formally approved decision.

## 2. PIMS scope and roles

Each deployment records the legal entities and processing roles for every purpose. VAT-MSA, NamRA, a taxpayer organisation, cloud provider and integration provider may have different controller/processor or equivalent roles for different processing. Contracts and notices must match the actual decision authority and processing, not a generic SaaS assumption.

PIMS governance includes Executive/Board accountability, Privacy/Data Protection Authority, CISO, Legal, Records, Data Owners/Stewards, Product/Engineering, Procurement/Supplier Risk, SOC/Incident Response and independent assurance. Country-specific data protection officers or representatives are appointed when applicable.

## 3. Privacy data model

| Record | Required fields |
|---|---|
| processing activity | purpose, role, categories, data subjects, sources, recipients, jurisdiction, systems, lawful basis, retention and owner |
| purpose/lawful-basis decision | authority/legal memo, conditions, effective dates, compatible secondary uses and reviewer |
| notice | audience, language/version, purposes, disclosures, rights, contacts and effective period |
| consent record | exact optional purpose/notice, affirmative action, time, actor, scope, expiry and revocation; never used where not valid/appropriate |
| rights request | identity assurance, jurisdiction/right, scope, deadlines, searches, exemptions, decision, disclosure and appeal |
| DPIA/assessment | trigger, data flow, necessity/proportionality, risks, mitigations, residual risk and approval |
| transfer/residency decision | exporter/importer, countries/regions, data, mechanism, supplementary controls, validity and review trigger |
| breach assessment | incident, data/subjects, harm, countries, notification decisions/deadlines and communications |
| processor/supplier record | service, data, location, subprocessors, contract, evidence, incidents and exit/deletion |

## 4. Privacy-by-design gates

Every feature involving personal data must show:

1. named purpose and responsible owner;
2. necessity and minimum fields/events/logs;
3. appropriate lawful-basis/legal-authority determination;
4. data flow, recipients, residency and transfers;
5. classification, access, encryption and logging controls;
6. notice/consent handling where applicable;
7. rights, correction, restriction, objection and portability behavior where applicable;
8. retention, legal hold, backup expiry and secure disposal;
9. privacy/security abuse cases and DPIA threshold decision;
10. tests, evidence and review after material change.

Dark patterns, bundled optional consent, pre-checked consent, irreversible withdrawal friction and collection “just in case” are prohibited.

## 5. Rights and taxpayer-record integrity

The platform supports jurisdiction-configurable intake, identity verification, search, review, redaction, disclosure, correction/restriction and appeal. Rights are not automatically granted or denied based solely on a profile; Legal/Privacy approves applicability and exceptions.

Correction of personal data does not rewrite certified fiscal/audit history. The system uses linked corrections, annotations, restricted processing and disclosure explanations while preserving legal evidence. Identity proofing is proportional and cannot demand more personal data than the request risk warrants.

## 6. Retention, deletion and legal hold

Retention is effective-dated by record class, purpose, jurisdiction and legal authority. A valid legal hold overrides scheduled disposal without changing the original schedule. Disposal propagates to operational stores, objects, search, analytics, caches and downstream processors; immutable backups expire through the approved backup lifecycle rather than unsafe selective alteration.

Deletion jobs produce signed/tamper-evident manifests with scope, authority, affected systems, count, exceptions, completion and verification. Cryptographic erasure is used only where key scope and recovery/legal obligations make it valid. Licence expiry never deletes records or defeats rights/retention.

## 7. Data residency and transfers

Routing is fail-closed against an approved residency/transfer policy. Each data product records permitted primary, replica, backup, support and telemetry regions; remote administrator/support access is treated as a potential transfer where law requires. Recovery topology cannot silently place data in an unapproved country.

Transfer mechanisms, adequacy, contractual clauses, government-access risk and supplementary measures are legal decisions. The architecture supplies encryption, customer/sovereign key options where feasible, minimization, pseudonymization, access transparency, region isolation and provider exit, but these do not independently legalize a transfer.

## 8. EU/EEA applicability profile

The authoritative draft is `regional-compliance-applicability.csv`. GDPR applicability is assessed using establishment, offering/monitoring, data subjects, processing role, data categories and exemptions. If applicable, the profile addresses principles and accountability; Article 30 records; processor terms; privacy by design/default; security; DPIA; rights; breach assessment/notification; DPO/representative; and Chapter V transfers as applicable.

NIS2 is a directive implemented through Member State law. Applicability depends on the entity type, service, sector, size and national transposition/designation. If applicable, governance, risk management, incident reporting, supply chain, continuity, vulnerability, cryptography, access/MFA and supervisory evidence are mapped to the relevant national law. VAT-MSA does not label NIS2 an ISO standard or assume all EU deployments are covered.

Electronic identity/trust, operational resilience, financial-sector and public-sector rules are evaluated only where the actual service/entity is in scope.

## 9. United States applicability profile

The USA profile uses NIST CSF 2.0 as a voluntary risk framework unless contract/law makes a requirement binding. NIST SP 800-53 is a selectable control catalog; SP 800-171 Rev. 3 applies only where a federal contract or agreement places CUI in nonfederal systems. FedRAMP/FISMA obligations apply only to the relevant federal cloud use/authorization context.

State privacy, breach, tax, records, biometric, employment and sector laws require deployment-specific legal analysis. The record identifies states, residents, thresholds, entity exemptions, data categories, sale/share/targeted-advertising behavior, rights/appeals, sensitive-data consent, contracts and retention. VAT-MSA's tax-processing purpose must not be repurposed for advertising or data sale by default.

SOC 2 is an optional independent attestation against selected Trust Services Criteria, not a law or architecture certification. No SOC 2 claim is made before the appropriate examination report.

## 10. PCI DSS scope decision

Card payment remains disabled. A future payment design must first minimize scope through a hosted/tokenized provider so VAT-MSA does not store, process or transmit sensitive authentication data and handles the minimum payment-account data. A PCI Qualified Security Assessor or otherwise authorized competent party confirms scope, segmentation and validation method where needed.

If VAT-MSA stores, processes, transmits cardholder data or can affect its security, PCI DSS 4.0.1 control/evidence requirements enter the applicable control profile. Provider attestation does not automatically remove VAT-MSA's own responsibilities. No real charge or card test is authorized by this package.

## 11. Namibia security and privacy profile

The draft profile is `security-profiles/NAM/manifest.yaml` and is independently governed from the tax pack while linked by country code and readiness evidence.

Confirmed architectural context at the review date:

- Namibia-specific VAT, taxpayer, ITAS/NamRA, records, government, financial-sector and security requirements require authoritative Namibian review.
- Parliament materials show data-protection legislation was in the legislative process in 2026; a bill or order-paper entry is not treated as enacted law.
- Final privacy/data-sovereignty, breach, residency, transfer, rights, regulator and commencement requirements must be verified from gazetted law and authoritative guidance before implementation or production.
- ITAS/NamRA security contracts, government identity/PKI, incident contacts, audit access and interface requirements remain unconfirmed.

The Namibia profile therefore remains `DRAFT_LEGAL_AND_REGULATORY_REVIEW`, `executable: false`, `productionEnabled: false`. ISO/NIST/OWASP controls operate as risk baselines; they do not replace Namibian law or NamRA authority.

## 12. Other regional/country profiles

Africa, UK, Canada, Middle East, Asia-Pacific, Australia/New Zealand and Latin America are profile families, not legal regimes. Every country receives its own applicability decision for privacy, cybersecurity, tax, government integration, electronic transactions/signatures, records, financial/payment, data localization/transfer, incident reporting and accreditation.

A regional template may provide reusable questions and stronger defaults. It cannot automatically activate rules in a country or weaken the global baseline.

## 13. Compliance conflict resolution

When obligations appear to conflict:

1. stop the affected activation or protected processing;
2. preserve both source obligations, jurisdictions, versions and scope;
3. apply the non-bypassable global security floor unless it would violate law;
4. obtain country Legal/Privacy/Regulatory and Security decisions;
5. document precedence, compensating controls, residual risk and effective dates;
6. encode only the approved result in a new signed profile version;
7. test cross-border and global regressions before activation.

No engineer, tenant administrator or automated engine resolves a legal conflict by silent fallback.

## 14. Independent review triggers

Legal/privacy/regulatory or independent security review is mandatory for: new country; new data purpose/category; children's/biometric/high-risk identity processing; monitoring/profiling/AI; material data matching; new residency/transfer; government/financial/payment integration; bulk export/open data; new retention/disposal rule; high-impact automated decision; public verification expansion; incident notification; cryptographic/statutory signature; certification/accreditation claim; and material supplier/subprocessor change.

## 15. Evidence and acceptance

Before country production activation, the readiness gate requires signed applicability opinion, processing inventory, approved privacy/security profile, DPIAs where triggered, records schedules, residency/transfer decision, supplier contracts, rights and breach workflows, notification contacts, profile signature/rollback protection, conformance tests, penetration/tenant-isolation evidence, incident/DR exercise and independent review.

Public sources used for the current legal-status checkpoint include the [EU GDPR text](https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:32016R0679), [EU NIS2 text](https://eur-lex.europa.eu/eli/dir/2022/2555/en) and [Namibia Parliament bill tracker](https://laws.parliament.na/). Production legal decisions must cite the then-current authoritative instruments and local counsel/regulator approval.
