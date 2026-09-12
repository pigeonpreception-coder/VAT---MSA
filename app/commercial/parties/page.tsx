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

export default async function BusinessPartiesPage({ searchParams }: { searchParams: Promise<{ relationship?: string; create?: string }> }) {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "parties:manage", { operationClass: "READ" });
  const query = await searchParams;
  const relationshipFilter = query.relationship === "CUSTOMER" || query.relationship === "SUPPLIER" ? query.relationship : null;
  const createRelationship = query.create === "customer" ? "CUSTOMER" : query.create === "supplier" ? "SUPPLIER" : null;
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
  const activeParties = parties.filter((party) => party.status === "ACTIVE");
  const customers = activeParties.filter((party) => party.relationships?.split(",").includes("CUSTOMER"));
  const suppliers = activeParties.filter((party) => party.relationships?.split(",").includes("SUPPLIER"));
  const trusted = activeParties.filter((party) => ["AUTHORITY_VERIFIED", "SYNTHETIC_VALID"].includes(party.trust_status ?? ""));
  const deployment = (process.env.VAT_MSA_ENVIRONMENT ?? "local").trim().toLowerCase();
  const syntheticVerificationEnabled = deployment !== "production" && (process.env.NODE_ENV !== "production" || (deployment === "staging" && process.env.VAT_MSA_ENABLE_SYNTHETIC_COUNTERPARTY_TRUST === "true"));

  const visibleParties = relationshipFilter ? parties.filter((party) => party.relationships?.split(",").includes(relationshipFilter)) : parties;
  const navKey = createRelationship === "CUSTOMER" ? "new-customer" : createRelationship === "SUPPLIER" ? "new-supplier"
    : relationshipFilter === "CUSTOMER" ? "customers" : relationshipFilter === "SUPPLIER" ? "suppliers" : "customers";
  const title = relationshipFilter === "CUSTOMER" ? "Customers" : relationshipFilter === "SUPPLIER" ? "Suppliers" : "Customers and suppliers";
  const eyebrow = createRelationship ? "New Registration" : relationshipFilter ? "Registered" : "Commercial master data";

  return <AppShell active={navKey} permission="parties:manage">
    <PageHeader eyebrow={eyebrow} title={title} description="Create and maintain tenant-scoped trading partners. Deactivation ends future use while preserving historical fiscal and accounting records." />
    <section className="metric-grid">
      <article className="metric"><div className="metric-top"><span className="metric-label">Active partners</span><span className="metric-icon">P</span></div><div className="metric-value">{activeParties.length}</div><div className="metric-foot">Available for authorised transactions</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Customers</span><span className="metric-icon">C</span></div><div className="metric-value">{customers.length}</div><div className="metric-foot">Available to quotations and projects</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Suppliers</span><span className="metric-icon">S</span></div><div className="metric-value">{suppliers.length}</div><div className="metric-foot">Available to expense capture</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Trusted</span><span className="metric-icon">T</span></div><div className="metric-value">{trusted.length}</div><div className="metric-foot">Current authority or labelled synthetic evidence</div></article>
    </section>
    <PartyManager organisationId={snapshot.organisation.id} parties={visibleParties} syntheticVerificationEnabled={syntheticVerificationEnabled} initialCreateRelationship={createRelationship} />
  </AppShell>;
}
