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

// Timelimit in seconds per LoLA run
$lola_timelimit = 3;

// Maximum number of markings that LoLA may explore (correlates to memory usage)
$lola_markinglimit = 100000;

// Class to hold results in
class CheckResult
{
    function __construct($result, $witness_path)
    {
        $this->result = $result;
        $this->witness_path = $witness_path;
    }
    public $result = NULL;
    public $witness_path = "";
}

// Definition of checks
$checks = [
    "deadlocks" => [
      "isChecked" => false,
      "type" => "global",
      "function" => function() {
        return lola_check_global("deadlocks", "EF DEADLOCK");
      }
    ],
    "reversibility" => [
      "isChecked" => false,
      "type" => "global",
      "function" => function() {
        return lola_check_global("reversibility", "AGEF INITIAL");
      }
    ],
    "quasiliveness" => [
      "isChecked" => false,
      "type" => "all_transitions",
      "function" => function() {
        // A net is quasi-live if it does not have any dead transition.
        return lola_check_all_transitions_negated("quasiliveness", "AG NOT FIREABLE");
      }
    ],
    "liveness" => [
      "isChecked" => false,
      "type" => "all_transitions",
      "function" => function() {
        // A net is live if all its transitions are live.
        return lola_check_all_transitions("liveness", "AGEF FIREABLE");
      }
    ],
    "soundness" => [
      "isChecked" => false,
      "type" => "all_transitions",
      "function" => function() {
        // A net is sound if the final marking is reachable from all reachable
        // markings and there is no dead transition.
        global $lola_filename;
        global $petrinet;
        global $checks;
        assert_is_workflow_net($petrinet);

        $all_but_sink_places = array_diff($petrinet["places"], $petrinet["sink_places"]);
        $only_sink_active_formula = "";
        foreach ($all_but_sink_places as $place) {
          $only_sink_active_formula .= $place . " = 0 AND ";
        }
        $only_sink_active_formula .= $petrinet["sink_places"][0] . " = 1";

        $formula = "AGEF (" . $only_sink_active_formula . ")";

        // Check liveness of reachability of final marking
        $ret = exec_lola_check("soundness", $formula);
        if (!$ret->result)
          return $ret;

        // Check that there is no dead transition (quasi-liveness)
        return $checks["quasiliveness"]["function"]();
      }
    ],
    "relaxed_soundness" => [
      "isChecked" => false,
      "type" => "all_transitions",
      "function" => function() {
        // A net is relaxed sound if each transition can participate in at least one sound execution
        // - meaning that there is a path that contains the transition that ends successfully
        // (only marking left in the net is on the sink place)
        global $lola_filename;
        global $petrinet;
        assert_is_workflow_net($petrinet);

        $all_but_sink_places = array_diff($petrinet["places"], $petrinet["sink_places"]);
        $only_sink_active_formula = "";
        foreach ($all_but_sink_places as $place) {
          $only_sink_active_formula .= $place . " = 0 AND ";
        }
        $only_sink_active_formula .= $petrinet["sink_places"][0] . " = 1";

        foreach ($petrinet["transitions"] as $transition) {
          $safe_name = safe_name($transition["id"]);
          $individual_check_name = "relaxed_soundness" . "." . $safe_name;

          $formula = "EF ( FIREABLE(" . $transition["id"] . ") AND EF (" . $only_sink_active_formula . "))";
          $ret = exec_lola_check($individual_check_name, $formula);
          if (!$ret->result)
            return $ret;
        }
        return new CheckResult(true, "");
      }
    ],
    "boundedness" => [
      "isChecked" => false,
      "type" => "all_transitions",
      "function" => function() {
        global $petrinet;
        foreach ($petrinet["places"] as $place) {
          $safe_place_name = safe_name($place);
          $individual_check_name = "boundedness" . "." . $safe_place_name;
          $formula = "AG " . $place . " < oo";
          $extra_parameters = "--encoder=full --search=cover";
          $ret = exec_lola_check($individual_check_name, $formula, $extra_parameters);
          if (!$ret->result)
            return $ret;
        }
        return new CheckResult(true, "");
      }
    ],
    "dead_transition" => [
      "isChecked" => false,
      "formula" => "AGEF NOT FIREABLE",
      "type" => "single_transition",
      "function" => function($transition) {
        return lola_check_single_transition("dead_transition", "AG NOT FIREABLE", $transition);
      }
    ],
    "live_transition" => [
      "isChecked" => false,
      "formula" => "AGEF FIREABLE",
      "type" => "single_transition",
      "function" => function($transition) {
        return lola_check_single_transition("live_transition", "AGEF FIREABLE", $transition);
      }
    ],
];



//
// FUNCTIONS
//

// Output debug messages to inline output
function debug($data) {
  global $debug_switch;
  if (!$debug_switch)
    return;

  if (is_array($data) || is_object($data)) {
    echo "<pre>"; print_r($data); echo "</pre>";
  } else {
    echo "<br />\n" . htmlspecialchars($data) . "<br />\n";
  }
}

