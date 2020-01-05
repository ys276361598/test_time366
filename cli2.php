<?php
$opts = array_change_key_case(getopt('h:H:p:P:u:U:d::D::s:S:'), \CASE_LOWER);

$host = '0.0.0.0';
if (filter_var($opts['h'] ?? null, \FILTER_VALIDATE_IP) !== false) {
    $host = $opts['h'];
}
$port = 9501;
if (filter_var($opts['p'] ?? null, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1024]]) !== false) {
    $port = intval($opts['p']);
}

$udpPort = 9009;
if (filter_var($opts['u'] ?? null, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1024]]) !== false) {
    $udpPort = intval($opts['u']);
}

$daemonize = false;
if (isset($opts['d'])) {
    $daemonize = true;
}

$signal = strtolower($opts['s'] ?? null);

if (!empty($signal)) {
    if (in_array($signal, ['restart', 'stop'])) {
        $Pid = __DIR__ . '/runtime/http_' . $port . '.pid';
        if (!file_exists($Pid)) {
            var_dump('未找到');
        } else {
            exec('kill -15 ' . intval(trim(file_get_contents($Pid))));
            @unlink($Pid);
        }

        if ($signal === 'stop') die();
    } else if ($signal === 'reload') {
        //
    }
}

include 'http.php';