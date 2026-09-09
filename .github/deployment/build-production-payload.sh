#!/usr/bin/env bash
set -Eeuo pipefail

MODE="${1:?mode required: fingerprint or build}"
shift

hash_tree_manifest() {
  local root="$1"
  local manifest="$2"
  shift 2

  : > "$manifest"
  (
    cd "$root"
    for path in "$@"; do
      if [[ -f "$path" ]]; then
        hash="$(sha256sum -- "$path" | awk '{print $1}')"
        size="$(stat -c '%s' -- "$path")"
        printf '%s\t%s\t%s\n' "$hash" "$size" "$path"
      elif [[ -d "$path" ]]; then
        find "$path" -type f -print0 \
          | LC_ALL=C sort -z \
          | while IFS= read -r -d '' file; do
              hash="$(sha256sum -- "$file" | awk '{print $1}')"
              size="$(stat -c '%s' -- "$file")"
              printf '%s\t%s\t%s\n' "$hash" "$size" "$file"
            done
      fi
    done
  ) | LC_ALL=C sort -t $'\t' -k3,3 > "$manifest"
}

manifest_to_sha256sums() {
  local manifest="$1"
  local output="$2"
  awk -F '\t' '{printf "%s  %s\n", $1, $3}' "$manifest" > "$output"
}

fingerprint_vendor_inputs() {
  local source_root="${1:?source root required}"
  local proof_dir="${2:?proof directory required}"

  source_root="$(cd "$source_root" && pwd -P)"
  mkdir -p "$proof_dir"
  rm -f "$proof_dir/vendor-input-manifest.tsv" "$proof_dir/vendor-input.sha256" "$proof_dir/vendor-input-summary.txt"
  proof_dir="$(cd "$proof_dir" && pwd -P)"

  [[ -f "$source_root/composer.json" ]] || { echo "composer.json missing" >&2; exit 1; }
  [[ -f "$source_root/composer.lock" ]] || { echo "composer.lock missing" >&2; exit 1; }
  command -v python3 >/dev/null 2>&1 || { echo "python3 required for Composer autoload-path inspection" >&2; exit 1; }

  # This fingerprint binds a FULL Composer rebuild to the exact root-package
  # Composer metadata and declared autoload source tree.  Routine rebuild
  # necessity is decided earlier from the certified Git delta: composer.json,
  # composer.lock, or a change to the optimized PSR-4 class inventory/path map.
  # Ordinary PHP method/body edits with unchanged class inventory do not reach
  # this full-build fingerprint path.
  mapfile -t autoload_paths < <(python3 - "$source_root/composer.json" <<'PYJSON'
import json, sys
from pathlib import Path
p = Path(sys.argv[1])
data = json.loads(p.read_text(encoding='utf-8'))
paths = []
autoload = data.get('autoload') or {}
for key in ('psr-4', 'psr-0'):
    mapping = autoload.get(key) or {}
    if isinstance(mapping, dict):
        for value in mapping.values():
            values = value if isinstance(value, list) else [value]
            paths.extend(v for v in values if isinstance(v, str) and v)
for key in ('classmap', 'files'):
    value = autoload.get(key) or []
    values = value if isinstance(value, list) else [value]
    paths.extend(v for v in values if isinstance(v, str) and v)
for item in sorted(set(x.rstrip('/') or '.' for x in paths)):
    print(item)
PYJSON
  )

  local manifest="$proof_dir/vendor-input-manifest.tsv"
  hash_tree_manifest "$source_root" "$manifest" composer.json composer.lock "${autoload_paths[@]}"
  printf '%s\n' 'deployment_vendor_contract=v1:composer-v2:no-dev:prefer-dist:optimize-autoloader' \
    >> "$manifest"
  sha256sum "$manifest" | awk '{print $1}' > "$proof_dir/vendor-input.sha256"

  printf 'vendor_input_sha256=%s\n' "$(cat "$proof_dir/vendor-input.sha256")" > "$proof_dir/vendor-input-summary.txt"
  printf 'Vendor input fingerprint: %s\n' "$(cat "$proof_dir/vendor-input.sha256")"
}

