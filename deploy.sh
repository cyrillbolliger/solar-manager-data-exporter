#!/usr/bin/env bash

set -euo pipefail

rsync -avzr --delete --files-from=deploy.txt . $1:$2