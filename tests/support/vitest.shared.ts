// YoureCom Standard: managed by _dev/standard (apply.php), do not edit here.
import path from "node:path";

/**
 * Declaration files hold no runtime code, the coverage remapping cannot parse them and Vitest 4 counts them as 0 %.
 */
export const coverageExclude = ["**/*.d.ts"];

/**
 * Fallback alias for every WoltLab core module without an own mock. The core modules exist only in the browser through
 * the RequireJS loader, an untested file that imports one would fail to transform, and the coverage would drop it
 * silently. List the aliases with a real mock before this one.
 */
export const coreFallbackAlias = {
  find: /^WoltLabSuite\/Core\/.+/,
  replacement: path.resolve(__dirname, "WoltLabCoreStub.ts"),
};

/**
 * The aliases of a plugin followed by the fallback, in the order Vite matches them. A key matches the whole module name,
 * a string alias of Vite would also take every sub path and map it below the replacement file.
 */
export const withCoreFallback = (aliases: Record<string, string>): { find: RegExp; replacement: string }[] => [
  ...Object.entries(aliases).map(([find, replacement]) => ({ find: new RegExp(`^${find.replace(/[.*+?^${}()|[\]]/g, "\\$&")}$`), replacement })),
  coreFallbackAlias,
];
