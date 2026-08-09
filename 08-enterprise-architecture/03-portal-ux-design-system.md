# D, N-O. Portal, UX and UI design-system architecture

## Experience model

One authenticated user enters a workspace switchboard. Available experiences derive from memberships, organisation buyer/seller capabilities and official/technical roles. Switching portal changes task emphasis and allowed actions, not the underlying taxpayer identity or records.

| Portal | Primary users | First-screen questions | Core navigation |
|---|---|---|---|
| Buyer | procurement, finance, accountant | What did we buy? Which input VAT is matched/eligible? What requires action? | Purchases, supplier invoices, input VAT, expenses, inventory receipts, approvals, returns, documents |
| Seller | sales, finance, accountant | What did we sell? Which invoices are certified/paid/matched? What output VAT is due? | quotations, customers, sales, invoices, output VAT, receivables, inventory, projects, returns |
| NamRA | compliance, audit, risk, reviewers | What is due, abnormal, unresolved or assigned to me? | taxpayers, registrations, invoices, transactions, returns, reconciliation, compliance, risk, audit, refunds, communications |
| NamRA Administration | authorised access administrators | Who can access which tax functions and why? | taxpayer activation, staff, roles, permissions, region/department, lifecycle, policy review |
| Super Administration | platform/SRE/security/integration operators | Is the platform healthy, secure and correctly configured? | health, capacity, security infrastructure, integrations, APIs, features, deployment and technical audit |
| Developer/Sandbox | approved SaaS teams | Is my app registered, conformant and within quota? | apps, credentials, docs, sandbox transactions, usage, conformance and production approval |

## Task journeys

Taxpayer: login → select organisation if delegated/multi-entity → choose Buyer/Seller workspace → guided business task → real-time validation → durable transaction receipt → contextual VAT/compliance impact → notification/action queue.

NamRA: login with required assurance → scoped work queue/search → taxpayer/transaction timeline → evidence and policy context → controlled workflow action/approval → immutable audit. Restricted internal risk never appears in taxpayer portals.

Registration: authenticate → provide/confirm VAT, TIN, company number and representative → retrieve/verify authoritative attributes → resolve duplicate/mismatch → MFA → create canonical organisation → assign first approved user → activate buyer/seller capabilities.

## Information architecture

Shared primitives: global organisation/portal switcher, contextual search, notification centre, task inbox, help, identity/assurance state and sign-out. Business/accounting and statutory VAT layers remain visually related but distinctly labelled. Every financial number exposes period, currency, status, source and drill-down evidence.

Enterprise search is permission-aware and domain-specific. It never implements unrestricted wildcard exports. Results show why records are available and apply masking, pagination and export approval.

## Design-system foundations

- Semantic tokens: canvas/surface/line; national navy; controlled teal; status green/amber/red; high-contrast ink.
- Typography: system sans for resilient rendering; monospaced style only for identifiers, hashes and machine evidence.
- Components: AppShell, PortalSwitcher, PageHeader, Metric, StatusBadge, Panel, DataTable, FilterBar, Field, Stepper, Alert, EmptyState, Timeline, ApprovalCard and EvidenceDrawer.
- Status language is consistent: Draft, Pending verification, Active, Under review, Matched, Certified, Rejected, Suspended, Reversed.
- Forms use visible labels, inline validation, plain-language recovery and confirmation summaries. Destructive/statutory actions require reason, impact and approval context.

## Accessibility and responsive behaviour

Target WCAG 2.2 AA, subject to independent audit. All workflows are keyboard-operable; headings/landmarks/table headers are semantic; focus is visible; errors are programmatically associated; status is not colour-only; touch targets are at least 44×44 where practical; text zoom does not hide functions. Tables become scrollable or task cards on narrow screens. Reduced motion is honored.

## Offline desktop experience

Offline work is a separately secured client, not browser local storage as authority. It stores encrypted signed work items, reserved sequence ranges and append-only local events; shows offline/provisional state; syncs through registered device identity and idempotent commands; and surfaces conflicts for controlled resolution. Certification and statutory acceptance remain server-confirmed.

## UX governance and measurement

No dark patterns, hidden legal effects or silent financial changes. Usability metrics include task completion, correction rate, support demand and taxpayer false-block rate. Accessibility, privacy and authorization reviews are release gates. Production content is bilingual/multilingual only after approved terminology and translation governance.
