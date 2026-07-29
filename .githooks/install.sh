#!/usr/bin/env bash
# Point this repo at versioned hooks under .githooks/
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"

chmod +x \
	"$root/.githooks/commit-msg" \
	"$root/.githooks/pre-push" \
	"$root/.githooks/check-cursor-trailers.sh" \
	"$root/.githooks/install.sh"

git -C "$root" config core.hooksPath .githooks

echo "Installed git hooks (core.hooksPath=.githooks)."
echo "  commit-msg — strips Cursor co-author lines from new commits"
echo "  pre-push   — blocks push if any commit still has those lines"
