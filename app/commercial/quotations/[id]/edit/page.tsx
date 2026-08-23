import type { Metadata } from "next";
import Link from "next/link";
import { AppShell } from "@/components/AppShell";
import { PageHeader, StatusBadge } from "@/components/PageHeader";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { getBusinessPlatformSnapshot, getQuotationForEdit } from "@/lib/data/business-repository";
import { evaluateQuotationLifecycle } from "@/lib/domain/business";
import { QuotationEditForm, type EditableQuotationLine } from "./QuotationEditForm";

export const metadata: Metadata = { title: "Edit quotation" };
export const dynamic = "force-dynamic";

export default async function EditQuotationPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "quotations:manage");
  const [detail, snapshot] = await Promise.all([getQuotationForEdit(id, user), getBusinessPlatformSnapshot(user)]);
  const quotation = detail.quotation;
  const status = String(quotation.status);
  const editPolicy = evaluateQuotationLifecycle({
    status,
    action: "EDIT",
    validUntil: String(quotation.valid_until),
    today: new Date().toISOString().slice(0, 10),
  });

  return <AppShell active="commercial" permission="quotations:manage">
    <PageHeader eyebrow="Quotation lifecycle" title={`Edit ${String(quotation.quotation_number)}`} description="Only an unexpired issued quotation may change. Accepted, rejected, expired and converted quotations remain immutable." actions={<Link className="btn btn-secondary" href="/commercial">Back to quotations</Link>} />
    <section className="metric-grid">
      <article className="metric"><div className="metric-top"><span className="metric-label">Status</span><span className="metric-icon">Q</span></div><div className="metric-value"><StatusBadge value={status} /></div><div className="metric-foot">Lifecycle guard enforced server-side</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Recorded revisions</span><span className="metric-icon">R</span></div><div className="metric-value">{Number(quotation.revision_count ?? 0)}</div><div className="metric-foot">Hash-chained immutable snapshots</div></article>
    </section>
    {!editPolicy.allowed ? <div className="alert alert-error"><strong>This quotation cannot be edited.</strong><div>{editPolicy.reason}</div></div> : <QuotationEditForm
      organisationId={detail.organisation.id}
      quotation={{ id: String(quotation.id), quotation_number: String(quotation.quotation_number), customer_party_id: String(quotation.customer_party_id), issue_date: String(quotation.issue_date), valid_until: String(quotation.valid_until), notes: quotation.notes ? String(quotation.notes) : "" }}
      initialLines={detail.lines.map((line): EditableQuotationLine => ({
        key: String(line.line_number),
        product_id: line.product_id ? String(line.product_id) : "",
        description: String(line.description),
        quantity: String(Number(line.quantity_micros) / 1_000_000),
        unit_code: String(line.unit_code),
        unit_price_cents: String(line.unit_price_cents),
        tax_category: String(line.tax_category) as EditableQuotationLine["tax_category"],
        tax_rate_bps: String(line.tax_rate_bps),
      }))}
      parties={snapshot.parties.filter((party) => String(party.status) === "ACTIVE" && String(party.relationships ?? "").split(",").includes("CUSTOMER")).map((party) => ({ id: String(party.id), label: String(party.display_name) }))}
      products={snapshot.products.filter((product) => String(product.status) === "ACTIVE").map((product) => ({ id: String(product.id), label: `${String(product.sku)} — ${String(product.name)}` }))}
    />}
  </AppShell>;
}
