#!/bin/bash

# Show info about this script, and setup PS4 for prefix of each trace line from "set -x"
echo -e "\n\n$(tput setaf 3)$(tput bold)>>>>> $(tput setaf 5)$(TZ=America/Chicago date +"%Y-%m-%d-%T") $(tput setaf 2)Running ${BASH_SOURCE} $(tput setaf 3)<<<<<$(tput sgr0)\n"
PS4='$(tput setaf 3)$(tput bold)>> $(tput setaf 5)$(TZ=America/Chicago date +%H:%M:%S.%3N) $(tput setaf 2)[rebuild:${LINENO}]$(tput sgr0) '
set -x

# Make sure node packages are installed
# (no need to cd to specific folder; workspaces setting in root package.json tells yarn what it needs)
yarn

# Build css and js assests on the theme workspace.
# Note: Workspace name is from workspace-level package.json: olympian (not olympian9).
yarn workspace olympian run build
# Also build assets on module olympian_core workspace.
yarn workspace olympian_core run build

set +x
echo -e "\n\n$(tput setaf 3)$(tput bold)>>>>> $(tput setaf 5)$(TZ=America/Chicago date +"%Y-%m-%d-%T") $(tput setaf 2)Finished ${BASH_SOURCE} $(tput setaf 3)<<<<<$(tput sgr0)\n"
