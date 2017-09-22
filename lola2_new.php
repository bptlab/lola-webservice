<?php

//
// CONFIGURATION
//


// Please report all problems, thanks
error_reporting(-1);

//ini_set("display_errors", 1);
//ini_set("track_errors", 1);
//ini_set("html_errors", 1);

// Set to 'true' to enable debug messages
$debug_switch = false;

// Location of 'petri' binary
$petri = "/opt/lola/bin/petri";

// Location of 'lola' (2.x) binary
$lola = "/usr/local/bin/lola";

// "UUID" generation for workdir
$uuid = date("Ymd-His-".rand(0,10));

// Location of workdir
$workdir = "/data/lola-workdir/".$uuid;

// Definition of checks
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



//
// FUNCTIONS
//
function parse_lola_file($lola_contents) {

  $token_list = [
    ["type" => "PLACE",       "str" => "PLACE"],
    ["type" => "MARKING",     "str" => "MARKING"],
    ["type" => "SAFE",        "str" => "SAFE"],
    ["type" => "NUMBER",      "str" => "NUMBER"],
    ["type" => "TRANSITION",  "str" => "TRANSITION"],
    ["type" => "CONSUME",     "str" => "CONSUME"],
    ["type" => "PRODUCE",     "str" => "PRODUCE"],
    ["type" => "STRONG",      "str" => "STRONG"],
    ["type" => "WEAK",        "str" => "WEAK"],
    ["type" => "FAIR",        "str" => "FAIR"],
    ["type" => "LPAR",        "str" => "\("],
    ["type" => "RPAR",        "str" => "\)"],
    ["type" => "RPAR",        "str" => "\)"],
    ["type" => "COMMA",       "str" => ","],
    ["type" => "SEMICOLON",   "str" => ";"],
    ["type" => "COLON",       "str" => ":"],
    ["type" => "WHITESPACE",  "regex" => "/\A\s+\z/"],
    ["type" => "number",      "regex" => "/\A-?[0-9]+\z/"],
    ["type" => "identifier",  "regex" => "/\A[^,;:()\t \n\r\{\}]+\z/"],
  ];

  // remove comments
  $lola_contents = preg_replace("/{[^}]*}/", "", $lola_contents);

  $token_stack = [];

  $token = "";
  $next_char = "";
  $last_match_type = "";
  $last_match_value = "";
  $i = 0;

  while($i < strlen($lola_contents)) {
    //$token = $token . $next_char;
    echo "token is '" . $token . "'<br />";
    $still_matching = false;
    foreach ($token_list as $token_entry) {
      if (isset($token_entry["str"])) {
          if(substr($token_entry["str"], 0, strlen($token)) == $token) {
            echo "str match: " . $token_entry["type"] . "<br />";
            $still_matching = true;
            $last_match_type = $token_entry["type"];
            $last_match_value = $token;
            break;
          }
      } else { // regex
        if (preg_match($token_entry["regex"], $token) != FALSE) {
          echo "match " . $token_entry["type"] . "<br />";
          $still_matching = true;
          $last_match_type = $token_entry["type"];
          $last_match_value = $token;
          break;
        }
      }
    }

    if ($still_matching) {
      $next_char = $lola_contents[$i];
      $i++;
      echo "reading '" . $next_char . "'<br />";
      $token = $token . $next_char;
    } else {
      echo "not matching anymore, last match was " . $last_match_type . "<br />";

      if ($last_match_type != "WHITESPACE")
        $token_stack[] = ["type" => $last_match_type, "value" => $last_match_value];

      $token = $next_char;
    }
  }
}

// Output debug messages to inline output
function debug($data) {
  global $debug_switch;
  if (!$debug_switch)
    return;

  if (is_array($data)) {
    echo "<pre>"; print_r($data); echo "</pre>";
  } else {
    echo "<br />\n" . htmlspecialchars($data) . "<br />\n";
  }
}

// Execute LoLA with given formula and parse result
function exec_lola_check($lola_filename, $check_name, $formula) {
  global $lola;
  $json_filename = $lola_filename . "." . $check_name . ".json";
  $process_output = [];
  $return_code = 0;

  // Run LoLA
  $lola_command = $lola . " --formula='" . $formula . "' --json='" . $json_filename . "' '" . $lola_filename . "' 2>&1";
  debug("Running command " . $lola_command);
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

  // Return analysis result as bool
  return (boolean)($json_result["analysis"]["result"]);
}

// Run a check on the whole net
function lola_check_global($lola_filename, $check_name) {
  global $checks;
  $formula = $checks[$check_name]['formula'];
  return exec_lola_check($lola_filename, $check_name, $formula);
}

// Run a check on a single transition
function lola_check_single_transition($lola_filename, $check_name, $transition_name) {
  global $checks;
  $safe_transition_name = preg_replace("/\W/", "", $transition_name);
  $individual_check_name = $check_name . "." . $safe_transition_name;
  $formula = $checks[$check_name]['formula'] . "(" . $transition_name . ")";
  return exec_lola_check($lola_filename, $individual_check_name, $formula);
}

// Run a check on every transition individually
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

//
// APPLICATION LOGIC
//

// Read input
if (empty($_REQUEST)) {
    die("Empty request.");
}

if (empty($_REQUEST['input'])) {
    die("Empty input");
}

mkdir($workdir);
echo $workdir . "<br />";

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

exec($petri . " -ipnml -olola ".$pnml_filename, $process_output, $return_code);
if ($return_code != 0) {
  echo "petri returned " . $return_code . "<br />";
  foreach ($process_output as $line) {
    echo htmlspecialchars($line) . "<br />";
  }
  die();
}
$jsonResult = [];
$arrayResult = [];

// "Parse" LOLA file to get list of transitions
$lola_filename = $workdir."/".$uuid.".pnml.lola";
$lola_content = file_get_contents($lola_filename);
if ($lola_content === FALSE)
  die("Can't open converted file");

parse_lola_file($lola_content);
die("breakpoint");

$matches = [];
$transition_regex = '/\sTRANSITION\s+([^,;:()\t \n\r\{\}]+)\s/';
$count = preg_match_all($transition_regex, $lola_content, $matches);
if ($count == 0)
  die ("No transitions found");

$transitions = array();
foreach ($matches[1] as $match) {
  $transitions[] = htmlspecialchars($match);
}

// Extract initial markings
$matches = [];
$marking_regex = '^\s*MARKING\s*([^,;:()\t \n\r\{\}]+):1\s*;';
$count = preg_match_all($marking_regex, $lola_content, $matches);
if ($count != 1)
  die ("No initial markings or too many found! There must be exactly one initial marking.");

foreach ($matches[1] as $match) {
  echo "Initial marking: " . $match . "<br />";
  //$transitions[] = htmlspecialchars($match);
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
          // Ugly hack because every single-transition check has its own text input
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
      // Output check result
      echo $check_name . " = " . ($result ? 'true' : 'false') . ";<br />\n";
    }
}

// Run custom check
if ($custom_formula_content) {
  $result = exec_lola_check($lola_filename, "custom", $custom_formula_content);
  echo "custom_check" . " = " . ($result ? 'true' : 'false') . ";<br />\n";
}

?>
