import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PageHeader } from "@/components/PageHeader";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { getBusinessPlatformSnapshot } from "@/lib/data/business-repository";
import { PartyManager, type PartyRow } from "./PartyManager";

export const metadata: Metadata = { title: "Customers and suppliers" };
export const dynamic = "force-dynamic";

function optionalString(value: unknown) {
  return value === null || value === undefined || value === "" ? null : String(value);
}

export default async function BusinessPartiesPage() {
  const user = await getCurrentUser();
  requirePermission(user, "parties:manage");
  const snapshot = await getBusinessPlatformSnapshot(user);
  const parties: PartyRow[] = snapshot.parties.map((item) => ({
    id: String(item.id),
    display_name: String(item.display_name),
    legal_name: optionalString(item.legal_name),
    vat_number: optionalString(item.vat_number),
    tin: optionalString(item.tin),
    email: optionalString(item.email),
    phone: optionalString(item.phone),
    address: optionalString(item.address),
    relationships: optionalString(item.relationships),
    status: String(item.status),
  }));
  const active = parties.filter((party) => party.status === "ACTIVE");
  const customers = active.filter((party) => party.relationships?.split(",").includes("CUSTOMER"));
  const suppliers = active.filter((party) => party.relationships?.split(",").includes("SUPPLIER"));

  return <AppShell active="parties" permission="parties:manage">
    <PageHeader eyebrow="Commercial master data" title="Customers and suppliers" description="Create and maintain tenant-scoped trading partners. Deactivation ends future use while preserving historical fiscal and accounting records." />
    <section className="metric-grid">
      <article className="metric"><div className="metric-top"><span className="metric-label">Active partners</span><span className="metric-icon">P</span></div><div className="metric-value">{active.length}</div><div className="metric-foot">Available for authorised transactions</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Customers</span><span className="metric-icon">C</span></div><div className="metric-value">{customers.length}</div><div className="metric-foot">Available to quotations and projects</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Suppliers</span><span className="metric-icon">S</span></div><div className="metric-value">{suppliers.length}</div><div className="metric-foot">Available to expense capture</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Retained history</span><span className="metric-icon">H</span></div><div className="metric-value">{parties.length - active.length}</div><div className="metric-foot">Inactive records are never deleted</div></article>
    </section>
    <PartyManager organisationId={snapshot.organisation.id} parties={parties} />
  </AppShell>;
}
