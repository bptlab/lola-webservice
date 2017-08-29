<?php

$uuid=date("Ymd-His-".rand(0,10));
$workdir="workdir/".$uuid;
$rootdir="/var/www/lola";
$bindir=$rootdir."/.lola/local/bin";
$formulars = [
    "deadlocks" => [
      "ischecked" => false,
      "formularexpression" => "EF DEADLOCK",
      "type" => "simple"
    ],
    "reversibility" => [
      "ischecked" => false,
      "formularexpression" => "AGEF INITIAL",
      "type" => "simple"
    ],
    "quasiliveness" => [
      "ischecked" => false,
      "command" => "quasiliveness",
      "type" => "complex"
    ],
    "relaxed" => [
      "ischecked" => false,
      "command" => "relaxed",
      "type" => "complex"
    ],
    "liveness" => [
      "ischecked" => false,
      "command" => "liveness",
      "type" => "complex"
    ],
    "boundedness" => [
      "ischecked" => false,
      "command" => "boundedness",
      "type" => "complex"
    ],
    "dead_transition" => [
      "ischecked" => false,
      "formularexpression" => "AG NOT FIREABLE",
      "type" => "single_transition"
    ],
    "live_transition" => [
      "ischecked" => false,
      "formularexpression" => "AG FIREABLE",
      "type" => "single_transition"
    ],
];

//ini_set("display_errors", 1);
//ini_set("track_errors", 1);
//ini_set("html_errors", 1);

function is_url($url){
    $url = substr($url,-1) == "/" ? substr($url,0,-1) : $url;
    if ( !$url || $url=="" ) return false;
    if ( !( $parts = @parse_url( $url ) ) ) return false;
    else {
        if ( $parts["scheme"] != "http" && $parts["scheme"] != "https" && $parts["scheme"] != "ftp" && $parts["scheme"] != "gopher" ) return false;
        else if ( !eregi( "^[0-9a-z]([-.]?[0-9a-z])*.[a-z]{2,4}$", $parts[host], $regs ) ) return false;
        else if ( !eregi( "^([0-9a-z-]|[_])*$", $parts[user], $regs ) ) return false;
        else if ( !eregi( "^([0-9a-z-]|[_])*$", $parts[pass], $regs ) ) return false;
        else if ( !eregi( "^[0-9a-z/_.@~-]*$", $parts[path], $regs ) ) return false;
        else if ( !eregi( "^[0-9a-z?&=#,]*$", $parts[query], $regs ) ) return false;
    }
    return true;
}

function debug_to_console($data) {
    if (is_array($data))
        $output = "<script>console.log( 'Debug Objects: " . implode( ',', $data) . "' );</script>";
    else
        $output = "<script>console.log( 'Debug Objects: " . $data . "' );</script>";

    echo $output;
}

function deleteDirectory($dir) {
    if (!file_exists($dir)) {
        return true;
    }

    if (!is_dir($dir)) {
        return unlink($dir);
    }

    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') {
            continue;
        }

        if (!deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
            return false;
        }

    }

    return rmdir($dir);
}

// create formular call for functions that do not need an extra lola-executable
function make_simple_formular_request($key, $formular) {
  global $workdir;
  global $uuid;
  global $bindir;
  $handle = fopen($workdir."/".$uuid.$key.".formula", "w+");
  fwrite($handle, stripslashes($formular));
  fclose($handle);
  exec($bindir."/lola --formula=".$workdir."/".$uuid.$key.".formula --quiet --json=/var/www/lola/".$workdir.$key."/output.json --path ".$workdir."/".$uuid.".pnml.lola", $path);
  $jsonResult[$key] = file_get_contents("/var/www/lola/".$workdir."/output.json");
  $arrayResult[$key] = json_decode($jsonResult[$key], TRUE);

  clearstatcache();
  echo("<b>Check for ".$key."</b></br>");
  if($arrayResult[$key]['analysis']['result']) {
    echo nl2br("satisfied\n");
  }
  else {
    echo nl2br("not satisfied\n");
  }
}

// create call for functions which needs a single transition
function make_single_transition_request($key, $formular, $transition) {
  global $workdir;
  global $uuid;
  global $bindir;
  $handle = fopen($workdir."/".$uuid.$key.".formula", "w+");
  fwrite($handle, stripslashes($formular." (".$transition.")"));
  fclose($handle);
  exec($bindir."/lola --formula=".$workdir."/".$uuid.$key.".formula --quiet --json=/var/www/lola/".$workdir.$key."/output.json --path ".$workdir."/".$uuid.".pnml.lola", $path);
  $jsonResult[$key] = file_get_contents("/var/www/lola/".$workdir."/output.json");
  $arrayResult[$key] = json_decode($jsonResult[$key], TRUE);

  clearstatcache();
  echo("<b>Check for ".$key." ".$transition."</b></br>");
  if($arrayResult[$key]['analysis']['result']) {
    echo nl2br("satisfied\n");
  }
  else {
    echo nl2br("not satisfied\n");
  }
}

// create call for functions that need an extra lola-executable (located in .lola/local/bin/)
function make_complex_formular_request($key, $command) {
  global $bindir;
  set_time_limit(10);
  system($bindir."/timeout3 -t 7 -d 2 " . "/usr/bin/make ".$command." >/dev/null 2>&1");
  echo("<b>Check for ".$key."</b></br>");
  exec($bindir."/process.sh", $finaloutput);
  debug_to_console($finaloutput);
  foreach($finaloutput as $val) {
      echo($val."\n");
  }
}

function prepare_complex_formular_requests() {
  global $workdir;
  global $bindir;
  global $rootdir;
  global $uuid;
  exec($bindir."/petri -ipnml -oowfn ".$workdir."/".$uuid.".pnml");
  // create Makefile
  chdir($workdir);
  $handle = fopen("Makefile", "w+");
  fwrite($handle, "PATH := $(PATH):".$bindir."\n\n");
  exec($bindir."/sound ".$uuid.".pnml.owfn", $output);
  foreach($output as $val) {
      fwrite($handle, $val."\n");
  }
  fclose($handle);
  chdir($rootdir);
}

if (empty($_REQUEST)) {
    die("Empty request.");
}

if (empty($_REQUEST['input'])) {
    die("Empty input");
}

foreach($_REQUEST as $key => $value) {
  foreach($formulars as $keyf => $valuef) {
    if (strcmp($key, $keyf) == 0) {
      $formulars[$keyf]['ischecked'] = true;
    }
  }
}

mkdir($workdir);
if (is_url($_REQUEST['input'])) {
    copy($_REQUEST['input'], $workdir."/".$uuid.".pnml");
} else {
    $handle = fopen($workdir."/".$uuid.".pnml", "w+");
    fwrite($handle, stripslashes($_REQUEST['input']));
    fclose($handle);
}

exec($bindir."/petri -ipnml -olola ".$workdir."/".$uuid.".pnml");
$jsonResult = [];
$arrayResult = [];

prepare_complex_formular_requests($workdir, $bindir, $uuid);

foreach($formulars as $key => $formular) {
    if($formular['ischecked']) {
      switch($formular['type']) {
        case "simple":
          make_simple_formular_request($key, $formular['formularexpression']);
          break;
        case "complex":
          make_complex_formular_request($key, $formular['command']);
          break;
        case "single_transition":
          make_single_transition_request($key, $formular['formularexpression'], $_REQUEST[$key."_name"]);
          break;
      }
    }
}

?>
