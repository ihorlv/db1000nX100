#!/bin/bash
clear

Yellow='\033[1;33m' #bold yellow
Blue='\033[0;34m'
Green='\033[0;32m'
Color_Off='\033[0m'
Cyan='\033[1;36m' #bold cyan

echo -e   "${Cyan}=======================================================${Color_off}"
echo -e   "${Cyan}*            Vpngate config generator v.1             *${Color_off}"
echo -e   "${Cyan}*                 for db1000nx100                     *${Color_off}"
echo -e "${Yellow}*  Glory to Ukraine and thank you for participation   *${Color_off}"
echo -e "${Yellow}*                                                     *${Color_off}"
echo -e "${Yellow}=======================================================${Color_off}"

sleep 1

printf "${Color_Off}1.clean vpng* directories and files..."
rm vpng* -R 2> /dev/null
echo -e ".${Green}done${Color_Off}"

# Directory naming date/time based
today=`date +%d-%m-%y-%H-%M`
vpn_name="vpngate-$today"

printf "2.create new vpn provider directory..."
mkdir $vpn_name
echo -e ".${Green}done${Color_Off}"

printf "3.download CSV file from vpngate.net...\n"
cd $vpn_name
wget http://www.vpngate.net/api/iphone/ -O servlist.txt -q --show-progress
#echo -e ".${Green}done${Color_Off}"

printf "4.cleaning last line..."
head -n -1 servlist.txt > temp.txt ; mv temp.txt servlist.txt
echo -e ".${Green}done${Color_Off}"

printf "5.cleaning first two lines..."
tail -n +3 servlist.txt > temp.txt ; mv temp.txt servlist.txt
echo -e ".${Green}done${Color_Off}"

printf "6.parsing and decoding started:\r"
i=0
while IFS="," read -r fn cfg
do
   echo "$cfg" | base64 --decode>"$fn.ovpn" 2>/dev/null
   i=$((i + 1))
   if [[ $i%10 -eq 0 ]]
   then
      printf "6.parsing and decoding started: ${Green}%d%%${Color_Off}\r" "$i"
   fi
done < <(cut -d "," -f1,15 servlist.txt)
echo -e "6.parsing and decoding started: ${Green}100%${Color_Off}...${Green}done${Color_Off}"

printf "7.creating 'credentials.txt' and 'vpn-provider-config.txt filled with proper data'..."
echo -e "vpn\nvpn">credentials.txt
echo -e "max_connections=999">vpn-provider-config.txt
echo -e ".${Green}done${Color_Off}"

printf "8.cleaning..."
rm servlist.txt
echo -e ".${Green}done${Color_Off}"
cd ..