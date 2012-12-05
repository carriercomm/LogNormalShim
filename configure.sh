#!/bin/bash

cfg='config.json'

echo "Configuring LogNormal Shim"
echo "Enter your app.lognormal.com credentials"
read -p "Email: " email
read -s -p "Password: " password

echo # newline
echo "Enter the domain for the account (ex. etsy.com)"
read -p "Domain: " domain

echo "Enter the url where you will host the shim"
read -p "API URL: " api_url

echo "Do you wish to configure graphite settings?"
echo "These will enable you to send LogNormal data to Graphite using scripts in the Client/scripts/ directory."
read -p "y/n: " yn

if [[ $yn == "N" || $yn == "n" ]]
then
    :
else
    echo "Enter the url of your graphite server"
    read -p "Graphite server URL: " graphite_url
    echo "Enter the port on which your graphite server will be listening"
    read -p "Graphite server port: " graphite_port
    echo "Enter the namespace to which lognormal data should be stored (ex. performance.lognormal)"
    read -p "Graphite server namespace: " graphite_namespace
fi

echo "{
    \"email\": \"$email\",
    \"password\": \"$password\",
    \"domain\": \"$domain\",
    \"api_url\": \"$api_url\",
    \"graphite\": {
        \"url\": \"$graphite_url\",
        \"port\": \"$graphite_port\",
        \"namespace\": \"$graphite_namespace\"
    }
}" > $cfg

echo
echo "Wrote config to $cfg:"

echo
echo "You'll probably want to add a virtual host definition to your web server config"
echo "For Apache, add the following to /etc/httpd/conf.d/lognormalshim.conf"
echo "and restart Apache"

dir="$( cd "$( dirname "$0" )" && pwd )"
echo "
<VirtualHost *:80>
  ServerName   $api_url

  DocumentRoot $dir/API/htdocs

</VirtualHost>"

echo
echo "After restarting apache, you should see data at http://$api_url/self.php"
