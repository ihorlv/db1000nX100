#!/usr/bin/env bash

cd "$(dirname "$BASH_SOURCE")"

# Set Timezone
ln  -sf /usr/share/zoneinfo/Europe/Kiev /etc/localtime

#Install required packages
apt -y  update
apt -y  upgrade
apt -y  install  super util-linux procps kmod iputils-ping mc htop php-cli php-mbstring php-curl curl openvpn

# Extend available ports range
echo 'net.ipv4.ip_local_port_range=1024 65535' >> /etc/sysctl.conf

# Create users
/usr/sbin/addgroup  --system  app-h
/usr/sbin/adduser   --system  app-h  --ingroup app-h

# Make /root available to change dir for all
chmod --silent  o+x /root

# Install Ookla speedtest CLI
apt remove speedtest-cli
curl -s https://packagecloud.io/install/repositories/ookla/speedtest-cli/script.deb.sh | bash
apt install -y speedtest

#Register SourceGuardian PHP extension
echo "extension=/root/x100/source-guardian-loaders/$(uname -m)-ixed.8.1.lin" > /etc/php/8.1/mods-available/ixed.ini
ln -s /etc/php/8.1/mods-available/ixed.ini  /etc/php/8.1/cli/conf.d/01-ixed.ini