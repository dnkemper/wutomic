#!/bin/bash

# =============================================================================
# backup-prod-db.sh
# Creates on-demand database backups for all live (prod) environments
# in parallel batches.
#
# Usage: bash backup-prod-db.sh [OPTIONS]
#
# Options:
#   --batch-size=N        Number of parallel backups (default: 5)
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

backup_site() {
  local SITE="$1"
  local SITE_ENV="${SITE}.live"

  if [ "$DRY_RUN" = true ]; then
    echo "[DRY RUN] terminus backup:create $SITE_ENV --element=db"
    echo "$SITE_ENV" >> "$SUCCESS_FILE"
    return 0
  fi

  echo "  Starting: $SITE_ENV"
  gtimeout 600 terminus backup:create "$SITE_ENV" --element=db > /tmp/log-backup-${SITE}.txt 2>&1
  if [ $? -ne 0 ]; then
    echo "  FAILED:   $SITE_ENV (see /tmp/log-backup-${SITE}.txt)"
    echo "$SITE_ENV" >> "$ERRORS_FILE"
  else
    echo "  DONE:     $SITE_ENV"
    echo "$SITE_ENV" >> "$SUCCESS_FILE"
  fi
}

export -f backup_site
export DRY_RUN ERRORS_FILE SUCCESS_FILE

SITES=(
  "wustl-artsciportal"
  "wustl-biology"
  "wustl-eeps"
  "wustl-humanities"
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
  "wustl-publichealthandsociety"
  "wustl-quantumleaps"
  "wustl-religiousstudies"
  "wustl-sociology"
  "wustl-summersession"
  "wustl-transdisciplinaryfutures"
  "wustl-triads"
  "wustl-polisci"
  "wustl-johnmaxwulfing"
  "wustl-olympian"
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
)

TOTAL=${#SITES[@]}
START_TIME=$(date +%s)

echo ""
echo "========================================"
echo "Backing up live DB ($TOTAL sites, batch size $BATCH_SIZE)"
echo "Started: $(date)"
echo "========================================"
echo ""

for ((i=0; i<TOTAL; i+=BATCH_SIZE)); do
  BATCH=("${SITES[@]:$i:$BATCH_SIZE}")
  BATCH_NUM=$(( i/BATCH_SIZE + 1 ))
  TOTAL_BATCHES=$(( (TOTAL + BATCH_SIZE - 1) / BATCH_SIZE ))

  echo "--- Batch $BATCH_NUM / $TOTAL_BATCHES ---"

  for SITE in "${BATCH[@]}"; do
    backup_site "$SITE" &
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
  echo "Failed backups (logs in /tmp/log-backup-*.txt):"
  while IFS= read -r site; do
    echo "  [FAILED] $site"
  done < "$ERRORS_FILE"
fi

rm -f "$ERRORS_FILE" "$SUCCESS_FILE"
