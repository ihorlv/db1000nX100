#!/usr/bin/env sh

# Wonder Shaper

# Modifications by Vince Mulhollon for debian package

if [ $# -eq 0 ]; then
  echo Please read the man page for the wondershaper and
  echo the file /usr/share/doc/wondershaper/README.Debian.gz
  exit
fi

if [ $# -eq 1 ]; then
  /sbin/tc -s qdisc ls dev $1
  /sbin/tc -s class ls dev $1
  exit
fi

if [ $# -eq 2 ]; then
  /sbin/tc qdisc del dev $2 root    2> /dev/null > /dev/null
  /sbin/tc qdisc del dev $2 ingress 2> /dev/null > /dev/null
  echo Wondershaper queues have been cleared.
  exit
fi

if [ $# -ne 3 ]; then
  echo Please read the man page for the wondershaper and
  echo the file /usr/share/doc/wondershaper/README.Debian.gz
  exit
fi

# please read the README before filling out these values
#
# Set the following values to somewhat less than your actual download
# and uplink speed. In kilobits. Also set the device that is to be shaped.
DOWNLINK=$2
UPLINK=$3
DEV=$1

# low priority OUTGOING traffic - you can leave this blank if you want
# low priority source netmasks
NOPRIOHOSTSRC=

# low priority destination netmasks
NOPRIOHOSTDST=

# low priority source ports
NOPRIOPORTSRC=

# low priority destination ports
NOPRIOPORTDST=

# Now remove the following two lines :-)

#echo Please read the documentation in 'README' first :-\)
#exit

#########################################################

#if [ "$1" = "status" ]
#then
#	/sbin/tc -s qdisc ls dev $DEV
#	/sbin/tc -s class ls dev $DEV
#	exit
#fi


# clean existing down- and uplink qdiscs, hide errors
/sbin/tc qdisc del dev $DEV root    2> /dev/null > /dev/null
/sbin/tc qdisc del dev $DEV ingress 2> /dev/null > /dev/null

#if [ "$1" = "stop" ]
#then
#	exit
#fi

###### uplink

# install root CBQ

/sbin/tc qdisc add dev $DEV root handle 1: cbq avpkt 1000 bandwidth 10mbit

# shape everything at $UPLINK speed - this prevents huge queues in your
# DSL modem which destroy latency:
# main class

/sbin/tc class add dev $DEV parent 1: classid 1:1 cbq rate ${UPLINK}kbit \
allot 1500 prio 5 bounded isolated

# high prio class 1:10:

/sbin/tc class add dev $DEV parent 1:1 classid 1:10 cbq rate ${UPLINK}kbit \
   allot 1600 prio 1 avpkt 1000

# bulk and default class 1:20 - gets slightly less traffic,
#  and a lower priority:

/sbin/tc class add dev $DEV parent 1:1 classid 1:20 cbq rate $((9*$UPLINK/10))kbit \
   allot 1600 prio 2 avpkt 1000

# 'traffic we hate'

/sbin/tc class add dev $DEV parent 1:1 classid 1:30 cbq rate $((8*$UPLINK/10))kbit \
   allot 1600 prio 2 avpkt 1000

# all get Stochastic Fairness:
/sbin/tc qdisc add dev $DEV parent 1:10 handle 10: sfq perturb 10
/sbin/tc qdisc add dev $DEV parent 1:20 handle 20: sfq perturb 10
/sbin/tc qdisc add dev $DEV parent 1:30 handle 30: sfq perturb 10

# start filters
# TOS Minimum Delay (ssh, NOT scp) in 1:10:
/sbin/tc filter add dev $DEV parent 1:0 protocol ip prio 10 u32 \
      match ip tos 0x10 0xff  flowid 1:10

# ICMP (ip protocol 1) in the interactive class 1:10 so we
# can do measurements & impress our friends:
/sbin/tc filter add dev $DEV parent 1:0 protocol ip prio 11 u32 \
        match ip protocol 1 0xff flowid 1:10

# pablo.iranzo@uv.es provided a patch for the MLDonkey system
# The MLDonkey uses small UDP packets for source propogation
# which floods the wondershaper out.
/sbin/tc filter add dev $DEV parent 1:0 protocol ip prio 10 u32 \
   match ip protocol 17 0xff \
   match ip sport 4666 0xffff \
   flowid 1:30

# prioritize small packets (<64 bytes)

/sbin/tc filter add dev $DEV parent 1: protocol ip prio 12 u32 \
   match ip protocol 6 0xff \
   match u8 0x05 0x0f at 0 \
   match u16 0x0000 0xffc0 at 2 \
   flowid 1:10


# some traffic however suffers a worse fate
for a in $NOPRIOPORTDST
do
	/sbin/tc filter add dev $DEV parent 1: protocol ip prio 14 u32 \
	   match ip dport $a 0xffff flowid 1:30
done

for a in $NOPRIOPORTSRC
do
 	/sbin/tc filter add dev $DEV parent 1: protocol ip prio 15 u32 \
	   match ip sport $a 0xffff flowid 1:30
done

for a in $NOPRIOHOSTSRC
do
 	/sbin/tc filter add dev $DEV parent 1: protocol ip prio 16 u32 \
	   match ip src $a flowid 1:30
done

for a in $NOPRIOHOSTDST
do
 	/sbin/tc filter add dev $DEV parent 1: protocol ip prio 17 u32 \
	   match ip dst $a flowid 1:30
done

# rest is 'non-interactive' ie 'bulk' and ends up in 1:20

/sbin/tc filter add dev $DEV parent 1: protocol ip prio 18 u32 \
   match ip dst 0.0.0.0/0 flowid 1:20


########## downlink #############
# slow downloads down to somewhat less than the real speed  to prevent
# queuing at our ISP. Tune to see how high you can set it.
# ISPs tend to have *huge* queues to make sure big downloads are fast
#
# attach ingress policer:

/sbin/tc qdisc add dev $DEV handle ffff: ingress

# filter *everything* to it (0.0.0.0/0), drop everything that's
# coming in too fast:

/sbin/tc filter add dev $DEV parent ffff: protocol ip prio 50 u32 match ip src \
   0.0.0.0/0 police rate ${DOWNLINK}kbit burst 10k drop flowid :1

