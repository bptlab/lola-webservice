<?php

//
// FUNCTIONS
//

// Output debug messages to inline output
function debug($data) {
    global $debug_switch;
    global $output;
  
    if (!$debug_switch)
      return;
  
    if (is_array($data) || is_object($data)) {
      $output["debug"][] = $data;
    } else {
      $output["debug"][] = htmlspecialchars($data);
    }
  }
  
  function terminate($msg) {
    global $uuid;
    global $output;
    $output["error"] = $msg;
    $output["uuid"] = $uuid;
    $output["notice"] = "If you think this is an error, please attach your input file as well as the above UUID to your report.";
    echo json_encode($output);
    die();
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
    global $output;

    $json_filename = $lola_filename . "." . $check_name . ".json";
    $witness_path_filename = $lola_filename . "." . $check_name . ".path";
    $witness_state_filename = $lola_filename . "." . $check_name . ".state";
    $process_output = [];
    $return_code = 0;
  
    // Run LoLA
    $lola_command = $lola 
      . " --timelimit=" . $lola_timelimit 
      . " --markinglimit=" . $lola_markinglimit 
      . " " . $extra_parameters 
      . " --formula='" . $formula . "'"
      . " --path='" . $witness_path_filename . "'"
      . " --state='" . $witness_state_filename . "'"
      . " --json='" . $json_filename . "'"
      . " '" . $lola_filename . "'"
      . " 2>&1";
    debug("Running command " . $lola_command);
    exec($lola_command, $process_output, $return_code);
  
    debug($process_output);
  
    // Check if run was okay
    if ($return_code != 0) {
        $output["lola_output"] = $process_output;
        terminate("LoLA exited with code ". $return_code);
    }
  
    // Load and parse result JSON file
    $string_result = file_get_contents($json_filename);
    if ($string_result === FALSE)
      terminate($check_name . ": Can't open result file " . $json_filename);
  
    $json_result = json_decode($string_result, TRUE);
    debug($json_result);
  
    if (!isset($json_result["analysis"]) || !isset($json_result["analysis"]["result"])) {
      debug($json_result);
      terminate($check_name . ": malformed JSON result");
    }
  
    // Load witness path
    $witness_path = load_witness_path($witness_path_filename);
  
    // Load witness state
    $witness_state = load_witness_state($witness_state_filename);
  
    // Create result object
    $result = new CheckResult((boolean)($json_result["analysis"]["result"]), $witness_path, $witness_state);
  
    // Return analysis result
    return $result;
  }
  
  function load_witness_path($witness_path_filename) {
    $witness_path = array();
    if (file_exists($witness_path_filename)) {
      $witness_path_contents = file_get_contents($witness_path_filename);
      // Split on newlines
      $witness_path = explode("\n", $witness_path_contents);
      
      // Since there is a newline after every line, the last element is empty
      $last_element = array_pop($witness_path);
      if($last_element != "") {
        // whoops
        array_push($witness_path, $last_element);
      }
    }
    return $witness_path;
  }
  
  function load_witness_state($witness_state_filename) {
    $witness_state = array();
    if (file_exists($witness_state_filename)) {
      $witness_state_contents = file_get_contents($witness_state_filename);
  
      if($witness_state_contents == "NOSTATE\n") {
        return array();
      }
  
      // Split on newlines
      $witness_states = explode("\n", $witness_state_contents);
  
      // Since there is a newline after every line, the last element is empty
      $last_element = array_pop($witness_states);
      if($last_element != "") {
        // whoops
        array_push($witness_states, $last_element);
      }
  
      // Split place and number of tokens
      foreach($witness_states as $place_and_token) {
        $exploded = explode(" : ", $place_and_token);
        if(count($exploded) != 2) {
          terminate("Cannot split witness state '" . $place_and_token . "' (got " . count($exploded) . ")");
        }
        $witness_state[$exploded[0]] = $exploded[1];
      }
    }
    return $witness_state;
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
    return new CheckResult(true, "", "");
  }
  
  // Run a check on every transition individually - negated
  function lola_check_all_transitions_negated($check_name, $formula) {
    global $petrinet;
    foreach ($petrinet["transitions"] as $transition) {
      $ret = lola_check_single_transition($check_name, $formula, $transition["id"]);
      if ($ret->result) {
        debug("Single negated transition check " . $check_name . " for transition " . $transition["id"] . " succeeded, returning false");
        return new CheckResult(false, $ret->witness_path, $ret->witness_state);
      }
    }
    return new CheckResult(true, "", "");
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
    global $output;
    
    $return_code = null;
    $process_output = [];
  
    exec($petri . " -ipnml -olola ".$pnml_filename, $process_output, $return_code);
    if ($return_code != 0) {
      foreach ($process_output as $line) {
        $output["petri_output"][] = htmlspecialchars($line);
      }
      terminate("petri exited with status " . $return_code . " -- probably the input is malformed.");
    }
  
    $lola_filename = $pnml_filename . ".lola";
    return $lola_filename;
  }
  

?>