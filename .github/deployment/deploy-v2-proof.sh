#!/usr/bin/env bash
set -Eeuo pipefail

MODE="${1:?mode required}"
PAYLOAD_DIR="${2:-}"
PROOF_DIR="${3:-}"

required_env=(SSH_HOST SSH_USER SSH_PASS SSH_PORT PROD_PATH SSH_KNOWN_HOSTS)
for name in "${required_env[@]}"; do
  [[ -n "${!name:-}" ]] || { echo "Required GitHub secret-backed environment value is missing: $name" >&2; exit 1; }
done

for tool in ssh ssh-keygen sshpass rsync; do
  command -v "$tool" >/dev/null 2>&1 || { echo "Runner tool missing: $tool" >&2; exit 1; }
done

RUNNER_TEMP="${RUNNER_TEMP:-/tmp}"
KNOWN_HOSTS_FILE="$RUNNER_TEMP/fitcrew-known-hosts"
printf '%s\n' "$SSH_KNOWN_HOSTS" > "$KNOWN_HOSTS_FILE"
chmod 600 "$KNOWN_HOSTS_FILE"

if [[ "$SSH_PORT" == "22" ]]; then
  host_lookup="$SSH_HOST"
else
  host_lookup="[$SSH_HOST]:$SSH_PORT"
fi

ssh-keygen -F "$host_lookup" -f "$KNOWN_HOSTS_FILE" >/dev/null 2>&1 || {
  echo "Pinned known_hosts material does not contain the configured SSH target." >&2
  exit 1
}

export SSHPASS="$SSH_PASS"
SSH_BASE=(
  sshpass -e ssh
  -p "$SSH_PORT"
  -o StrictHostKeyChecking=yes
  -o UserKnownHostsFile="$KNOWN_HOSTS_FILE"
  -o LogLevel=ERROR
  -o PreferredAuthentications=password,keyboard-interactive
  -o PubkeyAuthentication=no
  -o ConnectTimeout=20
)
REMOTE="$SSH_USER@$SSH_HOST"
remote_path="${PROD_PATH%/}"
printf -v remote_path_q '%q' "$remote_path"
CERT_DIR='.fitcrew-deployment/fitcrewchallenge.com'

probe() {
  local remote_user probe_output normalized rsync_version

  remote_user="$("${SSH_BASE[@]}" "$REMOTE" 'id -un')"
  [[ "$remote_user" == "$SSH_USER" ]] || {
    echo "SSH authenticated, but the remote account did not match the configured deployment account." >&2
    exit 1
  }

  probe_output="$("${SSH_BASE[@]}" "$REMOTE" "REMOTE_PATH=$remote_path_q bash -se" <<'REMOTE_PROBE'
set -Eeuo pipefail
required_tools=(bash rsync readlink stat sha256sum find sort awk wc)
for tool in "${required_tools[@]}"; do
  command -v "$tool" >/dev/null 2>&1 || {
    printf 'MISSING_TOOL=%s\n' "$tool"
    exit 21
  }
done
normalized="$(readlink -f -- "$REMOTE_PATH")"
[[ -d "$normalized" ]] || exit 22
[[ -r "$normalized" ]] || exit 23
[[ -w "$normalized" ]] || exit 24
printf 'NORMALIZED=%s\n' "$normalized"
printf 'RSYNC_VERSION=%s\n' "$(rsync --version | head -n 1)"
printf 'MODE=%s\n' "$(stat -c '%a' -- "$normalized")"
REMOTE_PROBE
)"

  normalized="$(printf '%s\n' "$probe_output" | sed -n 's/^NORMALIZED=//p')"
  rsync_version="$(printf '%s\n' "$probe_output" | sed -n 's/^RSYNC_VERSION=//p')"

  [[ "$normalized" == "$remote_path" ]] || {
    echo "Configured production path did not normalize to the expected production target." >&2
    exit 1
  }
  [[ -n "$rsync_version" ]] || { echo "Remote rsync version proof missing." >&2; exit 1; }

  if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
    cat >> "$GITHUB_STEP_SUMMARY" <<SUMMARY
## D2-0 — GitHub SSH credential probe

- SSH authentication: **PASS**
- Strict pinned host-key validation: **PASS**
- Expected remote account: **PASS**
- Configured host bound to pinned known-host entry: **PASS**
- Production path normalization: **PASS**
- Production-path existence/read permission: **PASS**
- Production-path write capability (permission inspection only): **PASS**
- Remote verification tools: **PASS**
- Remote rsync: **$rsync_version**
- Production mutation: **NONE**
SUMMARY
  fi
}

make_rsync_ssh_wrapper() {
  local wrapper="$RUNNER_TEMP/fitcrew-rsync-ssh"
  {
    printf '#!/usr/bin/env bash\n'
    printf 'exec sshpass -e ssh -p %q ' "$SSH_PORT"
    printf '%q ' '-o' 'StrictHostKeyChecking=yes'
    printf '%q ' '-o' "UserKnownHostsFile=$KNOWN_HOSTS_FILE"
    printf '%q ' '-o' 'LogLevel=ERROR'
    printf '%q ' '-o' 'PreferredAuthentications=password,keyboard-interactive'
    printf '%q ' '-o' 'PubkeyAuthentication=no'
    printf '%q ' '-o' 'ConnectTimeout=20'
    printf '"$@"\n'
  } > "$wrapper"
  chmod 700 "$wrapper"
  printf '%s' "$wrapper"
}

