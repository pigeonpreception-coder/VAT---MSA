import { readFile, readdir } from "node:fs/promises";
import { extname, join, relative } from "node:path";

const root = process.cwd();
const excludedDirectories = new Set([".git", ".next", ".wrangler", "coverage", "dist", "node_modules"]);
const scannedExtensions = new Set([".cjs", ".css", ".env", ".js", ".json", ".jsx", ".md", ".mjs", ".sql", ".ts", ".tsx", ".yaml", ".yml"]);
const signatures = [
  ["private key", /-----BEGIN (?:RSA |EC |OPENSSH |PGP )?PRIVATE KEY-----/],
  ["AWS access key", /\b(?:AKIA|ASIA)[A-Z0-9]{16}\b/],
  ["GitHub token", /\bgh[opusr]_[A-Za-z0-9]{30,}\b/],
  ["npm token", /\bnpm_[A-Za-z0-9]{30,}\b/],
  ["hard-coded credential", /\b(?:api[_-]?key|client[_-]?secret|password|token)\s*[:=]\s*["'][A-Za-z0-9_+/=.-]{20,}["']/i],
];

async function filesUnder(directory) {
  const files = [];
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    if (entry.isDirectory() && excludedDirectories.has(entry.name)) continue;
    const path = join(directory, entry.name);
    if (entry.isDirectory()) files.push(...await filesUnder(path));
    else if (scannedExtensions.has(extname(entry.name)) || entry.name.startsWith(".env")) files.push(path);
  }
  return files;
}

const findings = [];
for (const file of await filesUnder(root)) {
  const contents = await readFile(file, "utf8");
  contents.split(/\r?\n/).forEach((line, index) => {
    for (const [name, pattern] of signatures) {
      if (pattern.test(line)) findings.push(`${relative(root, file)}:${index + 1}: possible ${name}`);
    }
  });
}

if (findings.length) {
  console.error(findings.join("\n"));
  process.exitCode = 1;
} else {
  console.log("Secret scan passed (heuristic local baseline). Enterprise detection remains required in CI.");
}
