#!/usr/bin/env bash

if [[ $EUID -ne 0 ]]; then
   echo "This script must be run as root"
   exit 1
fi

x100Root="/root/x100"

apt -y  update
apt -y  upgrade

apt -y install git lsb-release

if [ $(lsb_release -is) = "Debian" ]; then
  apt -y install  apt-transport-https ca-certificates wget
  wget -O /etc/apt/trusted.gpg.d/php.gpg https://packages.sury.org/php/apt.gpg
  chown _apt /etc/apt/trusted.gpg.d/php.gpg
  lsbRelease=$(lsb_release -sc)
  echo "deb [ signed=/etc/apt/trusted.gpg.d/php.gpg ]  https://packages.sury.org/php/ $lsbRelease main" > /etc/apt/sources.list.d/php.list
else
  #Ubuntu
  apt -y install  apt-transport-https ca-certificates software-properties-common
  add-apt-repository ppa:ondrej/php
fi

rm -rf $x100Root
mkdir  $x100Root
mkdir  $x100Root/git
cd     $x100Root/git

git clone https://github.com/ihorlv/db1000nX100
mv -f ./db1000nX100/source-code/*  /root/x100

# Set sysctl options
cat "$x100Root/docker/x100-for-docker/for-macOS-and-Linux-hosts/sysctl-settings.txt" | while read line || [[ -n $line ]];
do
   echo "$line" >> /etc/sysctl.conf
done
sysctl -p
#

cd $x100Root/scripts
/usr/bin/env bash  ./install-x100slim-components-into-ubuntu.bash
/usr/bin/env bash  ./fix-permissions.bash