"use client";

import { useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { formatMoney } from "@/lib/format";

type ProductOption = { id: string; sku: string; name: string; unitCode: string; taxCategory: string; taxRateBps: number; salesPriceCents: number; costPriceCents: number };
type WarehouseOption = { id: string; name: string };
type BalanceRow = { warehouseId: string; productId: string; quantityMicros: number };
type TaxpayerOption = { id: string; legalName: string; vatNumber: string };
type CartLine = { productId: string; quantity: number };

function responseMessages(body: unknown): string[] {
  if (!body || typeof body !== "object") return ["The command failed without a readable response."];
  const value = body as { detail?: string; errors?: Array<{ message?: string }> };
  return value.errors?.map((item) => item.message ?? "Validation error") ?? [value.detail ?? "The command could not be completed."];
}

export function PosTerminal({ products, warehouses, balances, taxpayers }: { products: ProductOption[]; warehouses: WarehouseOption[]; balances: BalanceRow[]; taxpayers: TaxpayerOption[] }) {
  const router = useRouter();
  const [warehouseId, setWarehouseId] = useState(warehouses[0]?.id ?? "");
  const [cart, setCart] = useState<CartLine[]>([]);
  const [customerVat, setCustomerVat] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [status, setStatus] = useState<{ kind: "idle" | "error" | "success"; message: string; invoiceId?: string }>({ kind: "idle", message: "" });

  const stockFor = (productId: string) => {
    const row = balances.find((item) => item.warehouseId === warehouseId && item.productId === productId);
    return row ? row.quantityMicros / 1_000_000 : 0;
  };

  const addToCart = (productId: string) => {
    setCart((current) => {
      const existing = current.find((line) => line.productId === productId);
      if (existing) return current.map((line) => line.productId === productId ? { ...line, quantity: line.quantity + 1 } : line);
      return [...current, { productId, quantity: 1 }];
    });
  };

  const updateQuantity = (productId: string, quantity: number) => {
    setCart((current) => current.map((line) => line.productId === productId ? { ...line, quantity } : line).filter((line) => line.quantity > 0));
  };

  const removeLine = (productId: string) => setCart((current) => current.filter((line) => line.productId !== productId));

  const lines = useMemo(() => cart.map((line) => {
    const product = products.find((item) => item.id === line.productId);
    const net = (product?.salesPriceCents ?? 0) * line.quantity;
    const tax = Math.round(net * (product?.taxRateBps ?? 0) / 10_000);
    return { ...line, product, net, tax, total: net + tax };
  }), [cart, products]);

  const totals = lines.reduce((current, line) => ({ net: current.net + line.net, tax: current.tax + line.tax, total: current.total + line.total }), { net: 0, tax: 0, total: 0 });

  async function completeSale() {
    setSubmitting(true);
    setStatus({ kind: "idle", message: "" });
    const supplier = taxpayers[0];
    const customer = taxpayers.find((item) => item.vatNumber === customerVat.trim());
    const invoicePayload = {
      schema_version: "1.0.0",
      document_type: customer ? "TAX_INVOICE" : "SIMPLIFIED_TAX_INVOICE",
      source: { system_id: "VAT-MSA-POS", document_id: `POS-${crypto.randomUUID().slice(0, 8).toUpperCase()}`, submitted_at: new Date().toISOString() },
      supplier: { name: supplier?.legalName ?? "", identifiers: [{ type: "VAT_NUMBER", value: supplier?.vatNumber ?? "", country: "NA" }] },
      customer: customer
        ? { name: customer.legalName, identifiers: [{ type: "VAT_NUMBER", value: customer.vatNumber, country: "NA" }] }
        : { name: "Walk-in customer", identifiers: [{ type: "OTHER", value: "CONSUMER", country: "NA" }] },
      invoice_number: `POS-${new Date().toISOString().slice(0, 10)}-${crypto.randomUUID().slice(0, 6).toUpperCase()}`,
      issue_date: new Date().toISOString().slice(0, 10),
      currency: "NAD",
      lines: lines.map((line, index) => ({
        line_number: index + 1, description: line.product?.name ?? "", quantity: String(line.quantity), unit_code: line.product?.unitCode ?? "EA",
        unit_price: ((line.product?.salesPriceCents ?? 0) / 100).toFixed(2), net_amount: (line.net / 100).toFixed(2),
        tax: { category: line.product?.taxCategory ?? "STANDARD", rate: ((line.product?.taxRateBps ?? 0) / 100).toFixed(2), taxable_amount: (line.net / 100).toFixed(2), tax_amount: (line.tax / 100).toFixed(2), rule_reference: "NA-VAT-PILOT-2026.1" },
      })),
      totals: {
        line_net_amount: (totals.net / 100).toFixed(2), tax_exclusive_amount: (totals.net / 100).toFixed(2), tax_amount: (totals.tax / 100).toFixed(2),
        tax_inclusive_amount: (totals.total / 100).toFixed(2), payable_amount: (totals.total / 100).toFixed(2),
      },
    };

    try {
      const invoiceResponse = await fetch("/api/v1/invoices", { method: "POST", headers: { "content-type": "application/json", "idempotency-key": crypto.randomUUID() }, body: JSON.stringify(invoicePayload) });
      const invoiceBody = await invoiceResponse.json() as { invoice_id?: string };
      if (!invoiceResponse.ok || !invoiceBody.invoice_id) throw new Error(responseMessages(invoiceBody).join(" "));
      const invoiceId = invoiceBody.invoice_id;

      const failures: string[] = [];
      for (const [index, line] of lines.entries()) {
        const movementResponse = await fetch("/api/v1/inventory/movements", {
          method: "POST",
          headers: { "content-type": "application/json", "idempotency-key": crypto.randomUUID() },
          body: JSON.stringify({
            schema_version: "1.0.0", warehouse_id: warehouseId, product_id: line.productId, movement_type: "ISSUE",
            quantity_micros: line.quantity * 1_000_000, unit_cost_cents: line.product?.costPriceCents ?? 0,
            reference_type: "INVOICE", reference_id: `${invoiceId}:${index + 1}`, reason: "POS sale", occurred_at: new Date().toISOString(),
          }),
        });
        if (!movementResponse.ok) failures.push(`${line.product?.name ?? line.productId}: ${responseMessages(await movementResponse.json()).join(" ")}`);
      }

      if (failures.length) {
        setStatus({ kind: "error", message: `Invoice ${invoiceId} was created, but stock could not be adjusted for: ${failures.join("; ")}. Reconcile inventory manually.`, invoiceId });
      } else {
        setStatus({ kind: "success", message: "Sale completed. Invoice certified and stock adjusted.", invoiceId });
        setCart([]);
      }
      router.refresh();
    } catch (error) {
      setStatus({ kind: "error", message: error instanceof Error ? error.message : "The sale could not be completed." });
    } finally {
      setSubmitting(false);
    }
  }

  return <div className="grid-2">
    <section className="panel">
      <div className="panel-head"><div><h2 className="panel-title">Products</h2><div className="panel-meta">Available stock at the selected warehouse</div></div>
        <select className="select" value={warehouseId} onChange={(event) => setWarehouseId(event.target.value)}>{warehouses.map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.name}</option>)}</select>
      </div>
      <div className="table-wrap"><table><thead><tr><th>Product</th><th>Price</th><th>In stock</th><th /></tr></thead><tbody>
        {products.map((product) => {
          const stock = stockFor(product.id);
          return <tr key={product.id}>
            <td><strong>{product.name}</strong><div className="mono muted">{product.sku}</div></td>
            <td>{formatMoney(product.salesPriceCents)}</td>
            <td>{stock}</td>
            <td><button className="btn btn-secondary" type="button" disabled={stock <= 0} onClick={() => addToCart(product.id)}>Add</button></td>
          </tr>;
        })}
        {!products.length ? <tr><td colSpan={4} className="muted">No products are registered.</td></tr> : null}
      </tbody></table></div>
    </section>

    <section className="panel">
      <div className="panel-head"><div><h2 className="panel-title">Cart</h2><div className="panel-meta">Point-of-sale checkout</div></div></div>
      <div className="panel-body form-grid">
        <div className="table-wrap full"><table><thead><tr><th>Item</th><th>Qty</th><th>Line total</th><th /></tr></thead><tbody>
          {lines.map((line) => <tr key={line.productId}>
            <td>{line.product?.name}</td>
            <td><input className="field" style={{ width: 70 }} type="number" min="1" value={line.quantity} onChange={(event) => updateQuantity(line.productId, Number(event.target.value))} /></td>
            <td>{formatMoney(line.total)}</td>
            <td><button className="icon-button" type="button" aria-label="Remove" onClick={() => removeLine(line.productId)}>×</button></td>
          </tr>)}
          {!lines.length ? <tr><td colSpan={4} className="muted">The cart is empty.</td></tr> : null}
        </tbody></table></div>
        <div className="form-group full"><label htmlFor="pos-customer-vat">Buyer VAT number (optional)</label><input className="field" id="pos-customer-vat" list="pos-taxpayers" placeholder="Leave blank for a walk-in consumer" value={customerVat} onChange={(event) => setCustomerVat(event.target.value)} /><datalist id="pos-taxpayers">{taxpayers.map((item) => <option key={item.id} value={item.vatNumber}>{item.legalName}</option>)}</datalist></div>
        <div className="form-group full"><div className="totals-card">
          <div className="total-line"><span>Tax-exclusive value</span><strong>{formatMoney(totals.net)}</strong></div>
          <div className="total-line"><span>VAT</span><strong>{formatMoney(totals.tax)}</strong></div>
          <div className="total-line grand"><span>Payable amount</span><strong>{formatMoney(totals.total)}</strong></div>
        </div></div>
        <div className="form-actions full"><button className="btn btn-primary" type="button" disabled={submitting || !lines.length || !warehouseId} onClick={completeSale}>{submitting ? "Processing…" : "Complete sale"}</button></div>
        {status.message ? <div className={`alert full ${status.kind === "error" ? "alert-error" : status.kind === "success" ? "alert-success" : "alert-info"}`}>{status.message}{status.invoiceId ? <div><a href={`/invoices/${status.invoiceId}`}>View invoice {status.invoiceId}</a></div> : null}</div> : null}
      </div>
    </section>
  </div>;
}
