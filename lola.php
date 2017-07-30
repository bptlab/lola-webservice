<?php 

$uuid=date("Ymd-His-".rand(0,10));
$workdir="/data/lola-workdir/".$uuid;
$bindir="/opt/lola/bin";
$debug=false;

function is_url($url){
    $url = substr($url,-1) == "/" ? substr($url,0,-1) : $url;
    if ( !$url || $url=="" ) return false;
    if ( !( $parts = @parse_url( $url ) ) ) {
	return false;
    } else {
        if ( $parts["scheme"] != "http" && $parts["scheme"] != "https" && $parts["scheme"] != "ftp" && $parts["scheme"] != "gopher" ) return false;
        else if ( !eregi( "^[0-9a-z]([-.]?[0-9a-z])*.[a-z]{2,4}$", $parts[host], $regs ) ) return false;
        else if ( !eregi( "^([0-9a-z-]|[_])*$", $parts[user], $regs ) ) return false;
        else if ( !eregi( "^([0-9a-z-]|[_])*$", $parts[pass], $regs ) ) return false;
        else if ( !eregi( "^[0-9a-z/_.@~-]*$", $parts[path], $regs ) ) return false;
        else if ( !eregi( "^[0-9a-z?&=#,]*$", $parts[query], $regs ) ) return false;
    }
    return true;
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

if (empty($_REQUEST)) {
    die("Empty request.");
}

if (empty($_REQUEST['input'])) {
    die("Empty input");
}

if ($debug) echo "Creating $workdir<br/>";
mkdir($workdir);
if (is_url($_REQUEST['input'])) {
    copy($_REQUEST['input'], $workdir."/".$uuid.".pnml");
} else {
    $handle = fopen($workdir."/".$uuid.".pnml", "w+");
    fwrite($handle, stripslashes($_REQUEST['input']));
    fclose($handle);
}

if ($debug) echo "Executing ".$bindir."/petri -ipnml -oowfn ".$workdir."/".$uuid.".pnml<br/>";
exec($bindir."/petri -ipnml -oowfn ".$workdir."/".$uuid.".pnml 2>&1", $petri_output, $petri_return_status);
if ($debug) echo "petri return status: " . $petri_return_status . "<br/>";
if ($petri_return_status != 0) {
    if ($debug) {
	foreach ($petri_output as $line) {
            echo htmlspecialchars($line) . "<br/>";
	}
    }
    echo "Error converting from pnml to owfn";
    die();
}

// create Makefile
chdir($workdir);
$handle = fopen("Makefile", "w+");
fwrite($handle, "PATH := $(PATH):".$bindir."\n\n");
if ($debug) echo "Executing " . $bindir."/sound ".$uuid.".pnml.owfn" . "<br/>";
exec($bindir."/sound ".$uuid.".pnml.owfn", $output);
foreach($output as $val) {
    fwrite($handle, $val."\n");
}
fclose($handle);

// execute Makefile
set_time_limit(10);
system($bindir."/timeout3 -t 7 -d 2 " . "/usr/bin/make quasiliveness >/dev/null 2>&1");
system($bindir."/timeout3 -t 7 -d 2 " . "/usr/bin/make liveness >/dev/null 2>&1");
system($bindir."/timeout3 -t 7 -d 2 " . "/usr/bin/make boundedness >/dev/null 2>&1");
system($bindir."/timeout3 -t 7 -d 2 " . "/usr/bin/make relaxed >/dev/null 2>&1");

system("killall lola-boundednet >/dev/null 2>&1");
system("killall lola-boundedplace >/dev/null 2>&1");
system("killall lola-deadtransition >/dev/null 2>&1");
system("killall lola-findpath >/dev/null 2>&1");
system("killall lola-liveprop >/dev/null 2>&1");
system("killall lola-statepredicate >/dev/null 2>&1");


exec($bindir."/process.sh", $finaloutput);
foreach($finaloutput as $val) {
    echo($val."\n");
}


?>

