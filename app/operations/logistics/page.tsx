import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PageHeader } from "@/components/PageHeader";
import { LogisticsManager, type LogisticsDeliveryRow } from "@/components/LogisticsManager";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { getBusinessPlatformSnapshot } from "@/lib/data/business-repository";
import { listLogisticsDeliveries } from "@/lib/data/logistics-repository";
import { listFixedAssets } from "@/lib/data/fixed-asset-repository";

export const metadata: Metadata = { title: "Logistics" };
export const dynamic = "force-dynamic";

export default async function LogisticsPage() {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "logistics:read", { operationClass: "READ" });
  const snapshot = await getBusinessPlatformSnapshot(user);
  const [deliveries, vehicles] = await Promise.all([
    listLogisticsDeliveries(user, snapshot.organisation.id) as unknown as Promise<LogisticsDeliveryRow[]>,
    listFixedAssets(user, "MOVABLE", snapshot.organisation.id) as unknown as Promise<Array<{ id: string; asset_code: string; description: string; status: string }>>,
  ]);
  const activeVehicles = vehicles.filter((vehicle) => vehicle.status === "ACTIVE");

  return <AppShell active="logistics" permission="logistics:read">
    <PageHeader eyebrow="Operations" title="Logistics Module" description="Delivery dispatch and fulfilment tracking for already-issued invoices and POS sales." />
    <LogisticsManager organisationId={snapshot.organisation.id} deliveries={deliveries} vehicles={activeVehicles} />
  </AppShell>;
}
