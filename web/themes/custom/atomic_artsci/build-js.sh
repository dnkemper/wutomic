#!/bin/bash
## Description: Javascript Build: process js source changes and copy them to assets folder.

# Setup PS4 for prefix of each trace line from "set -x"
PS4='$(tput setaf 3)$(tput bold)>> $(tput setaf 5)$(TZ=America/Chicago date +%H:%M:%S.%3N) $(tput setaf 2)[build-js:${LINENO}]$(tput sgr0) '
set -x

# No need to minify or concat because Drupal does them automatically now.
# (If we needed to minify, we can use Babel to do it.  See the package.json on deps repo.)

# Run linter on source .js
eslint "js/**/*.js"

# Refresh the assets/js folder
del-cli "assets/js/**/*"
copy-folder js assets/js --summary

set +x
echo "... build-js done"
