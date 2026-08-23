import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";

const architectureRoot = join(process.cwd(), "08-enterprise-architecture", "dual-subscription");
const orderedArtefacts = [
  "01-updated-enterprise-architecture.md",
  "02-c4-context.mmd",
  "03-c4-container.mmd",
  "04-c4-component-architecture.mmd",
  "05-dual-signup-architecture.md",
  "06-identity-federation-architecture.md",
  "07-tax-authority-subscription-architecture.md",
  "08-commercial-saas-licensing-architecture.md",
  "09-license-entitlement-architecture.md",
  "10-rbac-abac-authorization-matrix.csv",
  "11-organisation-user-hierarchy.mmd",
  "12-database-erd.mmd",
  "13-api-catalog.yaml",
  "14-itas-integration-architecture.md",
  "15-global-tax-authority-adapter-architecture.md",
  "16-security-architecture.md",
  "17-sequence-diagrams.md",
  "18-end-to-end-signup-workflows.md",
  "19-license-enforcement-workflows.md",
  "20-taxpayer-authorization-workflows.md",
  "21-failure-exception-handling.md",
  "22-audit-compliance-architecture.md",
  "23-scalability-architecture.md",
  "24-offline-synchronization-architecture.md",
  "25-threat-model.csv",
  "26-test-strategy.md",
  "27-security-test-cases.csv",
  "28-license-enforcement-test-cases.csv",
  "29-acceptance-criteria.md",
];

function source(name: string) {
  return readFileSync(join(architectureRoot, name), "utf8");
}

describe("dual-subscription architecture gate", () => {
  it("contains the complete ordered 29-artefact package", () => {
    const actual = readdirSync(architectureRoot).filter((name) => /^\d{2}-/.test(name)).sort();
    expect(actual).toEqual(orderedArtefacts);
    for (const artefact of orderedArtefacts) expect(source(artefact).trim().length).toBeGreaterThan(300);
  });

  it("models independent government and commercial authority decisions", () => {
    const core = source("01-updated-enterprise-architecture.md");
    expect(core).toContain("GOVERNMENT_TAX");
    expect(core).toContain("COMMERCIAL_SAAS");
    expect(core).toContain("Government Tax Authorization Service");
    expect(core).toContain("License & Entitlement Service");
    expect(core).toMatch(/never.*substitute|not.*substitute/i);

    const matrix = source("10-rbac-abac-authorization-matrix.csv");
    expect(matrix).toContain("COMPANY_SYSTEM_ADMIN,authorize,taxpayer,GOVERNMENT_TAX");
    expect(matrix).toContain("TAX_AUTHORITY_ADMIN,manage,commercial_subscription,COMMERCIAL_SAAS");
    expect(matrix.match(/,DENY,/g)?.length).toBeGreaterThanOrEqual(9);
  });

  it("makes user capacity explicit and transactionally enforced", () => {
    const entitlement = source("09-license-entitlement-architecture.md");
    const erd = source("12-database-erd.mmd");
    const workflow = source("19-license-enforcement-workflows.md");
    for (const mode of ["FINITE", "UNLIMITED", "NOT_APPLICABLE"]) {
      expect(entitlement).toContain(mode);
      expect(erd).toContain(mode);
    }
    expect(workflow).toContain("USER_LICENSE_LIMIT_REACHED");
    expect(workflow).toMatch(/serialized transaction/i);
  });

  it("covers all mandatory workflows and high-risk negative tests", () => {
    const workflows = source("18-end-to-end-signup-workflows.md");
    const headings = workflows.match(/^## \d+\./gm) ?? [];
    expect(headings).toHaveLength(18);
    expect(workflows).toContain("## 18. One company operates as Buyer and Seller");

    const securityCases = source("27-security-test-cases.csv");
    const licenceCases = source("28-license-enforcement-test-cases.csv");
    expect(securityCases.match(/^SEC-/gm)?.length).toBeGreaterThanOrEqual(25);
    expect(licenceCases.match(/^LIC-/gm)?.length).toBeGreaterThanOrEqual(24);
    expect(licenceCases).toContain("Exactly one commits and usage never exceeds 100");
  });

  it("preserves the local/staging safety boundary", () => {
    const acceptance = source("29-acceptance-criteria.md");
    expect(acceptance).toMatch(/real payment.*live ITAS.*disabled/i);
    expect(acceptance).toMatch(/production.*remain.*approval/i);
    const itas = source("14-itas-integration-architecture.md");
    expect(itas).toMatch(/disabled reference design/i);
    expect(itas).toContain("ITAS_INTEGRATION_DISABLED");
  });
});
