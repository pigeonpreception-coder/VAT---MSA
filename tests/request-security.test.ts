import { describe, expect, it } from "vitest";
import { correlationIdFor, readBoundedJson, RequestGuardError } from "@/lib/security/request";

describe("request security controls", () => {
  it("preserves a valid caller correlation ID", () => {
    const id = "123e4567-e89b-42d3-a456-426614174000";
    expect(correlationIdFor(new Request("https://vat.example", { headers: { "x-correlation-id": id } }))).toBe(id);
  });

  it("replaces an invalid correlation ID", () => {
    expect(correlationIdFor(new Request("https://vat.example", { headers: { "x-correlation-id": "attacker-controlled-value" } }))).toMatch(/^[0-9a-f-]{36}$/);
  });

  it("rejects non-JSON content", async () => {
    await expect(readBoundedJson(new Request("https://vat.example", { method: "POST", headers: { "content-type": "text/plain" }, body: "{}" }))).rejects.toMatchObject({ status: 415 });
  });

  it("rejects an oversized body before parsing", async () => {
    await expect(readBoundedJson(new Request("https://vat.example", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ value: "x".repeat(100) }) }), 32)).rejects.toBeInstanceOf(RequestGuardError);
  });
});
