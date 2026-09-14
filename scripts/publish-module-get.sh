#!/usr/bin/env bash
# publish-module-get.sh <module> <tag>              mirror a billing module release to get.adminbolt.com
# publish-module-get.sh <module> --rollback <run-id> undo the activation recorded by that run
#
# Adapted from bolt-migrate/scripts/publish-get.sh. A billing module ships as one
# zip attached to its GitHub release; this downloads it, verifies it, uploads it
# through a staging directory, activates it with atomic link swaps, then verifies
# the result at the origin.
#
# <module> is one of: bolt-blesta, bolt-upmind, bolt-whmcs
#
# Layout on the server (served statically by nginx from public/):
#   public/downloads/<module>/<tag>/<module>-<version>.zip[.sha256]   immutable once published
#   public/downloads/<module>/latest -> <tag>
#   public/<module>.zip          -> downloads/<module>/<tag>/<module>-<version>.zip
#   public/<module>.zip.sha256   -> downloads/<module>/<tag>/<module>-<version>.zip.sha256
#   public/<module>.version      (plain text: tag/version/sha256/published)
#
# The short links point straight at the versioned files, not through "latest",
# so each public name changes with one atomic rename and never points at a
# file that does not exist.
#
# Differences from the bolt-migrate script: the asset is a zip, the version comes
# from the tag (no build number is burned into a zip), and the version file is
# not signed because no panel resolves modules from it.
#
# Requirements: gh (authenticated), rsync, curl, unzip, ssh access to the server.
# PUBLISH_GET_SSH_OPTS is split on whitespace on purpose (CI passes "-i <key> -o ...");
# do not put paths with spaces in it.
set -Eeuo pipefail

MODULE="${1:-}"
case "$MODULE" in
  bolt-blesta) REPO="AdminBolt/bolt-blesta" ;;
  bolt-upmind) REPO="AdminBolt/bolt-upmind" ;;
  bolt-whmcs)  REPO="AdminBolt/WHMCS-Plugin" ;;
  *) echo "usage: $0 <bolt-blesta|bolt-upmind|bolt-whmcs> <tag> | --rollback <run-id>" >&2; exit 1 ;;
esac
shift

SSH_DEST="${PUBLISH_GET_SSH:-root@37.27.40.58}"
SSH_OPTS="${PUBLISH_GET_SSH_OPTS:--o BatchMode=yes}"
PUB="/home/boltwebinstaller/public"
DL="$PUB/downloads/$MODULE"
DOMAIN="get.adminbolt.com"
ORIGIN_IP="${PUBLISH_GET_ORIGIN_IP:-37.27.40.58}"
URL="https://$DOMAIN"
MIN_FREE_MB=100

ACTIVATED=0
ROLLING_BACK=0
RUN_ID=""

# shellcheck disable=SC2086  # SSH_OPTS is a deliberately word-split option list.
run() { ssh $SSH_OPTS "$SSH_DEST" "$@"; }
# Everything interpolated into a remote command runs as root there.
valid_token() { [[ "$1" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ ]]; }

# Every failure goes through die: once this run has activated anything, the
# previous state is restored before exiting, whatever the failing step was.
die() {
  echo "ERROR: $*" >&2
  if [[ "$ACTIVATED" -eq 1 && "$ROLLING_BACK" -eq 0 && -n "$RUN_ID" ]]; then
    rollback "$RUN_ID" || echo "ERROR: rollback failed; restore by hand from $DL/.rollback-$RUN_ID" >&2
  fi
  exit 1
}

