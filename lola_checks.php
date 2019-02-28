<?php

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
        return new CheckResult(true, "", "");
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
        return new CheckResult(true, "", "");
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

?>