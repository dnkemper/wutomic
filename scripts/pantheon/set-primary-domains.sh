#!/bin/bash

# =============================================================================
# set-primary-domains.sh
#
# Sets primary domains for all sites on both test and dev environments.
#
# Usage: bash set-primary-domains.sh [--dry-run]
# =============================================================================

DRY_RUN=false
for arg in "$@"; do
  case $arg in
    --dry-run) DRY_RUN=true ;;
  esac
done

run() {
  if [ "$DRY_RUN" = true ]; then
    echo "[DRY RUN] $*"
    return 0
  else
    "$@"
    return $?
  fi
}

ERRORS=()
SUCCESS=0

do_primary() {
  local SITE_ENV="$1"
  local DOMAIN="$2"
  echo -n "  $SITE_ENV -> $DOMAIN ... "
  run terminus domain:primary:add "$SITE_ENV" "$DOMAIN"
  if [ $? -ne 0 ]; then
    echo "  FAILED"
    ERRORS+=("FAILED domain:primary:add $SITE_ENV $DOMAIN")
  else
    SUCCESS=$((SUCCESS + 1))
    [ "$DRY_RUN" = false ] && echo "  OK"
  fi
}

echo "Setting primary domains for test and dev environments..."
echo ""

# FORMAT: do_primary "site.env" "subdomain.stage/dev.wustl.edu"

# TEST environments (artscistage)
do_primary "wustl-afas.test"                    "afas.artscistage.wustl.edu"
do_primary "wustl-amcs.test"                    "amcs.artscistage.wustl.edu"
do_primary "wustl-anthropology.test"            "anthropology.artscistage.wustl.edu"
do_primary "wustl-arthistory.test"              "arthistory.artscistage.wustl.edu"
do_primary "wustl-artsci.test"                  "artsci.artscistage.wustl.edu"
do_primary "wustl-artsci-nu.test"               "artsci-nu.artscistage.wustl.edu"
do_primary "wustl-artsciadvising.test"          "artsciadvising.artscistage.wustl.edu"
do_primary "wustl-artsciportal.test"            "artsciportal.artscistage.wustl.edu"
do_primary "wustl-biology.test"                 "biology.artscistage.wustl.edu"
do_primary "wustl-buildingartsci.test"          "buildingartsci.artscistage.wustl.edu"
do_primary "wustl-chemistry.test"               "chemistry.artscistage.wustl.edu"
do_primary "wustl-classics.test"                "classics.artscistage.wustl.edu"
do_primary "wustl-collegewriting.test"          "collegewriting.artscistage.wustl.edu"
do_primary "wustl-complitandthought.test"       "complitandthought.artscistage.wustl.edu"
do_primary "wustl-ealc.test"                    "ealc.artscistage.wustl.edu"
do_primary "wustl-economics.test"               "economics.artscistage.wustl.edu"
do_primary "wustl-education.test"               "education.artscistage.wustl.edu"
do_primary "wustl-eeps.test"                    "eeps.artscistage.wustl.edu"
do_primary "wustl-english.test"                 "english.artscistage.wustl.edu"
do_primary "wustl-enst.test"                    "enst.artscistage.wustl.edu"
do_primary "wustl-fellowshipsoffice.test"       "fellowshipsoffice.artscistage.wustl.edu"
do_primary "wustl-fms.test"                     "fms.artscistage.wustl.edu"
do_primary "wustl-forms.test"                   "forms.artscistage.wustl.edu"
do_primary "wustl-globalstudies.test"           "globalstudies.artscistage.wustl.edu"
do_primary "wustl-gradstudies.test"             "gradstudies.artscistage.wustl.edu"
do_primary "wustl-graduation.test"              "graduation.artscistage.wustl.edu"
do_primary "wustl-hdw.test"                     "hdw.artscistage.wustl.edu"
do_primary "wustl-history.test"                 "history.artscistage.wustl.edu"
do_primary "wustl-holdthatthought.test"         "holdthatthought.artscistage.wustl.edu"
do_primary "wustl-humanities.test"              "humanities.artscistage.wustl.edu"
do_primary "wustl-immersivetech.test"           "immersivetech.artscistage.wustl.edu"
do_primary "wustl-insideartsci.test"            "insideartsci.artscistage.wustl.edu"
do_primary "wustl-itartsci.test"                "itartsci.artscistage.wustl.edu"
do_primary "wustl-jimes.test"                   "jimes.artscistage.wustl.edu"
do_primary "wustl-johnmaxwulfing.test"          "johnmaxwulfing.artscistage.wustl.edu"
do_primary "wustl-lasprogram.test"              "lasprogram.artscistage.wustl.edu"
do_primary "wustl-literacies.test"              "literacies.artscistage.wustl.edu"
do_primary "wustl-literaryarts.test"            "literaryarts.artscistage.wustl.edu"
do_primary "wustl-linguistics.test"             "linguistics.artscistage.wustl.edu"
do_primary "wustl-math.test"                    "math.artscistage.wustl.edu"
do_primary "wustl-mcss.test"                    "mcss.artscistage.wustl.edu"
do_primary "wustl-mindfulness.test"             "mindfulness.artscistage.wustl.edu"
do_primary "wustl-movingstories.test"           "movingstories.artscistage.wustl.edu"
do_primary "wustl-music.test"                   "music.artscistage.wustl.edu"
do_primary "wustl-myprehealth.test"             "myprehealth.artscistage.wustl.edu"
do_primary "wustl-overseas.test"                "overseas.artscistage.wustl.edu"
do_primary "wustl-pad.test"                     "pad.artscistage.wustl.edu"
do_primary "wustl-philosophy.test"              "philosophy.artscistage.wustl.edu"
do_primary "wustl-physics.test"                 "physics.artscistage.wustl.edu"
do_primary "wustl-pnp.test"                     "pnp.artscistage.wustl.edu"
do_primary "wustl-polisci.test"                 "polisci.artscistage.wustl.edu"
do_primary "wustl-postbaccpremed.test"          "postbaccpremed.artscistage.wustl.edu"
do_primary "wustl-precollege.test"              "precollege.artscistage.wustl.edu"
do_primary "wustl-prehealth.test"               "prehealth.artscistage.wustl.edu"
do_primary "wustl-psych.test"                   "psych.artscistage.wustl.edu"
do_primary "wustl-publichealthandsociety.test"  "publichealthandsociety.artscistage.wustl.edu"
do_primary "wustl-quantumleaps.test"            "quantumleaps.artscistage.wustl.edu"
do_primary "wustl-religiousstudies.test"        "religiousstudies.artscistage.wustl.edu"
do_primary "wustl-rll.test"                     "rll.artscistage.wustl.edu"
do_primary "wustl-sds.test"                     "sds.artscistage.wustl.edu"
do_primary "wustl-slavery.test"                 "slavery.artscistage.wustl.edu"
do_primary "wustl-sociology.test"               "sociology.artscistage.wustl.edu"
do_primary "wustl-strategicplan.test"           "strategicplan.artscistage.wustl.edu"
do_primary "wustl-summersession.test"           "summersession.artscistage.wustl.edu"
do_primary "wustl-transdisciplinaryfutures.test" "transdisciplinaryfutures.artscistage.wustl.edu"
do_primary "wustl-triads.test"                  "triads.artscistage.wustl.edu"
do_primary "wustl-undergradresearch.test"       "undergradresearch.artscistage.wustl.edu"
do_primary "wustl-wc.test"                      "wc.artscistage.wustl.edu"
do_primary "wustl-wgss.test"                    "wgss.artscistage.wustl.edu"

