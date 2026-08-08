"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import type { InvoiceSummary } from "@/lib/domain/types";
import { formatDate, formatMoney } from "@/lib/format";
import { StatusBadge } from "@/components/PageHeader";

export function InvoiceTable({ invoices }: { invoices: InvoiceSummary[] }) {
  const [query, setQuery] = useState("");
  const [status, setStatus] = useState("ALL");
  const visible = useMemo(() => invoices.filter((invoice) => {
    const text = `${invoice.invoiceNumber} ${invoice.supplierName} ${invoice.supplierVatNumber} ${invoice.customerName} ${invoice.customerVatNumber ?? ""}`.toLowerCase();
    return text.includes(query.toLowerCase()) && (status === "ALL" || invoice.status === status);
  }), [invoices, query, status]);

  return (
    <section className="panel">
      <div className="filters">
        <input className="field" type="search" aria-label="Search invoices" placeholder="Search invoice, supplier or VAT number" value={query} onChange={(event) => setQuery(event.target.value)} />
        <select className="select" aria-label="Filter invoice status" value={status} onChange={(event) => setStatus(event.target.value)}>
          <option value="ALL">All statuses</option><option value="MATCHED">Matched</option><option value="CERTIFIED">Certified</option><option value="EXCEPTION">Exception</option>
        </select>
        <span className="panel-meta" style={{ alignSelf: "center", marginLeft: "auto" }}>{visible.length} document{visible.length === 1 ? "" : "s"}</span>
      </div>
      {visible.length ? <div className="table-wrap"><table>
        <thead><tr><th>Invoice</th><th>Issue date</th><th>Supplier</th><th>Customer</th><th>Net value</th><th>VAT</th><th>Total</th><th>Status</th><th>Risk</th></tr></thead>
        <tbody>{visible.map((invoice) => <tr key={invoice.id}>
          <td><Link href={`/invoices/${invoice.id}`}><strong>{invoice.invoiceNumber}</strong></Link><div className="muted mono">{invoice.id}</div></td>
          <td>{formatDate(invoice.issueDate)}</td>
          <td>{invoice.supplierName}<div className="muted">{invoice.supplierVatNumber}</div></td>
          <td>{invoice.customerName}<div className="muted">{invoice.customerVatNumber ?? "Not VAT registered"}</div></td>
          <td className="amount">{formatMoney(invoice.lineNetCents, invoice.currency)}</td>
          <td className="amount">{formatMoney(invoice.taxCents, invoice.currency)}</td>
          <td className="amount">{formatMoney(invoice.totalCents, invoice.currency)}</td>
          <td><StatusBadge value={invoice.status} /></td><td><StatusBadge value={invoice.riskLevel} /></td>
        </tr>)}</tbody>
      </table></div> : <div className="empty"><strong>No invoices match this view</strong>Adjust the search or status filter.</div>}
    </section>
  );
}