cert_status() {
  [[ -n "$PROOF_DIR" ]] || { echo "Proof directory required." >&2; exit 1; }
  mkdir -p "$PROOF_DIR"

  local output
  output="$("${SSH_BASE[@]}" "$REMOTE" "CERT_DIR=$CERT_DIR bash -se" <<'REMOTE_CERT_STATUS'
set -Eeuo pipefail
cert="$HOME/$CERT_DIR"
if [[ ! -f "$cert/current.env" ]]; then
  echo 'CERT_AVAILABLE=0'
  echo 'CERT_REASON=no-deployment-certification'
  exit 0
fi
for required in application-manifest.tsv vendor-manifest.tsv vendor-sha256sums.txt; do
  if [[ ! -f "$cert/$required" ]]; then
    echo 'CERT_AVAILABLE=0'
    echo 'CERT_REASON=incomplete-deployment-ownership-manifest'
    exit 0
  fi
done
for key in git_commit payload_identity_sha256 application_manifest_sha256 vendor_input_sha256 vendor_manifest_sha256 vendor_file_count vendor_bytes; do
  value="$(sed -n "s/^${key}=//p" "$cert/current.env")"
  [[ -n "$value" ]] || {
    echo 'CERT_AVAILABLE=0'
    echo 'CERT_REASON=incomplete-deployment-certification'
    exit 0
  }
  printf '%s=%s\n' "${key^^}" "$value"
done
echo 'CERT_AVAILABLE=1'
echo 'CERT_REASON=certification-present'
REMOTE_CERT_STATUS
)"
  printf '%s\n' "$output" | tee "$PROOF_DIR/cert-status.txt"
}

vendor_status() {
  [[ -n "$PROOF_DIR" && -d "$PROOF_DIR" ]] || { echo "Proof directory missing." >&2; exit 1; }
  [[ -f "$PROOF_DIR/vendor-input.sha256" ]] || { echo "Vendor input fingerprint missing." >&2; exit 1; }

  local vendor_input output
  vendor_input="$(cat "$PROOF_DIR/vendor-input.sha256")"
  output="$("${SSH_BASE[@]}" "$REMOTE" "CERT_DIR=$CERT_DIR EXPECTED_VENDOR_INPUT=$vendor_input bash -se" <<'REMOTE_STATUS'
set -Eeuo pipefail
cert="$HOME/$CERT_DIR"
if [[ ! -f "$cert/current.env" || ! -f "$cert/vendor-sha256sums.txt" ]]; then
  echo 'VENDOR_FAST_PATH=0'
  echo 'VENDOR_REASON=no-certified-vendor-baseline'
  exit 0
fi
vendor_input_sha256="$(sed -n 's/^vendor_input_sha256=//p' "$cert/current.env")"
vendor_manifest_sha256="$(sed -n 's/^vendor_manifest_sha256=//p' "$cert/current.env")"
vendor_file_count="$(sed -n 's/^vendor_file_count=//p' "$cert/current.env")"
vendor_bytes="$(sed -n 's/^vendor_bytes=//p' "$cert/current.env")"
git_commit="$(sed -n 's/^git_commit=//p' "$cert/current.env")"
if [[ "$vendor_input_sha256" != "$EXPECTED_VENDOR_INPUT" ]]; then
  echo 'VENDOR_FAST_PATH=0'
  echo 'VENDOR_REASON=vendor-input-changed'
  exit 0
fi
[[ -n "$vendor_manifest_sha256" && -n "$vendor_file_count" && -n "$vendor_bytes" ]] || {
  echo 'VENDOR_FAST_PATH=0'
  echo 'VENDOR_REASON=incomplete-vendor-certification'
  exit 0
}
echo 'VENDOR_FAST_PATH=1'
echo 'VENDOR_REASON=certified-vendor-continuity'
printf 'VENDOR_MANIFEST_SHA256=%s\n' "$vendor_manifest_sha256"
printf 'VENDOR_FILE_COUNT=%s\n' "$vendor_file_count"
printf 'VENDOR_BYTES=%s\n' "$vendor_bytes"
printf 'CERTIFIED_COMMIT=%s\n' "${git_commit:-unknown}"
REMOTE_STATUS
)"

  printf '%s\n' "$output" | tee "$PROOF_DIR/vendor-status.txt"
}

