#!/bin/bash
ngx_addr=127.0.0.1:8082
status_uri=req-status
if [ $1 == "max_active" ];then
        MAX_ACTIVE=$(curl -s http://$ngx_addr/$status_uri|awk '{if($2 == "'$2'") print $3}')
        echo ${MAX_ACTIVE:=0}
fi

if [ $1 == "max_bw" ];then
        MAX_BW=$(curl -s http://$ngx_addr/$status_uri|awk '{if($2 == "'$2'") print $4}')
        echo ${MAX_BW:=0}
fi

if [ $1 == "active" ];then
        ACTIVE=$(curl -s http://$ngx_addr/$status_uri|awk '{if($2 == "'$2'") print $7}')
        echo ${ACTIVE:=0}
fi

if [ $1 == "bandwidth" ];then
        BW=$(curl -s http://$ngx_addr/$status_uri|awk '{if($2 == "'$2'") print $8}')
        echo ${BW:=0}
fi

