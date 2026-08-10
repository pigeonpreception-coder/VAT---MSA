import type { Metadata } from "next";
import Link from "next/link";
import { AppShell } from "@/components/AppShell";
import { PageHeader } from "@/components/PageHeader";
import { getCurrentUser } from "@/lib/auth";
import { getAvailablePortals } from "@/lib/portals";

export const metadata: Metadata = { title: "Portal switchboard" };
export const dynamic = "force-dynamic";

export default async function PortalsPage() {
  const user = await getCurrentUser();
  const portals = await getAvailablePortals(user);
  return <AppShell active="portals" permission="dashboard:read">
    <PageHeader eyebrow="Workspace switchboard" title="Choose an authorised VAT-MSA experience" description="Portal availability derives from identity role, active organisation scope and Buyer or Seller capability. Switching workspace changes tasks and visibility, not the canonical taxpayer record." />
    {portals.length ? <section className="portal-grid">{portals.map((portal) => <article className="portal-card" key={portal.key}><span className="portal-audience">{portal.audience}</span><h2>{portal.name}</h2><p>{portal.description}</p><Link className="btn btn-primary" href={portal.href}>Open {portal.name}</Link></article>)}</section> : <div className="panel empty"><strong>No portal assignment</strong>Your identity is authenticated but has no active portal role or organisation capability. Contact an authorised access administrator.</div>}
    <div className="alert alert-info" style={{ marginTop: 20 }}><strong>Separation is enforced server-side.</strong><br />A hidden navigation link does not grant access. Each portal route re-evaluates role and capability, and every domain repository still applies record scope.</div>
  </AppShell>;
}
