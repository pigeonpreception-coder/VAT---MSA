import type { Metadata } from "next";
import Link from "next/link";
import { AppShell } from "@/components/AppShell";
import { PageHeader } from "@/components/PageHeader";
import { getCurrentUser, hasPermission } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { getBusinessPlatformSnapshot } from "@/lib/data/business-repository";
import { getAdministrationSnapshot } from "@/lib/data/control-plane-repository";
import { listFixedAssets } from "@/lib/data/fixed-asset-repository";
import { listLogisticsDeliveries } from "@/lib/data/logistics-repository";
import { formatMoney } from "@/lib/format";

export const metadata: Metadata = { title: "ERP overview" };
export const dynamic = "force-dynamic";

function ModuleCard({ href, title, value, foot, available }: { href: string; title: string; value: string; foot: string; available: boolean }) {
  return <Link href={available ? href : "#"} className="metric" style={{ display: "block", textDecoration: "none", color: "inherit", opacity: available ? 1 : 0.5, pointerEvents: available ? "auto" : "none" }}>
    <div className="metric-top"><span className="metric-label">{title}</span></div>
    <div className="metric-value">{value}</div>
    <div className="metric-foot">{available ? foot : "No permission"}</div>
  </Link>;
}

export default async function ErpOverviewPage() {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "expenses:read", { operationClass: "READ" });
  const snapshot = await getBusinessPlatformSnapshot(user);

  const canReadEmployees = hasPermission(user, "employees:read");
  const canReadAssets = hasPermission(user, "fixed-assets:read");
  const canReadLogistics = hasPermission(user, "logistics:read");

  const [administration, assets, deliveries] = await Promise.all([
    canReadEmployees ? getAdministrationSnapshot(user) : Promise.resolve(null),
    canReadAssets ? listFixedAssets(user, null, snapshot.organisation.id) as unknown as Promise<Array<{ asset_class: string; status: string; current_value_cents: number | null; acquisition_cost_cents: number }>> : Promise.resolve([]),
    canReadLogistics ? listLogisticsDeliveries(user, snapshot.organisation.id) as unknown as Promise<Array<{ status: string }>> : Promise.resolve([]),
  ]);

  const activeEmployees = administration ? (administration.employees as Array<{ status: string }>).filter((employee) => employee.status === "ACTIVE").length : 0;
  const activeAssets = assets.filter((asset) => asset.status !== "DISPOSED");
  const assetValueCents = activeAssets.reduce((sum, asset) => sum + (asset.current_value_cents ?? asset.acquisition_cost_cents), 0);
  const inTransitDeliveries = deliveries.filter((delivery) => delivery.status === "IN_TRANSIT").length;
  const pendingDeliveries = deliveries.filter((delivery) => delivery.status === "PENDING").length;
  const balances = snapshot.balances as Array<{ quantity_micros: number; average_cost_cents: number }>;
  const inventoryValueCents = balances.reduce((sum, row) => sum + Math.round((row.quantity_micros / 1_000_000) * row.average_cost_cents), 0);
  const activeProjects = (snapshot.projects as Array<{ status: string }>).filter((project) => ["PLANNED", "ACTIVE"].includes(project.status)).length;

  return <AppShell active="erp" permission="expenses:read">
    <PageHeader eyebrow="Operations" title="ERP Module" description="A cross-module resource overview — Human Resources, Assets, Inventory, Logistics and Projects in one view. Each tile links to the module that owns the underlying data; this page holds no data of its own." />
    <section className="metric-grid">
      <ModuleCard href="/operations/human-resources" title="Human Resources" value={String(activeEmployees)} foot="Active employees" available={canReadEmployees} />
      <ModuleCard href="/operations/immovable-assets" title="Fixed Assets" value={formatMoney(assetValueCents)} foot={`${activeAssets.length} in service`} available={canReadAssets} />
      <ModuleCard href="/operations/inventory" title="Inventory (POS)" value={formatMoney(inventoryValueCents)} foot={`${snapshot.products.length} products`} available />
      <ModuleCard href="/operations/logistics" title="Logistics" value={String(inTransitDeliveries)} foot={`${pendingDeliveries} pending dispatch`} available={canReadLogistics} />
      <ModuleCard href="/project-management/ongoing" title="Projects" value={String(activeProjects)} foot="Planned or active" available />
    </section>
    <div className="alert alert-info" style={{ marginTop: 20 }}><strong>This overview aggregates existing modules.</strong><br />Human Resources, Immovable/Movable Asset Management, Inventory (point of sale) and Logistics are real, working modules — this page holds no data of its own, it only summarises theirs.</div>
  </AppShell>;
}
