/**
 * Test-only stand-in for the `cloudflare:workers` module. Production code
 * (db/runtime.ts) imports `{ env }` from `cloudflare:workers` to reach its D1
 * binding — that module only resolves inside an actual Workers runtime, so
 * plain `vitest run` can't load db/runtime.ts at all without this. Aliased
 * in vitest.config.ts. `env.DB` is a plain mutable slot: route-level test
 * setup assigns a fresh fake D1Database (tests/support/fake-d1.ts) into it
 * before exercising any code that calls ensureDatabase().
 */
export const env: { DB: D1Database; DOCUMENTS?: R2Bucket; ASSETS?: Fetcher } = {
  DB: undefined as unknown as D1Database,
};
