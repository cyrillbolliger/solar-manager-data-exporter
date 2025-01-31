#!/usr/bin/env bash

set -euo pipefail

# Check if the correct number of arguments are provided
if [[ $# -ne 2 ]]; then
  echo "Usage: $0 <max_age_sec> <mailto>"
  exit 1
fi

MAX_AGE_SEC=$1
MAILTO=$2

# switch into current dir
cd "$(dirname "${BASH_SOURCE[0]}")"

seconds_since_latest_update=$(($(date +%s) - $(date -d $(./cli --latest) +%s)))

if [[ $seconds_since_latest_update -gt $MAX_AGE_SEC ]]; then
	echo "$(pwd): No new data since $(($MAX_AGE_SEC / (60 * 60)))h. Check logs" | mail -s "Alert: no new data" $MAILTO
fi