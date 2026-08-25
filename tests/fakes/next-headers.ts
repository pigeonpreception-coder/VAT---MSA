/**
 * Test-only stand-in for `next/headers`. app/chatgpt-auth.ts calls its async
 * `headers()` to read the platform-authenticated-user headers; the real
 * implementation reads Next's request-scoped AsyncLocalStorage, which only
 * exists inside an actual Next/vinext request pipeline — not when a test
 * invokes an exported route handler directly. Aliased in vitest.config.ts.
 * __setRequestHeaders lets a test declare "this is the incoming request's
 * headers" immediately before calling a route handler; tests in this repo
 * run sequentially (no test.concurrent), so this module-level slot is safe.
 */
let current = new Map<string, string>();

export function __setRequestHeaders(entries: Record<string, string>): void {
  current = new Map(Object.entries(entries).map(([key, value]) => [key.toLowerCase(), value]));
}

export async function headers(): Promise<{ get(name: string): string | null }> {
  return { get: (name: string) => current.get(name.toLowerCase()) ?? null };
}
