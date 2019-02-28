<?php

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
      terminate ("Error matching place list regex");
    if ($result === 0)
      terminate ("Syntax error in LoLA file: Couldn't find any places");
    if (!isset($matches["placelist"]))
      terminate ("Named capture group for place list is non-existent!");
  
    $placelist = str_replace(" ", "", $matches["placelist"]);
    $places = explode(",", $placelist);
    if (count($places) == 0)
      terminate ("Could not extract places from LoLA file");
  
    // Extract initial markings
    $matches = [];
    $result = preg_match($marking_list_regex, $lola_contents, $matches);
    if ($result === FALSE)
      terminate ("Error matching marking list regex");
    if ($result === 0)
      terminate ("Syntax error in LoLA file: Couldn't find any markings");
    if (!isset($matches["markinglist"]))
      terminate ("Named capture group for marking list is non-existent!");
  
    $markinglist = str_replace(" ", "", $matches["markinglist"]);
    $markings = explode(",", $markinglist);
    if (count($markings) == 0)
      terminate ("Could not extract markings from LoLA file");
  
    // Extract transitions
    $matches = [];
    $result = preg_match_all($transition_list_regex, $lola_contents, $matches, PREG_SET_ORDER);
    if ($result === FALSE)
      terminate ("Error matching transition regex");
    if ($result === 0)
      terminate ("Syntax error in LoLA file: Couldn't find any transitions");
  
    $transitions = [];
    foreach($matches as $sub_matches) {
      if (!isset($sub_matches["transition"]))
        terminate ("Named capture group for transition is non-existent");
      if (!isset($sub_matches["consume"]))
        terminate ("Named capture group for transition consume list is non-existent");
      if (!isset($sub_matches["produce"]))
        terminate ("Named capture group for transition produce list is non-existent");
      $transition = htmlspecialchars($sub_matches["transition"]);
      $consume_raw = str_replace(" ", "", $sub_matches["consume"]);
      $consume_list_raw = explode(",", $consume_raw);
      if (count($consume_list_raw) == 0)
        terminate ("Could not extract consume list from LoLA file");
      $produce_raw = str_replace(" ", "", $sub_matches["produce"]);
      $produce_list_raw = explode(",", $produce_raw);
      if (count($produce_list_raw) == 0)
        terminate ("Could not extract produce list from LoLA file");
  
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

?>