#!/usr/bin/env bash
# Build the release zip from the repository sources.
#   ./build.sh            → FlexPay_WHMCS_Suite_v<version>.zip
set -euo pipefail
cd "$(dirname "$0")"

version=$(sed -n 's/^ \* @version *\([0-9.]*\).*/\1/p' modules/gateways/flexpay.php | head -1)
out="FlexPay_WHMCS_Suite_v${version}.zip"

for f in $(find modules -name '*.php'); do php -l "$f" >/dev/null; done

stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
cp -R modules sql README.md "$stage/"
cp packaging/CHANGELOG.md "$stage/CHANGELOG.md"
mkdir -p "$stage/modules/addons/flexpay_dashboard/templates"

rm -f "$out"
(cd "$stage" && zip -qrX "$OLDPWD/$out" modules sql README.md CHANGELOG.md)
echo "Built $out"
