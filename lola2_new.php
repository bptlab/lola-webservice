<?php

$uuid = date("Ymd-His-".rand(0,10));
$workdir = "workdir/".$uuid;
$rootdir = "/var/www/lola";
$bindir = $rootdir."/.lola/local/bin";
$lola = "/usr/local/bin/lola";
$checks = [
    "deadlocks" => [
      "isChecked" => false,
      "formula" => "EF DEADLOCK",
      "type" => "global"
    ],
    "reversibility" => [
      "isChecked" => false,
      "formula" => "AGEF INITIAL",
      "type" => "global"
    ],
    "quasiliveness" => [
      "isChecked" => false,
      "command" => "quasiliveness",
      "type" => "all_transitions"
    ],
    "relaxed" => [
      "isChecked" => false,
      "command" => "relaxed",
      "type" => "all_transitions"
    ],
    "liveness" => [
      "isChecked" => false,
      "command" => "liveness",
      "type" => "all_transitions"
    ],
    "boundedness" => [
      "isChecked" => false,
      "command" => "boundedness",
      "type" => "all_transitions"
    ],
    "dead_transition" => [
      "isChecked" => false,
      "formula" => "AG NOT FIREABLE",
      "type" => "single_transition"
    ],
    "live_transition" => [
      "isChecked" => false,
      "formula" => "AG FIREABLE",
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
function make_simple_formular_request($key, $formula) {
  global $workdir;
  global $uuid;
  global $bindir;
  $handle = fopen($workdir."/".$uuid.$key.".formula", "w+");
  fwrite($handle, stripslashes($formula));
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
function make_single_transition_request($key, $formula, $transition) {
  global $workdir;
  global $uuid;
  global $bindir;
  $handle = fopen($workdir."/".$uuid.$key.".formula", "w+");
  fwrite($handle, stripslashes($formula." (".$transition.")"));
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

// START OF APPLICATION LOGIC

if (empty($_REQUEST)) {
    die("Empty request.");
}

if (empty($_REQUEST['input'])) {
    die("Empty input");
}

// Which checks are requested?
foreach($_REQUEST as $key => $value) {
  foreach($checks as $keyf => $valuef) {
    if (strcmp($key, $keyf) == 0) {
      $checks[$keyf]['isChecked'] = true;
    }
  }
}

// Write input net to temp file
mkdir($workdir);
if (is_url($_REQUEST['input'])) {
    copy($_REQUEST['input'], $workdir."/".$uuid.".pnml");
} else {
    $handle = fopen($workdir."/".$uuid.".pnml", "w+");
    fwrite($handle, stripslashes($_REQUEST['input']));
    fclose($handle);
}

// Convert PNML to LOLA
exec($bindir."/petri -ipnml -olola ".$workdir."/".$uuid.".pnml");
$jsonResult = [];
$arrayResult = [];

//prepare_complex_formular_requests($workdir, $bindir, $uuid);

// Execute each check
foreach($checks as $key => $check) {
    if($check['isChecked']) {
      switch($check['type']) {
        case "global":
          make_simple_formular_request($key, $check['formula']);
          break;
        case "all_transitions":
          make_complex_formular_request($key, $check['command']);
          break;
        case "single_transition":
          make_single_transition_request($key, $check['formula'], $_REQUEST[$key."_name"]);
          break;
        default:
          die("Unknown check");
          break;
      }
    }
}

?>
