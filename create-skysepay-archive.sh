#!/usr/bin/env bash
set -euo pipefail

archive="SkySePay.zip"
if [[ -e "$archive" ]]; then
  echo "$archive already exists; move it before rebuilding." >&2
  exit 1
fi
mkdir -p .build
trap 'rm -rf .build' EXIT
mkdir -p .build/SkySePay
cp -R extensions/Gateways/SkySePay/. .build/SkySePay/
(cd .build && zip -qr "../$archive" SkySePay)
echo "Created $archive"
