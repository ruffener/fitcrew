#!/usr/bin/env bash
set -Eeuo pipefail

SOURCE_ROOT="${1:?source root required}"
PAYLOAD_DIR="${2:?payload directory required}"
PROOF_DIR="${3:?proof directory required}"

SOURCE_ROOT="$(cd "$SOURCE_ROOT" && pwd -P)"
rm -rf "$PAYLOAD_DIR" "$PROOF_DIR"
mkdir -p "$PAYLOAD_DIR" "$PROOF_DIR"
PAYLOAD_DIR="$(cd "$PAYLOAD_DIR" && pwd -P)"
PROOF_DIR="$(cd "$PROOF_DIR" && pwd -P)"

[[ -f "$SOURCE_ROOT/composer.lock" ]] || { echo "composer.lock missing" >&2; exit 1; }
[[ -f "$SOURCE_ROOT/vendor/autoload.php" ]] || { echo "vendor/autoload.php missing after Composer install" >&2; exit 1; }

rsync -a "$SOURCE_ROOT/" "$PAYLOAD_DIR/" \
  --exclude='.deploy/' \
  --exclude='.git/' \
  --exclude='.github/' \
  --exclude='.env' \
  --exclude='.env.*' \
  --exclude='.gitignore' \
  --exclude='README.md' \
  --exclude='docs/' \
  --exclude='tests/' \
  --exclude='branding/' \
  --exclude='CHANGED_FILES.txt' \
  --exclude='DELETE_THESE_FILES.txt' \
  --exclude='PHASE1B_ROOT_APPLY.txt'

# Absolute configuration boundary: .env material must never enter the payload.
if find "$PAYLOAD_DIR" -maxdepth 1 -type f \( -name '.env' -o -name '.env.*' \) -print -quit | grep -q .; then
  echo "Forbidden .env material entered production payload" >&2
  exit 1
fi

for required in '.htaccess' 'assets/.htaccess' 'database/.htaccess'; do
  [[ -f "$PAYLOAD_DIR/$required" ]] || {
    echo "Required governed security file missing from payload: $required" >&2
    exit 1
  }
done

MANIFEST="$PROOF_DIR/payload-manifest.tsv"
(
  cd "$PAYLOAD_DIR"
  find . -type f -print0 \
    | LC_ALL=C sort -z \
    | while IFS= read -r -d '' path; do
        hash="$(sha256sum -- "$path" | awk '{print $1}')"
        size="$(stat -c '%s' -- "$path")"
        printf '%s\t%s\t%s\n' "$hash" "$size" "${path#./}"
      done
) > "$MANIFEST"

sha256sum "$MANIFEST" | awk '{print $1}' > "$PROOF_DIR/payload-manifest.sha256"
find "$PAYLOAD_DIR" -type f -printf '%s\n' | awk '{n += 1; b += $1} END {printf "%d\t%d\n", n, b}' > "$PROOF_DIR/payload-totals.tsv"
find "$PAYLOAD_DIR/vendor" -type f -printf '%s\n' 2>/dev/null | awk '{n += 1; b += $1} END {printf "%d\t%d\n", n, b}' > "$PROOF_DIR/vendor-totals.tsv"

payload_count="$(awk -F '\t' '{print $1}' "$PROOF_DIR/payload-totals.tsv")"
payload_bytes="$(awk -F '\t' '{print $2}' "$PROOF_DIR/payload-totals.tsv")"
vendor_count="$(awk -F '\t' '{print $1}' "$PROOF_DIR/vendor-totals.tsv")"
vendor_bytes="$(awk -F '\t' '{print $2}' "$PROOF_DIR/vendor-totals.tsv")"
manifest_sha="$(cat "$PROOF_DIR/payload-manifest.sha256")"

cat > "$PROOF_DIR/payload-summary.txt" <<SUMMARY
payload_file_count=$payload_count
payload_bytes=$payload_bytes
vendor_file_count=$vendor_count
vendor_bytes=$vendor_bytes
payload_manifest_sha256=$manifest_sha
root_htaccess=present
assets_htaccess=present
database_htaccess=present
env_payload=absent
SUMMARY

printf 'Canonical production payload built: %s files / manifest %s\n' "$payload_count" "$manifest_sha"
