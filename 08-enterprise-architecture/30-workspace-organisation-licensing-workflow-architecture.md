# Enterprise workspace, organisation administration, licensing and workflow architecture

**Status:** Architecture-board draft. This extension integrates with the approved VAT-MSA baseline. It does not authorize production implementation.

## 1. Scope and mandatory separation

This architecture adds five bounded capabilities to the existing platform: Workspace and Navigation Configuration, Organisation Administration, Licensing and Entitlements, Organisation Workflow, and Access Governance. They reuse the canonical Taxpayer, Organisation, Identity, Policy, Audit, Integration and Fiscal domains. They do not create a parallel taxpayer, buyer, seller or NamRA-administration model.

The controlling rule is:

`business configuration may shape permitted work; it may never weaken a system-enforced security, identity, tax, audit, tenant or licensing boundary.`

One verified VAT taxpayer maps to one organisation account. Buyer, Seller, Supplier, Customer, Importer, Exporter, Employer, Project Operator, Retailer and Service Provider are effective-dated organisation capabilities. Employee access to those capabilities is separately authorized.

## 2. Target component model

The following components extend the current modular core and are independently extractable only when scale, availability, isolation or team ownership justifies it:

| Component | Owns | Depends on | Must never own |
|---|---|---|---|
| Workspace and Navigation Service | workspace/folder/item definitions, ordering, favourites, recent functions, visibility projections | IAM policy decisions, entitlements, organisation capabilities | authorization truth or protected search records |
| Organisation Administration Service | employees, positions, job titles, departments, business units, branches and delegated administrator appointments | canonical Organisation, Identity, Workflow and Audit | taxpayer identity verification or NamRA roles |
| License and Entitlement Service | plans, subscriptions, organisation licences, feature grants, limits, usage reservations and licence events | verified taxpayer/organisation, approved subscription provider | payment-card data or self-service licence authority |
| Organisation Authorization Service | organisation roles, permission sets, assignments, capabilities, scopes and financial authorities | Identity, Organisation, Entitlement and central Policy | statutory/NamRA or platform security policy overrides |
| Workflow Service | definitions, immutable published versions, nodes, conditions, transitions, assignments, approvals, delegations and escalations | Authorization, SoD Policy, Audit, domain commands | source transaction mutation or tax-rule authority |
| Access Governance Service | access requests, approvals, reviews, certifications, dormant/excessive privilege findings and offboarding orchestration | Identity, HR/organisation data, Workflow and Security | deletion of historical actor attribution |
| Permission-aware Search Service | authorized navigation and record projections with field masking | domain read models and policy filter compiler | raw cross-tenant indexes exposed to clients |

See `diagrams/workspace-licensing-components.mmd` and the updated C4 and domain diagrams.

## 3. Organisation architecture

### 3.1 Canonical hierarchy

```text
Verified Taxpayer
  -> Organisation
      -> Organisation License
      -> Organisation Capabilities
      -> Branches / Departments / Business Units
      -> Employees / Positions / Reporting Lines
      -> Organisation Roles / Permission Sets
      -> Workflow Definitions and Published Versions
```

An Employee is an organisation-owned employment profile. A User is a human identity. An employee may link to one internal user; a user may have separate memberships or employee records in multiple organisations. The link does not merge organisations or grant cross-tenant rights.

### 3.2 Licensed administrator provisioning

The first Organisation Portal Administrator is provisioned only after all gating facts are satisfied: taxpayer identity verified, active VAT registration verified, organisation resolved or created without duplicate, eligible subscription activated, required identity assurance met, and administrator invitation/identity proof completed. Provisioning is idempotent and emits evidence.

The existing `Taxpayer Administrator` role is retained as a compatibility role key but its canonical display and architecture name becomes `Organisation Portal Administrator`. It is not a NamRA role. NamRA Administration and Super Administration remain separate control planes.

### 3.3 Administrator hierarchy