function terminate($msg) {
  global $uuid;
  echo $msg . "<br />\n";
  echo "UUID: " . $uuid . "<br />\n";
  echo "If you think this is an error, please attach your input file as well as the above UUID to your report.<br />\n";
  die();
}

// Parse LoLA file into associative array
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

  // Determine source place(s) (no incoming edges)
  $source_place_candidates = $places;
  $transition_target_places = [];
  foreach ($transitions as $transition) {
    foreach ($transition["produce"] as $target) {
      array_push($transition_target_places, $target["place"]);
    }
  }
  $source_places = array_values(array_diff($source_place_candidates, $transition_target_places));

  // Determine sink place(s) (no outgoing edges)
  $sink_place_candidates = $places;
  $transition_source_places = [];
  foreach ($transitions as $transition) {
    foreach ($transition["consume"] as $source) {
      array_push($transition_source_places, $source["place"]);
    }
  }
  $sink_places = array_values(array_diff($sink_place_candidates, $transition_source_places));

  $petrinet = [
    "places" => $places,
    "markings" => $markings,
    "transitions" => $transitions,
    "source_places" => $source_places,
    "sink_places" => $sink_places,
  ];

  return $petrinet;
}

// Assert that the given petrinet is a workflow net.
// A workflow net has exactly one source place (no incoming edges),
// exactly one sink place (no outgoing edges) and exactly one initial marking.
function assert_is_workflow_net($petrinet) {
  if (count($petrinet["source_places"]) == 0) {
    terminate("The petri net has no source places and therefore is no workflow net");
  }
  if (count($petrinet["source_places"]) > 1) {
    terminate("The petri net has more than one source place and therefore is no workflow net");
  }
  if (count($petrinet["sink_places"]) == 0) {
    terminate("The petri net has no sink places and therefore is no workflow net");
  }
  if (count($petrinet["sink_places"]) > 1) {
    terminate("The petri net has more than one sink place and therefore is no workflow net");
  }
  if (count($petrinet["markings"]) == 0) {
    terminate("The petri net has zero initial markings and therefore is no workflow net");
  }
  if (count($petrinet["markings"]) > 1) {
    terminate("The petri net has more than one initial marking and therefore is no workflow net");
  }
}

// Mangle a place / transition name so that the result is a string that is safe to write to disk etc.
// by replacing all but "word" characters
function safe_name($name) {
  return preg_replace("/\W/", "_", $name);
}

// Execute LoLA with given formula and parse result
function exec_lola_check($check_name, $formula, $extra_parameters = "") {
  global $lola_filename;
  global $lola_timelimit;
  global $lola_markinglimit;
  global $lola;
  $json_filename = $lola_filename . "." . $check_name . ".json";
  $path_filename = $lola_filename . "." . $check_name . ".path";
  $process_output = [];
  $return_code = 0;

  // Run LoLA
  $lola_command = $lola . " --timelimit=" . $lola_timelimit . " --markinglimit=" . $lola_markinglimit . " " . $extra_parameters . " --formula='" . $formula . "' --path='" . $path_filename . "' --json='" . $json_filename . "' '" . $lola_filename . "' 2>&1";
  debug("Running command " . $lola_command);
  exec($lola_command, $process_output, $return_code);

  debug($process_output);

  // Check if run was okay
  if ($return_code != 0) {
    echo "LoLA exited with code ". $return_code . "<br />";
    terminate();
  }

  // Load and parse result JSON file
  $string_result = file_get_contents($json_filename);
  if ($string_result === FALSE)
    terminate($check_name . ": Can't open result file " . $json_filename);

  $json_result = json_decode($string_result, TRUE);

  if (!isset($json_result["analysis"]) || !isset($json_result["analysis"]["result"])) {
    debug($json_result);
    terminate($check_name . ": malformed JSON result");
  }

  // Load witness path
  $witness_path = "";
  if (file_exists($path_filename))
    $witness_path = file_get_contents($path_filename);

  // Create result object
  $result = new CheckResult((boolean)($json_result["analysis"]["result"]), $witness_path);

  // Return analysis result
  return $result;
}

// Run a check on the whole net
function lola_check_global($check_name, $formula) {
  return exec_lola_check($check_name, $formula);
}

// Run a check on a single transition
function lola_check_single_transition($check_name, $formula, $transition_name) {
  global $petrinet;

  // Check if this transition is present
  if (!array_filter($petrinet["transitions"], function($transition) use ($transition_name) { return $transition["id"] == $transition_name; })) {
    terminate("This transition does not exist in the petri net");
  }

  $safe_transition_name = preg_replace("/\W/", "", $transition_name);
  $individual_check_name = $check_name . "." . $safe_transition_name;

  $formula = $formula . "(" . $transition_name . ")";
  return exec_lola_check($individual_check_name, $formula);
}

// Run a check on every transition individually
function lola_check_all_transitions($check_name, $formula) {
  global $petrinet;
  foreach ($petrinet["transitions"] as $transition) {
    $ret = lola_check_single_transition($check_name, $formula, $transition["id"]);
    if (!$ret->result) {
      debug("Single transition check " . $check_name . " for transition " . $transition["id"] . " failed, returning false");
      // Re-using last returned result object - containing witness path
      return $ret;
    }
  }
  return new CheckResult(true, "");
}

