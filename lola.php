<?php

require_once("lola_file_parser.php");
require_once("lola_functions.php");
require_once("lola_checks.php");

//
// CONFIGURATION
//

// We will write JSON
header('Content-type:application/json;charset=utf-8');

// Output dictionary, to be returned as JSON
$output = array();

// Please report all problems, thanks
error_reporting(-1);

// bail on all problems
$exception_error_handler = function($errno, $errstr, $errfile, $errline ) {
  header("HTTP/1.0 500 Internal Server Error");
  $output["php_error"] = array();
  $output["php_error"]["str"] = $errstr;
  $output["php_error"]["file"] = $errfile;
  $output["php_error"]["line"] = $errline;
  json_encode($output);
  throw new ErrorException($errstr, $errno, 0, $errfile, $errline);
};
set_error_handler($exception_error_handler);

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
    function __construct($result, $witness_path, $witness_state)
    {
        $this->result = $result;
        $this->witness_path = $witness_path;
        $this->witness_state = $witness_state;
    }
    public $result = NULL;
    public $witness_path = NULL;
    public $witness_state = NULL;
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

if (isset($_FILES['file']) && $_FILES['file']['tmp_name']) {
  // Move uploaded file to workdir
  debug($_FILES);
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

$dead_transition_name = isset($_REQUEST['dead_transition_name']) ? htmlspecialchars($_REQUEST['dead_transition_name']) : "";
$live_transition_name = isset($_REQUEST['live_transition_name']) ? htmlspecialchars($_REQUEST['live_transition_name']) : "";

// custom formula content cannot be escaped entirely since a LoLA formula can contain characters like '>' or '<' etc.
$custom_formula_content = isset($_REQUEST['custom_formula']) ? $_REQUEST['custom_formula_content'] : "";

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
      $output["checks"][$check_name]["result"] = $result->result;

      if ($result->witness_path)
        $output["checks"][$check_name]["witness_path"] = $result->witness_path;
      
      if ($result->witness_state)
        $output["checks"][$check_name]["witness_state"] = $result->witness_state;
    }
}

// Run custom check
if ($custom_formula_content) {
  $result = exec_lola_check("custom", $custom_formula_content);
  $output["checks"]["custom_check"]["result"] = $result->result;
  
  if ($result->witness_path)
    $output["checks"]["custom_check"]["witness_path"] = $result->witness_path;

  if ($result->witness_state)
    $output["checks"]["custom_check"]["witness_state"] = $result->witness_state;
}

echo json_encode($output);

?>
