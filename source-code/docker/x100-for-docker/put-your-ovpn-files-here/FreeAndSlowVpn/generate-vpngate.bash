#!/bin/bash
clear

DarkGrey='\033[1;30m' #dark grey
Yellow='\033[1;33m' #bold yellow
Blue='\033[0;34m' 
Green='\033[0;32m'
Color_Off='\033[0m'
Cyan='\033[1;36m' #bold cyan

download_ovpns() {
    local input_file="vpngate.html"
    local output_file1="links1.txt"
    local output_file="links.txt"
    local count=0
    local ignore
    local protocol
    local host
    local valddns
    local port
    local filename
    local url
    
    # load whole html
    wget https://www.vpngate.net/en/ -O $input_file -q --show-progress
    
    awk -F "</*b>|</td>" '/<[b]>.*[0-9]/ {
      print $1, $3"|" >> "countryhost.html"
    }' "vpngate.html"

    # Use grep to extract all lines with <a href="...">
    grep -o '<a .*href=.*>' "$input_file" | sed -e 's/<a /\n<a /g' | sed -e 's/<a .*href=['"'"'"]//' -e 's/["'"'"'].*$//' -e '/^$/ d' | awk '/do_openvpn.aspx/' > "$output_file1"
    
    while IFS="?" read -r params
    do
      echo $params >> $output_file
    done < <(cut -d "?" -f2 $output_file1)
    
    while IFS="&" read -r ddns ip tcp udp sid hid
    do
      #ignore=0
    
      replace=""
      valip=${ip/ip=/$replace}
      
      
      
      replace=""
      valddns=${ddns/fqdn=/$replace}

      replace="host"
      host=${ddns/fqdn/$replace}

      
      #if [ "$tcp" != "tcp=0" && "$ignore" = 0 ]; then
      #  protocol="tcp"
      #
      #  replace=""
      #  port=${tcp/tcp=/$replace}
      #  
      #  filename="vpngate_"$valddns"_"$protocol"_"$port".ovpn"
      #  
      #  url="https://www.vpngate.net/common/openvpn_download.aspx?"$sid"&"$host"&"$port"&"$hid"/"$filename
      #
      #  curl -s $url > $filename && sed -i $"s/proto udp\x0D/proto ${protocol}\x0D/;s/remote ${valddns} 0\x0D/remote ${valddns} ${port}/" $filename &
      #fi
      
      if [[ "$udp" != "udp=0" ]]; then
        ignore=0
        while IFS="|" read -r line
        do
          if [[ $line = *'Russian Federation'* ]]; then
            yes_country=1
          else
            yes_country=0
          fi
          
          if [[ $line = *${valddns}* ]]; then
            yes_host=1
          else
            yes_host=0
          fi

          
          if [[ "$yes_host" = 1 ]]; then
            if [[ "$yes_country" = 1 ]]; then
              ignore=1
            fi
            break
          fi
        done < <(cut -d "|" -f1 "countryhost.html")
        
        if [[ "$ignore" == 0 ]]; then
          protocol="udp"
        
          replace=""
          port=${udp/udp=/$replace}
          
          filename="vpngate_"$valddns"_"$protocol"_"$port".ovpn"
          
          url="https://www.vpngate.net/common/openvpn_download.aspx?"$sid"&"$host"&"$port"&"$hid"/"$filename
          
          curl -s $url > $filename && sed -i $"s/proto tcp\x0D/proto ${protocol}\x0D/;s/remote ${valddns} 0\x0D/remote ${valddns} ${port}/" $filename &
          count=$((count+1))
          
          echo -e "${Green}        ${filename} - downloaded${Color_Off}"
        else
          echo -e "${DarkGrey}        ${valddns} - ignored for security reason${Color_Off}"
        fi
      fi
      
      
    done < <(cut -d "&" -f1,2,3,4,5,6 $output_file)
    
    echo -e "${Blue}Totally downloaded ${count} file(s)${Color_Off}"

    rm $input_file $output_file1 $output_file "countryhost.html"
}

echo -e   "${Cyan}=======================================================${Color_off}"
echo -e   "${Cyan}*            Vpngate config generator v.1             *${Color_off}"
echo -e   "${Cyan}*                 for db1000nx100                     *${Color_off}"
echo -e "${Yellow}*  Glory to Ukraine and thank you for participation   *${Color_off}"
echo -e "${Yellow}*                                                     *${Color_off}"
echo -e "${Yellow}=======================================================${Color_off}"

sleep 1

printf "${Color_Off}1.clean vpng* directories and files..."
#cd ./put-your-ovpn-files-here
rm vpng* -R 2> /dev/null
echo -e ".${Green}done${Color_Off}"

# Directory naming date/time based
today=`date +%d-%m-%y-%H-%M`
vpn_name="vpngate-$today" 

printf "2.create new vpn provider directory..."
mkdir $vpn_name
echo -e ".${Green}done${Color_Off}"

printf "3.download CSV file from vpngate.net...\n${DarkGrey}"
cd $vpn_name
#wget http://www.vpngate.net/api/iphone/ -O servlist.txt -q --show-progress
#echo -e ".${Green}done${Color_Off}"

printf "${Color_Off}4.cleaning last line..."
#head -n -1 servlist.txt > temp.txt ; mv temp.txt servlist.txt
echo -e ".${Green}done${Color_Off}"

printf "5.cleaning first two lines..."
#tail -n +3 servlist.txt > temp.txt ; mv temp.txt servlist.txt
echo -e ".${Green}done${Color_Off}"

printf "6.parsing and decoding started:\r"
#i=0
#while IFS="," read -r fn cfg
#do 
#   echo "$cfg" | base64 --decode>"$fn.ovpn" 2>/dev/null
#   i=$((i + 1))
#   if [[ $i%10 -eq 0 ]]
#   then
#      printf "6.parsing and decoding started: ${Green}%d%%${Color_Off}\r" "$i"
#   fi
#done < <(cut -d "," -f1,15 servlist.txt)
download_ovpns
echo -e "6.parsing and decoding started: ${Green}100%${Color_Off}...${Green}done${Color_Off}"

printf "7.creating 'credentials.txt' and 'vpn-provider-config.txt filled with proper data'..."
echo -e "vpn\nvpn">credentials.txt
echo -e "max_connections=999\ndistressUseUdpFlood=0\ndistressProxyConnectionsPercent=80%">vpn-provider-config.txt
echo -e ".${Green}done${Color_Off}"

printf "8.cleaning..."
#find . -type f -print0 | xargs -0 grep --include=*.ovpn -l $'proto tcp\x0D' | xargs rm -f
#rm servlist.txt
echo -e ".${Green}done${Color_Off}"
cd ../..

