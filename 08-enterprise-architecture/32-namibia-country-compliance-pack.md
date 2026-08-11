# VAT-MSA Namibia country compliance pack

## 1. Pack declaration

| Field | Architecture value |
|---|---|
| Pack ID | `VAT-MSA-NAM` |
| Country | Namibia |
| ISO country code | `NA` |
| Jurisdiction | `NAMIBIA_VAT` |
| Currency | Namibian Dollar |
| ISO currency code | `NAD` |
| Currency symbol | `N$` |
| Default display | `N$1,000.00` |
| Tax authority | Namibia Revenue Agency (`NamRA`) |
| Tax administration portal | Integrated Tax Administration System (`ITAS`) |
| Pack version | `NAM-DRAFT-1.0.0` |
| Readiness | `UNDER REGULATORY REVIEW` |
| Executable | `false` |
| Production enabled | `false` |

This is the first reference pack and an architecture artifact. It cannot calculate, certify, file or assert statutory compliance in production.

## 2. Evidence classification

- `SOURCE VERIFIED`: an authoritative source was located and the architecture may model the fact.
- `REGULATORY CONFIRMATION REQUIRED`: NamRA/legal owners must confirm currency of interpretation, machine-readable detail and operational use.
- `UNKNOWN`: no authoritative implementable contract is available; capability remains disabled.

Even `SOURCE VERIFIED` facts remain non-executable until the complete pack is approved, signed and production enabled.

## 3. Verified Namibia reference facts

| Area | Reference fact | Evidence | Architecture treatment |
|---|---|---|---|
| currency | Namibian Dollar, code `NAD`; Bank of Namibia displays denominations using `N$` | Bank of Namibia currency pages | `SOURCE VERIFIED`; use `N$` for Namibia UI and `NAD` in storage/APIs |
| VAT framework | Value-Added Tax Act 10 of 2000, as reflected in NamRA's hosted annotated text | NamRA-hosted Act | `SOURCE VERIFIED`; exact consolidated legal currency still requires legal review |
| standard rate | section 6 states 15% on taxable supplies/imports subject to the Act | Act section 6; official VAT brochure | `SOURCE VERIFIED`; disabled until regulatory approval and golden cases |
| tax invoices | section 21 and Schedule VI govern tax invoices; Schedule VI lists core particulars | Act section 21 and Schedule VI | `SOURCE VERIFIED`; full template and exceptions require confirmation |
| credit/debit notes | section 22 and Schedule VI govern credit and debit notes | Act section 22 and Schedule VI | `SOURCE VERIFIED`; adjustment workflow requires confirmation |
| tax periods | section 23 includes two-month Categories A/B and specific alternatives | Act section 23 | `SOURCE VERIFIED`; taxpayer category must be authority assigned |
| filing due date | section 24 states the twenty-fifth day after the period end | Act section 24 | `SOURCE VERIFIED`; holiday/weekend shifting is unknown |
| retention | section 48 requires relevant records for at least five years after period end | Act section 48 | `SOURCE VERIFIED`; legal hold and other laws may extend retention |
| tax currency | section 81 requires VAT amounts in Namibia's currency and addresses foreign-currency conversion | Act section 81 | `SOURCE VERIFIED`; rate source/precision workflow requires confirmation |
| ITAS | official portal supports taxpayer account linking and VAT-return interaction | NamRA/ITAS official pages | portal existence verified; all machine interfaces remain `UNKNOWN` |

## 4. Currency and monetary profile

```yaml
currencyCode: NAD
currencyName: Namibian Dollar
currencySymbol: N$
minorUnits: 2
defaultPattern: "N$1,000.00"
bareDollarSymbolAllowed: false
taxCurrency: NAD
```

The UI, PDFs, spreadsheets and notifications must use `N$` for Namibian amounts. APIs use `NAD` and return the symbol as metadata. CSVs separate numeric amount and currency code. Any foreign-currency transaction retains original currency/amount, exact rate and source, converted NAD amount and tax amount in NAD.

**REQUIRES NAMIBIAN REGULATORY CONFIRMATION:** approved exchange-rate sources, timing, direction, rounding precision, import conversion interaction with customs law and authorised manual-rate process.

