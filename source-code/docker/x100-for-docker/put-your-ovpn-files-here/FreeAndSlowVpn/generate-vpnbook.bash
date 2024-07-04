#!/bin/bash
clear

DarkGrey='\033[1;30m' #dark grey
Red='\033[1;31m' #red
Yellow='\033[1;33m' #bold yellow
Blue='\033[0;34m' 
Green='\033[0;32m'
Color_Off='\033[0m'
Cyan='\033[1;36m' #bold cyan

download_ovpns() {
    local DEBUG=0
    local ocr_api_key="K81813975188957"
    #local ocr_api_key="helloworld"
    local ocr_url=$"https://api.ocr.space/parse/imageurl?apikey=${ocr_api_key}&filetype=tiff&url="
    local tmpfiles_url="https://tmpfiles.org/"
    local tmpfiles_url_api="https://tmpfiles.org/api/v1/upload"
    local vpnbook_main_url="https://www.vpnbook.com"
    local vpnbook_url=$"${vpnbook_main_url}/freevpn"
    local ovpn_mask="udp25000.ovpn"
    local pw_benchmark="dnx97sa"
    local crc_pw_benchmark="a6ad3066" # for $pw_benchmark known password
    local count=0
    
    local input_file="vpnbook.html"
    local output_file="links.txt"
    local tmp_json="tmp.json"
    local ocr_json="pw.json"
    local pw_png="pw.png"
    local pw_tiff="pw.tiff"
    local temp_files_json="tempfiles.json"
    local pw_temp_files_json="pw_tempfiles.json"    
    local password_file="pw.txt"
    
    local password_url
    local pw_tiff_url
    local ignore
    local protocol
    local host
    local valddns
    local port
    local filename
    local url
    
    echo -e   "${Cyan}=======================================================${Color_off}"
    echo -e   "${Cyan}*           Vpnbook config generator v.1.0            *${Color_off}"
    echo -e   "${Cyan}*                 for db1000nx100                     *${Color_off}"
    echo -e "${Yellow}*  Glory to Ukraine and thank you for participation   *${Color_off}"
    echo -e "${Yellow}*                                                     *${Color_off}"
    echo -e "${Yellow}=======================================================${Color_off}"
    
    sleep 1
    
    REQUIRED_PKG="imagemagick"
    PKG_OK=$(dpkg-query -W --showformat='${Status}\n' $REQUIRED_PKG|grep "install ok installed")
    echo Checking for $REQUIRED_PKG: $PKG_OK
    if [ "" = "$PKG_OK" ]; then
      printf "${Color_Off}0.depended packages install (imagemagick)..."
      printf "\r\n"
      echo "No $REQUIRED_PKG. Setting up $REQUIRED_PKG."
      sudo apt-get --yes install $REQUIRED_PKG
      echo -e ".${Green}done${Color_Off}"
    fi
    
    printf "${Color_Off}1.clean vpnbook* directories and files..."
    if [[ "$DEBUG" != 1 ]]; then
      cd ./put-your-ovpn-files-here
    fi
    rm vpnbook-* -R 2> /dev/null
    echo -e ".${Green}done${Color_Off}"
    
    # Directory naming date/time based
    today=`date +%d-%m-%y-%H-%M`
    vpn_name="vpnbook-$today"
    
    printf "2.create new vpn provider directory..."
    mkdir $vpn_name
    cd $vpn_name
    echo -e ".${Green}done${Color_Off}"
    
    # load whole html
    wget $vpnbook_url -O $input_file -q --show-progress
    
    printf "3.password image file downloading..."
    
    # Use grep to extract all lines with <img src="...">
    grep -oh '<img[^>]*>' "$input_file" | sed 's/.*\ssrc=['"'"'"]//' | sed 's/['"'"'"].*//' | awk '/password.php\?t=/' > "$password_file"
    password_url=$(head -n 1 $password_file)
    if [[ $password_url = "" ]]; then
      echo -e "${Red}ERROR: process was not able to get a password${Color_Off}"
      exit
    fi
    
    replace="%20"
    password_url=${password_url/ /$replace}
    
    # download password image
    curl -s $"${vpnbook_main_url}/${password_url}" > $pw_png
    
    echo -e ".${Green}done${Color_Off}"
    
    crc_pw=$(crc32 $pw_png)
    
    if [[ "$crc_pw" = "$crc_pw_benchmark" ]]; then
      printf "4.password is known, few steps shall be skiped..."
      password="$pw_benchmark"
    else
      printf "4.password image file detection rate increasing..."
    
      # create TIFF for OCR
      convert $pw_png -units PixelsPerInch -respect-parenthesis \( -compress LZW -resample 300 -bordercolor black -border 1 -trim +repage -fill white -draw "color 0,0 floodfill" -alpha off -shave 1x1 \) \( -bordercolor black -border 2 -fill white -draw "color 0,0 floodfill" -alpha off -shave 0x1 -deskew 40 +repage \) -antialias -sharpen 0x3 $pw_tiff
      
      # upload to the temporary storage
      curl -F $"file=@${pw_tiff}" "$tmpfiles_url_api" --silent > $temp_files_json && grep -Po '"url":.*?[^\\]"}' $temp_files_json > $pw_temp_files_json
      
      pw_tiff_url=$(head -n 1 $pw_temp_files_json)
      replace=""
      pw_tiff_url=${pw_tiff_url/\"url\":\"/$replace}
      pw_tiff_url=${pw_tiff_url/\"\}/$replace}
      replace=$"${tmpfiles_url}dl/"
      pw_tiff_url=${pw_tiff_url/$tmpfiles_url/$replace}
      
      echo -e ".${Green}done${Color_Off}"
      printf "5.password detecting..."
      
      curl -s $"${ocr_url}${pw_tiff_url}" > $tmp_json && grep -Po '"ParsedText":.*?[^\\]",' $tmp_json > $ocr_json
      
      password=$(head -n 1 $ocr_json)
      replace=""
      password=${password/\"ParsedText\":\"/$replace}
      password=${password/\",/$replace}
      if [[ $password = "" ]]; then
        echo -e "${Red}ERROR: process was not able to get a password${Color_Off}"
        exit
      fi
      # delete \r\n
      password=$(echo $password | sed -e 's!\\r\\n!!g') 
    fi    
    
    echo -e ".${Green}done${Color_Off}"
    printf "6.ovpn files downloading...\r\n"
    
    # Use grep to extract all lines with <a href="...">
    grep -o '<a .*href=.*>' "$input_file" | sed -e 's/<a /\n<a /g' | sed -e 's/<a .*href=['"'"'"]//' -e 's/["'"'"'].*$//' -e '/^$/ d' | awk '/free-openvpn-account\/vpnbook-openvpn-/' > "$output_file"

    # download .ovpn files
    while IFS="/" read -r foa zipfile
    do
      replace=""
      ovpn_name=${zipfile/\-openvpn/$replace}
      replace="-$ovpn_mask"
      ovpn_name=${ovpn_name/.zip/$replace}
      curl -s $"${vpnbook_main_url}/${foa}/${zipfile}" > "$zipfile" && unzip -j -q "$zipfile" $"*${ovpn_mask}" && find . -type f -a \( -name "*$ovpn_mask" \) -a -exec sed -i -e $"s/cipher AES-256-CBC\x0D/cipher AES-256-GCM\x0D/" {} + && rm "$zipfile" &
      echo -e "${Green}        ${ovpn_name} - downloaded${Color_Off}"
      count=$((count+1))
    done < <(cut -d "/" -f2,3 $output_file)
    echo -e "${Blue}Totally downloaded ${count} file(s)${Color_Off}"
    
    printf "6.ovpn files downloading..."
    echo -e ".${Green}done${Color_Off}"
    
    printf "7.creating 'credentials.txt' and 'vpn-provider-config.txt filled with proper data'..."
    echo -e $"vpnbook\n${password}">credentials.txt
    echo -e "max_connections=999\ndistressUseUdpFlood=0\ndistressProxyConnectionsPercent=80%">vpn-provider-config.txt
    echo -e ".${Green}done${Color_Off}"

    printf "8.cleaning..."
    if [[ "$DEBUG" != 1 ]]; then
      rm $input_file $output_file $pw_png $password_file
      if [[ "$crc_pw" != "$crc_pw_benchmark" ]]; then
        rm $tmp_json $ocr_json $pw_tiff $temp_files_json $pw_temp_files_json
      fi
    fi
    echo -e ".${Green}done${Color_Off}"
    
    cd ../..
}

download_ovpns