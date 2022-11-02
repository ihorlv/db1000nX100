#!/usr/bin/env bash

if [[ $EUID -ne 0 ]]; then
   echo "This script must be run as root"
   exit 1
fi

rm -rf /root/X100
mkdir  /root/X100
mkdir  /root/X100/git
cd     /root/X100/git

git clone https://github.com/ihorlv/db1000nX100
mv -f ./db1000nX100/source-code/*  /root/X100
rm -rf /root/X100/git

cd    /root/X100/scripts
/usr/bin/env bash  ./install-x100slim-components-into-linux.bash
/usr/bin/env bash  ./fix-permissions.bash



