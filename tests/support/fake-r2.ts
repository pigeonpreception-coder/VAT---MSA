/**
 * A minimal in-memory stand-in for the R2Bucket binding platform-repository.ts
 * uses for document storage (env.DOCUMENTS). Only implements the two methods
 * that repository code actually calls today (put/delete) — extend if a later
 * phase starts reading objects back.
 */
export function createFakeR2Bucket(): R2Bucket {
  const store = new Map<string, unknown>();
  return {
    async put(key: string, value: unknown) {
      store.set(key, value);
      return null as never;
    },
    async delete(key: string) {
      store.delete(key);
    },
    async get() {
      return null;
    },
  } as unknown as R2Bucket;
}
