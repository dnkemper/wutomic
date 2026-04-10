#!/bin/bash

# Symlinks the simplesamlphp directories for cert, config, and metadata
# so our WashU specific changes are not overwritten when we update simplesamlphp
# Written by Steve Luongo - 2023/11/06

Red='\033[0;31m'          # Red
Green='\033[0;32m'        # Green
Yellow='\033[0;33m'       # Yellow
NC='\033[0m'              # No Color

printf "Preserving the cert, config, and metadata simplesamlphp symlinks... \n"

# Run our checks from the simplesaml vendor dir
cd vendor/simplesamlphp/simplesamlphp

# Symlink cert dir
printf "Checking if vendor/simplesamlphp/simplesamlphp/cert directory exists... \n"
if test -d cert; then
  printf "Cert directory exists; removing cert directory and creating symlink to /simplesaml_config/cert. \n"
  mv cert cert.daa
  printf "${Green}Symlinking simplesaml_config/cert directory${NC} \n"
  ln -s /exports/nfsdrupal/all_drupal/config/simplesamlphp/cert cert
fi

# Symlink config dir
printf "Checking if vendor/simplesamlphp/simplesamlphp/config directory exists... \n"
if test -d config; then
  printf "Config directory exists; removing config directory and creating symlink to /simplesaml_config/config. \n"
  mv config config.daa
  printf "${Green}Symlinking simplesaml_config/config directory${NC} \n"
  ln -s /exports/nfsdrupal/all_drupal/config/simplesamlphp/config config
fi

# Symlink metadata dir
printf "Checking if vendor/simplesamlphp/simplesamlphp/metadata directory exists... \n"
if test -d metadata; then
  printf "Metadata directory exists; removing config directory and creating symlink to /simplesaml_config/metadata. \n"
  mv metadata metadata.daa
  printf "${Green}Symlinking simplesaml_config/metadata directory${NC} \n"
  ln -s /exports/nfsdrupal/all_drupal/config/simplesamlphp/metadata metadata
fi

# Create logs dir dir
printf "Checking if vendor/simplesamlphp/simplesamlphp/logs directory exists... \n"
if test ! -d logs; then
  printf "${Green}logs directory does not exist, creating logs directory${NC} \n"
  mkdir logs
fi

# Make sure web/simplesaml symlink exists
printf "Checking if web/simplesaml symlink exists... \n"
cd ../../../web
if [ ! -e simplesaml ] ; then
    printf "${Green}Creating symlink as web/simplesaml to ../vendor/simplesamlphp/simplesamlphp/public${NC} \n"
 ln -s ../vendor/simplesamlphp/simplesamlphp/public simplesaml
fi