| Appointment | Maximum administrative scope | Prohibited inheritance |
|---|---|---|
| Primary Organisation Administrator | organisation configuration explicitly included in its policy | NamRA, platform operations, licence mutation, tax-rule authority |
| Finance Administrator | finance users/roles/workflows within assigned branches/business units | security policy, primary-admin change, unrelated departments |
| User/Access Administrator | employee, invitation, role assignment and review operations | financial records unless separately granted |
| Branch Administrator | assigned branch employees and local configuration | organisation-wide or other-branch access |
| Workflow Administrator | draft/test workflow definitions and approved publication requests | self-approval or domain execution authority |
| Integration Administrator | approved clients, webhooks and connector configuration | raw credentials, licence/API limit mutation or tax data by default |

Changing the primary administrator, granting a privileged administrator appointment or changing tax-sensitive authorization requires step-up authentication, re-authentication, recorded reason, an approved change workflow and immutable evidence. The required approval quorum is **PROPOSED - REQUIRES APPROVAL**.

## 4. License activation and entitlement architecture

### 4.1 Activation flow

`Select plan -> create pending subscription -> complete sandbox/approved payment activation -> verify taxpayer -> verify VAT registration -> resolve organisation -> create/link administrator identity -> assign organisation licence -> materialize entitlements -> administrator command centre.`

No stage trusts a client-provided success flag. Payment/subscription activation is accepted only from an approved provider adapter or authorised back-office activation command. VAT-MSA stores provider references and signed receipts, not payment-card data.

The payment provider, billing rules, plan catalogue, grace duration, renewal timing, refund policy and commercial prices are **PROPOSED - REQUIRES APPROVAL**.

### 4.2 Entitlement decision

Every protected operation evaluates:

```text
authenticated subject
+ active session and assurance
+ canonical organisation and active membership
+ organisation capability
+ role/permission and record scope
+ active licence state
+ feature entitlement
+ current/reserved usage within limit
+ workflow transition and financial authority
+ SoD and security policy
= allow with obligations OR deny with stable reason
```

The strictest applicable result wins. A plan grant cannot override identity, tenant, tax, audit, security or SoD denial. A role grant cannot create an unlicensed feature. A frontend visibility result is advisory; the command/API repeats the complete decision.

For consumable limits, mutation commands atomically reserve usage with the domain transaction or use a durable reservation/compensation pattern. Eventually consistent dashboard counters are not sufficient to authorize a write. Quotas are keyed by organisation, entitlement, period and resource type and are idempotent.

Reference checks include `CanCreateInvoice`, `CanCreateUser`, `CanCreateBranch`, `CanUseInventory`, `CanUseProjectManagement`, `CanUseAdvancedWorkflow`, `CanUseAPI` and `CanGenerateAdvancedReports`.

### 4.3 Licence states and data behavior

| State | Writes | Reads/reports | Integrations/API | Users/workflow | Records |
|---|---|---|---|---|---|
| Trial | entitled features within trial limits | entitled trial scope | sandbox/limited where granted | seat and workflow limits apply | retained by approved trial policy |
| Active | fully entitled within limits | fully entitled | enabled within scopes/quotas | enabled within seats/policies | retained by classification policy |
| Grace Period | essential tax/compliance writes plus approved business scope | read/export as policy permits | restricted to essential or approved connectors | no limit expansion; pending work continues if permitted | preserved |
| Pending Renewal | same as Active until effective expiry unless policy says otherwise | available | available within current entitlement | administrators warned | preserved |
| Suspended | deny new commercial writes except legally required corrective/compliance actions approved by policy | read-only/minimum compliance access | machine writes and new credentials denied | sessions may be restricted/revoked; tasks frozen or reassigned | preserved and placed on legal hold when applicable |
| Expired | deny new licensed business writes; allow approved retention/export/compliance path | read-only and statutory retrieval | disabled except status/export endpoints | no new users/workflows; existing identities retained disabled/read-only per policy | never silently deleted |
| Cancelled | effective-date behavior equivalent to expired after any approved notice period | controlled retrieval/export | disabled | access reduced according to retention policy | preserved |
| Upgraded | new plan/entitlements effective atomically at recorded time | expanded from effective time | new limits/scopes after policy evaluation | additional seats/features available | unchanged history |
| Downgraded | no destructive truncation; new writes obey lower limits at effective time | historical data remains readable as policy permits | disallowed features disabled prospectively | excess seats require controlled remediation, never random deletion | preserved |

