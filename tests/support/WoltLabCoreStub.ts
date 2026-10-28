// YoureCom Standard: managed by _dev/standard (apply.php), do not edit here.
// Stands in for a WoltLab core module without an own mock, so that a file importing it still transforms for the coverage.
// Any use fails loudly: a test needs a real mock (alias in vitest.config.ts).
const fail = (property: string | symbol): never => {
  throw new Error(`A WoltLab core module without a mock was used (${String(property)}), add an alias with a mock in vitest.config.ts.`);
};

export default new Proxy(
  {},
  {
    get: (_target, property) => (property === "__esModule" ? true : fail(property)),
    apply: () => fail("call"),
  },
);
