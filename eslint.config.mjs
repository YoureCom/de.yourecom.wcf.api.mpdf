// YoureCom Standard: managed by _dev/standard (apply.php), do not edit here.
// @ts-check
import tseslint from "typescript-eslint";
import eslintConfigPrettier from "eslint-config-prettier";

export default tseslint.config(
  {
    ignores: ["files/**", "files_wcf/**", "node_modules/**", "vendor/**", "var/**", "snapshots/**"],
  },
  ...tseslint.configs.recommended,
  {
    rules: {
      // the core modules are typed loosely in places, `any` at those boundaries is a warning only
      "@typescript-eslint/no-explicit-any": "warn",
      "@typescript-eslint/no-unused-vars": ["warn", { argsIgnorePattern: "^_" }],
      // strict comparisons only
      eqeqeq: ["error", "always"],
      // no debug output in the package (tests may log)
      "no-console": "error",
      "no-debugger": "error",
      "no-alert": "error",
    },
  },
  {
    files: ["tests/**"],
    rules: {
      "no-console": "off",
    },
  },
  eslintConfigPrettier,
);