Exact grace, suspension, export, legally required transaction and post-expiry access policies are **PROPOSED - REQUIRES APPROVAL** by Product, NamRA Tax, Legal, Records and Security.

## 5. Dynamic workspace and navigation engine

### 5.1 Information architecture

The canonical workspace groups are configuration records, not hardcoded per screen:

| Workspace | Folders and representative functions |
|---|---|
| Home / Command Centre | Dashboard: executive, financial, VAT, compliance, operational; notifications; tasks; alerts |
| Sales and Revenue | Sales: customers, quotations, sales orders, tax invoices, credit/debit notes, receipts, reports; Revenue: analysis, output VAT, balances, performance |
| Procurement and Purchases | Procurement: suppliers, requests, orders, supplier invoices, credit/debit notes; Purchases: records, input VAT, balances, analysis |
| VAT and Tax Management | VAT transactions, input/output VAT, reconciliation, exceptions, adjustments; returns by lifecycle/period; compliance, notices, correspondence, obligations |
| Accounting and Finance | general ledger, trial balance, AR/AP, journals, chart; cash flow, statements, budgets, analysis, period close |
| Inventory and Operations | products, stock, warehouses, transfers, adjustments, valuation; expenses, claims, cost control, operational budgets |
| Project Management | projects, budgets, costing, tasks, expenses, procurement, financials and reports |
| Documents and Records | tax documents, contracts, invoices, supporting/uploaded files, reports; audit, transaction, correspondence and system records |
| Reporting and Analytics | VAT, financial, sales, purchase, inventory, project and compliance reports; BI, trends, KPIs, tax analytics and custom reports |
| Integrations | ITAS, accounting, POS, ERP, banking/payment, SaaS and API connections; developer clients, keys, webhooks, logs, usage and docs |
| Administration | organisation structure; employees/accounts/invitations/access reviews; roles/permissions/capabilities/policies; workflow; MFA/sessions/devices/events/logs/privileged access |
| Licensing and Subscription | current licence/plan, usage, seats, features, billing, renewal, upgrade and downgrade; administrator-only |

### 5.2 Visibility and enforcement

The server returns a policy-filtered navigation projection using the order `identity -> organisation -> licence -> capability -> role/permission -> security policy -> workspace/folder/item/action`. Each result contains stable IDs, label keys, hierarchy, route/action reference, visibility reason, restriction type, version and cache metadata. The client never submits trusted permission expressions.

Visibility outcomes are `AVAILABLE`, `RESTRICTED_PERMISSION`, `RESTRICTED_LICENCE`, `REQUIRES_STEP_UP`, `DISABLED_POLICY` or `HIDDEN_SENSITIVE`. Only safe restriction explanations are returned. Sensitive NamRA, risk and commercial information is hidden rather than advertised.

### 5.3 Interaction and performance

- Only one primary workspace is expanded by default; selecting another collapses the previous primary branch.
- The current route and its ancestors remain visible. A user preference may retain one secondary expansion without authorizing content.
- Breadcrumbs are derived from stable hierarchy IDs: Workspace > Folder > Subfolder > Function.
- Favourites, recent functions and open/collapsed state are device/user preferences and do not grant access.
- Root navigation is a compact authorized projection. Children are lazy-loaded and paginated/cursor-based where large.
- Navigation projections are cached by user, organisation, policy version, entitlement version and navigation version. Role/licence/security events invalidate affected keys.
- Keyboard tree semantics, focus management, accessible names, visible focus, 44-pixel touch targets where practical and responsive drawers target WCAG 2.2 AA.

## 6. Dynamic organisation authorization

### 6.1 Model

`Job Title -> Position -> Organisation Role -> Permission Set -> Organisation Capability -> Record Scope -> Financial Authority -> Workflow Authority`.

Job titles and positions describe employment; they do not confer access. Roles are organisation-specific templates. Permission grants are normalized records and are never executable code or unrestricted query fragments.

