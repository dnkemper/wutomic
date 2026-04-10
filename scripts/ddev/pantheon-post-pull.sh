#!/bin/bash
## Description: Run sync to sync down a database and files to your local environment.
## Usage: pantheon-post-pull [environment] [site_name]

NOW=$(TZ=America/Chicago date +"%Y-%m-%d-%T")
red=$(tput setaf 1)$(tput bold)
green=$(tput setaf 2)
yellow=$(tput setaf 3)$(tput bold)
blue=$(tput setaf 4)
magenta=$(tput setaf 5)$(tput bold)
NC=$(tput sgr0) # no color; turn off all attributes

# Show info about this script, and setup PS4 for prefix of each trace line from "set -x"
echo -e "\n\n${yellow}>>>>>${NC} ${magenta}${NOW}${NC} ${green}Running Pantheon Post Pull Script${NC} ${yellow}<<<<<${NC}\n"
PS4='${yellow}>>${NC} ${magenta}$(TZ=America/Chicago date +%H:%M:%S.%3N)${NC} ${green}(${LINENO}) ${FUNCNAME[0]:+${FUNCNAME[0]}(): }${NC}'
#terminus site:list --field=Name --format=list | grep -i "wustl-" | sort
set +x; echo -e "${blue}Running database updates${NC}"; set -x
drush updb --yes
set +x; echo -e "${blue}Updating Composer packages${NC}"; set -x
composer update --lock
composer install
set +x; echo -e "${blue}Enabling local config split${NC}"; set -x
drush php-eval 'field_purge_batch(1000);'
drush config-split:activate local --yes
set +x; echo -e "${blue}Importing local configuration${NC}"; set -x
drush cim --source=../config/default --yes
set +x; echo -e "${blue}Clearing Drupal cache and setting development mode${NC}"; set -x
drush php:eval "\Drupal::keyValue('development_settings')->setMultiple(['disable_rendered_output_cache_bins' => TRUE, 'twig_debug' => TRUE, 'twig_cache_disable' => TRUE]);"
drush cr
set +x; echo -e "${blue}Opening local environment in a web browser${NC}"; set -x
drush uli --uri=https://default.ddev.site/