run_rsync() {
  local dry_flag="$1"
  [[ -n "$PAYLOAD_DIR" && -d "$PAYLOAD_DIR" ]] || { echo "Payload directory missing." >&2; exit 1; }
  [[ -n "$PROOF_DIR" && -d "$PROOF_DIR" ]] || { echo "Proof directory missing." >&2; exit 1; }

  local wrapper output_file start_ms end_ms duration_ms
  local payload_count transfer_count transfer_bytes unchanged_count vendor_transfer_count vendor_transfer_bytes
  local payload_identity root_ht assets_ht database_ht vendor_mode

  wrapper="$(make_rsync_ssh_wrapper)"
  if [[ "$dry_flag" == "dry" ]]; then
    output_file="$PROOF_DIR/d2-1-rsync-dry-run.txt"
  else
    output_file="$PROOF_DIR/d2-2-rsync-deploy.txt"
  fi

  start_ms="$(date +%s%3N)"
  args=(
    -rlc
    --no-times
    --omit-dir-times
    --no-perms
    --no-owner
    --no-group
    --safe-links
    --itemize-changes
    --stats
    --out-format='%i|%l|%n%L'
    -e "$wrapper"
  )
  if [[ "$dry_flag" == "dry" ]]; then
    args+=(--dry-run)
  fi
  if [[ -n "${RSYNC_FILE_LIST:-}" ]]; then
    [[ -f "$RSYNC_FILE_LIST" ]] || { echo "Configured rsync file list missing: $RSYNC_FILE_LIST" >&2; exit 1; }
    args+=(--files-from="$RSYNC_FILE_LIST")
  fi
  rsync "${args[@]}" "$PAYLOAD_DIR/" "$REMOTE:$remote_path/" | tee "$output_file"
  end_ms="$(date +%s%3N)"
  duration_ms=$((end_ms - start_ms))

  if grep -E '^[^|]*\|[0-9]+\|\.env($|\.)' "$output_file" >/dev/null; then
    echo "Rsync attempted to include forbidden .env state." >&2
    exit 1
  fi

  if [[ -n "${RSYNC_FILE_LIST:-}" ]]; then
    payload_count="$(grep -cve '^$' "$RSYNC_FILE_LIST" || true)"
  else
    payload_count="$(find "$PAYLOAD_DIR" -type f | wc -l | tr -d ' ')"
  fi
  transfer_count="$(awk -F '|' '$1 ~ /^>f/ {n += 1} END {print n + 0}' "$output_file")"
  transfer_bytes="$(awk -F '|' '$1 ~ /^>f/ {b += $2} END {printf "%.0f", b + 0}' "$output_file")"
  unchanged_count=$((payload_count - transfer_count))
  vendor_transfer_count="$(awk -F '|' '$1 ~ /^>f/ && $3 ~ /^vendor\// {n += 1} END {print n + 0}' "$output_file")"
  vendor_transfer_bytes="$(awk -F '|' '$1 ~ /^>f/ && $3 ~ /^vendor\// {b += $2} END {printf "%.0f", b + 0}' "$output_file")"
  payload_identity="$(cat "$PROOF_DIR/payload-identity.sha256")"
  vendor_mode="$(sed -n 's/^vendor_mode=//p' "$PROOF_DIR/payload-summary.txt")"

  item_status() {
    local wanted="$1"
    if awk -F '|' -v wanted="$wanted" '$1 ~ /^>f/ && $3 == wanted {found=1} END {exit !found}' "$output_file"; then
      printf 'TRANSFER'
    else
      printf 'UNCHANGED'
    fi
  }
  root_ht="$(item_status '.htaccess')"
  assets_ht="$(item_status 'assets/.htaccess')"
  database_ht="$(item_status 'database/.htaccess')"

  local phase
  if [[ "$dry_flag" == "dry" ]]; then phase='D2-1'; else phase="${DEPLOY_PHASE_LABEL:-D2-2}"; fi
  cat > "$PROOF_DIR/${phase,,}-rsync-summary.txt" <<SUMMARY
payload_files_in_rsync_scope=$payload_count
candidate_transfer_file_count=$transfer_count
candidate_transfer_bytes=$transfer_bytes
unchanged_files_in_rsync_scope=$unchanged_count
vendor_transfer_file_count=$vendor_transfer_count
vendor_transfer_bytes=$vendor_transfer_bytes
rsync_duration_ms=$duration_ms
payload_identity_sha256=$payload_identity
vendor_mode=$vendor_mode
root_htaccess=$root_ht
assets_htaccess=$assets_ht
database_htaccess=$database_ht
env_interaction=none
remote_delete=disabled
SUMMARY

  if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
    cat >> "$GITHUB_STEP_SUMMARY" <<SUMMARY

## $phase — Incremental rsync ${dry_flag/dry/dry run}

| Proof | Result |
|---|---:|
| Files in rsync scope | $payload_count |
| Candidate transfer files | $transfer_count |
| Candidate transfer bytes | $transfer_bytes |
| Unchanged files in rsync scope | $unchanged_count |
| Vendor mode | $vendor_mode |
| Vendor transfer files | $vendor_transfer_count |
| Vendor transfer bytes | $vendor_transfer_bytes |
| Rsync duration | ${duration_ms} ms |
| Payload identity SHA-256 | \`$payload_identity\` |
| Root .htaccess | $root_ht |
| assets/.htaccess | $assets_ht |
| database/.htaccess | $database_ht |
| .env interaction | **NONE** |
| Remote delete | **DISABLED** |
SUMMARY
  fi
}

manifest_delete_plan() {
  [[ -n "$PROOF_DIR" && -d "$PROOF_DIR" ]] || { echo "Proof directory missing." >&2; exit 1; }
  [[ -f "$PROOF_DIR/application-manifest.tsv" ]] || { echo "New application ownership manifest missing." >&2; exit 1; }
  [[ -f "$PROOF_DIR/payload-summary.txt" ]] || { echo "Payload summary missing." >&2; exit 1; }
  [[ -n "${CERTIFIED_COMMIT:-}" && "$CERTIFIED_COMMIT" =~ ^[0-9a-f]{40}$ ]] || { echo "CERTIFIED_COMMIT missing or malformed for D2-3." >&2; exit 1; }
  [[ -n "${MAIN_COMMIT:-}" && "$MAIN_COMMIT" =~ ^[0-9a-f]{40}$ ]] || { echo "MAIN_COMMIT missing or malformed for D2-3." >&2; exit 1; }

  local cert="$CERT_DIR" vendor_mode
  vendor_mode="$(sed -n 's/^vendor_mode=//p' "$PROOF_DIR/payload-summary.txt")"
  [[ "$vendor_mode" == 'full' || "$vendor_mode" == 'app-only' ]] || {
    echo "Unknown vendor mode for manifest deletion plan: $vendor_mode" >&2
    exit 1
  }

  # Fetch only the previous Deployment-owned manifests.  This deliberately does
  # not scan the production tree.  Remote-only/server-owned files never enter
  # the deletion candidate set.
  "${SSH_BASE[@]}" "$REMOTE" "set -Eeuo pipefail; cert=\"\$HOME/$cert\"; test -f \"\$cert/application-manifest.tsv\"; test -f \"\$cert/vendor-manifest.tsv\"; cat \"\$cert/application-manifest.tsv\"" \
    > "$PROOF_DIR/previous-application-manifest.tsv"

  if [[ "$vendor_mode" == 'full' ]]; then
    [[ -f "$PROOF_DIR/vendor-manifest.tsv" ]] || { echo "New vendor ownership manifest missing for full vendor mode." >&2; exit 1; }
    "${SSH_BASE[@]}" "$REMOTE" "set -Eeuo pipefail; cert=\"\$HOME/$cert\"; cat \"\$cert/vendor-manifest.tsv\"" \
      > "$PROOF_DIR/previous-vendor-manifest.tsv"
  fi

  python3 - "$PROOF_DIR" "$vendor_mode" <<'PY_MANIFEST_PLAN'
from __future__ import annotations
import hashlib
import re
import sys
from pathlib import Path, PurePosixPath

proof = Path(sys.argv[1])
vendor_mode = sys.argv[2]

protected_exact = {
    '.env',
    '.git',
    '.github',
    '.well-known',
    'storage/logs',
    'storage/cache',
    'storage/tmp',
}
protected_prefixes = (
    '.env.',
    '.git/',
    '.github/',
    '.well-known/',
    'storage/logs/',
    'storage/cache/',
    'storage/tmp/',
    '.fitcrew-deployment/',
)
hex64 = re.compile(r'^[0-9a-f]{64}$')

def validate_path(path: str) -> None:
    if not path or '\x00' in path or '\n' in path or '\r' in path or '\t' in path or '\\' in path:
        raise SystemExit(f'Unsafe deployment-owned path encoding: {path!r}')
    p = PurePosixPath(path)
    if p.is_absolute() or path.startswith('/') or any(part in ('', '.', '..') for part in p.parts):
        raise SystemExit(f'Unsafe deployment-owned relative path: {path!r}')

def validate_candidate(path: str) -> None:
    validate_path(path)
    if path in protected_exact or path.startswith(protected_prefixes):
        raise SystemExit(f'Protected server/runtime path cannot be a deletion candidate: {path}')

def load_manifest(path: Path) -> dict[str, tuple[str, int]]:
    result: dict[str, tuple[str, int]] = {}
    for lineno, raw in enumerate(path.read_text(encoding='utf-8').splitlines(), 1):
        if not raw:
            continue
        parts = raw.split('\t')
        if len(parts) != 3:
            raise SystemExit(f'Malformed manifest line {path.name}:{lineno}')
        digest, size_text, rel = parts
        if not hex64.fullmatch(digest):
            raise SystemExit(f'Invalid SHA-256 in {path.name}:{lineno}')
        try:
            size = int(size_text)
        except ValueError:
            raise SystemExit(f'Invalid byte count in {path.name}:{lineno}')
        if size < 0:
            raise SystemExit(f'Negative byte count in {path.name}:{lineno}')
        validate_path(rel)
        if rel in result:
            raise SystemExit(f'Duplicate deployment-owned path in {path.name}: {rel}')
        result[rel] = (digest, size)
    return result

old_app = load_manifest(proof / 'previous-application-manifest.tsv')
new_app = load_manifest(proof / 'application-manifest.tsv')

# Certified vendor continuity means old and new vendor ownership sets are
# identical in app-only mode.  Only a full vendor rebuild can create a vendor
# ownership delta, and that path has both old and new vendor manifests.
old_vendor: dict[str, tuple[str, int]] = {}
new_vendor: dict[str, tuple[str, int]] = {}
if vendor_mode == 'full':
    old_vendor = load_manifest(proof / 'previous-vendor-manifest.tsv')
    new_vendor = load_manifest(proof / 'vendor-manifest.tsv')

if set(old_app) & set(old_vendor):
    raise SystemExit('Previous application/vendor ownership manifests overlap unexpectedly')
if set(new_app) & set(new_vendor):
    raise SystemExit('New application/vendor ownership manifests overlap unexpectedly')
old_owned = dict(old_app)
old_owned.update(old_vendor)
new_owned = dict(new_app)
new_owned.update(new_vendor)

candidates = sorted(set(old_owned) - set(new_owned))
plan_lines = []
for rel in candidates:
    digest, size = old_owned[rel]
    validate_candidate(rel)
    if rel in new_owned:
        raise SystemExit(f'Internal set-difference error; target still owns {rel}')
    plan_lines.append(f'{digest}\t{size}\t{rel}\n')

plan = proof / 'd2-3-delete-plan.tsv'
plan.write_text(''.join(plan_lines), encoding='utf-8')
(proof / 'd2-3-delete-candidates.txt').write_text(
    ''.join(rel + '\n' for rel in candidates), encoding='utf-8'
)
sha = hashlib.sha256(plan.read_bytes()).hexdigest()
(proof / 'd2-3-delete-plan.tsv.sha256').write_text(sha + '\n', encoding='ascii')
bytes_total = sum(old_owned[rel][1] for rel in candidates)
(proof / 'd2-3-delete-plan-summary.env').write_text(
    f'candidate_count={len(candidates)}\n'
    f'candidate_bytes={bytes_total}\n'
    f'delete_plan_tsv_sha256={sha}\n'
    f'vendor_mode={vendor_mode}\n', encoding='utf-8'
)
PY_MANIFEST_PLAN

  local candidate_count candidate_bytes plan_tsv_sha plan_identity
  candidate_count="$(sed -n 's/^candidate_count=//p' "$PROOF_DIR/d2-3-delete-plan-summary.env")"
  candidate_bytes="$(sed -n 's/^candidate_bytes=//p' "$PROOF_DIR/d2-3-delete-plan-summary.env")"
  plan_tsv_sha="$(sed -n 's/^delete_plan_tsv_sha256=//p' "$PROOF_DIR/d2-3-delete-plan-summary.env")"
  plan_identity="$(printf 'fitcrew-manifest-delete-plan-v1\ncertified_commit=%s\ntarget_main=%s\ndelete_plan_tsv_sha256=%s\n' \
    "$CERTIFIED_COMMIT" "$MAIN_COMMIT" "$plan_tsv_sha" | sha256sum | awk '{print $1}')"
  printf '%s\n' "$plan_identity" > "$PROOF_DIR/d2-3-delete-plan-identity.sha256"
  cat > "$PROOF_DIR/d2-3-delete-plan-identity.env" <<IDENTITY
certified_commit=$CERTIFIED_COMMIT
target_main=$MAIN_COMMIT
delete_plan_tsv_sha256=$plan_tsv_sha
delete_plan_identity_sha256=$plan_identity
IDENTITY

  # D2-3 also proves every candidate resolves beneath PROD_PATH without writing
  # or scanning the production tree.  The remote Bash program is passed as an
  # argument so stdin remains exclusively the certified deletion plan.
  local validate_script validate_script_q validate_result
  validate_script="$(cat <<'REMOTE_PLAN_VALIDATE'
set -Eeuo pipefail
root="$(readlink -f -- "$REMOTE_PATH")"
[[ -d "$root" ]] || exit 71
while IFS=$'\t' read -r expected_sha expected_size rel; do
  [[ -n "$rel" ]] || continue
  [[ "$expected_sha" =~ ^[0-9a-f]{64}$ ]] || exit 76
  [[ "$expected_size" =~ ^[0-9]+$ ]] || exit 77
  [[ "$rel" != /* && "$rel" != *$'\n'* && "$rel" != *$'\r'* && "$rel" != *$'\t'* && "$rel" != *\\* ]] || exit 72
  candidate="$(readlink -m -- "$root/$rel")"
  case "$candidate" in "$root"/*) ;; *) exit 73 ;; esac
  [[ ! -L "$root/$rel" ]] || exit 74
  if [[ -e "$root/$rel" ]]; then
    [[ -f "$root/$rel" ]] || exit 75
    actual_sha="$(sha256sum -- "$root/$rel" | awk '{print $1}')"
    actual_size="$(stat -c '%s' -- "$root/$rel")"
    [[ "$actual_sha" == "$expected_sha" ]] || exit 78
    [[ "$actual_size" == "$expected_size" ]] || exit 79
  fi
done
echo 'REMOTE_PATH_VALIDATION=PASS'
REMOTE_PLAN_VALIDATE
)"
  printf -v validate_script_q '%q' "$validate_script"
  validate_result="$(cat "$PROOF_DIR/d2-3-delete-plan.tsv" | "${SSH_BASE[@]}" "$REMOTE" "REMOTE_PATH=$remote_path_q bash -c $validate_script_q")"
  [[ "$validate_result" == 'REMOTE_PATH_VALIDATION=PASS' ]] || { echo "Remote D2-3 path validation result missing." >&2; exit 1; }

  if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
    {
      echo
      echo '## ${PLAN_PHASE_LABEL:-D2-3} — Deployment-owned manifest deletion dry run'
      echo
      echo '- Previous certified Deployment ownership manifest: **AVAILABLE**'
      echo '- New canonical Deployment ownership manifest: **AVAILABLE**'
      echo '- Production-root tree scan: **NOT PERFORMED**'
      echo '- Candidate rule: **previously Deployment-owned AND absent from new canonical manifest**'
      echo '- Every candidate previously Deployment-owned: **PASS**'
      echo '- Every candidate absent from new canonical ownership manifest: **PASS**'
      echo '- Beneath-PROD_PATH resolution / protected-path validation: **PASS**'
      echo '- Existing candidate bytes still match previous certified hash/size: **PASS**'
      printf -- '- Candidate obsolete files: `%s`\n' "$candidate_count"
      printf -- '- Candidate obsolete bytes: `%s`\n' "$candidate_bytes"
      printf -- '- Exact delete-plan TSV SHA-256: `%s`\n' "$plan_tsv_sha"
      printf -- '- Commit-bound delete-plan identity SHA-256: `%s`\n' "$plan_identity"
      echo '- Production deletion: **NONE / DRY RUN**'
      echo
      echo '<details><summary>Exact candidate deletion list</summary>'
      echo
      echo '```text'
      if [[ "$candidate_count" == '0' ]]; then
        echo '<empty>'
      else
        cat "$PROOF_DIR/d2-3-delete-candidates.txt"
      fi
      echo '```'
      echo '</details>'
    } >> "$GITHUB_STEP_SUMMARY"
  fi
}

