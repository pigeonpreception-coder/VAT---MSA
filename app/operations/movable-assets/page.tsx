import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PageHeader } from "@/components/PageHeader";
import { FixedAssetManager, type FixedAssetRow } from "@/components/FixedAssetManager";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { getBusinessPlatformSnapshot } from "@/lib/data/business-repository";
import { listFixedAssets } from "@/lib/data/fixed-asset-repository";

export const metadata: Metadata = { title: "Movable asset management" };
export const dynamic = "force-dynamic";

export default async function MovableAssetsPage() {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "fixed-assets:read", { operationClass: "READ" });
  const snapshot = await getBusinessPlatformSnapshot(user);
  const assets = await listFixedAssets(user, "MOVABLE", snapshot.organisation.id) as unknown as FixedAssetRow[];

  return <AppShell active="movable-assets" permission="fixed-assets:read">
    <PageHeader eyebrow="Operations" title="Movable Asset Management" description="Vehicles, equipment, furniture and IT hardware register: acquisition cost, current value, custody and disposal." />
    <FixedAssetManager assetClass="MOVABLE" organisationId={snapshot.organisation.id} assets={assets} categories={["VEHICLE", "EQUIPMENT", "FURNITURE", "IT_HARDWARE", "OTHER"]} serialLabel="Serial / registration number" />
  </AppShell>;
}
