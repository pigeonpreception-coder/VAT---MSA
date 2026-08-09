import { execFileSync } from "node:child_process";
import { mkdir, readFile, writeFile } from "node:fs/promises";

const packageDocument = JSON.parse(await readFile("package.json", "utf8"));
let dependencyTree;
try {
  if (!process.env.npm_execpath) throw new Error("npm_execpath is unavailable; invoke this script through pnpm.");
  dependencyTree = JSON.parse(execFileSync(process.execPath, [process.env.npm_execpath, "list", "--prod", "--json", "--depth", "Infinity"], { encoding: "utf8" }))[0];
} catch (error) {
  console.error("Unable to enumerate installed production dependencies. Run pnpm install first.");
  throw error;
}

const componentsByRef = new Map();
function collect(dependencies = {}) {
  for (const [name, dependency] of Object.entries(dependencies)) {
    const version = dependency.version ?? "unknown";
    const bomRef = `pkg:npm/${encodeURIComponent(name)}@${version}`;
    componentsByRef.set(bomRef, { type: "library", name, version, "bom-ref": bomRef, purl: bomRef });
    collect(dependency.dependencies);
  }
}
collect(dependencyTree.dependencies);

const timestamp = new Date().toISOString();
const sbom = {
  bomFormat: "CycloneDX",
  specVersion: "1.5",
  serialNumber: `urn:uuid:${crypto.randomUUID()}`,
  version: 1,
  metadata: {
    timestamp,
    tools: [{ vendor: "VAT-MSA", name: "generate-sbom.mjs", version: "1.0.0" }],
    component: { type: "application", name: packageDocument.name, version: packageDocument.version, "bom-ref": `pkg:npm/${packageDocument.name}@${packageDocument.version}` },
  },
  components: [...componentsByRef.values()].sort((a, b) => a.purl.localeCompare(b.purl)),
};

await mkdir("artifacts", { recursive: true });
await writeFile("artifacts/sbom.cdx.json", `${JSON.stringify(sbom, null, 2)}\n`);
console.log(`Wrote CycloneDX SBOM with ${sbom.components.length} production components.`);
