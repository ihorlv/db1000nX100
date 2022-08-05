#!/usr/bin/env bash

cd "$(dirname "$BASH_SOURCE")"

#Install required packages
apt -y  update
apt -y  install  super util-linux procps kmod iputils-ping mc htop php-cli php-mbstring php-curl curl openvpn

# Set Timezone
ln  -sf /usr/share/zoneinfo/Europe/Kiev /etc/localtime

# Create users
/usr/sbin/addgroup  --system  hack-app
/usr/sbin/adduser   --system  hack-app  --ingroup hack-app
/usr/sbin/addgroup            user
/usr/sbin/adduser             user      --ingroup user

# Make /root available to change dir for all
chmod o+x /root

# Install Ookla speedtest CLI
apt-get remove speedtest-cli
curl -O https://install.speedtest.net/app/cli/install.deb.sh
chmod u+x ./install.deb.sh
./install.deb.sh
ln -s /usr/share/keyrings /etc/apt/keyrings
apt update
apt -y install speedtest
rm ./install.deb.sh