import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PageHeader } from "@/components/PageHeader";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { getBusinessPlatformSnapshot } from "@/lib/data/business-repository";
import { listTaxpayerOptions } from "@/lib/data/repository";
import { PosTerminal } from "./PosTerminal";

export const metadata: Metadata = { title: "Inventory point of sale" };
export const dynamic = "force-dynamic";

export default async function InventoryPosPage() {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "inventory:read", { operationClass: "READ" });
  const snapshot = await getBusinessPlatformSnapshot(user);
  const taxpayers = (await listTaxpayerOptions(user)).map((row) => ({ id: row.id, legalName: row.legal_name, vatNumber: row.vat_number }));

  const products = (snapshot.products as Array<Record<string, string | number | null>>)
    .filter((row) => row.status === "ACTIVE")
    .map((row) => ({
      id: String(row.id), sku: String(row.sku), name: String(row.name), unitCode: String(row.unit_code),
      taxCategory: String(row.tax_category), taxRateBps: Number(row.tax_rate_bps), salesPriceCents: Number(row.sales_price_cents), costPriceCents: Number(row.cost_price_cents),
    }));
  const warehouses = (snapshot.warehouses as Array<Record<string, string | number>>).map((row) => ({ id: String(row.id), name: String(row.name) }));
  const balances = (snapshot.balances as Array<Record<string, string | number | null>>).map((row) => ({
    warehouseId: String(row.warehouse_id), productId: String(row.product_id), quantityMicros: Number(row.quantity_micros),
  }));

  return <AppShell active="inventory" permission="inventory:read">
    <PageHeader eyebrow="Operations" title="Inventory (Point of Sale)" description="Sell registered products from stock. Completing a sale certifies a tax invoice and records a stock issue against the selected warehouse." />
    <PosTerminal products={products} warehouses={warehouses} balances={balances} taxpayers={taxpayers} />
  </AppShell>;
}
