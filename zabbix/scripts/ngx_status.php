<?php
#const server_name = 'server_name' ;
#const server_addr = 'server_addr' ;
#const server_url = 'server_url' ;
#switch ($argv[1])
#{
#case  server_name:
$cmd = "curl -s http://127.0.0.1:8082/req-status | awk '/^server_name/ {print $2}'";
exec($cmd,$arr_res);
foreach($arr_res as $key => $v)
{
        $json[]['{#SERVERNAME}'] = preg_replace('/:*/','',$v);
}
#echo json_encode(array('data'=>$json));
#break;
#case server_addr:
$cmd = "curl -s http://127.0.0.1:8082/req-status | awk '/^server_addr/ {print $2}'";
exec($cmd,$arr_res);
foreach($arr_res as $key => $v)
{
        $json[]['{#SERVERADDR}'] = preg_replace('/:*/','',$v);
}
#echo json_encode(array('data'=>$json));
#break;
#case server_url:
$cmd = "curl -s http://127.0.0.1:8082/req-status | awk '/^server_url/ {print $2}'";
exec($cmd,$arr_res);
foreach($arr_res as $key => $v)
{
        $json[]['{#SERVERURL}'] = preg_replace('/:*/','',$v);
}
#break;
#default:
#echo "error";
#}
echo json_encode(array('data'=>$json));