Permission dimensions include:

- module and function/action;
- own, reporting-line, department, branch, business-unit or organisation record scope;
- amount/currency threshold and transaction class;
- prepare, review, recommend, approve, reject, escalate and execute workflow authority;
- effective time, expiry, delegation, assurance and device/risk obligations.

The policy engine compiles these into safe server/data predicates. Data access always includes an organisation key and, where required, branch/department/case filters. Database row-level security is recommended for national production as defence in depth; service authorization remains mandatory.

### 6.2 Buyer/seller capability

Organisation capabilities enable functions at tenant level; user capability assignments and permissions enable them at subject level. An organisation with Buyer and Seller capability remains one taxpayer. Customer and Supplier are organisation-scoped party relationships, not new identities. The same employee may receive Buyer, Seller, VAT and Accounting rights subject to SoD and workflow policy.

## 7. Workflow, version control and segregation of duties

### 7.1 Definition and publication

A workflow begins as an editable draft. Administrators compose typed nodes, transitions, conditions, assignments, timeouts, escalation, delegation and threshold rules from an approved expression vocabulary. Arbitrary scripts, SQL, remote code and client-supplied predicates are forbidden.

`DRAFT -> VALIDATED -> CHANGE_APPROVAL_PENDING -> PUBLISHED -> RETIRED`.

Publishing creates an immutable WorkflowVersion with definition hash, compiler/policy version, effective time, approvers and test evidence. New transactions pin the effective version. Existing workflow instances continue on their pinned version unless a separately approved migration creates a recorded transition; historical approvals never move between versions.

### 7.2 Execution

The domain transaction requests a workflow instance. The engine resolves assignments from pinned organisation/role/branch/amount facts, creates tasks, records decisions append-only and returns only authorized domain transitions. Parallel approvals define quorum explicitly. Timeouts escalate through durable scheduled commands. Delegation/substitution is effective-dated, scoped, approved and cannot exceed the delegator's authority.

### 7.3 SoD

SoD is a system policy evaluated at design validation, assignment and decision time. Rules can prohibit combinations such as create+approve, approve+execute or workflow-admin+own-change-approval for a protected transaction. A workflow administrator does not inherit approval rights. A primary administrator does not bypass SoD.

A detected conflict fails closed, creates `SoDViolationDetected`, identifies the policy without exposing sensitive detection logic, and routes to approved remediation. Emergency override is **PROPOSED - REQUIRES APPROVAL** and, if allowed, requires narrow JIT authority, step-up, independent approval, expiry and retrospective review.

## 8. Access governance and employee lifecycle

Access request: employee request -> manager review -> organisation access administrator review -> optional security/owner review -> grant with effective/expiry time -> audit and cache invalidation.

Access certification campaigns snapshot users, roles, permissions, capabilities, privileged appointments, dormant activity and SoD findings. Reviewers certify, reduce, revoke or escalate. Evidence preserves the reviewer, facts, policy versions and completion time.

Offboarding is one idempotent orchestration: disable employee/membership -> revoke sessions/tokens -> revoke owned/delegated API credentials -> remove future access -> reassign pending tasks under approved policy -> preserve actor attribution and transaction history -> emit audit/security events. Historical ownership fields reference durable user/employee identifiers and are never replaced with a generic administrator.

## 9. Data model and invariants

The updated ERD adds `License`, `LicensePlan`, `Subscription`, `Entitlement`, `Feature`, `OrganisationLicense`, `LicenseUsage`, `LicenseEvent`, `OrganisationAdministrator`, `OrganisationAdministratorRole`, `Employee`, `JobTitle`, `Position`, `Department`, `BusinessUnit`, `OrganisationRole`, `Permission`, `RolePermission`, `UserRole`, `UserCapability`, workflow entities, access-governance entities, SoD entities and navigation entities.

Mandatory invariants:

