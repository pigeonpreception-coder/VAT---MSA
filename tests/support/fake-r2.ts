/**
 * A minimal in-memory stand-in for the R2Bucket binding platform-repository.ts
 * uses for document storage (env.DOCUMENTS). Implements put/delete/get —
 * enough for Module 6 Phase A's upload/supersede paths and Phase B's
 * AuthorizedDownload read-back. Extend further only if a later phase needs
 * more of the real R2Bucket surface (listing, conditional requests, etc.).
 */
export function createFakeR2Bucket(): R2Bucket {
  const store = new Map<string, ArrayBuffer>();
  return {
    async put(key: string, value: ArrayBuffer | ArrayBufferView | string) {
      const buffer = typeof value === "string"
        ? new TextEncoder().encode(value).buffer
        : ArrayBuffer.isView(value)
          ? value.buffer.slice(value.byteOffset, value.byteOffset + value.byteLength)
          : value;
      store.set(key, buffer as ArrayBuffer);
      return null as never;
    },
    async delete(key: string) {
      store.delete(key);
    },
    async get(key: string) {
      const buffer = store.get(key);
      if (!buffer) return null;
      return { arrayBuffer: async () => buffer } as unknown as R2ObjectBody;
    },
  } as unknown as R2Bucket;
}