## 5. Tax framework profile

The draft pack defines the vocabulary `STANDARD`, `ZERO_RATED`, `EXEMPT`, `OUTSIDE_SCOPE`, `REVERSE_CHARGE`, `IMPORT`, `EXPORT`, `CREDIT_ADJUSTMENT` and `DEBIT_ADJUSTMENT`. Only `STANDARD` has a sourced reference value in this architecture. The presence of a category does not assert that a particular supply qualifies.

```yaml
framework: NAMIBIA_VAT
standardRateReference: "15%"
standardRateActivation: DISABLED_PENDING_REGULATORY_APPROVAL
zeroRateRules: NOT_LOADED
exemptionRules: NOT_LOADED
importRules: NOT_LOADED
exportRules: NOT_LOADED
reverseChargeRules: NOT_LOADED
```

Schedules, amendments, notices, rulings and transitional rules must be converted into structured rules only after legal interpretation, source versioning, NamRA approval and golden-case testing.

## 6. Registration and identifier profile

The pack supports authority-defined VAT registration number, tax identification number and legal-entity/company identifiers without presuming that format validation is verification.

**REQUIRES NAMIBIAN REGULATORY CONFIRMATION:** current mandatory/voluntary registration thresholds, identifier formats/check digits, issuing authorities, precedence, effective/expiry rules, deregistration, entity types, BIPA integration and ITAS verification protocol.

The threshold shown in historical public material is evidence for legal review, not an executable value.

## 7. Tax period and deadline profile

Section 23 provides source evidence for two-month Category A/B periods, farming alternatives and a six-month category in stated circumstances. The Commissioner assigns or approves applicable periods. VAT-MSA therefore stores an authority-assigned period code and effective interval rather than allowing a taxpayer dropdown to determine the legal period.

Section 24 provides a twenty-fifth-day filing reference. Calendar adjustment for weekends, public holidays, outages or extensions is not inferred.

**REQUIRES NAMIBIAN REGULATORY CONFIRMATION:** current category assignment data, exception/extension notices, payment deadline alignment, business-day rules, public-holiday calendar and ITAS submission windows.

## 8. Invoice, credit-note and debit-note profile

Schedule VI supplies source evidence for core tax-invoice particulars including the document label, supplier identity/address/VAT registration number, recipient name/address, individual serial number/date, description, quantity/volume and tax/consideration totals. Credit and debit notes require linked supply and adjustment particulars.

The final template must be published as a signed, effective-dated document-rule version. The organisation may add branding only outside protected statutory zones.

**REQUIRES NAMIBIAN REGULATORY CONFIRMATION:** complete current exceptions, simplified invoice treatment, serial-number authority, copies, self-billing approval, language, digital signatures, certification, electronic invoicing, QR/barcode and government transmission requirements.

## 9. VAT return and reporting profile

The pack models an authority-defined return schema, period, rule version, calculation evidence and submission acknowledgement. ITAS publicly supports VAT return interaction, but no official machine API contract has been approved for VAT-MSA.

`VAT return generation` may be tested using synthetic schemas. `Official filing`, `acceptance`, `assessment`, `payment` and `refund` remain disabled.

**REQUIRES NAMIBIAN REGULATORY CONFIRMATION:** return fields/formulas, amendment lifecycle, attachments, validation codes, acknowledgements, legal submission time, payment/reference protocol and retention of official receipts.

## 10. Import, export and cross-border profile

The Act contains import, export, zero-rate, exemption and currency-conversion provisions. They are too context-dependent to translate from headings or examples alone.

**REQUIRES NAMIBIAN REGULATORY CONFIRMATION:** complete place-of-supply rules, customs valuation/rates, import VAT account process, ASYCUDA/Customs interfaces, export evidence, cross-border services, reverse charge and international agreement effects.

Unresolved cross-border facts must enter `HUMAN_REVIEW_REQUIRED`; the engine cannot guess treatment.

## 11. Records, privacy and residency

The VAT Act provides source evidence for English-language records, specified fiscal records, access/production duties and at least five-year retention. Section 48 also contains conditions relevant to records maintained outside Namibia.

