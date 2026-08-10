interface D1Result<T = Record<string, unknown>> {
  results: T[];
  success?: boolean;
  meta?: Record<string, unknown>;
}

interface D1PreparedStatement {
  bind(...values: unknown[]): D1PreparedStatement;
  first<T = Record<string, unknown>>(): Promise<T | null>;
  all<T = Record<string, unknown>>(): Promise<D1Result<T>>;
  run<T = Record<string, unknown>>(): Promise<D1Result<T>>;
}

interface D1Database {
  prepare(query: string): D1PreparedStatement;
  batch<T = Record<string, unknown>>(statements: D1PreparedStatement[]): Promise<Array<D1Result<T>>>;
}

interface Fetcher {
  fetch(input: RequestInfo | URL, init?: RequestInit): Promise<Response>;
}

interface R2PutOptions {
  httpMetadata?: { contentType?: string; contentDisposition?: string };
  customMetadata?: Record<string, string>;
}

interface R2Object {
  key: string;
  size: number;
  etag: string;
}

interface R2Bucket {
  put(key: string, value: ArrayBuffer | ArrayBufferView | Blob | ReadableStream, options?: R2PutOptions): Promise<R2Object | null>;
  delete(key: string): Promise<void>;
}

declare module "cloudflare:workers" {
  export const env: {
    DB: D1Database;
    DOCUMENTS: R2Bucket;
    ASSETS?: Fetcher;
  };
}
