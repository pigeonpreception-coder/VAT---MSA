"use client";

import { useState, type FormEvent } from "react";
import { StatusBadge } from "@/components/PageHeader";
import { formatDateTime } from "@/lib/format";

export type LogisticsDeliveryRow = {
  id: string;
  delivery_number: string;
  reference_type: string;
  reference_id: string | null;
  origin: string;
  destination: string;
  vehicle_asset_id: string | null;
  status: string;
  notes: string | null;
  dispatched_at: string | null;
  delivered_at: string | null;
  cancellation_reason: string | null;
};

type VehicleOption = { id: string; asset_code: string; description: string };
type ActionState = { kind: "idle" | "working" | "success" | "error"; message: string };

function responseMessages(body: unknown): string[] {
  if (!body || typeof body !== "object") return ["The command failed without a readable response."];
  const value = body as { detail?: string; errors?: Array<{ message?: string }> };
  return value.errors?.map((item) => item.message ?? "Validation error") ?? [value.detail ?? "The command could not be completed."];
}

export function LogisticsManager({ organisationId, deliveries, vehicles }: { organisationId: string; deliveries: LogisticsDeliveryRow[]; vehicles: VehicleOption[] }) {
  const [state, setState] = useState<ActionState>({ kind: "idle", message: "" });

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);
    const payload = {
      schema_version: "1.0.0",
      delivery_number: data.get("delivery_number"),
      reference_type: data.get("reference_type"),
      reference_id: data.get("reference_id") || undefined,
      origin: data.get("origin"),
      destination: data.get("destination"),
      vehicle_asset_id: data.get("vehicle_asset_id") || undefined,
      notes: data.get("notes") || undefined,
    };
    setState({ kind: "working", message: "Creating delivery…" });
    try {
      const response = await fetch(`/api/v1/logistics-deliveries?organisation_id=${encodeURIComponent(organisationId)}`, {
        method: "POST",
        headers: { "content-type": "application/json", "idempotency-key": crypto.randomUUID() },
        body: JSON.stringify(payload),
      });
      const body = await response.json() as unknown;
      if (!response.ok) throw new Error(responseMessages(body).join(" "));
      setState({ kind: "success", message: "Delivery created." });
      form.reset();
      window.setTimeout(() => window.location.reload(), 650);
    } catch (error) {
      setState({ kind: "error", message: error instanceof Error ? error.message : "Creation failed." });
    }
  }

  async function transition(id: string, path: string, body: Record<string, unknown> = {}) {
    setState({ kind: "working", message: "Updating delivery…" });
    try {
      const response = await fetch(`/api/v1/logistics-deliveries/${encodeURIComponent(id)}/${path}`, {
        method: "POST",
        headers: { "content-type": "application/json", "idempotency-key": crypto.randomUUID() },
        body: JSON.stringify({ schema_version: "1.0.0", ...body }),
      });
      const responseBody = await response.json() as unknown;
      if (!response.ok) throw new Error(responseMessages(responseBody).join(" "));
      setState({ kind: "success", message: "Delivery updated." });
      window.setTimeout(() => window.location.reload(), 650);
    } catch (error) {
      setState({ kind: "error", message: error instanceof Error ? error.message : "Update failed." });
    }
  }

  async function cancel(delivery: LogisticsDeliveryRow) {
    const reason = window.prompt(`Why is delivery ${delivery.delivery_number} being cancelled?`)?.trim();
    if (!reason || reason.length < 10) { if (reason !== undefined) window.alert("A cancellation reason of at least 10 characters is required."); return; }
    await transition(delivery.id, "cancellation", { reason });
  }

  return <div className="grid-2">
    <section className="panel">
      <div className="panel-head"><div><h2 className="panel-title">Delivery register</h2><div className="panel-meta">{deliveries.length} recorded</div></div></div>
      <div className="table-wrap"><table><thead><tr><th>Delivery</th><th>Route</th><th>Reference</th><th>Status</th><th>Action</th></tr></thead><tbody>
        {deliveries.map((delivery) => <tr key={delivery.id}>
          <td><strong>{delivery.delivery_number}</strong>{delivery.vehicle_asset_id ? <div className="muted mono">Vehicle: {delivery.vehicle_asset_id}</div> : null}</td>
          <td>{delivery.origin} → {delivery.destination}{delivery.dispatched_at ? <div className="muted">Dispatched {formatDateTime(delivery.dispatched_at)}</div> : null}{delivery.delivered_at ? <div className="muted">Delivered {formatDateTime(delivery.delivered_at)}</div> : null}</td>
          <td>{delivery.reference_type}{delivery.reference_id ? <div className="mono muted">{delivery.reference_id}</div> : null}</td>
          <td><StatusBadge value={delivery.status} />{delivery.cancellation_reason ? <div className="muted">{delivery.cancellation_reason}</div> : null}</td>
          <td><div className="actions">
            {delivery.status === "PENDING" ? <button className="btn btn-secondary" type="button" disabled={state.kind === "working"} onClick={() => transition(delivery.id, "dispatch")}>Dispatch</button> : null}
            {delivery.status === "IN_TRANSIT" ? <button className="btn btn-primary" type="button" disabled={state.kind === "working"} onClick={() => transition(delivery.id, "delivery")}>Mark delivered</button> : null}
            {["PENDING", "IN_TRANSIT"].includes(delivery.status) ? <button className="btn btn-danger" type="button" disabled={state.kind === "working"} onClick={() => cancel(delivery)}>Cancel</button> : <span className="muted">{delivery.status === "DELIVERED" || delivery.status === "CANCELLED" ? "Read-only history" : ""}</span>}
          </div></td>
        </tr>)}
        {!deliveries.length ? <tr><td colSpan={5} className="muted">No deliveries have been recorded.</td></tr> : null}
      </tbody></table></div>
    </section>

    <form className="panel" onSubmit={submit}>
      <div className="panel-head"><div><h2 className="panel-title">Create delivery</h2><div className="panel-meta">Fulfils an already-issued invoice</div></div></div>
      <div className="panel-body form-grid">
        <div className="form-group"><label htmlFor="ld-delivery-number">Delivery number</label><input className="field" id="ld-delivery-number" name="delivery_number" required maxLength={40} placeholder="DEL-001" /></div>
        <div className="form-group"><label htmlFor="ld-reference-type">Reference type</label><select className="select" id="ld-reference-type" name="reference_type" required defaultValue="INVOICE"><option value="INVOICE">Invoice</option><option value="POS_SALE">POS sale</option><option value="OTHER">Other</option></select></div>
        <div className="form-group full"><label htmlFor="ld-reference-id">Reference ID</label><input className="field mono" id="ld-reference-id" name="reference_id" maxLength={80} placeholder="Invoice or POS sale ID" /><span className="field-help">Required unless reference type is Other.</span></div>
        <div className="form-group"><label htmlFor="ld-origin">Origin</label><input className="field" id="ld-origin" name="origin" required maxLength={300} /></div>
        <div className="form-group"><label htmlFor="ld-destination">Destination</label><input className="field" id="ld-destination" name="destination" required maxLength={300} /></div>
        <div className="form-group full"><label htmlFor="ld-vehicle">Vehicle (optional)</label><select className="select" id="ld-vehicle" name="vehicle_asset_id" defaultValue=""><option value="">No vehicle assigned</option>{vehicles.map((vehicle) => <option key={vehicle.id} value={vehicle.id}>{vehicle.asset_code} · {vehicle.description}</option>)}</select></div>
        <div className="form-group full"><label htmlFor="ld-notes">Notes (optional)</label><textarea className="textarea" id="ld-notes" name="notes" maxLength={500} /></div>
        <div className="form-actions full"><button className="btn btn-primary" disabled={state.kind === "working"}>{state.kind === "working" ? "Saving…" : "Create delivery"}</button></div>
        {state.message ? <div className={`alert full ${state.kind === "error" ? "alert-error" : state.kind === "success" ? "alert-success" : "alert-info"}`}>{state.message}</div> : null}
      </div>
    </form>
  </div>;
}
