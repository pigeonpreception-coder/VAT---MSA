import { DatabaseSync } from "node:sqlite";

/**
 * A D1Database-shaped wrapper over Node's built-in node:sqlite (stable in
 * this repo's Node 24). Cloudflare D1 *is* SQLite under the hood, and this
 * codebase only ever issues plain parameterized SQL (no D1-specific
 * extensions), so this gives route/repository tests a real SQL engine
 * enforcing real constraints — not a mock that just returns canned data.
 * Not a byte-for-byte substitute for the actual workerd-hosted D1 runtime;
 * see MODULE_DEVELOPMENT_PLAYBOOK.md's Phase E note on that tradeoff.
 */
class FakeStatement implements D1PreparedStatement {
  readonly #db: DatabaseSync;
  readonly #sql: string;
  readonly #params: unknown[];

  constructor(db: DatabaseSync, sql: string, params: unknown[] = []) {
    this.#db = db;
    this.#sql = sql;
    this.#params = params;
  }

  bind(...values: unknown[]): D1PreparedStatement {
    return new FakeStatement(this.#db, this.#sql, values);
  }

  async first<T = Record<string, unknown>>(): Promise<T | null> {
    const row = this.#db.prepare(this.#sql).get(...(this.#params as never[]));
    return (row as T | undefined) ?? null;
  }

  async all<T = Record<string, unknown>>(): Promise<D1Result<T>> {
    const rows = this.#db.prepare(this.#sql).all(...(this.#params as never[])) as T[];
    return { results: rows, success: true };
  }

  async run<T = Record<string, unknown>>(): Promise<D1Result<T>> {
    // .all() rather than .run(): node:sqlite's .run() silently discards any
    // RETURNING clause, which this codebase relies on (see
    // lib/security/request.ts's enforceRateLimits). .all() executes the
    // statement identically and additionally captures RETURNING rows when
    // present, or an empty array when it isn't — a strict superset of .run().
    const rows = this.#db.prepare(this.#sql).all(...(this.#params as never[])) as T[];
    return { results: rows, success: true };
  }
}

export function createFakeD1(): D1Database {
  const sqlite = new DatabaseSync(":memory:");
  return {
    prepare(sql: string): D1PreparedStatement {
      return new FakeStatement(sqlite, sql);
    },
    async batch<T = Record<string, unknown>>(statements: D1PreparedStatement[]): Promise<Array<D1Result<T>>> {
      const results: Array<D1Result<T>> = [];
      sqlite.exec("BEGIN");
      try {
        for (const statement of statements) results.push(await statement.run<T>());
        sqlite.exec("COMMIT");
      } catch (error) {
        sqlite.exec("ROLLBACK");
        throw error;
      }
      return results;
    },
  };
}
