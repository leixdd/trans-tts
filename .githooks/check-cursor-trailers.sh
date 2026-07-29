#!/usr/bin/env bash
# Shared helpers: detect Cursor agent attribution in commit messages.
# Usage:
#   source .githooks/check-cursor-trailers.sh
#   commit_has_cursor_trailer <sha>
#   range_has_cursor_trailer <git-rev-list-args...>
#   Or run directly:
#   .githooks/check-cursor-trailers.sh [git rev-list args...]

CURSOR_TRAILER_PATTERN='cursoragent@cursor\.com|^[[:space:]]*Co-authored-by:[[:space:]]*Cursor\b'

commit_has_cursor_trailer() {
	local commit="${1:-}"
	[[ -n "$commit" ]] || return 1
	git log -1 --format=%B "$commit" | grep -qiE "$CURSOR_TRAILER_PATTERN"
}

range_has_cursor_trailer() {
	local commit
	local found=0

	while IFS= read -r commit; do
		[[ -n "$commit" ]] || continue
		if commit_has_cursor_trailer "$commit"; then
			echo "error: commit ${commit:0:12} contains Cursor co-author attribution:" >&2
			git log -1 --format='  %s%n%b' "$commit" | grep -iE "$CURSOR_TRAILER_PATTERN" >&2 || true
			found=1
		fi
	done < <(git rev-list "$@")

	[[ "$found" -eq 0 ]]
}

# When executed (not sourced), scan a revision range (default: HEAD).
if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
	set -euo pipefail
	if [[ "$#" -eq 0 ]]; then
		set -- -n 1 HEAD
	fi
	if ! range_has_cursor_trailer "$@"; then
		echo "Remove Cursor co-author trailers (commit-msg hook should strip them locally)." >&2
		exit 1
	fi
fi
