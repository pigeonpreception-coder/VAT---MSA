import { describe, expect, it } from "vitest";
import { calculateReturnPosition, normalizeAndValidateAdjustment, validateDecisionComment, VatLifecycleValidationError } from "@/lib/domain/vat-lifecycle";

describe("governed VAT lifecycle", () => {
  it("calculates output less eligible input using integer cents", () => {
    const result = calculateReturnPosition({
      outputEntries: [{ id: "out-1", amount_cents: 1_717_500 }],
      inputEntries: [{ id: "in-1", amount_cents: 780_000 }],
      adjustments: [],
    });
    expect(result.outputTaxCents).toBe(1_717_500);
    expect(result.inputTaxCents).toBe(780_000);
    expect(result.netPayableCents).toBe(937_500);
  });

  it("applies output, input and direct net adjustment semantics", () => {
    const result = calculateReturnPosition({
      outputEntries: [{ id: "out-1", amount_cents: 100_000 }],
      inputEntries: [{ id: "in-1", amount_cents: 20_000 }],
      adjustments: [
        { id: "a1", adjustment_type: "OUTPUT_TAX", direction: "INCREASE", amount_cents: 5_000 },
        { id: "a2", adjustment_type: "INPUT_TAX", direction: "INCREASE", amount_cents: 2_000 },
        { id: "a3", adjustment_type: "NET_PAYABLE", direction: "DECREASE", amount_cents: 1_000 },
      ],
    });
    expect(result.outputTaxCents).toBe(105_000);
    expect(result.inputTaxCents).toBe(22_000);
    expect(result.adjustmentCents).toBe(2_000);
    expect(result.netPayableCents).toBe(82_000);
  });

  it("rejects an adjustment that underflows a VAT side", () => {
    expect(() => calculateReturnPosition({
      outputEntries: [{ id: "out-1", amount_cents: 1_000 }],
      inputEntries: [],
      adjustments: [{ id: "a1", adjustment_type: "OUTPUT_TAX", direction: "DECREASE", amount_cents: 1_001 }],
    })).toThrowError(VatLifecycleValidationError);
  });

  it("normalizes a governed adjustment request", () => {
    expect(normalizeAndValidateAdjustment({
      schema_version: "1.0.0",
      adjustment_type: "input_tax",
      direction: "decrease",
      amount_cents: 15_000,
      reason_code: "credit-note",
      explanation: "Credit note was received after the original ledger posting.",
    })).toEqual({
      schema_version: "1.0.0",
      adjustment_type: "INPUT_TAX",
      direction: "DECREASE",
      amount_cents: 15_000,
      reason_code: "CREDIT-NOTE",
      explanation: "Credit note was received after the original ledger posting.",
    });
  });

  it("requires a substantive maker-checker decision comment", () => {
    expect(() => validateDecisionComment("ok")).toThrowError(VatLifecycleValidationError);
    expect(validateDecisionComment("Evidence reviewed and totals reconciled.")).toBe("Evidence reviewed and totals reconciled.");
  });
});