manifest_delete_apply() {
  [[ -n "$PROOF_DIR" && -d "$PROOF_DIR" ]] || { echo "Proof directory missing." >&2; exit 1; }
  [[ -f "$PROOF_DIR/d2-3-delete-plan.tsv" ]] || { echo "D2-3 delete plan missing." >&2; exit 1; }
  [[ -n "${EXPECTED_DELETE_PLAN_SHA256:-}" ]] || { echo "EXPECTED_DELETE_PLAN_SHA256 missing." >&2; exit 1; }
  [[ "$EXPECTED_DELETE_PLAN_SHA256" =~ ^[0-9a-f]{64}$ ]] || { echo "Expected delete-plan SHA-256 is malformed." >&2; exit 1; }

  [[ -f "$PROOF_DIR/d2-3-delete-plan-identity.sha256" ]] || { echo "D2-3 commit-bound plan identity missing." >&2; exit 1; }
  local actual_identity
  actual_identity="$(cat "$PROOF_DIR/d2-3-delete-plan-identity.sha256")"
  [[ "$actual_identity" == "$EXPECTED_DELETE_PLAN_SHA256" ]] || {
    echo "D2-4 delete plan does not match the separately proved, commit-bound D2-3 plan." >&2
    echo "Expected: $EXPECTED_DELETE_PLAN_SHA256" >&2
    echo "Actual:   $actual_identity" >&2
    exit 41
  }

  local candidate_count
  candidate_count="$(grep -cve '^$' "$PROOF_DIR/d2-3-delete-plan.tsv" || true)"
  [[ "$candidate_count" -gt 0 ]] || {
    echo "D2-4 requires at least one manifest-owned obsolete application file." >&2
    exit 42
  }

  local delete_script delete_script_q result
  delete_script="$(cat <<'REMOTE_DELETE'
set -Eeuo pipefail
root="$(readlink -f -- "$REMOTE_PATH")"
[[ -d "$root" ]] || exit 51
removed=0
already_absent=0

protected() {
  case "$1" in
    .env|.env.*|.git|.git/*|.github|.github/*|.well-known|.well-known/*|storage/logs|storage/logs/*|storage/cache|storage/cache/*|storage/tmp|storage/tmp/*|.fitcrew-deployment/*)
      return 0 ;;
    *) return 1 ;;
  esac
}

while IFS=$'\t' read -r expected_sha expected_size rel; do
  [[ -n "$rel" ]] || continue
  [[ "$expected_sha" =~ ^[0-9a-f]{64}$ ]] || { echo "INVALID_SHA=$rel"; exit 52; }
  [[ "$expected_size" =~ ^[0-9]+$ ]] || { echo "INVALID_SIZE=$rel"; exit 53; }
  [[ "$rel" != /* && "$rel" != *$'\n'* && "$rel" != *$'\r'* && "$rel" != *$'\t'* && "$rel" != *\\* ]] || {
    echo "UNSAFE_PATH=$rel"; exit 54;
  }
  IFS='/' read -r -a parts <<< "$rel"
  for part in "${parts[@]}"; do
    [[ -n "$part" && "$part" != '.' && "$part" != '..' ]] || { echo "UNSAFE_SEGMENT=$rel"; exit 55; }
  done
  protected "$rel" && { echo "PROTECTED_PATH=$rel"; exit 56; }

  target="$root/$rel"
  if [[ ! -e "$target" && ! -L "$target" ]]; then
    printf 'ALREADY_ABSENT=%s\n' "$rel"
    already_absent=$((already_absent + 1))
    continue
  fi
  [[ ! -L "$target" ]] || { echo "SYMLINK_REFUSED=$rel"; exit 57; }
  [[ -f "$target" ]] || { echo "NON_REGULAR_REFUSED=$rel"; exit 58; }
  resolved="$(readlink -f -- "$target")"
  case "$resolved" in
    "$root"/*) ;;
    *) echo "OUTSIDE_PROD_PATH=$rel"; exit 59 ;;
  esac
  actual_sha="$(sha256sum -- "$target" | awk '{print $1}')"
  [[ "$actual_sha" == "$expected_sha" ]] || { echo "REMOTE_HASH_CHANGED=$rel"; exit 60; }
  actual_size="$(stat -c '%s' -- "$target")"
  [[ "$actual_size" == "$expected_size" ]] || { echo "REMOTE_SIZE_CHANGED=$rel"; exit 61; }

  rm -- "$target"
  [[ ! -e "$target" && ! -L "$target" ]] || { echo "DELETE_FAILED=$rel"; exit 62; }
  printf 'REMOVED=%s\n' "$rel"
  removed=$((removed + 1))
done
printf 'REMOVED_COUNT=%s\n' "$removed"
printf 'ALREADY_ABSENT_COUNT=%s\n' "$already_absent"
REMOTE_DELETE
)"
  printf -v delete_script_q '%q' "$delete_script"
  result="$(cat "$PROOF_DIR/d2-3-delete-plan.tsv" | "${SSH_BASE[@]}" "$REMOTE" "REMOTE_PATH=$remote_path_q bash -c $delete_script_q")"
  printf '%s\n' "$result" | tee "$PROOF_DIR/d2-4-delete-result.txt"

  local removed_count absent_count
  removed_count="$(printf '%s\n' "$result" | sed -n 's/^REMOVED_COUNT=//p')"
  absent_count="$(printf '%s\n' "$result" | sed -n 's/^ALREADY_ABSENT_COUNT=//p')"
  [[ -n "$removed_count" && -n "$absent_count" ]] || { echo "D2-4 deletion result counters missing." >&2; exit 1; }

  if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
    cat >> "$GITHUB_STEP_SUMMARY" <<SUMMARY

## D2-4 — Manifest-owned obsolete-file removal

- D2-3 delete-plan identity match: **PASS**
- Commit-bound delete-plan identity SHA-256: \`$actual_identity\`
- Candidate files: \`$candidate_count\`
- Exact old-byte hash/size verified before each removal: **PASS**
- Removed files: \`$removed_count\`
- Already-absent files (safe rerun): \`$absent_count\`
- Directory deletion: **NONE**
- Broad rsync delete: **NOT USED**
- Server-tree scan: **NOT PERFORMED**
- .env interaction: **NONE**
SUMMARY
  fi
}

verify_and_certify() {
  [[ -n "$PROOF_DIR" && -d "$PROOF_DIR" ]] || { echo "Proof directory missing." >&2; exit 1; }
  [[ -f "$PROOF_DIR/application-sha256sums.txt" ]] || { echo "Application verification manifest missing." >&2; exit 1; }
  [[ -f "$PROOF_DIR/vendor-input.sha256" ]] || { echo "Vendor input fingerprint missing." >&2; exit 1; }
  [[ -f "$PROOF_DIR/vendor-manifest.sha256" ]] || { echo "Vendor manifest identity missing." >&2; exit 1; }
  [[ -f "$PROOF_DIR/payload-identity.sha256" ]] || { echo "Payload identity missing." >&2; exit 1; }
  [[ -n "${MAIN_COMMIT:-}" ]] || { echo "MAIN_COMMIT missing." >&2; exit 1; }

  local vendor_mode cert="$CERT_DIR" app_verify_start app_verify_end vendor_verify_start vendor_verify_end
  vendor_mode="$(sed -n 's/^vendor_mode=//p' "$PROOF_DIR/payload-summary.txt")"

  # Verify the governed application payload directly against expected local hashes.
  app_verify_start="$(date +%s%3N)"
  cat "$PROOF_DIR/application-sha256sums.txt" | "${SSH_BASE[@]}" "$REMOTE" \
    "set -Eeuo pipefail; cd $remote_path_q; manifest=\$(mktemp); trap 'rm -f \"\$manifest\"' EXIT; cat > \"\$manifest\"; sha256sum -c \"\$manifest\" --quiet"
  app_verify_end="$(date +%s%3N)"

  # Full mode re-verifies vendor bytes. App-only mode intentionally reuses the
  # previously certified vendor identity after Git/vendor-input continuity proof;
  # it does not re-hash tens of thousands of unchanged vendor files on every run.
  vendor_verify_start="$(date +%s%3N)"
  if [[ "$vendor_mode" == "full" ]]; then
    [[ -f "$PROOF_DIR/vendor-sha256sums.txt" ]] || { echo "Full vendor verification manifest missing." >&2; exit 1; }
    cat "$PROOF_DIR/vendor-sha256sums.txt" | "${SSH_BASE[@]}" "$REMOTE" \
      "set -Eeuo pipefail; cd $remote_path_q; manifest=\$(mktemp); trap 'rm -f \"\$manifest\"' EXIT; cat > \"\$manifest\"; sha256sum -c \"\$manifest\" --quiet"
    vendor_verification_result='pass/full-byte-verification'
  else
    local expected_vendor_input expected_vendor_manifest continuity
    expected_vendor_input="$(cat "$PROOF_DIR/vendor-input.sha256")"
    expected_vendor_manifest="$(cat "$PROOF_DIR/vendor-manifest.sha256")"
    continuity="$("${SSH_BASE[@]}" "$REMOTE" "CERT_DIR=$cert EXPECTED_VENDOR_INPUT=$expected_vendor_input EXPECTED_VENDOR_MANIFEST=$expected_vendor_manifest bash -se" <<'REMOTE_VERIFY'
set -Eeuo pipefail
cert="$HOME/$CERT_DIR"
[[ -f "$cert/current.env" && -f "$cert/vendor-sha256sums.txt" ]]
grep -Fx "vendor_input_sha256=$EXPECTED_VENDOR_INPUT" "$cert/current.env" >/dev/null
grep -Fx "vendor_manifest_sha256=$EXPECTED_VENDOR_MANIFEST" "$cert/current.env" >/dev/null
echo PASS
REMOTE_VERIFY
)"
    [[ "$continuity" == 'PASS' ]] || { echo "Certified vendor continuity proof failed." >&2; exit 1; }
    vendor_verification_result='reused-certified-baseline/no-rehash'
  fi
  vendor_verify_end="$(date +%s%3N)"

  local vendor_input vendor_manifest_sha payload_identity app_manifest_sha vendor_count vendor_bytes now run_id
  vendor_input="$(cat "$PROOF_DIR/vendor-input.sha256")"
  vendor_manifest_sha="$(cat "$PROOF_DIR/vendor-manifest.sha256")"
  payload_identity="$(cat "$PROOF_DIR/payload-identity.sha256")"
  app_manifest_sha="$(cat "$PROOF_DIR/application-manifest.sha256")"
  vendor_count="$(sed -n 's/^vendor_file_count=//p' "$PROOF_DIR/payload-summary.txt")"
  vendor_bytes="$(sed -n 's/^vendor_bytes=//p' "$PROOF_DIR/payload-summary.txt")"
  now="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
  run_id="${GITHUB_RUN_ID:-manual}"

  cat > "$PROOF_DIR/current.env" <<MARKER
schema_version=2
git_commit=$MAIN_COMMIT
payload_identity_sha256=$payload_identity
application_manifest_sha256=$app_manifest_sha
vendor_input_sha256=$vendor_input
vendor_manifest_sha256=$vendor_manifest_sha
vendor_file_count=$vendor_count
vendor_bytes=$vendor_bytes
deployed_at_utc=$now
github_run_id=$run_id
MARKER

  # Only after both remote verifications pass, advance deployment certification.
  "${SSH_BASE[@]}" "$REMOTE" "CERT_DIR=$cert bash -se" <<'REMOTE_CERT'
set -Eeuo pipefail
cert="$HOME/$CERT_DIR"
mkdir -p "$cert"
chmod 700 "$cert"
REMOTE_CERT

  # Write deployment-owned certification files outside the web root using stdin.
  remote_write_file() {
    local local_file="$1" remote_name="$2"
    cat "$local_file" | "${SSH_BASE[@]}" "$REMOTE" "set -Eeuo pipefail; cert=\"\$HOME/$cert\"; tmp=\"\$cert/.${remote_name}.tmp.\$\$\"; cat > \"\$tmp\"; chmod 600 \"\$tmp\"; mv -f \"\$tmp\" \"\$cert/$remote_name\""
  }

  remote_write_file "$PROOF_DIR/current.env" current.env
  remote_write_file "$PROOF_DIR/application-manifest.tsv" application-manifest.tsv
  remote_write_file "$PROOF_DIR/application-sha256sums.txt" application-sha256sums.txt
  if [[ "$vendor_mode" == "full" ]]; then
    remote_write_file "$PROOF_DIR/vendor-manifest.tsv" vendor-manifest.tsv
    remote_write_file "$PROOF_DIR/vendor-sha256sums.txt" vendor-sha256sums.txt
  fi

  local cert_readback
  cert_readback="$("${SSH_BASE[@]}" "$REMOTE" "cat \"\$HOME/$cert/current.env\"")"
  grep -Fx "git_commit=$MAIN_COMMIT" <<< "$cert_readback" >/dev/null
  grep -Fx "payload_identity_sha256=$payload_identity" <<< "$cert_readback" >/dev/null

  cat > "$PROOF_DIR/d2-2-verification-summary.txt" <<SUMMARY
application_verification=pass
application_verification_duration_ms=$((app_verify_end - app_verify_start))
vendor_verification=$vendor_verification_result
vendor_verification_duration_ms=$((vendor_verify_end - vendor_verify_start))
certification_advanced=pass
certified_git_commit=$MAIN_COMMIT
payload_identity_sha256=$payload_identity
vendor_mode=$vendor_mode
SUMMARY

  if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
    cat >> "$GITHUB_STEP_SUMMARY" <<SUMMARY

## ${CERTIFY_PHASE_LABEL:-D2-2} — Remote verification and deployment certification

- Governed application payload verification: **PASS** ($((app_verify_end - app_verify_start)) ms)
- Vendor verification: **$vendor_verification_result** ($((vendor_verify_end - vendor_verify_start)) ms)
- Vendor mode: **$vendor_mode**
- Certified main commit: \`$MAIN_COMMIT\`
- Certified payload identity: \`$payload_identity\`
- Certification metadata location: **outside web root**
- Certification advanced only after verification: **PASS**
- Remote delete: **DISABLED**
- .env interaction: **NONE**
SUMMARY
  fi
}

case "$MODE" in
  probe)
    probe
    ;;
  cert-status)
    cert_status
    ;;
  vendor-status)
    vendor_status
    ;;
  dry-run)
    run_rsync dry
    ;;
  deploy)
    run_rsync deploy
    ;;
  manifest-delete-plan)
    manifest_delete_plan
    ;;
  manifest-delete-apply)
    manifest_delete_apply
    ;;
  verify-certify)
    verify_and_certify
    ;;
  *)
    echo "Unknown mode: $MODE" >&2
    exit 2
    ;;
esac
