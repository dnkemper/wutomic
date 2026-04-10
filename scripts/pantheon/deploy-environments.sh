#!/bin/bash

# =============================================================================
# deploy-environments.sh
# Deploys code across environments in parallel batches.
#
# Usage: bash deploy-environments.sh [OPTIONS]
#
# Options:
#   --env=dev-to-test     Deploy dev -> test (default)
#   --env=test-to-live    Deploy test -> live
#   --env=both            Deploy dev -> test, then test -> live
#   --batch-size=N        Number of parallel deployments (default: 5)
#   --dry-run             Print commands without executing
# =============================================================================

DRY_RUN=false
BATCH_SIZE=5
DEPLOY_ENV="dev-to-test"

for arg in "$@"; do
  case $arg in
    --dry-run) DRY_RUN=true ;;
    --batch-size=*) BATCH_SIZE="${arg#*=}" ;;
    --env=*) DEPLOY_ENV="${arg#*=}" ;;
  esac
done

if [[ "$DEPLOY_ENV" != "dev-to-test" && "$DEPLOY_ENV" != "test-to-live" && "$DEPLOY_ENV" != "both" ]]; then
  echo "ERROR: --env must be one of: dev-to-test, test-to-live, both"
  exit 1
fi

ERRORS_FILE=$(mktemp)
SUCCESS_FILE=$(mktemp)

deploy() {
  local SITE="$1"
  local ENV="$2"  # "test" or "live"
  local SITE_ENV="${SITE}.${ENV}"

  if [ "$DRY_RUN" = true ]; then
    echo "[DRY RUN] terminus env:deploy $SITE_ENV --updatedb --cc --yes"
    echo "$SITE_ENV" >> "$SUCCESS_FILE"
    return 0
  fi

  echo "  Starting: $SITE_ENV"
  gtimeout 300 terminus env:deploy "$SITE_ENV" --updatedb --cc --yes > /tmp/log-deploy-${SITE_ENV}.txt 2>&1
  if [ $? -ne 0 ]; then
    echo "  FAILED:   $SITE_ENV (see /tmp/log-deploy-${SITE_ENV}.txt)"
    echo "$SITE_ENV" >> "$ERRORS_FILE"
  else
    echo "  DONE:     $SITE_ENV"
    echo "$SITE_ENV" >> "$SUCCESS_FILE"
  fi
}

export -f deploy
export DRY_RUN ERRORS_FILE SUCCESS_FILE

SITES=(
  "wustl-afas"
  "wustl-amcs"
  "wustl-anthropology"
  "wustl-arthistory"
  "wustl-artsci"
  "wustl-artsci-nu"
  "wustl-artsciadvising"
  "wustl-artsciportal"
  "wustl-artscistage"
  "wustl-base"
  "wustl-biology"
  "wustl-buildingartsci"
  "wustl-chemistry"
  "wustl-classics"
  "wustl-collegewriting"
  "wustl-complitandthought"
  "wustl-ealc"
  "wustl-economics"
  "wustl-education"
  "wustl-eeps"
  "wustl-english"
  "wustl-enst"
  "wustl-fellowshipsoffice"
  "wustl-fms"
  "wustl-forms"
  "wustl-globalstudies"
  "wustl-gradstudies"
  "wustl-graduation"
  "wustl-hdw"
  "wustl-history"
  "wustl-holdthatthought"
  "wustl-humanities"
  "wustl-immersivetech"
  "wustl-insideartsci"
  "wustl-itartsci"
  "wustl-jimes"
  "wustl-johnmaxwulfing"
  "wustl-lasprogram"
  "wustl-linguistics"
  "wustl-literacies"
  "wustl-literaryarts"
  "wustl-math"
  "wustl-mcss"
  "wustl-mindfulness"
  "wustl-movingstories"
  "wustl-music"
  "wustl-myprehealth"
  "wustl-olympian"
  "wustl-overseas"
  "wustl-pad"
  "wustl-philosophy"
  "wustl-physics"
  "wustl-pnp"
  "wustl-polisci"
  "wustl-postbaccpremed"
  "wustl-precollege"
  "wustl-prehealth"
  "wustl-psych"
  "wustl-publichealthandsociety"
  "wustl-quantumleaps"
  "wustl-religiousstudies"
  "wustl-rll"
  "wustl-sds"
  "wustl-slavery"
  "wustl-sociology"
  "wustl-strategicplan"
  "wustl-summersession"
  "wustl-transdisciplinaryfutures"
  "wustl-triads"
  "wustl-undergradresearch"
  "wustl-wc"
  "wustl-wgss"
)

TOTAL=${#SITES[@]}

run_deploys() {
  local TARGET_ENV="$1"
  echo ""
  echo "========================================"
  echo "Deploying to $TARGET_ENV ($TOTAL sites, batch size $BATCH_SIZE)"
  echo "Started: $(date)"
  echo "========================================"
  echo ""

  for ((i=0; i<TOTAL; i+=BATCH_SIZE)); do
    BATCH=("${SITES[@]:$i:$BATCH_SIZE}")
    BATCH_NUM=$(( i/BATCH_SIZE + 1 ))
    TOTAL_BATCHES=$(( (TOTAL + BATCH_SIZE - 1) / BATCH_SIZE ))

    echo "--- Batch $BATCH_NUM / $TOTAL_BATCHES ---"

    for SITE in "${BATCH[@]}"; do
      deploy "$SITE" "$TARGET_ENV" &
    done

    wait
    echo ""
  done
}

START_TIME=$(date +%s)

# Run the appropriate deploys
if [[ "$DEPLOY_ENV" == "dev-to-test" || "$DEPLOY_ENV" == "both" ]]; then
  run_deploys "test"
fi

if [[ "$DEPLOY_ENV" == "test-to-live" || "$DEPLOY_ENV" == "both" ]]; then
  if [[ "$DEPLOY_ENV" == "both" ]]; then
    echo "Waiting 10 seconds before deploying to live..."
    sleep 10
  fi
  run_deploys "live"
fi

END_TIME=$(date +%s)
ELAPSED=$(( END_TIME - START_TIME ))
SUCCESS_COUNT=$(wc -l < "$SUCCESS_FILE" | tr -d ' ')
FAIL_COUNT=$(wc -l < "$ERRORS_FILE" | tr -d ' ')

echo "========================================"
echo "DONE: $SUCCESS_COUNT succeeded, $FAIL_COUNT failed (${ELAPSED}s elapsed)"
echo "========================================"

if [ -s "$ERRORS_FILE" ]; then
  echo ""
  echo "Failed deployments (logs in /tmp/log-deploy-*.txt):"
  while IFS= read -r site; do
    echo "  [FAILED] $site"
  done < "$ERRORS_FILE"
fi

rm -f "$ERRORS_FILE" "$SUCCESS_FILE"
