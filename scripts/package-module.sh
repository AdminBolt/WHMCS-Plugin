#!/usr/bin/env bash
# package-module.sh <module> <version> <repo-dir> <out-dir>
#
# Builds <out-dir>/<module>-<version>.zip from the committed state of a billing
# module repository. The zip holds exactly one top-level folder named <module>,
# with only the files a customer installs plus the README (and CHANGELOG /
# LICENSE where the repository has them). Nothing uncommitted, no git or CI
# metadata, no .DS_Store. A required payload that is missing fails the build.
#
# Reproducible for the same commit and the same Python/zlib toolchain (fixed
# timestamps, sorted entries).
#
# <module> is one of: bolt-blesta, bolt-upmind, bolt-whmcs
set -Eeuo pipefail

MODULE="${1:-}"; VERSION="${2:-}"; REPO_DIR="${3:-}"; OUT_DIR="${4:-}"
[[ -n "$MODULE" && -n "$VERSION" && -n "$REPO_DIR" && -n "$OUT_DIR" ]] \
  || { echo "usage: $0 <bolt-blesta|bolt-upmind|bolt-whmcs> <version> <repo-dir> <out-dir>" >&2; exit 1; }
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "version must be MAJOR.MINOR.PATCH" >&2; exit 1; }

# REQUIRED entries must exist in the commit; OPTIONAL ones are packed when present.
case "$MODULE" in
  bolt-blesta) REQUIRED=(README.md adminbolt/adminbolt.php adminbolt/config.json); INCLUDE=(adminbolt); OPTIONAL=(LICENSE) ;;
  bolt-upmind) REQUIRED=(README.md composer.json src/Provider.php); INCLUDE=(src); OPTIONAL=(LICENSE) ;;
  bolt-whmcs)  REQUIRED=(README.md CHANGELOG.md modules/servers/AdminBolt/AdminBolt.php); INCLUDE=(images modules); OPTIONAL=(LICENSE) ;;
  *) echo "unknown module '$MODULE'" >&2; exit 1 ;;
esac

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# Only committed content, so a dirty working tree cannot leak into a release.
git -C "$REPO_DIR" archive --format=tar HEAD | tar -x -C "$TMP"

for f in "${REQUIRED[@]}"; do
  [[ -e "$TMP/$f" ]] || { echo "required file $f is missing from the commit - refusing to package" >&2; exit 1; }
done

# The version inside the module must agree with the one being released.
case "$MODULE" in
  bolt-blesta)
    IN_REPO="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["version"])' "$TMP/adminbolt/config.json")"
    [[ "$IN_REPO" == "$VERSION" ]] || { echo "adminbolt/config.json says $IN_REPO, releasing $VERSION - bump it first" >&2; exit 1; }
    ;;
  bolt-whmcs)
    CHANGELOG="$(cat "$TMP/CHANGELOG.md")"
    if ! grep -F "## [$VERSION]" <<<"$CHANGELOG" >/dev/null; then
      echo "CHANGELOG.md has no [$VERSION] section - cut the release in the changelog first" >&2; exit 1
    fi
    ;;
esac

mkdir -p "$OUT_DIR"
OUT="$(cd "$OUT_DIR" && pwd)/$MODULE-$VERSION.zip"

python3 - "$TMP" "$OUT" "$MODULE" "${REQUIRED[@]}" --include "${INCLUDE[@]}" --optional "${OPTIONAL[@]}" <<'PY'
import os, sys, zipfile
args = sys.argv[1:]
src, out, module = args[:3]
rest = args[3:]
i_inc = rest.index('--include'); i_opt = rest.index('--optional')
required, include, optional = rest[:i_inc], rest[i_inc + 1:i_opt], rest[i_opt + 1:]
# Module code ships exactly as committed; only repository and editor metadata is left out.
skip_dirs = {'.git', '.github', '.idea', '.vscode'}
skip_files = {'.DS_Store', '.gitignore', '.gitattributes'}
entries = set()
def add_path(item):
    path = os.path.join(src, item)
    if os.path.isfile(path):
        if os.path.basename(item) not in skip_files:
            entries.add(item)
        return
    for root, dirs, files in os.walk(path):
        dirs[:] = sorted(d for d in dirs if d not in skip_dirs)
        for f in sorted(files):
            if f not in skip_files:
                entries.add(os.path.relpath(os.path.join(root, f), src))
for item in required + include:
    if not os.path.exists(os.path.join(src, item)):
        sys.exit(f'{item} is missing from the commit')
    add_path(item)
for item in optional:
    if os.path.exists(os.path.join(src, item)):
        add_path(item)
# Fixed timestamps and order keep the zip identical for the same commit and toolchain.
with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
    for rel in sorted(entries):
        info = zipfile.ZipInfo(f'{module}/{rel}', date_time=(2020, 1, 1, 0, 0, 0))
        info.external_attr = 0o644 << 16
        info.compress_type = zipfile.ZIP_DEFLATED
        with open(os.path.join(src, rel), 'rb') as fh:
            z.writestr(info, fh.read())
print(f'{out}: {len(entries)} files')
PY

SHA="$(sha256sum "$OUT" | awk '{print $1}')"
printf '%s  %s\n' "$SHA" "$(basename "$OUT")" > "$OUT.sha256"
echo "sha256: $SHA"
