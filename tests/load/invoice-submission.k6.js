/* global __ENV, __VU, __ITER */
import http from "k6/http";
import { check } from "k6";
import { Rate, Trend } from "k6/metrics";

const acceptanceFailures = new Rate("invoice_acceptance_failures");
const acceptanceLatency = new Trend("invoice_acceptance_latency", true);

export const options = {
  scenarios: {
    baseline: {
      executor: "constant-arrival-rate",
      rate: Number(__ENV.RATE || 10),
      timeUnit: "1s",
      duration: __ENV.DURATION || "2m",
      preAllocatedVUs: Number(__ENV.PREALLOCATED_VUS || 20),
      maxVUs: Number(__ENV.MAX_VUS || 200),
    },
  },
  thresholds: {
    http_req_failed: ["rate<0.005"],
    invoice_acceptance_failures: ["rate<0.005"],
    invoice_acceptance_latency: ["p(95)<750", "p(99)<1500"],
  },
};

export default function () {
  const unique = `${__VU}-${__ITER}-${Date.now()}`;
  const body = {
    schema_version: "1.0.0",
    document_type: "TAX_INVOICE",
    source: { system_id: "K6-AUTHORIZED", document_id: unique, submitted_at: new Date().toISOString() },
    supplier: { name: "Authorized synthetic supplier", identifiers: [{ type: "VAT_NUMBER", value: __ENV.SUPPLIER_VAT || "VAT1000123" }] },
    customer: { name: "Authorized synthetic buyer", identifiers: [{ type: "VAT_NUMBER", value: __ENV.CUSTOMER_VAT || "VAT1000789" }] },
    invoice_number: `LOAD-${unique}`,
    issue_date: new Date().toISOString().slice(0, 10),
    currency: "NAD",
    lines: [{ line_number: 1, description: "Authorized synthetic load test", quantity: "1", unit_code: "EA", unit_price: "100.00", net_amount: "100.00", tax: { category: "STANDARD", rate: "15.00", taxable_amount: "100.00", tax_amount: "15.00" } }],
    totals: { line_net_amount: "100.00", tax_exclusive_amount: "100.00", tax_amount: "15.00", tax_inclusive_amount: "115.00", payable_amount: "115.00" },
  };
  const response = http.post(`${__ENV.BASE_URL || "http://localhost:3000"}/api/v1/invoices`, JSON.stringify(body), {
    headers: {
      "Content-Type": "application/json",
      "Idempotency-Key": `k6-${unique}`,
      "X-Device-Id": "authorized-k6-runner",
    },
  });
  acceptanceLatency.add(response.timings.duration);
  const passed = check(response, { "accepted or idempotent": (result) => result.status === 201 || result.status === 200 });
  acceptanceFailures.add(!passed);
}