// Run a check on every transition individually - negated
function lola_check_all_transitions_negated($check_name, $formula) {
  global $petrinet;
  foreach ($petrinet["transitions"] as $transition) {
    $ret = lola_check_single_transition($check_name, $formula, $transition["id"]);
    if ($ret->result) {
      debug("Single negated transition check " . $check_name . " for transition " . $transition["id"] . " succeeded, returning false");
      return new CheckResult(false, $ret->witness_path);
    }
  }
  return new CheckResult(true, "");
}

// Write input (PNML or LOLA or whatever) to file
function write_input_to_file($input, $filepath) {
  $handle = fopen($filepath, "w+");
  if ($handle === FALSE)
    terminate("Can't open temp file");
  fwrite($handle, $input);
  fclose($handle);
}

// Convert .pnml file to .lola file and return path
function convert_pnml_to_lola($pnml_filename) {
  $return_code = null;
  $process_output = [];

  exec($petri . " -ipnml -olola ".$pnml_filename, $process_output, $return_code);
  if ($return_code != 0) {
    echo "petri exited with status " . $return_code . " -- probably the input is malformed.<br />";
    foreach ($process_output as $line) {
      echo htmlspecialchars($line) . "<br />";
    }
    terminate();
  }

  $lola_filename = $pnml_filename . ".lola";
  return $lola_filename;
}

//
// APPLICATION LOGIC
//

// Read input
if (empty($_REQUEST)) {
    terminate("Empty request.");
}

mkdir($workdir);
debug($workdir);

if (isset($_FILES['file']) && !empty($_FILES['file'])) {
  // Move uploaded file to workdir
  $uploaded_file_tmp_name = $_FILES['file']['tmp_name'];
  $uploaded_file_new_name = $workdir."/".$uuid."uploaded.tmp";
  move_uploaded_file($uploaded_file_tmp_name, $uploaded_file_new_name);
  debug($uploaded_file_tmp_name);
  debug($uploaded_file_new_name);
  $input_content = file_get_contents($uploaded_file_new_name);
} elseif (isset($_REQUEST['input']) && !empty($_REQUEST['input'])) {
  // Read from form text input
  $input_content = stripslashes($_REQUEST['input']);
} else {
  terminate("Empty input");
}

$dead_transition_name = htmlspecialchars($_REQUEST['dead_transition_name']);
$live_transition_name = htmlspecialchars($_REQUEST['live_transition_name']);

$custom_formula_content = "";
if (isset($_REQUEST['custom_formula']))
  $custom_formula_content = $_REQUEST['custom_formula_content'];

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
  terminate("No checks selected.");

// Check if PNML or already LOLA
$pnml_prefix = "<?xml ";
$lola_prefix = "PLACE";
$lola_filename = $workdir."/".$uuid.".lola";
$lola_content = "";

if (substr($input_content, 0, strlen($pnml_prefix)) === $pnml_prefix) {
	// PNML
	// Write input net to temp file
	$pnml_filename = $workdir."/".$uuid.".pnml";
	write_input_to_file($input_content, $pnml_filename);

	// Convert PNML to LOLA
	$lola_filename = convert_pnml_to_lola($pnml_filename);
	
	// Read LOLA file
	$lola_content = file_get_contents($lola_filename);
	if ($lola_content === FALSE)
		terminate("Can't open converted file");
		
} elseif (substr($input_content, 0, strlen($lola_prefix)) === $lola_prefix) {
	// LOLA
	// Write input net to temp file
	write_input_to_file($input_content, $lola_filename);
	$lola_content = $input_content;
} else {
	// Unknown
	terminate("Input is neither in PNML (or missing XML header) nor LOLA format");
}

$jsonResult = [];
$arrayResult = [];

// "Parse" LOLA file to get list of transitions
$petrinet = parse_lola_file($lola_content);
debug($petrinet);

// Execute each check
foreach($checks as $check_name => $check_properties) {
    if($check_properties['isChecked']) {
      $result = false;
      switch($check_properties['type']) {
        case "global":
        case "all_transitions":
          $result = $check_properties['function']();
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
              terminate("Unknown single-transition check");
              break;
          }
          $result = $check_properties['function']($transition_name);
          break;
        default:
          terminate("Unknown check");
          break;
      }
      // Output check result
      debug($result);
      echo $check_name . " = " . ($result->result ? 'true' : 'false') . ";<br />\n";
      if ($result->witness_path)
        echo $check_name . "_witness_path = '" . $result->witness_path . "';<br />\n";
    }
}

// Run custom check
if ($custom_formula_content) {
  $result = exec_lola_check("custom", $custom_formula_content);
  echo "custom_check" . " = " . ($result->result ? 'true' : 'false') . ";<br />\n";
  if ($result->witness_path)
    echo "custom_check_witness_path = '" . $result->witness_path . "';<br />\n";
}

?>