build_payload() {
  local source_root="${1:?source root required}"
  local payload_dir="${2:?payload directory required}"
  local proof_dir="${3:?proof directory required}"
  local vendor_mode="${4:?vendor mode required: full or app-only}"
  local certified_vendor_manifest_sha="${5:-}"
  local certified_vendor_count="${6:-0}"
  local certified_vendor_bytes="${7:-0}"

  source_root="$(cd "$source_root" && pwd -P)"
  rm -rf "$payload_dir"
  mkdir -p "$payload_dir" "$proof_dir"
  payload_dir="$(cd "$payload_dir" && pwd -P)"
  proof_dir="$(cd "$proof_dir" && pwd -P)"

  [[ -f "$source_root/composer.lock" ]] || { echo "composer.lock missing" >&2; exit 1; }
  if [[ "$vendor_mode" == "full" ]]; then
    [[ -f "$source_root/vendor/autoload.php" ]] || { echo "vendor/autoload.php missing after Composer install" >&2; exit 1; }
  elif [[ "$vendor_mode" == "app-only" ]]; then
    [[ -n "$certified_vendor_manifest_sha" ]] || { echo "certified vendor manifest SHA required for app-only mode" >&2; exit 1; }
  else
    echo "Unknown vendor mode: $vendor_mode" >&2
    exit 2
  fi

  rsync_args=(
    -a "$source_root/" "$payload_dir/"
    --exclude='.deploy/'
    --exclude='.git/'
    --exclude='.github/'
    --exclude='.env'
    --exclude='.env.*'
    --exclude='.gitignore'
    --exclude='README.md'
    --exclude='docs/'
    --exclude='tests/'
    --exclude='branding/'
    --exclude='CHANGED_FILES.txt'
    --exclude='DELETE_THESE_FILES.txt'
    --exclude='PHASE1B_ROOT_APPLY.txt'
  )
  if [[ "$vendor_mode" == "app-only" ]]; then
    rsync_args+=(--exclude='vendor/')
  fi
  rsync "${rsync_args[@]}"

  if find "$payload_dir" -maxdepth 1 -type f \( -name '.env' -o -name '.env.*' \) -print -quit | grep -q .; then
    echo "Forbidden .env material entered production payload" >&2
    exit 1
  fi

  for required in '.htaccess' 'assets/.htaccess' 'database/.htaccess'; do
    [[ -f "$payload_dir/$required" ]] || {
      echo "Required governed security file missing from payload: $required" >&2
      exit 1
    }
  done

  local app_manifest="$proof_dir/application-manifest.tsv"
  (
    cd "$payload_dir"
    find . -type f ! -path './vendor/*' -print0 \
      | LC_ALL=C sort -z \
      | while IFS= read -r -d '' path; do
          hash="$(sha256sum -- "$path" | awk '{print $1}')"
          size="$(stat -c '%s' -- "$path")"
          printf '%s\t%s\t%s\n' "$hash" "$size" "${path#./}"
        done
  ) > "$app_manifest"
  manifest_to_sha256sums "$app_manifest" "$proof_dir/application-sha256sums.txt"
  sha256sum "$app_manifest" | awk '{print $1}' > "$proof_dir/application-manifest.sha256"

  local app_count app_bytes vendor_count vendor_bytes vendor_manifest_sha
  app_count="$(awk -F '\t' '{n += 1} END {print n + 0}' "$app_manifest")"
  app_bytes="$(awk -F '\t' '{b += $2} END {printf "%.0f", b + 0}' "$app_manifest")"

  if [[ "$vendor_mode" == "full" ]]; then
    [[ -d "$payload_dir/vendor" ]] || { echo "vendor/ missing from full payload" >&2; exit 1; }
    local vendor_manifest="$proof_dir/vendor-manifest.tsv"
    (
      cd "$payload_dir"
      find vendor -type f -print0 \
        | LC_ALL=C sort -z \
        | while IFS= read -r -d '' path; do
            hash="$(sha256sum -- "$path" | awk '{print $1}')"
            size="$(stat -c '%s' -- "$path")"
            printf '%s\t%s\t%s\n' "$hash" "$size" "$path"
          done
    ) > "$vendor_manifest"
    manifest_to_sha256sums "$vendor_manifest" "$proof_dir/vendor-sha256sums.txt"
    sha256sum "$vendor_manifest" | awk '{print $1}' > "$proof_dir/vendor-manifest.sha256"
    vendor_manifest_sha="$(cat "$proof_dir/vendor-manifest.sha256")"
    vendor_count="$(awk -F '\t' '{n += 1} END {print n + 0}' "$vendor_manifest")"
    vendor_bytes="$(awk -F '\t' '{b += $2} END {printf "%.0f", b + 0}' "$vendor_manifest")"
  else
    vendor_manifest_sha="$certified_vendor_manifest_sha"
    vendor_count="$certified_vendor_count"
    vendor_bytes="$certified_vendor_bytes"
    printf '%s\n' "$vendor_manifest_sha" > "$proof_dir/vendor-manifest.sha256"
  fi

  local app_manifest_sha payload_identity
  app_manifest_sha="$(cat "$proof_dir/application-manifest.sha256")"
  payload_identity="$(printf 'fitcrew-payload-v2\napplication_manifest_sha256=%s\nvendor_manifest_sha256=%s\n' \
    "$app_manifest_sha" "$vendor_manifest_sha" | sha256sum | awk '{print $1}')"
  printf '%s\n' "$payload_identity" > "$proof_dir/payload-identity.sha256"

  local payload_count payload_bytes
  payload_count=$((app_count + vendor_count))
  payload_bytes=$((app_bytes + vendor_bytes))

  cat > "$proof_dir/payload-summary.txt" <<SUMMARY
payload_file_count=$payload_count
payload_bytes=$payload_bytes
application_file_count=$app_count
application_bytes=$app_bytes
vendor_file_count=$vendor_count
vendor_bytes=$vendor_bytes
vendor_mode=$vendor_mode
application_manifest_sha256=$app_manifest_sha
vendor_manifest_sha256=$vendor_manifest_sha
payload_identity_sha256=$payload_identity
root_htaccess=present
assets_htaccess=present
database_htaccess=present
env_payload=absent
SUMMARY

  printf 'Canonical production payload identity built: %s files / %s / vendor mode %s\n' \
    "$payload_count" "$payload_identity" "$vendor_mode"
}

case "$MODE" in
  fingerprint)
    fingerprint_vendor_inputs "$@"
    ;;
  build)
    build_payload "$@"
    ;;
  *)
    echo "Unknown mode: $MODE" >&2
    exit 2
    ;;
esac
