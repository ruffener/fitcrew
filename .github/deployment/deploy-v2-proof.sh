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

vendor_status() {
  [[ -n "$PROOF_DIR" && -d "$PROOF_DIR" ]] || { echo "Proof directory missing." >&2; exit 1; }
  [[ -f "$PROOF_DIR/vendor-input.sha256" ]] || { echo "Vendor input fingerprint missing." >&2; exit 1; }
  probe >/dev/null

  local vendor_input output
  vendor_input="$(cat "$PROOF_DIR/vendor-input.sha256")"
  output="$("${SSH_BASE[@]}" "$REMOTE" "REMOTE_PATH=$remote_path_q CERT_DIR=$CERT_DIR EXPECTED_VENDOR_INPUT=$vendor_input bash -se" <<'REMOTE_STATUS'
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
cd "$REMOTE_PATH"
if ! sha256sum -c "$cert/vendor-sha256sums.txt" --quiet; then
  echo 'VENDOR_FAST_PATH=0'
  echo 'VENDOR_REASON=remote-vendor-verification-failed'
  exit 0
fi
echo 'VENDOR_FAST_PATH=1'
printf 'VENDOR_REASON=certified-vendor-verified\n'
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

  probe >/dev/null
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
  rsync "${args[@]}" "$PAYLOAD_DIR/" "$REMOTE:$remote_path/" | tee "$output_file"
  end_ms="$(date +%s%3N)"
  duration_ms=$((end_ms - start_ms))

  if grep -E '^[^|]*\|[0-9]+\|\.env($|\.)' "$output_file" >/dev/null; then
    echo "Rsync attempted to include forbidden .env state." >&2
    exit 1
  fi

  payload_count="$(find "$PAYLOAD_DIR" -type f | wc -l | tr -d ' ')"
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
  if [[ "$dry_flag" == "dry" ]]; then phase='D2-1'; else phase='D2-2'; fi
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

  # Verify vendor bytes. Full mode uses the just-built local vendor manifest;
  # fast mode reuses the previously certified vendor manifest stored outside webroot.
  vendor_verify_start="$(date +%s%3N)"
  if [[ "$vendor_mode" == "full" ]]; then
    [[ -f "$PROOF_DIR/vendor-sha256sums.txt" ]] || { echo "Full vendor verification manifest missing." >&2; exit 1; }
    cat "$PROOF_DIR/vendor-sha256sums.txt" | "${SSH_BASE[@]}" "$REMOTE" \
      "set -Eeuo pipefail; cd $remote_path_q; manifest=\$(mktemp); trap 'rm -f \"\$manifest\"' EXIT; cat > \"\$manifest\"; sha256sum -c \"\$manifest\" --quiet"
  else
    "${SSH_BASE[@]}" "$REMOTE" "REMOTE_PATH=$remote_path_q CERT_DIR=$cert bash -se" <<'REMOTE_VERIFY'
set -Eeuo pipefail
cert="$HOME/$CERT_DIR"
[[ -f "$cert/vendor-sha256sums.txt" ]]
cd "$REMOTE_PATH"
sha256sum -c "$cert/vendor-sha256sums.txt" --quiet
REMOTE_VERIFY
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
vendor_verification=pass
vendor_verification_duration_ms=$((vendor_verify_end - vendor_verify_start))
certification_advanced=pass
certified_git_commit=$MAIN_COMMIT
payload_identity_sha256=$payload_identity
vendor_mode=$vendor_mode
SUMMARY

  if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
    cat >> "$GITHUB_STEP_SUMMARY" <<SUMMARY

## D2-2 — Remote verification and deployment certification

- Governed application payload verification: **PASS** ($((app_verify_end - app_verify_start)) ms)
- Vendor verification: **PASS** ($((vendor_verify_end - vendor_verify_start)) ms)
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
  vendor-status)
    vendor_status
    ;;
  dry-run)
    run_rsync dry
    ;;
  deploy)
    run_rsync deploy
    ;;
  verify-certify)
    verify_and_certify
    ;;
  *)
    echo "Unknown mode: $MODE" >&2
    exit 2
    ;;
esac
