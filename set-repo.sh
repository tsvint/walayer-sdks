#!/usr/bin/env bash
# Point every package at the GitHub repository that actually hosts it.
#
# The Go module path MUST equal the repository URL — that is how `go get`
# resolves it, and it is why no vanity host is needed. Run this only if the
# repo moves to a different owner or name, then re-tag.
#
#   ./set-repo.sh tsvint                 # -> github.com/tsvint/walayer-sdks
#   ./set-repo.sh some-org my-repo-name
set -euo pipefail

OWNER="${1:?usage: ./set-repo.sh <github-owner> [repo-name]}"
REPO_NAME="${2:-walayer-sdks}"
NEW="github.com/${OWNER}/${REPO_NAME}"
OLD=$(sed -n '1s|^module \(.*\)/go$|\1|p' go/go.mod)

if [ -z "$OLD" ]; then echo "could not read the module path from go/go.mod" >&2; exit 1; fi
if [ "$OLD" = "$NEW" ]; then echo "already set to $NEW"; exit 0; fi

# -print0/-0 so a path containing a space cannot split into two arguments.
find . -type f \( -name '*.go' -o -name '*.md' -o -name '*.mod' -o -name '*.json' -o -name '*.toml' \) \
  -not -path './.git/*' -not -path '*/node_modules/*' -not -path '*/vendor/*' -print0 \
  | xargs -0 sed -i '' "s|${OLD}|${NEW}|g"

echo "repository set to ${NEW}"
echo "verify with: (cd go && go build ./... && go test ./...)"
