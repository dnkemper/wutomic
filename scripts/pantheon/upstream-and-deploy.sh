#!/bin/bash

# =============================================================================
# upstream-and-deploy.sh
# Applies upstream updates to dev, then deploys to test in parallel batches.
#
# Usage: bash upstream-and-deploy.sh [OPTIONS]
#
# Options:
#   --batch-size=N        Number of parallel jobs (default: 5)
#   --dry-run             Print commands without executing
# =============================================================================

DRY_RUN=false
BATCH_SIZE=5

for arg in "$@"; do
  case $arg in
    --dry-run) DRY_RUN=true ;;
    --batch-size=*) BATCH_SIZE="${arg#*=}" ;;
  esac
done

ERRORS_FILE=$(mktemp)
SUCCESS_FILE=$(mktemp)

process_site() {
  local SITE="$1"

  if [ "$DRY_RUN" = true ]; then
    echo "[DRY RUN] terminus upstream:updates:apply ${SITE}.dev --accept-upstream --yes"
    echo "[DRY RUN] terminus env:deploy ${SITE}.test --updatedb --cc --yes"
    echo "$SITE" >> "$SUCCESS_FILE"
    return 0
  fi

  echo "  Starting: $SITE"

  # Step 1: Apply upstream updates to dev
  gtimeout 300 terminus upstream:updates:apply "${SITE}.dev" --accept-upstream --yes > /tmp/log-upstream-${SITE}.txt 2>&1
  if [ $? -ne 0 ]; then
    echo "  FAILED (upstream): $SITE (see /tmp/log-upstream-${SITE}.txt)"
    echo "$SITE" >> "$ERRORS_FILE"
    return 1
  fi

  # Step 2: Deploy dev -> test
  gtimeout 300 terminus env:deploy "${SITE}.test" --updatedb --cc --yes >> /tmp/log-upstream-${SITE}.txt 2>&1
  if [ $? -ne 0 ]; then
    echo "  FAILED (deploy):   $SITE (see /tmp/log-upstream-${SITE}.txt)"
    echo "$SITE" >> "$ERRORS_FILE"
    return 1
  fi

  echo "  DONE:     $SITE"
  echo "$SITE" >> "$SUCCESS_FILE"
}

export -f process_site
export DRY_RUN ERRORS_FILE SUCCESS_FILE

SITES=(
  wustl-afas
  wustl-amcs
  wustl-anthropology
  wustl-artsciadvising
  wustl-artsciportal
  wustl-artscistage
  wustl-arthistory
  wustl-base
  wustl-biology
  wustl-buildingartsci
  wustl-chemistry
  wustl-classics
  wustl-collegewriting
  wustl-complitandthought
  wustl-ealc
  wustl-economics
  wustl-education
  wustl-eeps
  wustl-english
  wustl-enst
  wustl-fellowshipsoffice
  wustl-fms
  wustl-forms
  wustl-globalstudies
  wustl-graduation
  wustl-gradstudies
  wustl-hdw
  wustl-history
  wustl-holdthatthought
  wustl-humanities
  wustl-immersivetech
  wustl-insideartsci
  wustl-itartsci
  wustl-jimes
  wustl-johnmaxwulfing
  wustl-linguistics
  wustl-literacies
  wustl-literaryarts
  wustl-math
  wustl-mcss
  wustl-mindfulness
  wustl-movingstories
  wustl-music
  wustl-olympian
  wustl-overseas
  wustl-pad
  wustl-philosophy
  wustl-physics
  wustl-pnp
  wustl-polisci
  wustl-postbaccpremed
  wustl-precollege
  wustl-prehealth
  wustl-psych
  wustl-publichealthandsociety
  wustl-quantumleaps
  wustl-religiousstudies
  wustl-rll
  wustl-sds
  wustl-slavery
  wustl-sociology
  wustl-strategicplan
  wustl-summersession
  wustl-transdisciplinaryfutures
  wustl-triads
  wustl-undergradresearch
  wustl-wc
  wustl-wgss
)

TOTAL=${#SITES[@]}
START_TIME=$(date +%s)

echo ""
echo "========================================"
echo "Upstream updates + deploy to test ($TOTAL sites, batch size $BATCH_SIZE)"
echo "Started: $(date)"
echo "========================================"
echo ""

for ((i=0; i<TOTAL; i+=BATCH_SIZE)); do
  BATCH=("${SITES[@]:$i:$BATCH_SIZE}")
  BATCH_NUM=$(( i/BATCH_SIZE + 1 ))
  TOTAL_BATCHES=$(( (TOTAL + BATCH_SIZE - 1) / BATCH_SIZE ))

  echo "--- Batch $BATCH_NUM / $TOTAL_BATCHES ---"

  for SITE in "${BATCH[@]}"; do
    process_site "$SITE" &
  done

  wait
  echo ""
done

SUCCESS_COUNT=$(wc -l < "$SUCCESS_FILE" | tr -d ' ')
FAIL_COUNT=$(wc -l < "$ERRORS_FILE" | tr -d ' ')
END_TIME=$(date +%s)
ELAPSED=$(( END_TIME - START_TIME ))

echo "========================================"
echo "DONE: $SUCCESS_COUNT succeeded, $FAIL_COUNT failed (${ELAPSED}s elapsed)"
echo "========================================"

if [ -s "$ERRORS_FILE" ]; then
  echo ""
  echo "Failed sites (logs in /tmp/log-upstream-*.txt):"
  while IFS= read -r site; do
    echo "  [FAILED] $site"
  done < "$ERRORS_FILE"
fi

rm -f "$ERRORS_FILE" "$SUCCESS_FILE"
