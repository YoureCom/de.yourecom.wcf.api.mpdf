// YoureCom Standard: the committed JavaScript (`jsBuildDir` of tests/lint/config.php, e.g. files/js) is exactly what the
// TypeScript sources produce. Managed by _dev/standard (apply.php), do not edit here.
import { execFileSync } from "node:child_process";
import { existsSync, mkdtempSync, readdirSync, readFileSync, rmSync, statSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, relative } from "node:path";
import { fileURLToPath } from "node:url";

const root = fileURLToPath(new URL("../..", import.meta.url));
const configSource = readFileSync(join(root, "tests/lint/config.php"), "utf8");
const buildDir = /'jsBuildDir'\s*=>\s*'([^']+)'/.exec(configSource)?.[1];

function files(dir) {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name);
    return statSync(path).isDirectory() ? files(path) : [path];
  });
}

if (!buildDir) {
  // without jsBuildDir nothing would be checked, so TypeScript sources require it
  const sources = existsSync(join(root, "ts"))
    ? files(join(root, "ts")).filter((file) => file.endsWith(".ts") && !file.endsWith(".d.ts") && !file.endsWith(".test.ts"))
    : [];
  if (sources.length > 0) {
    console.error(`ts/ has ${sources.length} TypeScript files, but tests/lint/config.php sets no jsBuildDir (e.g. 'files/js').`);
    process.exit(1);
  }
  console.log("OK (no TypeScript)");
  process.exit(0);
}

const tsc = [join(root, "node_modules/typescript/bin/tsc"), join(root, "../node_modules/typescript/bin/tsc")].find(existsSync);
const out = mkdtempSync(join(tmpdir(), "yourecom-js-"));

function read(dir) {
  return new Map(files(dir).map((file) => [relative(dir, file).replaceAll("\\", "/"), readFileSync(file, "utf8").replaceAll("\r\n", "\n")]));
}

try {
  // tsconfig.ci.json resolves the types from the plugin's node_modules (CI), tsconfig.json from ../node_modules (local)
  const project = existsSync(join(root, "node_modules/@woltlab/d.ts")) ? "tsconfig.ci.json" : "tsconfig.json";
  execFileSync(process.execPath, [tsc, "-p", project, "--noEmit", "false", "--outDir", out], { cwd: root, stdio: "inherit" });

  const fresh = read(out);
  const committed = existsSync(join(root, buildDir)) ? read(join(root, buildDir)) : new Map();
  const problems = [];
  for (const [name, content] of fresh) {
    if (!committed.has(name)) problems.push(`missing in ${buildDir}: ${name}`);
    else if (committed.get(name) !== content) problems.push(`outdated: ${buildDir}/${name}`);
  }
  for (const name of committed.keys()) {
    if (name.endsWith(".js") && !fresh.has(name) && !name.startsWith("3rdParty/")) problems.push(`no TypeScript source for: ${buildDir}/${name}`);
  }

  if (problems.length > 0) {
    console.error(problems.join("\n"));
    console.error("Run `npm run build` and commit the result.");
    process.exit(1);
  }
  console.log(`OK (${fresh.size} files)`);
} finally {
  rmSync(out, { recursive: true, force: true });
}
