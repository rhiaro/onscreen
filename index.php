<?
session_start();
date_default_timezone_set(file_get_contents("http://rhiaro.co.uk/tz"));
if(isset($_GET['logout'])){ session_unset(); session_destroy(); header("Location: /onscreen"); }
if(isset($_GET['reset'])){ unset($_SESSION[$_GET['reset']]); }

include "link-rel-parser.php";

//$base = "https://apps.rhiaro.co.uk/onscreen";
$base = "http://localhost";
if(isset($_GET['code'])){
  $auth = auth($_GET['code'], $_GET['state']);
  if($auth !== true){ $errors = $auth; }
  else{
    $response = get_access_token($_GET['code'], $_GET['state']);
    if($response !== true){ $errors = $auth; }
    else {
      header("Location: ".$_GET['state']);
    }
  }
}

$_id = "id";
$_type = "type";

function auth($code, $state, $client_id="https://apps.rhiaro.co.uk/onscreen"){
  
  $params = "code=".$code."&redirect_uri=".urlencode($state)."&state=".urlencode($state)."&client_id=".$client_id;
  $ch = curl_init("https://indieauth.com/auth");
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/x-www-form-urlencoded", "Accept: application/json"));
  curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
  //curl_setopt($ch, CURLOPT_HEADERFUNCTION, "dump_headers");
  $response = curl_exec($ch);
  $response = json_decode($response, true);
  $_SESSION['me'] = $response['me'];
  $info = curl_getinfo($ch);
  curl_close($ch);
  
  if(isset($response) && ($response === false || $info['http_code'] != 200)){
    $errors["Login error"] = $info['http_code'];
    if(curl_error($ch)){
      $errors["curl error"] = curl_error($ch);
    }
    return $errors;
  }else{
    return true;
  }
}

function get_access_token($code, $state, $client_id="https://apps.rhiaro.co.uk/onscreen"){
  
  $params = "me={$_SESSION['me']}&code=$code&redirect_uri=".urlencode($state)."&state=".urlencode($state)."&client_id=$client_id";
  $token_ep = discover_endpoint($_SESSION['me'], "token_endpoint");
  $ch = curl_init($token_ep);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/x-www-form-urlencoded"));
  curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
  $response = Array();
  parse_str(curl_exec($ch), $response);
  $info = curl_getinfo($ch);
  curl_close($ch);
  
  if(isset($response) && ($response === false || $info['http_code'] != 200)){
    $errors["Login error"] = $info['http_code'];
    if(curl_error($ch)){
      $errors["curl error"] = curl_error($ch);
    }
    return $errors;
  }else{
    $_SESSION['access_token'] = $response['access_token'];
    return true;
  }
  
}

require_once('init.php');

EasyRdf_Namespace::set('solid', 'http://www.w3.org/ns/solid/terms#');
EasyRdf_Namespace::set('ldp', 'http://www.w3.org/ns/ldp#');

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
      return $e->getMessage();
    }
    
    $subject = $graph->resource($url);
    $inbox = $subject->get('ldp:inbox');

  }
  return $inbox;
}

function context(){
  return array(
      "@context" => array("http://www.w3.org/ns/activitystreams#")
    );
}

function get_feed(){
  
  $source = urldecode($_SESSION['inbox']);
  $ch = curl_init($source);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array("Accept: application/ld+json"));
  $response = curl_exec($ch);
  curl_close($ch);
  $contents = json_decode($response, true);
  
  if(isset($contents["ldp:contains"])){
    $items = $contents["ldp:contains"];
  }elseif(isset($contents["http://www.w3.org/ns/ldp#contains"])){
    $items = $contents["http://www.w3.org/ns/ldp#contains"];
  }elseif(isset($contents[0]["http://www.w3.org/ns/ldp#contains"])){
    $items = $contents[0]["http://www.w3.org/ns/ldp#contains"];
  }else{
    $items = $contents["contains"];
  }

  foreach($items as $item){
    $dump = file_get_contents($item["@id"]);
    if($dump){
      $out[$item["@id"]] = array($dump);
    }else{
      $out[$item["@id"]] = "Could not get file (probably needs auth)";
    }
  }

  return $out;

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
  $_SESSION['inbox'] = get_inbox($_SESSION['url']);
  $feed = get_feed();
  
}elseif(isset($_SESSION['me'])){
  $prefs = get_prefs($_SESSION['me']);
}

