import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";

describe("self-serve signup activation safety", () => {
  it("keeps the public repository free of user, taxpayer, organisation, subscription and licence creation", () => {
    const repository = readFileSync(join(process.cwd(), "lib", "data", "signup-repository.ts"), "utf8");
    expect(repository).not.toMatch(/INSERT INTO\s+(app_users|taxpayers|organisations|subscriptions|organisation_licenses)/i);
    expect(repository).toContain('"NOT_ACTIVATED"');
    expect(repository).toContain('"SelfServeSignupSubmitted"');
  });

  it("documents and rate-limits the deliberate public exemption", () => {
    const route = readFileSync(join(process.cwd(), "app", "api", "v1", "signup-applications", "route.ts"), "utf8");
    const openapi = readFileSync(join(process.cwd(), "03-api", "openapi.yaml"), "utf8");
    const architecture = readFileSync(join(process.cwd(), "08-enterprise-architecture", "30-workspace-organisation-licensing-workflow-architecture.md"), "utf8");
    expect(route).toContain("enforceSelfServeSignupSourceRateLimits");
    expect(route).toContain("enforceSelfServeSignupEmailRateLimit");
    expect(openapi).toContain("/v1/signup-applications:");
    expect(openapi).toContain("licence_status: { type: string, const: NOT_ACTIVATED }");
    expect(architecture).toContain("Controlled self-serve application channel");
  });
});
