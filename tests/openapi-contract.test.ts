import { readFileSync, readdirSync } from "node:fs";
import { join, relative } from "node:path";
import { describe, expect, it } from "vitest";

const HTTP_METHODS = new Set(["get", "post", "put", "patch", "delete"]);

function walk(directory: string): string[] {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = join(directory, entry.name);
    return entry.isDirectory() ? walk(path) : [path];
  });
}

function normalized(path: string): string {
  return path.replace(/\{[^}]+\}/gu, "{}").replace(/\[[^\]]+\]/gu, "{}");
}

function runtimeOperations(): string[] {
  const root = join(process.cwd(), "app", "api", "v1");
  return walk(root).filter((path) => path.endsWith("route.ts")).flatMap((path) => {
    const source = readFileSync(path, "utf8");
    const route = normalized(`/v1/${relative(root, path).replaceAll("\\", "/").replace(/\/route\.ts$/u, "")}`);
    return Array.from(source.matchAll(/^export async function (GET|POST|PUT|PATCH|DELETE)\b/gmu), (match) => `${match[1].toLowerCase()} ${route}`);
  }).sort();
}

function documentedOperations(): string[] {
  const lines = readFileSync(join(process.cwd(), "03-api", "openapi.yaml"), "utf8").split(/\r?\n/u);
  const operations: string[] = [];
  let path: string | null = null;
  for (const line of lines) {
    const pathMatch = /^ {2}(\/v1\/[^:]+):\s*$/u.exec(line);
    if (pathMatch) {
      path = normalized(pathMatch[1]);
      continue;
    }
    if (/^[^ ]/u.test(line)) path = null;
    const methodMatch = /^ {4}([a-z]+):\s*$/u.exec(line);
    if (path && methodMatch && HTTP_METHODS.has(methodMatch[1])) operations.push(`${methodMatch[1]} ${path}`);
  }
  return operations.sort();
}

describe("OpenAPI runtime contract", () => {
  it("documents every implemented v1 operation and no stale operation", () => {
    expect(documentedOperations()).toEqual(runtimeOperations());
  });
});