?>
<!doctype html>
<html>
  <head>
    <title>On Screen.</title>
    <link rel="stylesheet" type="text/css" href="https://apps.rhiaro.co.uk/css/normalize.min.css" />
    <link rel="stylesheet" type="text/css" href="https://apps.rhiaro.co.uk/css/main.css" />
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
     body { background-color: #0a0a0a; color: silver; font-size: 1.2em; background-image: url('http://wallpapercave.com/wp/oPRLSHe.png'); background-position: bottom center; background-attachment: fixed; background-repeat: no-repeat; background-size: 100%;}
     h1 { color: silver; text-align: center; border: none; }
     hgroup { text-align: center; }
     h3 { text-align: center; }
     form { text-align: center; }
     form label { font-weight: bold; }
     form input { opacity: 0.7; background-color: #3d3d3d; border: 1px solid #2d2d2d; padding: 0.4em; color: silver; }
     form input[type=text] { width: 100%; }
     pre { background-color: black; max-height: 16em; overflow: auto; }
    </style>
  </head>
  <body>
    <main class="w1of2 center">
      <hgroup>
        <h1>On Screen.</h1>
        <p><img src="giphy.gif" alt="Riker makes irresponsible use of the viewscreen." title="Riker makes irresponsible use of the viewscreen." /></p>
      </hgroup>

      <?if(isset($errors)):?>
        <div class="fail">
          <?foreach($errors as $key=>$error):?>
            <p><strong><?=$key?>: </strong><?=$error?></p>
          <?endforeach?>
        </div>
      <?endif?>
      
      <?if(!isset($_SESSION['url'])):?>
        <!--<p>Sign in so I can look for your inbox.</p>
        <form action="https://indieauth.com/auth" method="get" class="inner clearfix">
          <label for="indie_auth_url">Domain:</label>
          <input id="indie_auth_url" type="text" name="me" placeholder="yourdomain.com" />
          <input type="submit" value="signin" />
          <input type="hidden" name="client_id" value="http://rhiaro.co.uk" />
          <input type="hidden" name="redirect_uri" value="<?=$base?>" />
          <input type="hidden" name="state" value="<?=$base?>" />
          <input type="hidden" name="scope" value="post" />
        </form>-->
        <form method="get" id="direct">
          <p><label for="url">You're being hailed</label></p>
          <p><input id="url" name="url" type="text" placeholder="Resource to get notifications about (eg. your profile, or a blog post..)" value="<?=isset($_SESSION['url']) ? urldecode($_SESSION['url']) : ""?>" /><input type="submit" value="On screen." /></p>
        </form>
      <?else:?>
        <!--<p>You are logged in as <strong><?=$_SESSION['me']?></strong> <a href="?logout=1">Logout</a></p>-->
        <h3><?=$_SESSION['url']?></h3>
        <p style="text-align: center">[ <a href="<?=$_SESSION['inbox']?>">inbox</a> | <a href="?reset=url">reset</a> ]</p>
      <?endif?>

      <?if(isset($feed)):?>

        <?foreach($feed as $uri => $data):?>
          <h4><a href="<?=$uri?>">Notification</a></h4>
          <pre>
            <? var_dump($data);?>
          </pre>
          <ul class="wee">
            <?//foreach($data as $k => $v):?>
              <!--<li><?=$k?>: <strong><?=$v?></strong></li>-->
            <?//endforeach?>
          </ul>
        <?endforeach?>

      <?elseif(isset($_SESSION['url'])):?>
        <p class="fail">Could not read anything here.</p>
      <?endif?>

    </main>
  </body>
</html>