#!/bin/bash

# =============================================================================
# import-prod-to-dev.sh
# Clones the live (prod) environment database & files into dev in parallel batches.
#
# Usage: bash import-prod-to-dev.sh [OPTIONS]
#
# Options:
#   --batch-size=N        Number of parallel imports (default: 5)
#   --files               Also sync files (default: db only)
#   --dry-run             Print commands without executing
# =============================================================================

DRY_RUN=false
BATCH_SIZE=5
SYNC_FILES=false

for arg in "$@"; do
  case $arg in
    --dry-run) DRY_RUN=true ;;
    --batch-size=*) BATCH_SIZE="${arg#*=}" ;;
    --files) SYNC_FILES=true ;;
  esac
done

ERRORS_FILE=$(mktemp)
SUCCESS_FILE=$(mktemp)

import_prod_to_dev() {
  local SITE="$1"
  local SITE_ENV="${SITE}.dev"

  if [ "$DRY_RUN" = true ]; then
    echo "[DRY RUN] terminus env:clone-content ${SITE}.live dev --db-only"
    [ "$SYNC_FILES" = true ] && echo "[DRY RUN] terminus env:clone-content ${SITE}.live ${SITE}.dev --files-only"
    echo "$SITE_ENV" >> "$SUCCESS_FILE"
    return 0
  fi

  echo "  Starting: $SITE (live -> dev)"

  gtimeout 600 terminus env:clone-content "${SITE}.live" dev --db-only > /tmp/log-import-${SITE}.txt 2>&1
  if [ $? -ne 0 ]; then
    echo "  FAILED (db):  $SITE (see /tmp/log-import-${SITE}.txt)"
    echo "$SITE_ENV" >> "$ERRORS_FILE"
    return 1
  fi

  if [ "$SYNC_FILES" = true ]; then
    gtimeout 600 terminus env:clone-content "${SITE}.live" dev --files-only >> /tmp/log-import-${SITE}.txt 2>&1
    if [ $? -ne 0 ]; then
      echo "  FAILED (files): $SITE (see /tmp/log-import-${SITE}.txt)"
      echo "$SITE_ENV" >> "$ERRORS_FILE"
      return 1
    fi
  fi

  echo "  DONE:     $SITE"
  echo "$SITE_ENV" >> "$SUCCESS_FILE"
}

export -f import_prod_to_dev
export DRY_RUN SYNC_FILES ERRORS_FILE SUCCESS_FILE

SITES=(
  "wustl-artsciportal"
  "wustl-artsci"
  "wustl-artsci-nu"
  "wustl-base"
  "wustl-biology"
  "wustl-eeps"
  "wustl-humanities"
  "washu-humanities"
  "washu"
  "wustl-insideartsci"
  "wustl-itartsci"
  "wustl-afas"
  "wustl-anthropology"
  "wustl-buildingartsci"
  "wustl-chemistry"
  "wustl-classics"
  "wustl-ealc"
  "wustl-graduation"
  "wustl-holdthatthought"
  "wustl-jimes"
  "wustl-rll"
  "wustl-slavery"
  "wustl-amcs"
  "wustl-arthistory"
  "wustl-artsciadvising"
  "wustl-complitandthought"
  "wustl-education"
  "wustl-enst"
  "wustl-fms"
  "wustl-globalstudies"
  "wustl-hdw"
  "wustl-history"
  "wustl-pad"
  "wustl-precollege"
  "wustl-psych"
  "wustl-wc"
  "wustl-collegewriting"
  "wustl-fellowshipsoffice"
  "wustl-immersivetech"
  "wustl-linguistics"
  "wustl-literacies"
  "wustl-literaryarts"
  "wustl-movingstories"
  "wustl-music"
  "wustl-overseas"
  "wustl-philosophy"
  "wustl-pnp"
  "wustl-prehealth"
  "wustl-myprehealth"
  "wustl-publichealthandsociety"
  "wustl-quantumleaps"
  "wustl-religiousstudies"
  "wustl-sociology"
  "wustl-summersession"
  "wustl-transdisciplinaryfutures"
  "wustl-triads"
  "wustl-polisci"
  "wustl-johnmaxwulfing"
  "wustl-artscistage"
  "wustl-economics"
  "wustl-english"
  "wustl-gradstudies"
  "wustl-math"
  "wustl-mcss"
  "wustl-mindfulness"
  "wustl-physics"
  "wustl-postbaccpremed"
  "wustl-sds"
  "wustl-strategicplan"
  "wustl-undergradresearch"
  "wustl-wgss"
  "wustl-forms"
  "wustl-lasprogram"
)

TOTAL=${#SITES[@]}
START_TIME=$(date +%s)

echo ""
echo "========================================"
echo "Importing live -> dev ($TOTAL sites, batch size $BATCH_SIZE)"
[ "$SYNC_FILES" = true ] && echo "Mode: DB + Files" || echo "Mode: DB only"
echo "Started: $(date)"
echo "========================================"
echo ""

for ((i=0; i<TOTAL; i+=BATCH_SIZE)); do
  BATCH=("${SITES[@]:$i:$BATCH_SIZE}")
  BATCH_NUM=$(( i/BATCH_SIZE + 1 ))
  TOTAL_BATCHES=$(( (TOTAL + BATCH_SIZE - 1) / BATCH_SIZE ))

  echo "--- Batch $BATCH_NUM / $TOTAL_BATCHES ---"

  for SITE in "${BATCH[@]}"; do
    import_prod_to_dev "$SITE" &
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
  echo "Failed imports (logs in /tmp/log-import-*.txt):"
  while IFS= read -r site; do
    echo "  [FAILED] $site"
  done < "$ERRORS_FILE"
fi

rm -f "$ERRORS_FILE" "$SUCCESS_FILE"
