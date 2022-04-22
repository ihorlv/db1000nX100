#!/usr/bin/env bash

/sbin/fstrim --all
sleep 3

/usr/sbin/swapoff --all
/usr/sbin/swapon --discard  --all

rm -r /tmp/*
rm -r /var/tmp/*
rm -r /var/mail/*
rm -r /var/log/*

echo '' > /root/.bash_history
echo '' > /home/user/.bash_history

systemctl poweroff
