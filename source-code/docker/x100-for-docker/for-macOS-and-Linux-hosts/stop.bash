#!/usr/bin/env bash

cd "$(dirname "$BASH_SOURCE")"

export dockerAutoUpdateLockFile="$(pwd)/../put-your-ovpn-files-here/docker-auto-update.lock"

echo "9" > "$dockerAutoUpdateLockFile"