# DEV environments (artscidev)
do_primary "wustl-afas.dev"                    "afas.artscidev.wustl.edu"
do_primary "wustl-amcs.dev"                    "amcs.artscidev.wustl.edu"
do_primary "wustl-anthropology.dev"            "anthropology.artscidev.wustl.edu"
do_primary "wustl-arthistory.dev"              "arthistory.artscidev.wustl.edu"
do_primary "wustl-artsci.dev"                  "artsci.artscidev.wustl.edu"
do_primary "wustl-artsci-nu.dev"               "artsci-nu.artscidev.wustl.edu"
do_primary "wustl-artsciadvising.dev"          "artsciadvising.artscidev.wustl.edu"
do_primary "wustl-artsciportal.dev"            "artsciportal.artscidev.wustl.edu"
do_primary "wustl-biology.dev"                 "biology.artscidev.wustl.edu"
do_primary "wustl-buildingartsci.dev"          "buildingartsci.artscidev.wustl.edu"
do_primary "wustl-chemistry.dev"               "chemistry.artscidev.wustl.edu"
do_primary "wustl-classics.dev"                "classics.artscidev.wustl.edu"
do_primary "wustl-collegewriting.dev"          "collegewriting.artscidev.wustl.edu"
do_primary "wustl-complitandthought.dev"       "complitandthought.artscidev.wustl.edu"
do_primary "wustl-ealc.dev"                    "ealc.artscidev.wustl.edu"
do_primary "wustl-economics.dev"               "economics.artscidev.wustl.edu"
do_primary "wustl-education.dev"               "education.artscidev.wustl.edu"
do_primary "wustl-eeps.dev"                    "eeps.artscidev.wustl.edu"
do_primary "wustl-english.dev"                 "english.artscidev.wustl.edu"
do_primary "wustl-enst.dev"                    "enst.artscidev.wustl.edu"
do_primary "wustl-fellowshipsoffice.dev"       "fellowshipsoffice.artscidev.wustl.edu"
do_primary "wustl-fms.dev"                     "fms.artscidev.wustl.edu"
do_primary "wustl-forms.dev"                   "forms.artscidev.wustl.edu"
do_primary "wustl-globalstudies.dev"           "globalstudies.artscidev.wustl.edu"
do_primary "wustl-gradstudies.dev"             "gradstudies.artscidev.wustl.edu"
do_primary "wustl-graduation.dev"              "graduation.artscidev.wustl.edu"
do_primary "wustl-hdw.dev"                     "hdw.artscidev.wustl.edu"
do_primary "wustl-history.dev"                 "history.artscidev.wustl.edu"
do_primary "wustl-holdthatthought.dev"         "holdthatthought.artscidev.wustl.edu"
do_primary "wustl-humanities.dev"              "humanities.artscidev.wustl.edu"
do_primary "wustl-immersivetech.dev"           "immersivetech.artscidev.wustl.edu"
do_primary "wustl-insideartsci.dev"            "insideartsci.artscidev.wustl.edu"
do_primary "wustl-itartsci.dev"                "itartsci.artscidev.wustl.edu"
do_primary "wustl-jimes.dev"                   "jimes.artscidev.wustl.edu"
do_primary "wustl-johnmaxwulfing.dev"          "johnmaxwulfing.artscidev.wustl.edu"
do_primary "wustl-lasprogram.dev"              "lasprogram.artscidev.wustl.edu"
do_primary "wustl-literacies.dev"              "literacies.artscidev.wustl.edu"
do_primary "wustl-literaryarts.dev"            "literaryarts.artscidev.wustl.edu"
do_primary "wustl-linguistics.dev"             "linguistics.artscidev.wustl.edu"
do_primary "wustl-math.dev"                    "math.artscidev.wustl.edu"
do_primary "wustl-mcss.dev"                    "mcss.artscidev.wustl.edu"
do_primary "wustl-mindfulness.dev"             "mindfulness.artscidev.wustl.edu"
do_primary "wustl-movingstories.dev"           "movingstories.artscidev.wustl.edu"
do_primary "wustl-music.dev"                   "music.artscidev.wustl.edu"
do_primary "wustl-myprehealth.dev"             "myprehealth.artscidev.wustl.edu"
do_primary "wustl-overseas.dev"                "overseas.artscidev.wustl.edu"
do_primary "wustl-pad.dev"                     "pad.artscidev.wustl.edu"
do_primary "wustl-philosophy.dev"              "philosophy.artscidev.wustl.edu"
do_primary "wustl-physics.dev"                 "physics.artscidev.wustl.edu"
do_primary "wustl-pnp.dev"                     "pnp.artscidev.wustl.edu"
do_primary "wustl-polisci.dev"                 "polisci.artscidev.wustl.edu"
do_primary "wustl-postbaccpremed.dev"          "postbaccpremed.artscidev.wustl.edu"
do_primary "wustl-precollege.dev"              "precollege.artscidev.wustl.edu"
do_primary "wustl-prehealth.dev"               "prehealth.artscidev.wustl.edu"
do_primary "wustl-psych.dev"                   "psych.artscidev.wustl.edu"
do_primary "wustl-publichealthandsociety.dev"  "publichealthandsociety.artscidev.wustl.edu"
do_primary "wustl-quantumleaps.dev"            "quantumleaps.artscidev.wustl.edu"
do_primary "wustl-religiousstudies.dev"        "religiousstudies.artscidev.wustl.edu"
do_primary "wustl-rll.dev"                     "rll.artscidev.wustl.edu"
do_primary "wustl-sds.dev"                     "sds.artscidev.wustl.edu"
do_primary "wustl-slavery.dev"                 "slavery.artscidev.wustl.edu"
do_primary "wustl-sociology.dev"               "sociology.artscidev.wustl.edu"
do_primary "wustl-strategicplan.dev"           "strategicplan.artscidev.wustl.edu"
do_primary "wustl-summersession.dev"           "summersession.artscidev.wustl.edu"
do_primary "wustl-transdisciplinaryfutures.dev" "transdisciplinaryfutures.artscidev.wustl.edu"
do_primary "wustl-triads.dev"                  "triads.artscidev.wustl.edu"
do_primary "wustl-undergradresearch.dev"       "undergradresearch.artscidev.wustl.edu"
do_primary "wustl-wc.dev"                      "wc.artscidev.wustl.edu"
do_primary "wustl-wgss.dev"                    "wgss.artscidev.wustl.edu"

echo ""
echo "========================================"
echo "DONE: $SUCCESS commands succeeded"
echo "========================================"

if [ ${#ERRORS[@]} -gt 0 ]; then
  echo ""
  echo "The following failed:"
  for err in "${ERRORS[@]}"; do
    echo "  [FAILED] $err"
  done
fi
