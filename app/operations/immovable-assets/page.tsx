import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PageHeader } from "@/components/PageHeader";
import { FixedAssetManager, type FixedAssetRow } from "@/components/FixedAssetManager";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { getBusinessPlatformSnapshot } from "@/lib/data/business-repository";
import { listFixedAssets } from "@/lib/data/fixed-asset-repository";

export const metadata: Metadata = { title: "Immovable asset management" };
export const dynamic = "force-dynamic";

export default async function ImmovableAssetsPage() {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "fixed-assets:read", { operationClass: "READ" });
  const snapshot = await getBusinessPlatformSnapshot(user);
  const assets = await listFixedAssets(user, "IMMOVABLE", snapshot.organisation.id) as unknown as FixedAssetRow[];

  return <AppShell active="immovable-assets" permission="fixed-assets:read">
    <PageHeader eyebrow="Operations" title="Immovable Asset Management" description="Land and buildings register: acquisition cost, current value, custody and disposal." />
    <FixedAssetManager assetClass="IMMOVABLE" organisationId={snapshot.organisation.id} assets={assets} categories={["LAND", "BUILDING", "OTHER"]} serialLabel="Title deed / reference (optional)" />
  </AppShell>;
}
