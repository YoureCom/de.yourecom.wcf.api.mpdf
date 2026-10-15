#!/usr/bin/env bash
# YoureCom Standard: managed by _dev/standard (apply.php), do not edit here.
# Builds the installable package (WoltLab format) into the given directory, default: var/package.
# Used by the release workflow and locally. Uploading stays a manual step that needs Marcel's approval.
#
# Usage: bash .github/scripts/build-package.sh [output dir]
# Prints the path of the created archive. Locally the archive is also copied to C:\laragon\www\archive\<package>.tar.gz
# (override the folder with ARCHIVE_DIR).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OUT="${1:-$ROOT/var/package}"
# absolute, because pack() below changes the folder
case "$OUT" in
  /* | [A-Za-z]:*) ;;
  *) OUT="$PWD/$OUT" ;;
esac
STAGE="$OUT/stage"

PACKAGE="$(sed -n 's:.*<package name="\([^"]*\)".*:\1:p' "$ROOT/package.xml" | head -1)"
VERSION="$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$ROOT/package.xml" | head -1)"
[[ -n "$PACKAGE" && -n "$VERSION" ]] || { echo "package.xml has no name or version" >&2; exit 1; }

# the package ships the committed JavaScript, nothing compiles it here: a stale or missing build would load fine locally
# (deploy.sh compiles into the dev system) and fail on the live site, so stop before packing
if [[ -f "$ROOT/tests/lint/check-js-build.mjs" ]]; then
  (cd "$ROOT" && node tests/lint/check-js-build.mjs) || { echo "the committed JavaScript is out of date, run: npm run build" >&2; exit 1; }
fi

rm -rf "$STAGE"
mkdir -p "$STAGE"

# the archives of the file and template PIPs, named as in package.xml (the default names when no name is given)
pack() { # pack <source dir> <archive>
  [[ -d "$ROOT/$1" ]] || return 0
  # cd and back instead of a subshell: a subshell is one more process start (set -e ends the script when tar fails)
  cd "$ROOT/$1"
  tar --format=ustar -cf "$STAGE/$2" --exclude='.DS_Store' --exclude='*.orig' --exclude='*.bak' -- *
  cd "$ROOT"
}
pack files files.tar
pack files_wcf files_wcf.tar
pack templates templates.tar
pack acptemplates acptemplates.tar

# package.xml, the PIP files and the language files; development files stay outside. One cp for all of them and no
# basename per file: on Windows every process start costs about 100 ms (a package with 24 PIP files spent 4 s here)
XML_FILES=()
for FILE in "$ROOT"/*.xml; do
  case "${FILE##*/}" in
    phpunit*.xml) ;;
    *) XML_FILES+=("$FILE") ;;
  esac
done
cp -- "${XML_FILES[@]}" "$STAGE/"
# packages without phrases (e.g. branding free) have no language folder
if [[ -d "$ROOT/language" ]]; then
  cp -r "$ROOT/language" "$STAGE/language"
fi
# packages delivered with the plugin (<requiredpackage file="requirements/...">)
if [[ -d "$ROOT/requirements" ]]; then
  cp -r "$ROOT/requirements" "$STAGE/requirements"
fi

ARCHIVE="$OUT/${PACKAGE}_${VERSION// /_}.tar.gz"
rm -f "$ARCHIVE"
(cd "$STAGE" && tar --format=ustar -czf "$ARCHIVE" -- *)
rm -rf "$STAGE"

# the archive contains everything package.xml refers to: the named files of the instructions and required packages,
# the default files of instructions without a name (file -> files.tar, language -> language/*.xml, <pip> -> <pip>.xml)
# and no pax headers (WoltLab reads ustar)
CONTENTS="$(tar -tzf "$ARCHIVE")"
if [[ "$CONTENTS" == *PaxHeader* ]]; then
  echo "the archive contains pax headers" >&2
  exit 1
fi
php -r '
  $contents = array_flip(explode("\n", $argv[2]));
  $xml = new DOMDocument();
  $xml->load($argv[1]);
  $xpath = new DOMXPath($xml);
  $xpath->registerNamespace("w", "http://www.woltlab.com");
  $defaults = ["file" => "files.tar", "template" => "templates.tar", "acpTemplate" => "acptemplates.tar"];
  $missing = [];
  foreach ($xpath->query("//w:requiredpackage[@file]") as $node) {
    $missing[] = $node->getAttribute("file");
  }
  foreach ($xpath->query("//w:instruction") as $node) {
    $type = $node->getAttribute("type");
    $name = trim($node->textContent);
    if ($name === "") {
      if ($type === "language") {
        if (!preg_grep("~^language/\w+\.xml$~", array_keys($contents))) {
          $missing[] = "language/*.xml";
        }
        continue;
      }
      $name = $defaults[$type] ?? "{$type}.xml";
    } elseif (in_array($type, ["script", "database", "sql"], true)) {
      // these refer to files inside files.tar
      continue;
    }
    $missing[] = $name;
  }
  $missing = array_values(array_unique(array_filter($missing, static fn (string $file): bool => !isset($contents[$file]))));
  if ($missing !== []) {
    fwrite(STDERR, "package.xml refers to files the archive does not contain: " . implode(", ", $missing) . "\n");
    exit(1);
  }
' -- "$ROOT/package.xml" "$CONTENTS" || exit 1

# local builds: the finished package is also put into the archive folder as <package>.tar.gz, an existing file is
# overwritten (the folder does not exist on the CI runner, there the step is skipped)
ARCHIVE_DIR="${ARCHIVE_DIR:-/c/laragon/www/archive}"
if [[ -d "$ARCHIVE_DIR" ]]; then
  cp -f "$ARCHIVE" "$ARCHIVE_DIR/$PACKAGE.tar.gz"
  echo "copied to $ARCHIVE_DIR/$PACKAGE.tar.gz" >&2
fi

echo "$ARCHIVE"
