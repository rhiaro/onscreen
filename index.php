<?
session_start();
if(isset($_SESSION['errors'])) { unset($_SESSION['errors']); }
if(isset($_GET['logout'])){ session_unset(); session_destroy(); header("Location: /onscreen"); }
if(isset($_GET['reset'])){ unset($_SESSION[$_GET['reset']]); }

include "link-rel-parser.php";

$base = "https://apps.rhiaro.co.uk/onscreen";
// $base = "http://localhost";

$_id = "id";
$_type = "type";

require_once('init.php');

EasyRdf_Namespace::set('solid', 'http://www.w3.org/ns/solid/terms#');
EasyRdf_Namespace::set('ldp', 'http://www.w3.org/ns/ldp#');
EasyRdf_Namespace::set('as', 'http://www.w3.org/ns/activitystreams#');

function inbox_from_header($url, $rel="http://www.w3.org/ns/ldp#inbox"){
  if(isset($_SESSION[$rel])){
    return $_SESSION[$rel];
  }else{
    $res = head_http_rels($url);
    $rels = $res['rels'];
    return $rels[$rel][0];
  }
}

function get_inbox($url){

  $inbox = inbox_from_header($url);

  if(empty($inbox)){
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $data = curl_exec($ch);
    $ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    
    $cts = explode(';', $ct);
    if(count($cts) > 1){
      foreach($cts as $act){
        $act = trim($act);
        try {
          if(EasyRdf_Format::getFormat($act)){
            $ct = $act;
            break;
          }
        }catch(Exception $e){}
      }
    }
    $graph = new EasyRdf_Graph();
    
    try{
      $graph->parse($data, $ct, $url);
    } catch (Exception $e) {
      $_SESSION['errors']['Inbox'] = "Couldn't find an Inbox here (".$e->getMessage().")";
      return false;
    }
    
    $subject = $graph->resource($url);
    $inbox = $subject->get('ldp:inbox');
  
  }

  if(!empty($inbox)){
    return $inbox;
  }else{
    $_SESSION['errors']['Inbox'] = "Could not find an Inbox at this URL";
    return false;
  }
}

function context(){
  return array(
      "@context" => array("http://www.w3.org/ns/activitystreams#")
    );
}

function get_feed(){

  $items = array();
  
  $source = urldecode($_SESSION['inbox']);
  $ch = curl_init($source);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array("Accept: application/ld+json"));
  $response = curl_exec($ch);
  curl_close($ch);

  if($response){
    $inbox = new EasyRdf_Graph($source);
    $inbox->parse($response, 'jsonld');

    $contains = $inbox->toRdfPhp();

    foreach($contains[$source]["http://www.w3.org/ns/ldp#contains"] as $notif){

      $ch = curl_init($notif['value']);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
      curl_setopt($ch, CURLOPT_HTTPHEADER, array("Accept: application/ld+json"));
      $response = curl_exec($ch);
      $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      if($http_code == '200'){
        try{
          $data = new EasyRdf_Graph($notif['value']);
          $data->parse($response, 'jsonld');
          $items[$notif['value']] = $data;
        }catch(Exception $e){
          //
        }
      }
      curl_close($ch);
    }

    $items = array_reverse($items);
    return $items;
  }else{
    $_SESSION['errors']['feed'] = "Could not get Inbox feed";
    return false;
  }

}
function id_from_object($object){
  global $_id;
  return $object[$_id];
}
function arrayids_to_string($array){
  $flat = array_map("id_from_object", $array);
  return implode(",", $flat);
}

function url_to_objectid($url){
  return array("id" => trim($url));
}
function url_strings_to_array($urls){
  $ar = explode(",", $urls);
  return array_map("url_to_objectid", $ar);
}

