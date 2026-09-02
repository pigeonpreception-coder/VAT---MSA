import { fileURLToPath } from "node:url";
import { defineConfig } from "vitest/config";

export default defineConfig({
  resolve: {
    alias: [
      { find: "@", replacement: fileURLToPath(new URL("./", import.meta.url)) },
      // Test-only stand-ins for Workers/Next APIs that only resolve inside
      // their real runtimes — see the aliased files for why. Route-level
      // tests (tests/routes/**) rely on these; pure-domain tests never
      // import anything that reaches them.
      { find: "cloudflare:workers", replacement: fileURLToPath(new URL("./tests/fakes/cloudflare-workers.ts", import.meta.url)) },
      { find: "next/headers", replacement: fileURLToPath(new URL("./tests/fakes/next-headers.ts", import.meta.url)) },
      { find: "next/navigation", replacement: fileURLToPath(new URL("./tests/fakes/next-navigation.ts", import.meta.url)) },
    ],
  },
  test: {
    environment: "node",
    include: ["tests/**/*.test.ts"],
  },
});

