import type { Metadata } from "next";
import Link from "next/link";
import { AppShell } from "@/components/AppShell";
import { PageHeader } from "@/components/PageHeader";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";

export const metadata: Metadata = { title: "Fixed asset module" };
export const dynamic = "force-dynamic";

export default async function FixedAssetsPage() {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "accounting:read", { operationClass: "READ" });
  return <AppShell active="fixed-assets" permission="accounting:read">
    <PageHeader eyebrow="Accounting & Finance" title="Fixed Asset Module" description="Asset registration, valuation and disposal now live under Operations, split by asset class." />
    <section className="panel">
      <div className="panel-head"><div><h2 className="panel-title">Managed under Operations</h2><div className="panel-meta">Immovable and movable fixed assets share one register</div></div></div>
      <div className="panel-body"><div className="alert alert-info">
        Register, revalue, flag for maintenance and dispose assets from:
        <div style={{ marginTop: 8 }}><Link href="/operations/immovable-assets">Immovable Asset Management</Link> (land and buildings)</div>
        <div><Link href="/operations/movable-assets">Movable Asset Management</Link> (vehicles, equipment, furniture, IT hardware)</div>
      </div></div>
    </section>
  </AppShell>;
}
