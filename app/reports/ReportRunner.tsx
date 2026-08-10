"use client";

import { useState } from "react";

type Definition = { code: string; name: string; description: string };

export function ReportRunner({ definitions }: { definitions: Definition[] }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<Record<string, unknown> | null>(null);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    setError(null);
    setResult(null);
    const form = new FormData(event.currentTarget);
    try {
      const response = await fetch(`/api/v1/reports/${encodeURIComponent(String(form.get("report_code") ?? ""))}/runs`, {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({}),
      });
      const body = await response.json() as { report_run?: Record<string, unknown>; detail?: string };
      if (!response.ok) setError(body.detail ?? "The report could not be completed.");
      else setResult(body.report_run ?? null);
    } catch {
      setError("The reporting service could not be reached.");
    } finally {
      setBusy(false);
    }
  }

  return <form className="form-grid" onSubmit={submit}>
    <div className="form-group full"><label htmlFor="report_code">Report definition</label><select className="select" id="report_code" name="report_code" required defaultValue=""><option value="" disabled>Select a controlled report</option>{definitions.map((item) => <option key={item.code} value={item.code}>{item.name} - {item.description}</option>)}</select></div>
    {error ? <div className="form-group full alert alert-error" role="alert"><strong>Report failed</strong><br />{error}</div> : null}
    {result ? <div className="form-group full alert alert-success" role="status"><strong>Inline report completed</strong><pre className="mono" style={{ whiteSpace: "pre-wrap", marginBottom: 0 }}>{JSON.stringify(result.result_summary ?? {}, null, 2)}</pre></div> : null}
    <div className="form-group full form-actions"><button className="btn btn-primary" type="submit" disabled={busy || definitions.length === 0}>{busy ? "Running..." : "Run report"}</button></div>
  </form>;
}
