import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PageHeader } from "@/components/PageHeader";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { getBusinessPlatformSnapshot } from "@/lib/data/business-repository";
import { PartyManager, type PartyRow } from "./PartyManager";

export const metadata: Metadata = { title: "Customers and suppliers" };
export const dynamic = "force-dynamic";

function optionalString(value: unknown) {
  return value === null || value === undefined || value === "" ? null : String(value);
}

export default async function BusinessPartiesPage() {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "parties:manage", { operationClass: "READ" });
  const snapshot = await getBusinessPlatformSnapshot(user);
  const parties: PartyRow[] = snapshot.parties.map((item) => ({
    id: String(item.id),
    display_name: String(item.display_name),
    legal_name: optionalString(item.legal_name),
    vat_number: optionalString(item.vat_number),
    tin: optionalString(item.tin),
    company_registration_number: optionalString(item.company_registration_number),
    email: optionalString(item.email),
    phone: optionalString(item.phone),
    address: optionalString(item.address),
    relationships: optionalString(item.relationships),
    status: String(item.status),
    trust_status: optionalString(item.trust_status),
    tax_registration_status: optionalString(item.tax_registration_status),
    confidence_bps: item.confidence_bps === null || item.confidence_bps === undefined ? null : Number(item.confidence_bps),
    provider_environment: optionalString(item.provider_environment),
    expires_at: optionalString(item.expires_at),
  }));
  const active = parties.filter((party) => party.status === "ACTIVE");
  const customers = active.filter((party) => party.relationships?.split(",").includes("CUSTOMER"));
  const suppliers = active.filter((party) => party.relationships?.split(",").includes("SUPPLIER"));
  const trusted = active.filter((party) => ["AUTHORITY_VERIFIED", "SYNTHETIC_VALID"].includes(party.trust_status ?? ""));
  const deployment = (process.env.VAT_MSA_ENVIRONMENT ?? "local").trim().toLowerCase();
  const syntheticVerificationEnabled = deployment !== "production" && (process.env.NODE_ENV !== "production" || (deployment === "staging" && process.env.VAT_MSA_ENABLE_SYNTHETIC_COUNTERPARTY_TRUST === "true"));

  return <AppShell active="parties" permission="parties:manage">
    <PageHeader eyebrow="Commercial master data" title="Customers and suppliers" description="Create and maintain tenant-scoped trading partners. Deactivation ends future use while preserving historical fiscal and accounting records." />
    <section className="metric-grid">
      <article className="metric"><div className="metric-top"><span className="metric-label">Active partners</span><span className="metric-icon">P</span></div><div className="metric-value">{active.length}</div><div className="metric-foot">Available for authorised transactions</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Customers</span><span className="metric-icon">C</span></div><div className="metric-value">{customers.length}</div><div className="metric-foot">Available to quotations and projects</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Suppliers</span><span className="metric-icon">S</span></div><div className="metric-value">{suppliers.length}</div><div className="metric-foot">Available to expense capture</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Trusted</span><span className="metric-icon">T</span></div><div className="metric-value">{trusted.length}</div><div className="metric-foot">Current authority or labelled synthetic evidence</div></article>
    </section>
    <PartyManager organisationId={snapshot.organisation.id} parties={parties} syntheticVerificationEnabled={syntheticVerificationEnabled} />
  </AppShell>;
}
