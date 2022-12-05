#!/usr/bin/env bash

cd "$(dirname "$BASH_SOURCE")"

#Install required packages
apt -y  update
apt -y  install  super util-linux procps kmod iputils-ping mc htop php-cli php-mbstring php-curl curl openvpn

# Set Timezone
ln  -sf /usr/share/zoneinfo/Europe/Kiev /etc/localtime

# Create users
/usr/sbin/addgroup  --system  app-h
/usr/sbin/adduser   --system  app-h  --ingroup app-h
/usr/sbin/addgroup            user
/usr/sbin/adduser             user      --ingroup user

# Make /root available to change dir for all
chmod o+x /root

# Install Ookla speedtest CLI
apt-get remove speedtest-cli
curl -O https://install.speedtest.net/app/cli/install.deb.sh
chmod u+x ./install.deb.sh
./install.deb.sh
rm ./install.deb.sh
mkdir /etc/apt/keyrings
cp /usr/share/keyrings/*ookla* /etc/apt/keyrings
apt update
apt -y install speedtest

#Register SourceGuardian PHP extension
echo "extension=/root/x100/source-guardian-loaders/$(uname -m)-ixed.7.4.lin" > /etc/php/7.4/mods-available/ixed.ini
ln -s /etc/php/7.4/mods-available/ixed.ini  /etc/php/7.4/cli/conf.d/01-ixed.ini