1. Every organisation-owned record includes `organisation_id`; foreign keys cannot cross organisations.
2. One organisation has at most one effective primary administrator appointment at a time.
3. Plan/entitlement versions and subscription state transitions are append-audited; organisation users cannot write them.
4. Published workflow versions and completed approvals are immutable.
5. A workflow instance pins exactly one published version.
6. Usage reservations are unique by organisation, metric, period and idempotency identity.
7. Navigation permission expressions reference typed policy/entitlement IDs, not executable text.
8. Offboarding preserves historical identities and approvals.

National indexes include organisation+status/name for employees; organisation+role/subject/effective time for assignments; organisation+feature/state/effective time for licences; organisation+metric+period for usage; organisation+workflow+version/status; assignee+status+due time for tasks; organisation+parent+order for navigation; and organisation+campaign/status for access reviews. Large sets use server filtering and keyset pagination.

## 10. API and event contracts

All new endpoints pass the existing gateway plus `identity + organisation + role/permission + entitlement + security policy + workflow/SoD` enforcement. Commands require expected version and idempotency where material. Reads apply policy-derived filters before query execution.

The authoritative additions are in `api-catalog.yaml`; key groups are `/licensing`, `/organisations/{id}/employees`, `/organisation-roles`, `/workflows`, `/access-governance`, `/navigation` and `/search`. Upgrade/renew commands initiate an approved subscription-provider workflow and never directly accept a client-selected licence state.

The authoritative additions are in `event-catalog.csv`. Sensitive events minimize payload, use the transactional outbox, partition by organisation or aggregate, and flow to immutable audit/security evidence. Entitlement and access changes trigger bounded cache/session invalidation.

## 11. Security and abuse testing

The threat model adds licence-state tampering, quota races, navigation leakage, custom-role escalation, self-approval, workflow-definition injection, historical-version rewrite, delegated-admin breakout, cross-tenant search, dormant-session persistence and offboarding races.

Required negative evidence includes:

- direct API calls to hidden/unlicensed functions are denied;
- changing client navigation/role/licence fields grants nothing;
- organisation A cannot reference organisation B IDs in queries, commands, exports, search or workflow assignments;
- concurrent quota writes cannot exceed approved bounds without a detected/compensated outcome;
- a creator cannot approve or execute when SoD forbids it;
- an administrator cannot publish a workflow or grant a privileged role without required approval and assurance;
- published workflow history, audit evidence and licence events reject update/delete attempts;
- token/session/credential revocation completes within the approved bound after suspension/offboarding;
- ordinary organisation administrators cannot impersonate users or disable monitoring, encryption, MFA minimums or tenant controls.

The testing environment must use disposable synthetic data and explicit authorization. Production destructive, payment, messaging and stress actions remain prohibited without separate approval.

## 12. Organisation Administration Command Centre

The administrator experience is a permission- and licence-aware workspace, not an all-powerful portal. It presents organisation/VAT identity and structure; user/seat/invitation/dormancy status; roles, permission sets, access requests and certification; active workflows, pending approvals and versions; licence, feature, API/storage/seat usage and renewal; and organization-scoped security alerts, devices, sessions and privileged changes.

The security view never exposes NamRA internal detections, SOC infrastructure or other tenants. Access Restricted states identify the missing permission, entitlement or step-up action only when disclosure is safe. Upgrade details are visible only to billing/licence administrators.

## 13. Configuration versus system-enforced controls

The expanded formal classification is `configuration-system-control-matrix.csv`. Configuration includes organisation-specific titles, roles, workflow shape, thresholds within platform maximums, navigation ordering and enabled capabilities within purchased entitlements. System controls include identity, tenant isolation, licence authority, policy enforcement, tax rules, immutable history, encryption minimums, audit/security monitoring and privileged-access requirements.

## 14. Contradictions and resolutions

