#!/usr/bin/env bash

rm -r /root/.docker
rm -r /tmp/*
rm -r /var/tmp/*
rm -r /var/mail/*
rm -r /var/log/*

     echo 'x' > /root/.bash_history
echo 'x' > /home/user/.bash_history

/usr/sbin/swapoff --all
sleep 1
/usr/sbin/swapon --discard  --all
sleep 1
/sbin/fstrim --all --verbose
/sbin/fstrim --all --verbose
/sbin/fstrim --all --verbose

systemctl poweroff
