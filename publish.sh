#!/usr/bin/env bash
# One-shot publication, in the order the dependencies require.
#
# npm goes FIRST: the n8n community node declares @walayer/sdk as a registry
# dependency, so nothing downstream resolves until it exists. Go and Packagist
# are just tags — the registries pull from this repository, which is the whole
# reason it is public.
#
# Prereqs, checked below rather than assumed:
#   git push done, CI green      (PHP is only tested in CI — no local runtime)
#   npm login                    (npm whoami works)
#   PyPI: ~/.pypirc or TWINE_* env, and `pip install build twine`
set -euo pipefail
cd "$(dirname "$0")"

step() { printf '\n\033[1m== %s ==\033[0m\n' "$*"; }

step "preflight"
git diff --quiet || { echo "uncommitted changes — commit or stash first"; exit 1; }
[ "$(git rev-parse --abbrev-ref HEAD)" = "main" ] || { echo "not on main"; exit 1; }
git fetch origin main --quiet
[ "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)" ] || {
  echo "local main != origin/main — push first and let CI go green"; exit 1; }
npm whoami >/dev/null 2>&1 || { echo "npm not logged in — run: npm login"; exit 1; }

step "1/4 npm: @walayer/sdk"
( cd node
  npm install --no-audit --no-fund
  npm test
  npm publish   # prepublishOnly builds dist/
)

step "2/4 PyPI: walayer"
( cd python
  python3 -m unittest discover -s tests -q
  rm -rf dist build ./*.egg-info
  python3 -m build
  python3 -m twine upload dist/*
)

step "3/4 Go + PHP tags"
git tag -f go/v0.1.0
git tag -f php/v0.1.0
git push origin go/v0.1.0 php/v0.1.0

step "4/4 what is left is manual"
cat <<'EOF'
  - Packagist: submit https://github.com/tsvint/walayer-sdks once at
    https://packagist.org/packages/submit — it tracks php/v* tags from then on.
  - Go needs nothing more: the proxy resolves the module from the tag on
    first `go get github.com/tsvint/walayer-sdks/go`.
  - n8n node (separate repo/package): publish it AFTER @walayer/sdk is live,
    then submit to the n8n community registry.

Done. Verify:
  npm view @walayer/sdk version
  pip index versions walayer     (or check pypi.org/project/walayer)
EOF
