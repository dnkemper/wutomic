#!/usr/bin/env bash
# =============================================================================
# drush-all.sh
# Run any drush command across all sites, in parallel.
#
# USAGE:
#   ./drush-all.sh [OPTIONS] -- <drush command>
#
# EXAMPLES:
#   ./drush-all.sh -- cr
#   ./drush-all.sh -- updb -y
#   ./drush-all.sh --env=live -- cim -y
#   ./drush-all.sh --env=dev,test -- php:eval "drupal_flush_all_caches();"
#   ./drush-all.sh --mode=ddev -- cr
#   ./drush-all.sh --site=artsci.wustl.edu -- cr
#   ./drush-all.sh --parallel=5 --env=live -- updb -y
#
# OPTIONS:
#   --mode=pantheon|ddev|both   Which environment to target (default: both)
#   --env=dev|test|live         Pantheon env(s), comma-separated (default: dev,test,live)
#   --org=ORGNAME               Pantheon org to filter sites by (default: $PANTHEON_ORG)
#   --site=SITENAME             Run on a single site only
#   --parallel=N                Max parallel jobs (default: 5)
#   --dry-run                   Print commands without running them
#   --log=FILE                  Log output to file
#   -h|--help                   Show this help
# =============================================================================

set -euo pipefail

# ---------- Defaults ----------------------------------------------------------
MODE="both"
ENVS="dev,test,live"
ORG="${PANTHEON_ORG:-}"
SINGLE_SITE=""
PARALLEL=5
DRY_RUN=false
LOG_FILE=""
DRUSH_CMD=""

# ---------- Colors ------------------------------------------------------------
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# ---------- Helpers -----------------------------------------------------------
log()     { echo -e "${BLUE}[INFO]${NC} $*"; }
success() { echo -e "${GREEN}[OK]${NC} $*"; }
warn()    { echo -e "${YELLOW}[WARN]${NC} $*"; }
error()   { echo -e "${RED}[ERROR]${NC} $*" >&2; }

usage() {
  sed -n '/^# USAGE/,/^# =====/p' "$0" | grep -v "^# ====="
  exit 0
}

# ---------- Parse args --------------------------------------------------------
while [[ $# -gt 0 ]]; do
  case "$1" in
    --mode=*)    MODE="${1#*=}" ;;
    --env=*)     ENVS="${1#*=}" ;;
    --org=*)     ORG="${1#*=}" ;;
    --site=*)    SINGLE_SITE="${1#*=}" ;;
    --parallel=*) PARALLEL="${1#*=}" ;;
    --dry-run)   DRY_RUN=true ;;
    --log=*)     LOG_FILE="${1#*=}" ;;
    -h|--help)   usage ;;
    --)          shift; DRUSH_CMD="$*"; break ;;
    *)           error "Unknown option: $1"; exit 1 ;;
  esac
  shift
done

if [[ -z "$DRUSH_CMD" ]]; then
  error "No drush command specified. Use: $0 [OPTIONS] -- <drush command>"
  exit 1
fi

# Redirect output to log file if specified
if [[ -n "$LOG_FILE" ]]; then
  exec > >(tee -a "$LOG_FILE") 2>&1
  log "Logging to $LOG_FILE"
fi

# ---------- Job runner --------------------------------------------------------
# Runs a command, prints pass/fail with site label
run_job() {
  local label="$1"
  local cmd="$2"

  if [[ "$DRY_RUN" == true ]]; then
    echo -e "${CYAN}[DRY-RUN]${NC} $label: $cmd"
    return 0
  fi

  echo -e "${BLUE}[RUN]${NC} $label"
  if eval "$cmd"; then
    success "$label — done"
  else
    error "$label — FAILED (exit $?)"
  fi
}

export -f run_job log success warn error
export DRY_RUN RED GREEN YELLOW BLUE CYAN NC

# ---------- PANTHEON ----------------------------------------------------------
run_pantheon() {
  if ! command -v terminus &>/dev/null; then
    warn "terminus not found, skipping Pantheon"
    return
  fi

  log "Fetching Pantheon site list..."

  local terminus_cmd="terminus site:list --format=list --field=name"
  [[ -n "$ORG" ]] && terminus_cmd+=" --org=$ORG"

  local sites
  if [[ -n "$SINGLE_SITE" ]]; then
    sites="$SINGLE_SITE"
  else
    sites=$(eval "$terminus_cmd" 2>/dev/null) || {
      error "Could not fetch Pantheon site list. Are you logged in? Run: terminus auth:login"
      return
    }
  fi

  local jobs=()
  IFS=',' read -ra env_list <<< "$ENVS"

  for site in $sites; do
    for env in "${env_list[@]}"; do
      local label="pantheon:${site}.${env}"
      local cmd="terminus drush ${site}.${env} -- ${DRUSH_CMD}"
      jobs+=("$label|||$cmd")
    done
  done

  log "Running on ${#jobs[@]} Pantheon site/env combinations (parallel: $PARALLEL)..."

  printf '%s\n' "${jobs[@]}" | xargs -P "$PARALLEL" -I{} bash -c '
    label=$(echo "{}" | cut -d"|" -f1)
    cmd=$(echo "{}" | cut -d"|" -f4-)
    run_job "$label" "$cmd"
  '
}

# ---------- DDEV --------------------------------------------------------------
run_ddev() {
  if ! command -v drush &>/dev/null && ! command -v ddev &>/dev/null; then
    warn "Neither drush nor ddev found, skipping DDEV"
    return
  fi

  log "Fetching DDEV drush aliases..."

  local aliases
  if [[ -n "$SINGLE_SITE" ]]; then
    aliases="@$SINGLE_SITE"
  else
    # List all local drush aliases, filter out @none and @self
    aliases=$(drush site:alias --format=list 2>/dev/null | grep -v '@none\|@self\|@local' || true)
  fi

  if [[ -z "$aliases" ]]; then
    warn "No drush aliases found for DDEV. Make sure aliases are configured in drush/sites/"
    return
  fi

  local jobs=()
  for alias in $aliases; do
    local label="ddev:${alias}"
    local cmd="drush ${alias} ${DRUSH_CMD}"
    jobs+=("$label|||$cmd")
  done

  log "Running on ${#jobs[@]} DDEV sites (parallel: $PARALLEL)..."

  printf '%s\n' "${jobs[@]}" | xargs -P "$PARALLEL" -I{} bash -c '
    label=$(echo "{}" | cut -d"|" -f1)
    cmd=$(echo "{}" | cut -d"|" -f4-)
    run_job "$label" "$cmd"
  '
}

# ---------- Main --------------------------------------------------------------
log "drush-all.sh — command: drush $DRUSH_CMD"
log "Mode: $MODE | Envs: $ENVS | Parallel: $PARALLEL | Dry-run: $DRY_RUN"
echo "---"

case "$MODE" in
  pantheon) run_pantheon ;;
  ddev)     run_ddev ;;
  both)     run_pantheon; run_ddev ;;
  *)        error "Invalid mode: $MODE. Use pantheon, ddev, or both"; exit 1 ;;
esac

echo "---"
success "All done."
