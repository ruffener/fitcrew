#!/usr/bin/env bash
set -Eeuo pipefail

MODE="${1:?mode required: probe or dry-run}"
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

  echo "D2-0 SSH authentication: PASS"
  echo "D2-0 strict pinned host-key validation: PASS"
  echo "D2-0 expected remote account: PASS"
  echo "D2-0 configured/normalized production path: PASS"
  echo "D2-0 production read permission: PASS"
  echo "D2-0 production write capability by permission inspection: PASS"
  echo "D2-0 remote verification tools: PASS"
  echo "D2-0 remote rsync: $rsync_version"

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

dry_run() {
  [[ -n "$PAYLOAD_DIR" && -d "$PAYLOAD_DIR" ]] || { echo "Payload directory missing for D2-1." >&2; exit 1; }
  [[ -n "$PROOF_DIR" && -d "$PROOF_DIR" ]] || { echo "Proof directory missing for D2-1." >&2; exit 1; }
  [[ -f "$PROOF_DIR/payload-manifest.tsv" ]] || { echo "Payload manifest missing for D2-1." >&2; exit 1; }

  # Repeat D2-0 at the start of D2-1 so the dry run cannot silently bypass its gate.
  probe >/dev/null

  local wrapper output_file start_ms end_ms duration_ms
  local payload_count transfer_count transfer_bytes unchanged_count vendor_transfer_count vendor_transfer_bytes
  local manifest_sha root_ht assets_ht database_ht

  wrapper="$(make_rsync_ssh_wrapper)"
  output_file="$PROOF_DIR/d2-1-rsync-dry-run.txt"
  start_ms="$(date +%s%3N)"

  rsync \
    -rlc \
    --no-times \
    --omit-dir-times \
    --no-perms \
    --no-owner \
    --no-group \
    --safe-links \
    --dry-run \
    --itemize-changes \
    --stats \
    --out-format='%i|%l|%n%L' \
    -e "$wrapper" \
    "$PAYLOAD_DIR/" \
    "$REMOTE:$remote_path/" \
    | tee "$output_file"

  end_ms="$(date +%s%3N)"
  duration_ms=$((end_ms - start_ms))

  # A no-delete dry run must never propose interaction with .env.
  if grep -E '^[^|]*\|[0-9]+\|\.env($|\.)' "$output_file" >/dev/null; then
    echo "D2-1 attempted to include forbidden .env state." >&2
    exit 1
  fi

  payload_count="$(awk -F '\t' '{print $1}' "$PROOF_DIR/payload-totals.tsv")"
  transfer_count="$(awk -F '|' '$1 ~ /^>f/ {n += 1} END {print n + 0}' "$output_file")"
  transfer_bytes="$(awk -F '|' '$1 ~ /^>f/ {b += $2} END {printf "%.0f", b + 0}' "$output_file")"
  unchanged_count=$((payload_count - transfer_count))
  vendor_transfer_count="$(awk -F '|' '$1 ~ /^>f/ && $3 ~ /^vendor\// {n += 1} END {print n + 0}' "$output_file")"
  vendor_transfer_bytes="$(awk -F '|' '$1 ~ /^>f/ && $3 ~ /^vendor\// {b += $2} END {printf "%.0f", b + 0}' "$output_file")"
  manifest_sha="$(cat "$PROOF_DIR/payload-manifest.sha256")"

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

  cat > "$PROOF_DIR/d2-1-summary.txt" <<SUMMARY
payload_file_count=$payload_count
candidate_transfer_file_count=$transfer_count
candidate_transfer_bytes=$transfer_bytes
unchanged_file_count=$unchanged_count
vendor_transfer_file_count=$vendor_transfer_count
vendor_transfer_bytes=$vendor_transfer_bytes
comparison_duration_ms=$duration_ms
payload_manifest_sha256=$manifest_sha
root_htaccess=$root_ht
assets_htaccess=$assets_ht
database_htaccess=$database_ht
env_interaction=none
remote_delete=disabled
production_mutation=none
SUMMARY

  echo "D2-1 dry run: PASS"
  echo "D2-1 payload files: $payload_count"
  echo "D2-1 candidate transfer files: $transfer_count"
  echo "D2-1 candidate transfer bytes: $transfer_bytes"
  echo "D2-1 unchanged files: $unchanged_count"
  echo "D2-1 vendor transfer files: $vendor_transfer_count"
  echo "D2-1 vendor transfer bytes: $vendor_transfer_bytes"
  echo "D2-1 comparison duration: ${duration_ms} ms"

  if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
    cat >> "$GITHUB_STEP_SUMMARY" <<SUMMARY

## D2-1 — Incremental rsync dry run

| Proof | Result |
|---|---:|
| Payload files | $payload_count |
| Candidate transfer files | $transfer_count |
| Candidate transfer bytes | $transfer_bytes |
| Unchanged files | $unchanged_count |
| Vendor transfer files | $vendor_transfer_count |
| Vendor transfer bytes | $vendor_transfer_bytes |
| Comparison duration | ${duration_ms} ms |
| Payload manifest SHA-256 | \`$manifest_sha\` |
| Root .htaccess | $root_ht |
| assets/.htaccess | $assets_ht |
| database/.htaccess | $database_ht |
| .env interaction | **NONE** |
| Remote delete | **DISABLED** |
| Production mutation | **NONE** |

Full itemized rsync output is retained in the proof artifact.
SUMMARY
  fi
}

case "$MODE" in
  probe)
    probe
    ;;
  dry-run)
    dry_run
    ;;
  *)
    echo "Unknown mode: $MODE" >&2
    exit 2
    ;;
esac