| Finding | Resolution |
|---|---|
| Existing portals describe separate Buyer and Seller experiences; the brief prohibits separate organisations. | No contradiction. Experiences become workspace emphasis over one organisation and shared records. |
| Existing `Taxpayer Administrator` and new `Organisation Portal Administrator` overlap. | Treat them as one organisation role with a compatibility key; do not create a second administrator identity. |
| Existing role families are mostly predefined; the brief requires organisation-specific roles. | Keep system role families as protected templates; introduce tenant-owned OrganisationRole and PermissionSet instances below platform/NamRA policy ceilings. |
| Existing workflow references are domain-specific; the brief requires a designer. | Add a typed versioned Workflow domain. Domain services retain authority for legal transitions and expose only approved extension points. |
| Existing subscription was not an authoritative bounded context. | Add License and Entitlement as a separate commercial-control domain that cannot override statutory/security controls. |
| Existing UI navigation is portal-oriented. | Replace static menu authority with a server-built workspace projection while preserving portal audiences and routes during migration. |
| Payment activation is requested but real payment authority/provider is unapproved. | Model the adapter and state machine only; keep provider and commercial rules gated. |

## 15. Required changes and dependencies

1. **Database:** add the entities, tenant keys, immutable versions, usage reservations, constraints and indexes in section 9; migrations require design review and backfill/reconciliation plans.
2. **API:** add licensing, organisation administration, role/permission, workflow, navigation, access-governance and permission-aware search contracts; extend the gateway policy context with entitlement and SoD decisions.
3. **Security:** add entitlement enforcement, typed workflow compiler, SoD policy, step-up/change approval, access certification, rapid revocation and new abuse tests.
4. **UX:** adopt collapsible hierarchical workspaces, breadcrumb/search/favourites/recent functions, restricted states and the Administration Command Centre.
5. **Infrastructure:** add centrally managed policy/entitlement caches with invalidation, durable scheduler for workflow deadlines, permission-aware search indexes, immutable evidence for licence/workflow/admin events and high-cardinality-safe telemetry.
6. **Licensing:** approve provider, plan catalogue, prices, taxes, grace/suspension/renewal/downgrade rules, metering definitions, dispute/refund policy and billing-data ownership.
7. **Regulatory:** approve post-expiry statutory access, retention/export, legally required corrective actions, administrator proofing and whether licence state may ever block a statutory duty.
8. **ITAS:** confirm taxpayer/VAT verification attributes, identity federation assurance, organisation lifecycle/status changes and service availability needed during activation.
9. **Operations:** define cache invalidation/revocation SLOs, workflow timer recovery, quota reconciliation, support/escalation ownership and scale tests for thousands of employees/permissions/workflows.
10. **Governance:** approve ADR-016 through ADR-019, the configuration-control matrix, new domain ownership and the revised roadmap before production coding.

## 16. Proposed decisions requiring explicit approval

- **PROPOSED - REQUIRES APPROVAL:** licence plans, commercial feature bundles and metric limits.
- **PROPOSED - REQUIRES APPROVAL:** payment/subscription provider and activation/refund contracts.
- **PROPOSED - REQUIRES APPROVAL:** grace, suspension, expiry, downgrade and statutory-continuity behavior.
- **PROPOSED - REQUIRES APPROVAL:** first/changed primary administrator proofing and approval quorum.
- **PROPOSED - REQUIRES APPROVAL:** configurable financial thresholds and platform-enforced maximums.
- **PROPOSED - REQUIRES APPROVAL:** emergency SoD override, if any.
- **PROPOSED - REQUIRES APPROVAL:** access certification frequency and dormant-account thresholds.
- **PROPOSED - REQUIRES APPROVAL:** workflow expression vocabulary and which domain transitions are configurable.
- **PROPOSED - REQUIRES APPROVAL:** navigation labels, multilingual terminology and licence-upgrade messaging.
- **PROPOSED - REQUIRES ITAS/NAMRA/LEGAL CONFIRMATION:** authoritative verification and statutory access when licensing is restricted.

## 17. Architecture acceptance gate

Architecture approval requires review of ADR-016 through ADR-019, the updated C4/domain/ERD/API/event/threat/UX artifacts, the configuration-control matrix, the requirements traceability extension and the decision rows in `29-architecture-approval-gate.md`.

Production implementation of these capabilities remains blocked until applicable rows are `APPROVED` or `APPROVED WITH CONDITIONS` with named owners and dates. Disposable non-production spikes may validate policy evaluation, quota concurrency, workflow compilation and navigation performance, but must not be represented as production licensing, payment, ITAS or statutory authority.
