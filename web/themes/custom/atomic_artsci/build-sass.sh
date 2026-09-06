#!/bin/bash
## Description: SASS Build: process scss source changes and copy them to assets folder.

# Setup PS4 for prefix of each trace line from "set -x"
PS4='$(tput setaf 3)$(tput bold)>> $(tput setaf 5)$(TZ=America/Chicago date +%H:%M:%S.%3N) $(tput setaf 2)[build-sass:${LINENO}]$(tput sgr0) '
set -x

# Run prettier on sass source
prettier "scss/**/*.scss" --write --log-level error

# Uncomment linting after code is cleaned-up/delinted.
stylelint "scss/**/*.scss"

# Clear-out the assets/css folder
del-cli "assets/css/*"

# Compile sass files
sass scss:assets/css --embed-sources --quiet-deps

# Run postcss steps (see postcss.config.js).
# (Skipping minify because Drupal does it automatically now.
# If we wanted to minify, then add this parameter to postcss: --env minifycss
# so that it runs cssnano.)
postcss "assets/css/**/*.css" --replace --map

set +x
echo "... build-sass done"
