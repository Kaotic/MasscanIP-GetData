<?php
/**
 * Created by PhpStorm.
 * User: Kaotic
 * Date: 18/10/2016
 * Time: 03:50
 */

if (substr(php_sapi_name(), 0, 3) != 'cli'):
    die('This script can only be run from command line!');
endif;

define('DOC_ROOT', dirname(__FILE__).'/');
require_once 'functions.php';
require_once 'curl.php';
require_once     '../config.php';

$start_ts = time();

function getData($url)
{
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);

    $data = curl_exec($ch);
    curl_close($ch);

    return $data;
}

function isOnline($domain)
{
    //check, if a valid url is provided
    if(!filter_var($domain, FILTER_VALIDATE_URL))
    {
        return false;
    }

    //initialize curl
    $curlInit = curl_init($domain);
    curl_setopt($curlInit,CURLOPT_CONNECTTIMEOUT,10);
    curl_setopt($curlInit, CURLOPT_TIMEOUT, 10);
    curl_setopt($curlInit,CURLOPT_HEADER,true);
    curl_setopt($curlInit,CURLOPT_NOBODY,true);
    curl_setopt($curlInit,CURLOPT_RETURNTRANSFER,true);

    //get answer
    $response = curl_exec($curlInit);

    curl_close($curlInit);

    if ($response) return true;

    return false;
}

class AsyncOperation extends Thread {

    public function __construct($ip, $port) {
        $this->ip = $ip;
        $this->port = $port;
    }

    public function run() {
        if ($this->ip && $this->port) {
            $ip = $this->ip;
            $port = $this->port;
            $_ip = long2ip($ip);
            $http = 'http://'.$_ip . ':' . $port;
            $https = 'https://'.$_ip . ':' . $port;
            $isConnection = false;
            $_server = "";

            echo "[TEST N°".$ip."]Démarrage du test pour ".$_ip." sur le port ".$port.".".PHP_EOL;

            $data = getData($http);
            if($data == false){
                echo "[TEST N°".$ip."]Aucune donnée reçu en HTTP test en HTTPS.".PHP_EOL;
                $data = getData($https);
                if($data != false){
                    echo "[TEST N°".$ip."]Données reçu en HTTPS.".PHP_EOL;
                    $isConnection = "https";
                }else{
                    echo "[TEST N°".$ip."]Aucune donnée reçu en HTTPS.".PHP_EOL;
                }
            }else{
                echo "[TEST N°".$ip."]Données reçu en HTTP.".PHP_EOL;
                $isConnection = "http";
            }

            if($isConnection == "http"){
                $host = $http;
            }elseif($isConnection == "https"){
                $host = $https;
            }

            if($data != false && $isConnection != false){
                echo "[TEST N°".$ip."]Traitement des données en cours..".PHP_EOL;

                $doc = new DOMDocument();
                @$doc->loadHTML($data);
                $nodes = $doc->getElementsByTagName('title');
                $title = $nodes->item(0)->nodeValue;
                $server = get_headers($host);

                if(empty($server[2])){
                    $_server = $server;
                }else{
                    $_server = $server[2];
                }

                try{
                    $bdd = new PDO('mysql:host=localhost;dbname=massip', 'massip', '');
                    $bdd->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                    $bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
                    $bdd->exec('SET NAMES utf8');
                } catch (Exception $e){
                    echo 'Impossible de se connecter à la base de donnée';
                    echo $e->getMessage();
                    die();
                }

                $host = $isConnection . '://' . $_ip . ':' . $port . '/';
                $log  = "ServeurID: " . $ip . " - Host: " . $host . " - Title: " . $title . PHP_EOL;

                $bdd->query("UPDATE data SET banner='".$_server."', title='".$title."', service='".$isConnection."' WHERE ip=".$ip." AND port_id=" . $port);
                file_put_contents('./scan_'.date("j.n.Y").'.txt', $log, FILE_APPEND);

                echo "[TEST N°".$ip."]Test fini pour ".long2ip($ip)." sur le port ".$port." avec succès.".PHP_EOL;

                //updateEntry($ip, $port, $_server, $title, $isConnection);
            }else {
                //updateEntry($ip, $port);

                try{
                    $bdd = new PDO('mysql:host=localhost;dbname=massip', 'massip', '');
                    $bdd->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                    $bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
                    $bdd->exec('SET NAMES utf8');
                } catch (Exception $e){
                    echo 'Impossible de se connecter à la base de donnée';
                    echo $e->getMessage();
                    die();
                }

                $host = $isConnection . '://' . $_ip . ':' . $port . '/';
                $log  = "ServeurID: " . $ip . " - Host: " . $host . " - Title: OFFLINE SERVER" . PHP_EOL;

                $bdd->query("UPDATE data SET banner='OFFLINE SERVER', title='OFFLINE SERVER', service='".$isConnection."' WHERE ip=".$ip." AND port_id=" . $port);
                file_put_contents('./scan_'.date("j.n.Y").'.txt', $log, FILE_APPEND);

                echo "[TEST N°".$ip."]Serveur ".$_ip." sur le port ".$port." offline.".PHP_EOL;
            }
        }
    }
}