**REQUIRES NAMIBIAN REGULATORY CONFIRMATION AND LEGAL REVIEW:** complete retention overlays, legal holds, electronic evidence, approved hosting regions, cross-border transfers, regulator access, privacy law, data-subject rights, breach notification and deletion/anonymisation rules.

Until decided, production residency is `NOT READY`; architecture does not infer that global hosting is lawful.

## 12. Language, locale, date and time

Draft technical defaults are `en` interface fallback, Gregorian dates and Namibia deployment time-zone metadata. Legal terminology and documents remain governed by the pack.

**REQUIRES NAMIBIAN REGULATORY CONFIRMATION:** official/accepted filing languages, translated legal terms, approved date/number pattern, `Africa/Windhoek` operational use, public holidays and deadline-shift rules.

## 13. Government integrations

| Integration | Status | Permitted architecture behaviour |
|---|---|---|
| NamRA/ITAS taxpayer identity | `REQUIRES OFFICIAL CONTRACT` | adapter skeleton and contract tests only |
| NamRA/ITAS VAT filing | `REQUIRES OFFICIAL CONTRACT` | synthetic payloads only |
| NamRA acknowledgements/status | `REQUIRES OFFICIAL CONTRACT` | no legal status claims |
| Customs/ASYCUDA | `REQUIRES OFFICIAL CONTRACT` | no live connectivity |
| BIPA/company registry | `REQUIRES OFFICIAL CONTRACT` | no authority verification claims |
| Government payments | `DISABLED` | no real payment execution |
| Digital signature/certification | `REQUIRES AUTHORITY DECISION` | local test signatures have no statutory effect |

Credentials, URLs, schemas and SLAs are never invented.

## 14. Namibia readiness assessment

| Gate | Status | Blocking evidence |
|---|---|---|
| country/currency identity | `TECHNICALLY READY` | final presentation convention approval |
| legal source inventory | `UNDER REGULATORY REVIEW` | consolidated sources/notices/rulings and legal opinion |
| tax rules | `NOT READY` | complete approved rule catalogue and golden cases |
| identifiers/registration | `NOT READY` | authority contract and format catalogue |
| invoice/documents | `UNDER REGULATORY REVIEW` | complete current template requirements |
| returns/deadlines | `NOT READY` | official schema, formulas, calendar and interface |
| FX | `NOT READY` | approved source, timing and precision |
| privacy/residency | `NOT READY` | legal/hosting decision |
| government integrations | `NOT READY` | official specifications and sandbox |
| security/operations | `IN DEVELOPMENT` | KMS/HSM, signing, penetration, DR and SOC evidence |
| overall country | `UNDER REGULATORY REVIEW` | all critical gates above |

The overall state cannot advance beyond its least-ready critical gate.

## 15. Required Namibia compliance tests

- N$ formatting and rejection of ambiguous `$`;
- exact 15% reference calculation only after rule approval;
- zero/exempt/out-of-scope classification golden cases;
- invoice, credit-note and debit-note required-field cases;
- authority-assigned period categories and due-date cases;
- at-least-five-year retention and legal-hold cases;
- original/functional/tax currency preservation and exact conversion;
- historical rule pinning across a simulated rate change;
- import/export and cross-border evidence cases;
- return schema/formula and acknowledgement contract tests;
- identifier format versus authority-verification distinction;
- signed-pack tamper, rollback and unauthorised-activation tests;
- global tenant, IAM, licensing and audit regression.

## 16. Authoritative source register

Retrieved 2026-08-10/11 for architecture review:

1. [NamRA-hosted Value-Added Tax Act 10 of 2000](https://www.namra.org.na/documents/cms/uploaded/valueadded-tax-act-10-of-2000-0d8c7ed7fc.pdf).
2. [Official VAT information/brochure hosted by NamRA/ITAS](https://itas.namra.org.na/assets/documents/other-forms/Value_Added_Tax_Brochure.pdf).
3. [NamRA/ITAS official tax information](https://www.itas.namra.org.na/taxes).
4. [NamRA/ITAS portal information](https://www.itas.namra.org.na/about).
5. [Bank of Namibia currency information](https://www.bon.com.na/Currency/Learn/Explore-Currency.aspx).

Source availability does not replace a formal legal opinion, NamRA approval or an official machine-interface contract.
