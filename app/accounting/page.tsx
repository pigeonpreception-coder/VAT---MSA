import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PageHeader, StatusBadge } from "@/components/PageHeader";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { getBusinessPlatformSnapshot } from "@/lib/data/business-repository";

export const metadata: Metadata = { title: "Accounting" };
export const dynamic = "force-dynamic";

export default async function AccountingPage() {
  const user = await getCurrentUser();
  requirePermission(user, "accounting:read");
  const snapshot = await getBusinessPlatformSnapshot(user);
  return <AppShell active="accounting" permission="accounting:read">
    <PageHeader eyebrow="Accounting domain" title="Controlled general ledger" description="Every journal is tenant-scoped, uses integer cents and must balance before it can be posted. Source references preserve traceability to operational and fiscal evidence." />
    <section className="metric-grid">
      <article className="metric"><div className="metric-top"><span className="metric-label">Ledger accounts</span><span className="metric-icon">A</span></div><div className="metric-value">{snapshot.accounts.length}</div><div className="metric-foot">Controlled chart of accounts</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Journals</span><span className="metric-icon">J</span></div><div className="metric-value">{snapshot.journals.length}</div><div className="metric-foot">Balanced double-entry records</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Posted</span><span className="metric-icon">✓</span></div><div className="metric-value">{snapshot.journals.filter((item) => item.status === "POSTED").length}</div><div className="metric-foot positive">Immutable after posting</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Currency</span><span className="metric-icon">N$</span></div><div className="metric-value">NAD</div><div className="metric-foot">Per-entry currency control</div></article>
    </section>
    <div className="grid-equal">
      <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Journal register</h2><div className="panel-meta">POST /api/v1/accounting/journals enforces balance and idempotency</div></div></div><div className="table-wrap"><table><thead><tr><th>Journal</th><th>Date</th><th>Description</th><th>Source</th><th>Status</th></tr></thead><tbody>{snapshot.journals.map((item) => <tr key={String(item.id)}><td><strong>{String(item.journal_number)}</strong><div className="mono muted">{String(item.id)}</div></td><td>{String(item.journal_date)}</td><td>{String(item.description)}</td><td>{String(item.source_type).replaceAll("_", " ")}</td><td><StatusBadge value={String(item.status)} /></td></tr>)}</tbody></table></div></section>
      <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Chart of accounts</h2><div className="panel-meta">Organisation-specific posting controls</div></div></div><div className="table-wrap"><table><thead><tr><th>Code</th><th>Account</th><th>Type</th><th>Control</th><th>Status</th></tr></thead><tbody>{snapshot.accounts.map((item) => <tr key={String(item.id)}><td className="mono"><strong>{String(item.code)}</strong></td><td>{String(item.name)}</td><td>{String(item.account_type)}</td><td>{String(item.control_type ?? "GENERAL").replaceAll("_", " ")}</td><td><StatusBadge value={String(item.status)} /></td></tr>)}</tbody></table></div></section>
    </div>
    <div className="alert alert-info" style={{ marginTop: 20 }}><strong>Posting interface is active.</strong><br />The governed API accepts only balanced journals and validates every account, branch and project against the authorised organisation. Interactive journal authoring and approval queues will be expanded with the VAT close workflow.</div>
  </AppShell>;
}
