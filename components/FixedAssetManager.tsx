"use client";

import { useState, type FormEvent } from "react";
import { StatusBadge } from "@/components/PageHeader";
import { formatMoney } from "@/lib/format";

export type FixedAssetRow = {
  id: string;
  asset_code: string;
  category: string;
  description: string;
  serial_or_registration_number: string | null;
  location_or_address: string;
  acquisition_date: string;
  acquisition_cost_cents: number;
  current_value_cents: number | null;
  status: string;
  disposal_reason: string | null;
};

type ActionState = { kind: "idle" | "working" | "success" | "error"; message: string };

function responseMessages(body: unknown): string[] {
  if (!body || typeof body !== "object") return ["The command failed without a readable response."];
  const value = body as { detail?: string; errors?: Array<{ message?: string }> };
  return value.errors?.map((item) => item.message ?? "Validation error") ?? [value.detail ?? "The command could not be completed."];
}

export function FixedAssetManager({ assetClass, organisationId, assets, categories, serialLabel }: { assetClass: "IMMOVABLE" | "MOVABLE"; organisationId: string; assets: FixedAssetRow[]; categories: string[]; serialLabel: string }) {
  const [state, setState] = useState<ActionState>({ kind: "idle", message: "" });

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);
    const acquisitionCostRands = Number(data.get("acquisition_cost"));
    const currentValueRands = data.get("current_value") ? Number(data.get("current_value")) : null;
    const payload = {
      schema_version: "1.0.0",
      asset_class: assetClass,
      asset_code: data.get("asset_code"),
      category: data.get("category"),
      description: data.get("description"),
      serial_or_registration_number: data.get("serial_or_registration_number") || undefined,
      location_or_address: data.get("location_or_address"),
      acquisition_date: data.get("acquisition_date"),
      acquisition_cost_cents: Math.round(acquisitionCostRands * 100),
      current_value_cents: currentValueRands === null ? null : Math.round(currentValueRands * 100),
    };
    setState({ kind: "working", message: "Registering asset…" });
    try {
      const response = await fetch(`/api/v1/fixed-assets?organisation_id=${encodeURIComponent(organisationId)}`, {
        method: "POST",
        headers: { "content-type": "application/json", "idempotency-key": crypto.randomUUID() },
        body: JSON.stringify(payload),
      });
      const body = await response.json() as unknown;
      if (!response.ok) throw new Error(responseMessages(body).join(" "));
      setState({ kind: "success", message: "Asset registered." });
      form.reset();
      window.setTimeout(() => window.location.reload(), 650);
    } catch (error) {
      setState({ kind: "error", message: error instanceof Error ? error.message : "Registration failed." });
    }
  }

  async function transition(id: string, path: string, body: Record<string, unknown> = {}) {
    setState({ kind: "working", message: "Updating asset…" });
    try {
      const response = await fetch(`/api/v1/fixed-assets/${encodeURIComponent(id)}/${path}`, {
        method: "POST",
        headers: { "content-type": "application/json", "idempotency-key": crypto.randomUUID() },
        body: JSON.stringify({ schema_version: "1.0.0", ...body }),
      });
      const responseBody = await response.json() as unknown;
      if (!response.ok) throw new Error(responseMessages(responseBody).join(" "));
      setState({ kind: "success", message: "Asset updated." });
      window.setTimeout(() => window.location.reload(), 650);
    } catch (error) {
      setState({ kind: "error", message: error instanceof Error ? error.message : "Update failed." });
    }
  }

  async function dispose(asset: FixedAssetRow) {
    const reason = window.prompt(`Why is ${asset.description} (${asset.asset_code}) being disposed?`)?.trim();
    if (!reason || reason.length < 10) { if (reason !== undefined) window.alert("A disposal reason of at least 10 characters is required."); return; }
    await transition(asset.id, "disposal", { reason });
  }

  return <div className="grid-2">
    <section className="panel">
      <div className="panel-head"><div><h2 className="panel-title">Asset register</h2><div className="panel-meta">{assets.length} registered</div></div></div>
      <div className="table-wrap"><table><thead><tr><th>Asset</th><th>Category</th><th>Location</th><th>Cost / value</th><th>Status</th><th>Action</th></tr></thead><tbody>
        {assets.map((asset) => <tr key={asset.id}>
          <td><strong>{asset.description}</strong><div className="mono muted">{asset.asset_code}{asset.serial_or_registration_number ? ` · ${asset.serial_or_registration_number}` : ""}</div></td>
          <td>{asset.category.replaceAll("_", " ")}</td>
          <td>{asset.location_or_address}</td>
          <td>{formatMoney(asset.acquisition_cost_cents)}<div className="muted">{asset.current_value_cents === null ? "No current valuation" : `Now ${formatMoney(asset.current_value_cents)}`}</div></td>
          <td><StatusBadge value={asset.status} />{asset.disposal_reason ? <div className="muted">{asset.disposal_reason}</div> : null}</td>
          <td><div className="actions">
            {asset.status === "ACTIVE" ? <button className="btn btn-secondary" type="button" disabled={state.kind === "working"} onClick={() => transition(asset.id, "maintenance")}>Flag maintenance</button> : null}
            {asset.status === "UNDER_MAINTENANCE" ? <button className="btn btn-secondary" type="button" disabled={state.kind === "working"} onClick={() => transition(asset.id, "restoration")}>Restore</button> : null}
            {asset.status !== "DISPOSED" ? <button className="btn btn-danger" type="button" disabled={state.kind === "working"} onClick={() => dispose(asset)}>Dispose</button> : <span className="muted">Read-only history</span>}
          </div></td>
        </tr>)}
        {!assets.length ? <tr><td colSpan={6} className="muted">No assets have been registered.</td></tr> : null}
      </tbody></table></div>
    </section>

    <form className="panel" onSubmit={submit}>
      <div className="panel-head"><div><h2 className="panel-title">Register asset</h2><div className="panel-meta">{assetClass === "IMMOVABLE" ? "Land and buildings" : "Vehicles, equipment, furniture and IT hardware"}</div></div></div>
      <div className="panel-body form-grid">
        <div className="form-group"><label htmlFor={`${assetClass}-asset-code`}>Asset code</label><input className="field" id={`${assetClass}-asset-code`} name="asset_code" required maxLength={40} placeholder={assetClass === "IMMOVABLE" ? "IMM-001" : "MOV-001"} /></div>
        <div className="form-group"><label htmlFor={`${assetClass}-category`}>Category</label><select className="select" id={`${assetClass}-category`} name="category" required defaultValue={categories[0]}>{categories.map((category) => <option key={category} value={category}>{category.replaceAll("_", " ")}</option>)}</select></div>
        <div className="form-group full"><label htmlFor={`${assetClass}-description`}>Description</label><input className="field" id={`${assetClass}-description`} name="description" required maxLength={300} /></div>
        <div className="form-group"><label htmlFor={`${assetClass}-serial`}>{serialLabel}</label><input className="field" id={`${assetClass}-serial`} name="serial_or_registration_number" maxLength={80} /></div>
        <div className="form-group"><label htmlFor={`${assetClass}-location`}>Location / address</label><input className="field" id={`${assetClass}-location`} name="location_or_address" required maxLength={300} /></div>
        <div className="form-group"><label htmlFor={`${assetClass}-acquisition-date`}>Acquisition date</label><input className="field" id={`${assetClass}-acquisition-date`} name="acquisition_date" type="date" required /></div>
        <div className="form-group"><label htmlFor={`${assetClass}-acquisition-cost`}>Acquisition cost (N$)</label><input className="field" id={`${assetClass}-acquisition-cost`} name="acquisition_cost" type="number" min="0" step="0.01" required /></div>
        <div className="form-group"><label htmlFor={`${assetClass}-current-value`}>Current value (N$, optional)</label><input className="field" id={`${assetClass}-current-value`} name="current_value" type="number" min="0" step="0.01" /></div>
        <div className="form-actions full"><button className="btn btn-primary" disabled={state.kind === "working"}>{state.kind === "working" ? "Saving…" : "Register asset"}</button></div>
        {state.message ? <div className={`alert full ${state.kind === "error" ? "alert-error" : state.kind === "success" ? "alert-success" : "alert-info"}`}>{state.message}</div> : null}
      </div>
    </form>
  </div>;
}
