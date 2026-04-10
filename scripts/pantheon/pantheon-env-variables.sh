#!/bin/bash

GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

printf "${BLUE}=== Set Secrets on All Pantheon Sites ===${NC}\n\n"

# Array to store secrets
declare -a SECRET_NAMES
declare -a SECRET_VALUES

# Collect secrets
while true; do
  printf "${GREEN}Enter secret name (or press Enter to finish):${NC}\n"
  read SECRET_NAME
  
  if [ -z "$SECRET_NAME" ]; then
    if [ ${#SECRET_NAMES[@]} -eq 0 ]; then
      printf "${YELLOW}No secrets provided. Exiting.${NC}\n"
      exit 0
    fi
    break
  fi
  
  printf "${GREEN}Enter value for $SECRET_NAME:${NC}\n"
  read SECRET_VALUE
  
  if [ -z "$SECRET_VALUE" ]; then
    printf "${RED}Value cannot be empty. Skipping.${NC}\n"
    continue
  fi
  
  SECRET_NAMES+=("$SECRET_NAME")
  SECRET_VALUES+=("$SECRET_VALUE")
  
  printf "${GREEN}✓ Added $SECRET_NAME${NC}\n\n"
done

# Ask for secret type
printf "\n${GREEN}Select secret type:${NC}\n"
echo "1) runtime (default)"
echo "2) env"
echo "3) file"
read -p "Select (1-3, default=2): " TYPE_CHOICE

case $TYPE_CHOICE in
  2) SECRET_TYPE="env" ;;
  3) SECRET_TYPE="file" ;;
  *) SECRET_TYPE="runtime" ;;
esac

# Ask for secret scope
printf "\n${GREEN}Select secret scope:${NC}\n"
echo "1) user (default)"
echo "2) web"
echo "3) ic (Integrated Composer)"
echo "4) user,web"
echo "5) user,ic"
echo "6) web,ic"
echo "7) user,web,ic"
read -p "Select (1-7, default=4): " SCOPE_CHOICE

case $SCOPE_CHOICE in
  2) SECRET_SCOPE="web" ;;
  3) SECRET_SCOPE="ic" ;;
  4) SECRET_SCOPE="user,web" ;;
  5) SECRET_SCOPE="user,ic" ;;
  6) SECRET_SCOPE="web,ic" ;;
  7) SECRET_SCOPE="user,web,ic" ;;
  *) SECRET_SCOPE="user" ;;
esac

# Ask if they want environment-specific overrides
printf "\n${GREEN}Do you want to set environment-specific values?${NC}\n"
echo "1) No, use same value for all environments (site-level secret)"
echo "2) Yes, I'll set different values per environment"
read -p "Select (1-2, default=1): " ENV_OVERRIDE_CHOICE

if [ "$ENV_OVERRIDE_CHOICE" = "2" ]; then
  SET_ENV_OVERRIDES=true
  
  # Select environments
  printf "\n${GREEN}Which environments?${NC}\n"
  echo "1) dev only"
  echo "2) test only"
  echo "3) live only"
  echo "4) dev and test"
  echo "5) All (dev, test, live)"
  read -p "Select (1-5): " ENV_CHOICE

  case $ENV_CHOICE in
    1) ENVIRONMENTS="dev" ;;
    2) ENVIRONMENTS="test" ;;
    3) ENVIRONMENTS="live" ;;
    4) ENVIRONMENTS="dev test" ;;
    5) ENVIRONMENTS="dev test live" ;;
    *) ENVIRONMENTS="dev test live" ;;
  esac
else
  SET_ENV_OVERRIDES=false
fi

# Get all sites
printf "\n${BLUE}Fetching all sites...${NC}\n"
SITES=$(terminus site:list --format=list --field=name 2>/dev/null)
SITE_COUNT=$(echo "$SITES" | wc -w | tr -d ' ')

printf "${YELLOW}Found $SITE_COUNT sites${NC}\n"

# Display summary
printf "\n${YELLOW}Summary:${NC}\n"
echo "  Sites: ALL ($SITE_COUNT sites)"
echo "  Secret type: $SECRET_TYPE"
echo "  Secret scope: $SECRET_SCOPE"
if [ "$SET_ENV_OVERRIDES" = true ]; then
  echo "  Environment overrides: Yes (for $ENVIRONMENTS)"
else
  echo "  Environment overrides: No (site-level only)"
fi
echo "  Secrets to set:"
for i in "${!SECRET_NAMES[@]}"; do
  echo "    ${SECRET_NAMES[$i]} = ${SECRET_VALUES[$i]}"
done
echo ""

read -p "Continue? (y/N): " CONFIRM
if [[ ! $CONFIRM =~ ^[Yy]$ ]]; then
  echo "Cancelled."
  exit 0
fi

# Set the secrets
printf "\n${BLUE}Setting secrets...${NC}\n\n"

CURRENT_SITE=0

for site in $SITES; do
  ((CURRENT_SITE++))
  printf "${BLUE}[%d/%d] Processing %s...${NC}\n" "$CURRENT_SITE" "$SITE_COUNT" "$site"
  
  # Set each secret
  for i in "${!SECRET_NAMES[@]}"; do
    SECRET_NAME="${SECRET_NAMES[$i]}"
    SECRET_VALUE="${SECRET_VALUES[$i]}"
    
    if [ "$SET_ENV_OVERRIDES" = true ]; then
      # Set site-level secret first (required for env overrides)
      printf "  %s (site-level)... " "$SECRET_NAME"
      if terminus secret:site:set $site "$SECRET_NAME" "$SECRET_VALUE" --type="$SECRET_TYPE" --scope="$SECRET_SCOPE" 2>/dev/null 1>/dev/null; then
        printf "${GREEN}✓${NC}\n"
        
        # Then set environment overrides
        for env in $ENVIRONMENTS; do
          # Check if environment exists
          if terminus env:info $site.$env 2>/dev/null 1>/dev/null; then
            printf "  %s (%s override)... " "$SECRET_NAME" "$env"
            
            # For env overrides, don't pass type and scope (they're immutable)
            if terminus secret:site:set $site.$env "$SECRET_NAME" "$SECRET_VALUE" 2>/dev/null 1>/dev/null; then
              printf "${GREEN}✓${NC}\n"
            else
              printf "${RED}✗${NC}\n"
            fi
          fi
        done
      else
        printf "${RED}✗${NC}\n"
      fi
    else
      # Just set site-level secret
      printf "  %s... " "$SECRET_NAME"
      if terminus secret:site:set $site "$SECRET_NAME" "$SECRET_VALUE" --type="$SECRET_TYPE" --scope="$SECRET_SCOPE" 2>/dev/null 1>/dev/null; then
        printf "${GREEN}✓${NC}\n"
      else
        printf "${RED}✗${NC}\n"
      fi
    fi
  done
  echo ""
done

printf "${GREEN}Done! Secrets set on all sites.${NC}\n"
printf "\n${BLUE}To verify, run:${NC}\n"
printf "  terminus secret:site:list <site>\n"
