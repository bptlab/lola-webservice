<?php

// Please report all problems, thanks
error_reporting(-1);

//ini_set("display_errors", 1);
//ini_set("track_errors", 1);
//ini_set("html_errors", 1);

// Set to 'true' to enable debug messages
$debug_switch = false;

$rootdir = "/var/www/lola";
$bindir = "/opt/lola/bin";
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
      "formula" => "AGEF FIREABLE", // net satisfies quasiliveness if no transition is dead (= each transition can fire eventually)
      "type" => "all_transitions"
    ],
    "relaxed" => [
      "isChecked" => false,
      "formula" => "", // TODO FIXME
      "type" => "all_transitions"
    ],
    "liveness" => [
      "isChecked" => false,
      "formula" => "", // TODO FIXME
      "type" => "all_transitions"
    ],
    "boundedness" => [
      "isChecked" => false,
      "formula" => "", // TODO FIXME
      "type" => "all_transitions"
    ],
    "dead_transition" => [
      "isChecked" => false,
      "formula" => "AGEF NOT FIREABLE",
      "type" => "single_transition"
    ],
    "live_transition" => [
      "isChecked" => false,
      "formula" => "AGEF FIREABLE",
      "type" => "single_transition"
    ],
];

// Output debug messages to inline output and browser console
function debug($data) {
  global $debug_switch;
  if (!$debug_switch)
    return;

  // if (is_array($data))
  //   echo "<script>console.log( 'Debug Objects: " . implode( ',', $data) . "' );</script>";
  // else
  //   echo "<script>console.log( 'Debug Objects: " . $data . "' );</script>";

  if (is_array($data)) {
    echo "<pre>"; print_r($data); echo "</pre>";
  } else {
    echo "<br />\n" . htmlspecialchars($data) . "<br />\n";
  }
}

// Global check
function exec_lola_check($lola_filename, $check_name, $formula) {
  global $lola;
  $json_filename = $lola_filename . "." . $check_name . ".json";
  $process_output = [];
  $return_code = 0;

  $lola_command = $lola . " --formula='" . $formula . "' --json='" . $json_filename . "' '" . $lola_filename . "' 2>&1";
  debug("Running command " . $lola_command);
  // Run LoLA
  exec($lola_command, $process_output, $return_code);

  debug($process_output);

  // Check if run was okay
  if ($return_code != 0) {
    echo "LoLA exited with code ". $return_code . "<br />";
    die();
  }

  // Load and parse result JSON file
  $string_result = file_get_contents($json_filename);
  if ($string_result === FALSE)
    die($check_name . ": Can't open result file " . $json_filename);

  $json_result = json_decode($string_result, TRUE);

  if (!isset($json_result["analysis"]) || !isset($json_result["analysis"]["result"])) {
    debug($json_result);
    die($check_name . ": malformed JSON result in " . $json_filename);
  }

  return (boolean)($json_result["analysis"]["result"]);
}

function lola_check_global($lola_filename, $check_name) {
  global $checks;
  $formula = $checks[$check_name]['formula'];
  return exec_lola_check($lola_filename, $check_name, $formula);
}

function lola_check_all_transitions($lola_filename, $check_name) {
  global $checks;
  global $transitions;
  foreach ($transitions as $transition_name) {
    $ret = lola_check_single_transition($lola_filename, $check_name, $transition_name);
    if (!$ret)
      return false;
  }
  return true;
}

function lola_check_single_transition($lola_filename, $check_name, $transition_name) {
  global $checks;
  $safe_transition_name = preg_replace("/\W/", "", $transition_name);
  $individual_check_name = $check_name . "." . $safe_transition_name;
  $formula = $checks[$check_name]['formula'] . "(" . $transition_name . ")";
  return exec_lola_check($lola_filename, $individual_check_name, $formula);
}

// START OF APPLICATION LOGIC

if (empty($_REQUEST)) {
    die("Empty request.");
}

if (empty($_REQUEST['input'])) {
    die("Empty input");
}

$uuid = date("Ymd-His-".rand(0,10));
$workdir = "/data/lola-workdir/".$uuid;

mkdir($workdir);

$pnml_input = stripslashes($_REQUEST['input']);
$pnml_filename = $workdir."/".$uuid.".pnml";

$dead_transition_name = htmlspecialchars($_REQUEST['dead_transition_name']);
$live_transition_name = htmlspecialchars($_REQUEST['live_transition_name']);

$custom_formula_content = "";
if ($_REQUEST['custom_formula'])
  $custom_formula_content = htmlspecialchars($_REQUEST['custom_formula_content']);

// Which checks are requested?
$num_checks = 0;
foreach($_REQUEST as $key => $value) {
  foreach($checks as $keyf => $valuef) {
    if (strcmp($key, $keyf) == 0) {
      $checks[$keyf]['isChecked'] = true;
      $num_checks++;
    }
  }
}

if ($num_checks == 0 && !$custom_formula_content)
  die("No checks selected.");

// Write input net to temp file
$handle = fopen($pnml_filename, "w+");
if ($handle === FALSE)
  die("Can't open temp file");
fwrite($handle, $pnml_input);
fclose($handle);

// Convert PNML to LOLA
$return_code = null;
$process_output = [];

exec($bindir . "/petri -ipnml -olola ".$pnml_filename, $process_output, $return_code);
if ($return_code != 0) {
  echo "petri returned " . $return_code . "<br />";
  foreach ($process_output as $line) {
    echo htmlspecialchars($line) . "<br />";
  }
  die();
}
$jsonResult = [];
$arrayResult = [];

// Parse LOLA file to get list of transitions
$lola_filename = $workdir."/".$uuid.".pnml.lola";
$lola_content = file_get_contents($lola_filename);
if ($lola_content === FALSE)
  die("Can't open converted file");

$matches = [];
$count = preg_match_all(
    '/\sTRANSITION\s+([^,;:()\t \n\r\{\}]+)\s/',
    $lola_content,
    $matches
);
if ($count == 0)
  die ("No transitions found");

$transitions = array();
foreach ($matches[1] as $match) {
  $transitions[] = htmlspecialchars($match);
}

// Execute each check
foreach($checks as $check_name => $check_properties) {
    if($check_properties['isChecked']) {
      $result = false;
      switch($check_properties['type']) {
        case "global":
          $result = lola_check_global($lola_filename, $check_name);
          break;
        case "all_transitions":
          $result = lola_check_all_transitions($lola_filename, $check_name);
          break;
        case "single_transition":
          $transition_name = "";
          switch($check_name) {
            case "dead_transition":
              $transition_name = $dead_transition_name;
              break;
            case "live_transition":
              $transition_name = $live_transition_name;
              break;
            default:
              die("Unknown single-transition check");
              break;
          }
          $result = lola_check_single_transition($lola_filename, $check_name, $transition_name);
          break;
        default:
          die("Unknown check");
          break;
      }
      echo $check_name . " = " . ($result ? 'true' : 'false') . ";<br />\n";
    }
}

if ($custom_formula_content) {
  $result = exec_lola_check($lola_filename, "custom", $custom_formula_content);
  echo "custom_check" . " = " . ($result ? 'true' : 'false') . ";<br />\n";
}

?>
