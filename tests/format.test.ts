import { describe, expect, it } from "vitest";
import { formatMoney } from "@/lib/format";

describe("money formatting", () => {
  it("uses N$ for the default Namibian-dollar presentation", () => {
    const formatted = formatMoney(123_456).replaceAll("\u00a0", " ");

    expect(formatted).toBe("N$ 1,234.56");
    expect(formatted).not.toContain("NAD");
  });

  it("uses N$ when NAD is supplied explicitly", () => {
    expect(formatMoney(10_000, "NAD")).toContain("N$");
    expect(formatMoney(10_000, "nad")).toContain("N$");
  });
});
