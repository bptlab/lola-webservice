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
  // Place list regex: Produces a named capturing group "placelist" that contains the list of places
  //   (comma-separated plus optional whitespace, e.g. "p1, p2,p3")
  $place_list_regex = "/PLACE\s+(?P<placelist>(([^,;:()\t \n\r\{\}]+)(?:,\s*){0,1})*);/";
  // Marking list regex: Produces a named capturing group "markinglist" that contains the list of markings
  //   (comma-separated plus optional whitespace, e.g. "p1:1, p2,p3:3")
  $marking_list_regex = "/MARKING\s+(?P<markinglist>([^,;:()\t \n\r\{\}]+(\s*:\s*[0-9])?(,\s*)?)*);/";
  // Transition list regex: Produces three named capturing groups (will match for each transition individually):
  // Capturing group "transition" contains the name of the transition (e.g. "t1")
  // Capturing group "consume" contains the list of places that this transition consumes from
  //   (comma-separated plus optional whitespace, e.g. "p1:1, p2,p3:3")
  // Capturing group "produce" contains the list of places that this transition produces to
  //   (comma-separated plus optional whitespace, e.g. "p1:1, p2,p3:3")
  $transition_list_regex = "/TRANSITION\s+(?P<transition>[^,;:()\t \n\r\{\}]+)\s+CONSUME\s*(?P<consume>(?:[^,;:()\t \n\r\{\}]+(?:\s*:\s*[0-9])?(?:,\s*)?)*)?;\s+PRODUCE\s*(?P<produce>(?:[^,;:()\t \n\r\{\}]+(?:\s*:\s*[0-9])?(?:,\s*)?)*)?;/";

  // Extract places
  $matches = [];
  $result = preg_match($place_list_regex, $lola_contents, $matches);
  if ($result === FALSE)
    die ("Error matching place list regex");
  if ($result === 0)
    die ("Syntax error in LoLA file: Couldn't find any places");
  if (!isset($matches["placelist"]))
    die ("Named capture group for place list is non-existent!");

  $placelist = str_replace(" ", "", $matches["placelist"]);
  $places = explode(",", $placelist);
  if (count($places) == 0)
    die ("Could not extract places from LoLA file");

  // Extract initial markings
  $matches = [];
  $result = preg_match($marking_list_regex, $lola_contents, $matches);
  if ($result === FALSE)
    die ("Error matching marking list regex");
  if ($result === 0)
    die ("Syntax error in LoLA file: Couldn't find any markings");
  if (!isset($matches["markinglist"]))
    die ("Named capture group for marking list is non-existent!");

  $markinglist = str_replace(" ", "", $matches["markinglist"]);
  $markings = explode(",", $markinglist);
  if (count($markings) == 0)
    die ("Could not extract markings from LoLA file");

  // Extract transitions
  $matches = [];
  $result = preg_match_all($transition_list_regex, $lola_contents, $matches, PREG_SET_ORDER);
  if ($result === FALSE)
    die ("Error matching transition regex");
  if ($result === 0)
    die ("Syntax error in LoLA file: Couldn't find any transitions");

  $transitions = [];
  foreach($matches as $sub_matches) {
    if (!isset($sub_matches["transition"]))
      die ("Named capture group for transition is non-existent");
    if (!isset($sub_matches["consume"]))
      die ("Named capture group for transition consume list is non-existent");
    if (!isset($sub_matches["produce"]))
      die ("Named capture group for transition produce list is non-existent");
    $transition = htmlspecialchars($sub_matches["transition"]);
    $consume_raw = str_replace(" ", "", $sub_matches["consume"]);
    $consume_list_raw = explode(",", $consume_raw);
    if (count($consume_list_raw) == 0)
      die ("Could not extract consume list from LoLA file");
    $produce_raw = str_replace(" ", "", $sub_matches["produce"]);
    $produce_list_raw = explode(",", $produce_raw);
    if (count($produce_list_raw) == 0)
      die ("Could not extract produce list from LoLA file");

    // Extract place name and arc weight for consume list
    $consume = [];
    foreach ($consume_list_raw as $consume_entry) {
      $consume_entry = htmlspecialchars($consume_entry);
      $pos = strpos($consume_entry, ":");
      if ($pos === FALSE) {
        // Implicit count of 1
        $consume[] = ["place" => $consume_entry, "weight" => "1"];
      } else {
        $consume[] = ["place" => substr($consume_entry, 0, $pos), "weight" => substr($consume_entry, $pos+1)];
      }
    }

    // Extract place name and arc weight for produce list
    $produce = [];
    foreach ($produce_list_raw as $produce_entry) {
      $produce_entry = htmlspecialchars($produce_entry);
      $pos = strpos($produce_entry, ":");
      if ($pos === FALSE) {
        // Implicit count of 1
        $produce[] = ["place" => $produce_entry, "weight" => "1"];
      } else {
        $produce[] = ["place" => substr($produce_entry, 0, $pos), "weight" => substr($produce_entry, $pos+1)];
      }
    }

    $transitions[] = [
      "id" => $transition,
      "consume" => $consume,
      "produce" => $produce,
    ];
  }

  echo "<pre>";
  print_r($transitions);
  echo "</pre>";
}

