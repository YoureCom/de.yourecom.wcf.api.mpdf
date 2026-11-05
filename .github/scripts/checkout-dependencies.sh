#!/usr/bin/env bash
# YoureCom Standard: clones the required YoureCom packages of package.xml (and those of .github/phpstan-packages.txt) next to the plugin (CI only), so PHPStan
# finds their classes like in the local plugins folder. Managed by _dev/standard (apply.php), do not edit here.
#
# Usage: TOKEN=<token> bash .github/scripts/checkout-dependencies.sh <plugin dir>
set -euo pipefail

PLUGIN="${1:-.}"
BRANCH="${DEPENDENCY_BRANCH:-6.2}"
mapfile -t PACKAGES < <(sed -n 's:.*<requiredpackage[^>]*>\(de\.yourecom\.[^<]*\)</requiredpackage>.*:\1:p' "$PLUGIN/package.xml")

# packages the plugin only uses when they are installed (no requiredpackage): one per line in .github/phpstan-packages.txt
if [[ -f "$PLUGIN/.github/phpstan-packages.txt" ]]; then
  mapfile -t -O "${#PACKAGES[@]}" PACKAGES < <(sed -n 's:^\(de\.yourecom\.[A-Za-z0-9.]*\)[[:space:]]*$:\1:p' "$PLUGIN/.github/phpstan-packages.txt")
fi

if [[ ${#PACKAGES[@]} -eq 0 ]]; then
  echo "No required YoureCom packages."
  exit 0
fi

if [[ -z "${TOKEN:-}" ]]; then
  echo "::warning::YOURECOM_DEPENDENCY_TOKEN is not set, PHPStan runs without: ${PACKAGES[*]}"
  exit 0
fi

# the packages those packages require, too (clan.board.wbb -> clan.board -> clan): PHPStan needs their classes as well
QUEUE=("${PACKAGES[@]}")
DONE=" "
for ((i = 0; i < ${#QUEUE[@]}; i++)); do
  PACKAGE="${QUEUE[$i]}"
  [[ "$DONE" == *" $PACKAGE "* ]] && continue
  DONE+="$PACKAGE "
  # the repositories keep the camel case of the plugin folders, GitHub resolves the name case-insensitively
  git clone --quiet --depth 1 --branch "$BRANCH" "https://x-access-token:${TOKEN}@github.com/YoureCom/${PACKAGE}.git" "$PACKAGE" \
    || git clone --quiet --depth 1 "https://x-access-token:${TOKEN}@github.com/YoureCom/${PACKAGE}.git" "$PACKAGE" \
    || { echo "::warning::$PACKAGE could not be cloned, PHPStan runs without it"; continue; }
  echo "Cloned $PACKAGE"
  mapfile -t NESTED < <(sed -n 's:.*<requiredpackage[^>]*>\(de\.yourecom\.[^<]*\)</requiredpackage>.*:\1:p' "$PACKAGE/package.xml" 2>/dev/null)
  QUEUE+=("${NESTED[@]}")
done
