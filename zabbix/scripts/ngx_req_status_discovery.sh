#!/bin/sh
#parameter: servername,serveraddr,url
ngx_addr=127.0.0.1:8082
status_uri=req-status
case $1 in
 server_name)

curl -s http://$ngx_addr/$status_uri | awk 'BEGIN{printf "\{\n\t\"data\":\[\n"}/^server_name/{gsub(/.*:/,"",$2);++s[$2]}END{for(a in s)printf "\t\t\{\n\t\t\t\"\{#SERVERNAME\}\":\""a"\"\}\,""\n"}' 2> /dev/null
;;
 *)
echo "error"
esac