function parse_lola_file2($lola_contents) {

  $token_definitions = [
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

  $token_stream = [];

  $token = "";
  $next_char = "";
  $last_match_type = "";
  $last_match_value = "";
  $last_match_complete = false;
  $i = 0;

  // Read characters one by one and extract tokens
  while($i < strlen($lola_contents)) {
    //$token = $token . $next_char;
    echo "token is '" . $token . "'<br />";
    $still_matching = false;
    $match_complete = false;
    foreach ($token_definitions as $token_definition) {
      if (isset($token_definition["str"])) {
          if(substr($token_definition["str"], 0, strlen($token)) == $token) {
            echo "str match: " . $token_definition["type"] . "<br />";
            $still_matching = true;
            $last_match_type = $token_definition["type"];
            $last_match_value = $token;
            if (strlen($token) >= strlen($token_definition["str"])) {
              $match_complete = true;
              break;
            }
          }
      } else { // regex
        if (preg_match($token_definition["regex"], $token) != FALSE) {
          echo "match " . $token_definition["type"] . "<br />";
          $still_matching = true;
          $last_match_type = $token_definition["type"];
          $last_match_value = $token;
          $match_complete = true;
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
      echo "not matching anymore. ";
      if (!$last_match_complete) {
        echo "Last match was incomplete.<br />";
        die("SYNTAX ERROR");
      }
      echo "Last match was " . $last_match_type . "<br />";

      if ($last_match_type != "WHITESPACE")
        $token_stream[] = ["type" => $last_match_type, "value" => $last_match_value];



      echo "Token stream is now: ";
      foreach ($token_stream as $token_found) {
        echo $token_found["value"] . " ";
      }
      echo "<br /><br />";

      $token = $next_char;
    }
    $last_match_complete = $match_complete;
  }

  if (count($token_stream) == 0)
    die ("Empty token stream");

  // Read tokens and create parse tree
  $grammar = [
    ["lhs" => "net", "rhs" => ["PLACE", "place_lists", "MARKING", "marking_list", "SEMICOLON", "transition_list"]],
    ["lhs" => "net", "rhs" => ["PLACE", "place_lists", "MARKING", "SEMICOLON", "transition_list"]],

    ["lhs" => "place_lists", "rhs" => ["place_lists", "place_list", "SEMICOLON"]],
    ["lhs" => "place_lists", "rhs" => ["place_list", "SEMICOLON"]],

    ["lhs" => "place_list", "rhs" => ["nodeident"]],
    ["lhs" => "place_list", "rhs" => ["place_list", "COMMA", "nodeident"]],

    ["lhs" => "nodeident", "rhs" => ["identifier"]],
    ["lhs" => "nodeident", "rhs" => ["number"]],

    ["lhs" => "marking_list", "rhs" => ["marking"]],
    ["lhs" => "marking_list", "rhs" => ["marking_list", "COMMA", "marking"]],

    ["lhs" => "marking", "rhs" => ["nodeident"]],
    ["lhs" => "marking", "rhs" => ["nodeident", "COLON", "number"]],

    ["lhs" => "transition_list", "rhs" => ["TRANSITION", "nodeident", "CONSUME", "marking_list", "SEMICOLON", "PRODUCE", "marking_list", "SEMICOLON"]],
    ["lhs" => "transition_list", "rhs" => ["TRANSITION", "nodeident", "CONSUME", "SEMICOLON", "PRODUCE", "marking_list", "SEMICOLON"]],
    ["lhs" => "transition_list", "rhs" => ["TRANSITION", "nodeident", "CONSUME", "marking_list", "SEMICOLON", "PRODUCE", "SEMICOLON"]],
    ["lhs" => "transition_list", "rhs" => ["TRANSITION", "nodeident", "CONSUME", "SEMICOLON", "PRODUCE", "SEMICOLON"]],
  ];

  $token_stack = [];
  while (count($token_stream) > 0) {
    $changed = false;
    //
    // foreach ($grammar as $grammar_rule) {
    //   for ($i = 0; $i < count($grammar_rule["rhs"]))
    // }

    if (!$changed) {
      echo "Cannot reduce anymore. ";
      $shifted_token = array_shift($token_stream);
      echo "Shifting '" . $shifted_token . "'<br />";
      array_push($token_stack, $shifted_token);
      $changed = true;
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