# Callers run this inside "||", where bash ignores errexit, so every step is
# checked explicitly. The record is only retired after every step succeeded,
# so a failed rollback can be retried with --rollback <run-id>.
rollback() {
  local rid="$1" rec prev prev_zip
  ROLLING_BACK=1
  valid_token "$rid" || { echo "ERROR: invalid run id '$rid'." >&2; return 1; }
  rec="$DL/.rollback-$rid"
  if ! run "test -f '$rec'"; then
    echo "==> Rollback: no record for run $rid (nothing was activated) - nothing to do."
    return 0
  fi
  prev="$(run "sed -n 's/^prev_latest=//p' '$rec'")" || { echo "ERROR: cannot read $rec." >&2; return 1; }
  prev_zip="$(run "sed -n 's/^prev_zip=//p' '$rec'")" || { echo "ERROR: cannot read $rec." >&2; return 1; }
  echo "==> Rollback: restoring previous state (prev_latest=${prev:-NONE}, prev_zip=${prev_zip:-NONE})"
  if [[ -z "$prev" || "$prev" == "NONE" ]]; then
    run "rm -f '$DL/latest' '$PUB/$MODULE.zip' '$PUB/$MODULE.zip.sha256' '$PUB/$MODULE.version'" \
      || { echo "ERROR: cannot remove the links of the first release." >&2; return 1; }
  else
    valid_token "$prev" || { echo "ERROR: refusing to restore malformed previous tag '$prev'." >&2; return 1; }
    run "test -d '$DL/$prev'" || { echo "ERROR: previous version directory $DL/$prev is missing; restore by hand." >&2; return 1; }
    run "ln -sfn '$prev' '$DL/.latest.rb.$rid' && mv -T '$DL/.latest.rb.$rid' '$DL/latest'" \
      || { echo "ERROR: cannot restore latest." >&2; return 1; }
    if [[ -n "$prev_zip" && "$prev_zip" != "NONE" ]] && valid_token "$prev_zip" && run "test -f '$DL/$prev/$prev_zip'"; then
      run "ln -sfn 'downloads/$MODULE/$prev/$prev_zip' '$PUB/.mz.rb.$rid' && mv -T '$PUB/.mz.rb.$rid' '$PUB/$MODULE.zip'" \
        || { echo "ERROR: cannot restore the short zip link." >&2; return 1; }
      run "ln -sfn 'downloads/$MODULE/$prev/$prev_zip.sha256' '$PUB/.mzs.rb.$rid' && mv -T '$PUB/.mzs.rb.$rid' '$PUB/$MODULE.zip.sha256'" \
        || { echo "ERROR: cannot restore the short checksum link." >&2; return 1; }
    else
      echo "    WARNING: no usable previous short link was recorded; removing the short links instead of pointing them at a missing file." >&2
      run "rm -f '$PUB/$MODULE.zip' '$PUB/$MODULE.zip.sha256'" \
        || { echo "ERROR: cannot remove the short links." >&2; return 1; }
    fi
    if run "test -f '$rec.version'"; then
      run "cp -a '$rec.version' '$PUB/.mv.rb.$rid' && chmod 0644 '$PUB/.mv.rb.$rid' && mv -T '$PUB/.mv.rb.$rid' '$PUB/$MODULE.version'" \
        || { echo "ERROR: cannot restore $MODULE.version." >&2; return 1; }
    else
      run "rm -f '$PUB/$MODULE.version'" || { echo "ERROR: cannot remove $MODULE.version." >&2; return 1; }
    fi
  fi
  run "mv -f '$rec' '$rec.done' && rm -f '$rec.version'" \
    || { echo "ERROR: state restored, but the rollback record could not be retired." >&2; return 1; }
  echo "==> Rollback complete."
}

on_abort() {
  if [[ "$ACTIVATED" -eq 1 && "$ROLLING_BACK" -eq 0 && -n "$RUN_ID" ]]; then
    rollback "$RUN_ID" || echo "ERROR: rollback failed; restore by hand from $DL/.rollback-$RUN_ID" >&2
  fi
}

if [[ "${1:-}" == "--rollback" ]]; then
  [[ -n "${2:-}" ]] || die "usage: $0 $MODULE --rollback <run-id>"
  rollback "$2"
  exit $?
fi