$online = 0;
$offline = 0;

$filter['port'] = 0;
$filter['protocol']		= isset($_GET['protocol']) && !empty($_GET['protocol']) && is_string($_GET['protocol'])	?	DB::escape($_GET['protocol'])	:	'';
$filter['state']		= isset($_GET['state']) && !empty($_GET['state']) && is_string($_GET['state'])	?	DB::escape($_GET['state'])	:	'';
$filter['service']		= isset($_GET['service']) && !empty($_GET['service']) && is_string($_GET['service'])	?	DB::escape($_GET['service'])	:	'';
$filter['banner']		= isset($_GET['banner']) && !empty($_GET['banner'])	&& is_string($_GET['banner'])	?	DB::escape($_GET['banner'])	:	'';
$filter['exact-match']	= isset($_GET['exact-match']) && (int) $_GET['exact-match'] === 1 ?	1	:	0;
$filter['text']			= isset($_GET['text']) && !empty($_GET['text'])	&& is_string($_GET['text'])	?	DB::escape($_GET['text'])	:	'';
$filter['page']			= isset($_GET['page']) && (int) $_GET['page'] > 1	?	(int) $_GET['page']	:	1;

do {
    echo PHP_EOL;
    echo "Combien de page voulez vous analyser? ";
    $handle = fopen ("php://stdin","r");
    $input = fgets($handle);
} while (!in_array(trim($input), array(10, 20, 50, 100, 200, 300, 400, 10000)));

if (trim(strtolower($input)) == 10) $filter['rec_per_page'] = 10;
if (trim(strtolower($input)) == 20) $filter['rec_per_page'] = 20;
if (trim(strtolower($input)) == 50) $filter['rec_per_page'] = 50;
if (trim(strtolower($input)) == 100) $filter['rec_per_page'] = 100;
if (trim(strtolower($input)) == 200) $filter['rec_per_page'] = 200;
if (trim(strtolower($input)) == 300) $filter['rec_per_page'] = 300;
if (trim(strtolower($input)) == 400) $filter['rec_per_page'] = 400;
if (trim(strtolower($input)) == 10000) $filter['rec_per_page'] = 10000;

//$filter['rec_per_page']	= 10000;

if (defined('EXPORT')){
    $results = browse($filter, true);
}else{
    $results = browse($filter);
}

stream_context_set_default(
    array(
        'http' => array(
            'method' => 'HEAD',
            'header' => "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_7_4) AppleWebKit/537.1 (KHTML, like Gecko) Chrome/21.0.1180.79 Safari/537.1\r\n"
        )
    )
);


echo PHP_EOL;
echo "Servers founds.";
echo PHP_EOL;
echo "Analyse de ".$filter['rec_per_page']." pages.";
echo PHP_EOL;
echo "Analyse de ".$results['pagination']['to']." serveurs.";
echo PHP_EOL;
echo "Total serveurs: ".$results['pagination']['records'].".";
echo PHP_EOL;

$stack = array();

foreach ($results['data'] as $k => $r){
    $ip = $r['ipaddress'];
    $port = $filter['port'];

    $stack[] = new AsyncOperation($ip, $port);

}

foreach ( $stack as $t ) {
    $t->start();
}

$end_ts = time();