function get_prefs($domain){
  // TODO: get http://www.w3.org/ns/pim/space#preferencesFile from $domain
  $prefsfile = discover_endpoint($domain, "http://www.w3.org/ns/pim/space#preferencesFile");
  $prefsjson = file_get_contents($prefsfile);
  $prefs = json_decode($prefsjson, true);
  if(isset($prefs["applications"])){
    $apps = $prefs["applications"];
    foreach($apps as $app){
      if($app["@id"] == "http://apps.rhiaro.co.uk/onscreen"){
        return $app;
      }
    }
  }
}

// Store config stuff
if(isset($_GET['url'])){
  $_SESSION['url'] = $_GET['url'];
}

// Fetch feed
if(isset($_SESSION['url'])){
  $inbox = get_inbox($_SESSION['url']);
  if($inbox){
    $_SESSION['inbox'] = $inbox;
    $feed = get_feed();
  }
  
}elseif(isset($_SESSION['me'])){
  $prefs = get_prefs($_SESSION['me']);
}

?>
<!doctype html>
<html>
  <head>
    <title>On Screen.</title>
    <link rel="stylesheet" type="text/css" href="http://apps.rhiaro.co.uk/css/normalize.min.css" />
    <link rel="stylesheet" type="text/css" href="http://apps.rhiaro.co.uk/css/main.css" />
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
     body { background-color: #0a0a0a; color: silver; font-size: 1.2em; background-image: url('http://wallpapercave.com/wp/oPRLSHe.png'); background-position: bottom center; background-attachment: fixed; background-repeat: no-repeat; background-size: 100%;}
     h1 { color: silver; border: none; }
     h2 { padding-top: 0.8em; padding-bottom: 0.8em; }
     form label { font-weight: bold; }
     form input { opacity: 0.7; background-color: #3d3d3d; border: 1px solid #2d2d2d; padding: 0.4em; color: silver; }
     form input[type=text] { width: 90%; }
     pre { background-color: black; max-height: 16em; overflow: auto; }
     .screen { 
      -webkit-box-shadow: 0px 0px 22px 2px rgba(28,206,255,1);
      -moz-box-shadow: 0px 0px 22px 2px rgba(28,206,255,1);
      box-shadow: 0px 0px 22px 2px rgba(28,206,255,1);
      padding: 0.8em;
     }
     .fail { opacity: 0.6; }
    </style>
  </head>
  <body>
    <header class="w1of4">
      <div class="inner">
        <h1><img src="onscreen.png" alt="On Screen." /></h1>
        <p><img src="giphy.gif" alt="Riker makes irresponsible use of the viewscreen." title="Riker makes irresponsible use of the viewscreen." /></p>
      </div>
    </header>

    <main class="w3of4">
      <div class="inner">
        <h2>You're being hailed</h2>

        <?if(isset($_SESSION['errors'])):?>
          <div class="fail">
            <h3>There's something wrong Captain... we're losing the signal!</h3>
            <?foreach($_SESSION['errors'] as $key=>$error):?>
              <p><strong><?=$key?>: </strong><?=$error?></p>
            <?endforeach?>
          </div>
        <?endif?>
        
        <?if(!isset($_SESSION['url'])):?>

          <form method="get" id="direct">
            <p><label for="url">URL to get notifications about (eg. your profile, or a blog post..)</label></p>
            <p><input id="url" name="url" type="text" placeholder="https://my-domain.me" value="<?=isset($_SESSION['url']) ? urldecode($_SESSION['url']) : ""?>" /><input type="submit" value="On screen." /></p>
          </form>
        <?else:?>

          <h3>Incoming messages for <?=$_SESSION['url']?></h3>
          <p style="text-align: center">[ <a href="<?=$_SESSION['inbox']?>">inbox</a> | <a href="?reset=url">reset</a> ]</p>
        <?endif?>

        <?if(isset($feed)):?>

          <?foreach($feed as $uri => $data):?>

            <div class="screen">
              <h4><a href="<?=$uri?>"><?=$uri?></a></h4>
              <?=$data->dump()?>
            </div>
          <?endforeach?>

        <?endif?>
      </div>
    </main>
  </body>
</html>