TAG="${1:-}"
[[ -n "$TAG" ]] || die "usage: $0 $MODULE <release-tag>   (e.g. $0 $MODULE v1.0.0)"
[[ "$TAG" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "refusing tag '$TAG': expected vMAJOR.MINOR.PATCH."
VERSION="${TAG#v}"
ASSET="$MODULE-$VERSION.zip"
valid_token "$ASSET" || die "invalid asset name '$ASSET'."

RUN_ID="${PUBLISH_GET_RUN_ID:-manual-$(date -u +%Y%m%dT%H%M%SZ)}"
valid_token "$RUN_ID" || die "invalid run id '$RUN_ID'."
REC="$DL/.rollback-$RUN_ID"

trap on_abort ERR
trap 'on_abort; exit 130' INT TERM

echo "==> Checking release $TAG in $REPO"
META="$(gh release view "$TAG" -R "$REPO" --json isDraft,isPrerelease,tagName --jq '[.isDraft,.isPrerelease,.tagName] | @tsv')" \
  || die "cannot read release $TAG in $REPO."
IFS=$'\t' read -r IS_DRAFT IS_PRE REL_TAG <<<"$META"
if [[ "$IS_DRAFT" == "true" || "$IS_PRE" == "true" ]]; then
  die "$TAG is a draft or prerelease - refusing to publish."
fi
[[ "$REL_TAG" == "$TAG" ]] || die "resolved tag '$REL_TAG' does not match requested '$TAG'."

asset_fingerprint() {
  gh release view "$TAG" -R "$REPO" --json assets \
    --jq "[.assets[] | select(.name==\"$ASSET\") | .size, .updatedAt] | @tsv"
}
FP_BEFORE="$(asset_fingerprint)" || die "cannot read the assets of $TAG."
[[ -n "$FP_BEFORE" ]] || die "release $TAG has no asset named $ASSET."

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
echo "==> Downloading asset $ASSET"
gh release download "$TAG" -R "$REPO" -p "$ASSET" -D "$TMP" || die "download of $ASSET failed."
[[ -f "$TMP/$ASSET" ]] || die "asset $ASSET missing in release $TAG."

# A zip that does not open, or that carries repository metadata, never ships.
# The listing is captured once so no early-exiting grep can mask a match.
unzip -tq "$TMP/$ASSET" >/dev/null || die "$ASSET is not a valid zip."
LISTING="$(unzip -Z1 "$TMP/$ASSET")" || die "cannot list $ASSET."
if grep -E '(^|/)(\.git|\.github|\.DS_Store|node_modules)(/|$)' <<<"$LISTING" >/dev/null; then
  die "$ASSET contains repository or OS metadata - fix the packaging, do not publish it."
fi
TOPS="$(cut -d/ -f1 <<<"$LISTING" | sort -u)"
[[ "$TOPS" == "$MODULE" ]] || die "$ASSET must hold exactly one top-level folder named $MODULE (found: $(tr '\n' ' ' <<<"$TOPS"))."

SHA="$(sha256sum "$TMP/$ASSET" | awk '{print $1}')"
SIZE="$(wc -c <"$TMP/$ASSET" | tr -d ' ')"
printf '%s  %s\n' "$SHA" "$ASSET" >"$TMP/$ASSET.sha256"
echo "    sha256=$SHA size=$SIZE"

echo "==> Preflight on server"
run "mkdir -p '$DL' && chmod 0755 '$DL'" || die "cannot prepare $DL on the server."
FREE_MB="$(run "df -Pm '$PUB' | awk 'NR==2{print \$4}'")" || die "cannot read free space on the server."
[[ "$FREE_MB" =~ ^[0-9]+$ && "$FREE_MB" -ge "$MIN_FREE_MB" ]] || die "only ${FREE_MB}MB free on server (need ${MIN_FREE_MB}MB)."

SKIP_UPLOAD=0
if run "test -f '$DL/$TAG/$ASSET'"; then
  REMOTE_SHA="$(run "sha256sum '$DL/$TAG/$ASSET' | awk '{print \$1}'")" || die "cannot hash the published $ASSET."
  if [[ "$REMOTE_SHA" == "$SHA" ]]; then
    echo "    $TAG already published with identical content - will only re-point links."
    SKIP_UPLOAD=1
  else
    die "$DL/$TAG exists with DIFFERENT content (sha256 $REMOTE_SHA). Refusing to overwrite a published version; cut a new tag instead."
  fi
fi

if [[ "$SKIP_UPLOAD" -eq 0 ]]; then
  echo "==> Uploading to staging"
  STAGING="$DL/.staging-$TAG"
  run "rm -rf '$STAGING' && mkdir -p '$STAGING'" || die "cannot create staging directory."
  # shellcheck disable=SC2086
  rsync -az -e "ssh $SSH_OPTS" "$TMP/$ASSET" "$TMP/$ASSET.sha256" "$SSH_DEST:$STAGING/" || die "upload failed."
  run "cd '$STAGING' && sha256sum -c '$ASSET.sha256' >/dev/null" || die "checksum mismatch after upload."
  REMOTE_SIZE="$(run "stat -c%s '$STAGING/$ASSET'")" || die "cannot stat the uploaded file."
  [[ "$REMOTE_SIZE" == "$SIZE" ]] || die "size mismatch after upload."
  run "chmod 0755 '$STAGING' && chmod 0644 '$STAGING/$ASSET' '$STAGING/$ASSET.sha256'" || die "cannot set permissions on staging."
  echo "==> Moving $TAG into place"
  run "mv -T '$STAGING' '$DL/$TAG'" || die "cannot move staging into $DL/$TAG."
fi

[[ "$(asset_fingerprint)" == "$FP_BEFORE" ]] \
  || die "the release asset changed while this run was in flight - refusing to activate."

echo "==> Recording rollback point for run $RUN_ID"
PREV_TAG="$(run "readlink '$DL/latest' 2>/dev/null || echo NONE")" || die "cannot read the current latest link."
PREV_ZIP="NONE"
if [[ "$PREV_TAG" != "NONE" ]]; then
  valid_token "$PREV_TAG" || die "current latest link points at a malformed tag '$PREV_TAG'; fix it by hand first."
  CUR_TARGET="$(run "readlink '$PUB/$MODULE.zip' 2>/dev/null || true")" || die "cannot read the current short link."
  CANDIDATE="${CUR_TARGET##*/}"
  if [[ -n "$CANDIDATE" ]] && valid_token "$CANDIDATE" && run "test -f '$DL/$PREV_TAG/$CANDIDATE'"; then
    PREV_ZIP="$CANDIDATE"
  fi
fi
run "printf 'prev_latest=%s\nprev_zip=%s\n' '$PREV_TAG' '$PREV_ZIP' > '$REC' && chmod 0600 '$REC'" || die "cannot write the rollback record."
run "if [ -f '$PUB/$MODULE.version' ]; then cp -a '$PUB/$MODULE.version' '$REC.version'; fi" || die "cannot save the current version file."

echo "==> Activating $TAG"
ACTIVATED=1
run "ln -s '$TAG' '$DL/.latest.$RUN_ID' && mv -T '$DL/.latest.$RUN_ID' '$DL/latest'" || die "cannot switch latest."
run "ln -s 'downloads/$MODULE/$TAG/$ASSET' '$PUB/.mz.$RUN_ID' && mv -T '$PUB/.mz.$RUN_ID' '$PUB/$MODULE.zip'" || die "cannot switch the short zip link."
run "ln -s 'downloads/$MODULE/$TAG/$ASSET.sha256' '$PUB/.mzs.$RUN_ID' && mv -T '$PUB/.mzs.$RUN_ID' '$PUB/$MODULE.zip.sha256'" || die "cannot switch the short checksum link."
{
  echo "tag=$TAG"
  echo "version=$VERSION"
  echo "sha256=$SHA"
  echo "published=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
} >"$TMP/version.txt"
# shellcheck disable=SC2086
rsync -az -e "ssh $SSH_OPTS" "$TMP/version.txt" "$SSH_DEST:$PUB/.mv.$RUN_ID" || die "cannot upload the version file."
run "chmod 0644 '$PUB/.mv.$RUN_ID' && mv -T '$PUB/.mv.$RUN_ID' '$PUB/$MODULE.version'" || die "cannot switch the version file."

echo "==> Verifying origin (bypassing Cloudflare)"
o_curl() { curl -fsS --resolve "$DOMAIN:443:$ORIGIN_IP" "$@"; }
for path in "downloads/$MODULE/$TAG/$ASSET" "$MODULE.zip"; do
  o_curl "$URL/$path" -o "$TMP/check.zip" || die "origin does not serve $path."
  [[ "$(sha256sum "$TMP/check.zip" | awk '{print $1}')" == "$SHA" ]] || die "origin $path sha256 mismatch."
done
SUMS="$(o_curl "$URL/$MODULE.zip.sha256")" || die "origin does not serve $MODULE.zip.sha256."
grep -q "^$SHA " <<<"$SUMS" || die "origin .sha256 mismatch."
VER="$(o_curl "$URL/$MODULE.version")" || die "origin does not serve $MODULE.version."
grep -q "^tag=$TAG$" <<<"$VER" || die "origin .version mismatch."

echo "==> Checking the public edge (advisory)"
EDGE="$(curl -fsS "$URL/$MODULE.version" 2>/dev/null || true)"
if grep -q "^tag=$TAG$" <<<"$EDGE"; then
  echo "    edge is in sync"
else
  echo "    WARNING: the edge does not serve $TAG yet - most likely Cloudflare caching; the origin is correct"
fi

trap - ERR INT TERM
run "mv -f '$REC' '$REC.done' 2>/dev/null; rm -f '$REC.version'" || true
echo "==> Published $MODULE $TAG: $URL/downloads/$MODULE/$TAG/$ASSET"
