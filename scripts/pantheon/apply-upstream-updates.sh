#!/bin/bash

# =============================================================================
# apply-upstream-updates.sh
# Applies upstream updates to all sites on dev in parallel batches.
# Usage: bash apply-upstream-updates.sh [--dry-run] [--batch-size=N]
# Default batch size is 5. Increase if Pantheon isn't rate limiting you.
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

apply() {
  local SITE_ENV="$1"
  if [ "$DRY_RUN" = true ]; then
    echo "[DRY RUN] terminus upstream:updates:apply $SITE_ENV --accept-upstream --updatedb"
    echo "$SITE_ENV" >> "$SUCCESS_FILE"
    return 0
  fi

  echo "  Starting: $SITE_ENV"
  gtimeout 300 terminus upstream:updates:apply "$SITE_ENV" --accept-upstream --updatedb > /tmp/log-${SITE_ENV//\//-}.txt 2>&1
  if [ $? -ne 0 ]; then
    echo "  FAILED:   $SITE_ENV (see /tmp/log-${SITE_ENV//\//-}.txt)"
    echo "$SITE_ENV" >> "$ERRORS_FILE"
  else
    echo "  DONE:     $SITE_ENV"
    echo "$SITE_ENV" >> "$SUCCESS_FILE"
  fi
}

export -f apply
export DRY_RUN ERRORS_FILE SUCCESS_FILE

SITES=(
  "wustl-artsciportal.dev"
  "wustl-biology.dev"
  "wustl-eeps.dev"
  "wustl-humanities.dev"
  "wustl-insideartsci.dev"
  "wustl-itartsci.dev"
  "wustl-afas.dev"
  "wustl-anthropology.dev"
  "wustl-buildingartsci.dev"
  "wustl-chemistry.dev"
  "wustl-classics.dev"
  "wustl-ealc.dev"
  "wustl-graduation.dev"
  "wustl-holdthatthought.dev"
  "wustl-jimes.dev"
  "wustl-rll.dev"
  "wustl-slavery.dev"
  "wustl-amcs.dev"
  "wustl-arthistory.dev"
  "wustl-artsciadvising.dev"
  "wustl-complitandthought.dev"
  "wustl-education.dev"
  "wustl-enst.dev"
  "wustl-fms.dev"
  "wustl-globalstudies.dev"
  "wustl-hdw.dev"
  "wustl-history.dev"
  "wustl-pad.dev"
  "wustl-precollege.dev"
  "wustl-psych.dev"
  "wustl-wc.dev"
  "wustl-collegewriting.dev"
  "wustl-fellowshipsoffice.dev"
  "wustl-immersivetech.dev"
  "wustl-linguistics.dev"
  "wustl-literacies.dev"
  "wustl-literaryarts.dev"
  "wustl-movingstories.dev"
  "wustl-music.dev"
  "wustl-overseas.dev"
  "wustl-philosophy.dev"
  "wustl-pnp.dev"
  "wustl-prehealth.dev"
  "wustl-publichealthandsociety.dev"
  "wustl-quantumleaps.dev"
  "wustl-religiousstudies.dev"
  "wustl-sociology.dev"
  "wustl-summersession.dev"
  "wustl-transdisciplinaryfutures.dev"
  "wustl-triads.dev"
  "wustl-polisci.dev"
  "wustl-johnmaxwulfing.dev"
  "wustl-olympian.dev"
  "wustl-artscistage.dev"
  "wustl-economics.dev"
  "wustl-english.dev"
  "wustl-gradstudies.dev"
  "wustl-math.dev"
  "wustl-mcss.dev"
  "wustl-mindfulness.dev"
  "wustl-physics.dev"
  "wustl-postbaccpremed.dev"
  "wustl-sds.dev"
  "wustl-strategicplan.dev"
  "wustl-undergradresearch.dev"
  "wustl-wgss.dev"
)

TOTAL=${#SITES[@]}
echo "Applying upstream updates to $TOTAL sites in batches of $BATCH_SIZE..."
echo ""

# Process in batches
for ((i=0; i<TOTAL; i+=BATCH_SIZE)); do
  BATCH=("${SITES[@]:$i:$BATCH_SIZE}")
  BATCH_NUM=$(( i/BATCH_SIZE + 1 ))
  TOTAL_BATCHES=$(( (TOTAL + BATCH_SIZE - 1) / BATCH_SIZE ))

  echo "--- Batch $BATCH_NUM / $TOTAL_BATCHES ---"

  # Launch batch in parallel
  for SITE in "${BATCH[@]}"; do
    apply "$SITE" &
  done

  # Wait for entire batch to finish before starting next
  wait
  echo ""
done

SUCCESS_COUNT=$(wc -l < "$SUCCESS_FILE" | tr -d ' ')
FAIL_COUNT=$(wc -l < "$ERRORS_FILE" | tr -d ' ')

echo "========================================"
echo "DONE: $SUCCESS_COUNT succeeded, $FAIL_COUNT failed"
echo "========================================"

if [ -s "$ERRORS_FILE" ]; then
  echo ""
  echo "Failed sites (logs in /tmp/log-*.txt):"
  while IFS= read -r site; do
    echo "  [FAILED] $site"
  done < "$ERRORS_FILE"
fi

rm -f "$ERRORS_FILE" "$SUCCESS_FILE"
