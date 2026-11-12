<?php

// Throw-away clamd stand-in for ClamdScanner tests. Run in its own process:
//   php fake_clamd.php <recordFile> <mode> <base64 reply>
// Binds an ephemeral port on 127.0.0.1, prints it, accepts ONE connection,
// records every byte received until the zero-length chunk, then:
//   reply: writes the reply followed by \0
//   sleep: writes nothing and sleeps (client read timeout)
//   close: closes without replying

[, $record, $mode, $reply] = $argv;
$reply = base64_decode($reply);

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "bind failed: {$errstr}\n");
    exit(1);
}
$name = stream_socket_get_name($server, false);
echo substr(strrchr($name, ':'), 1), "\n";
fflush(STDOUT);

$client = stream_socket_accept($server, 30);
if ($client === false) {
    exit(1);
}

$received = '';
$read = function (int $length) use ($client, &$received): string {
    $data = '';
    while (strlen($data) < $length && ! feof($client)) {
        $chunk = fread($client, $length - strlen($data));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $data .= $chunk;
    }
    $received .= $data;

    return $data;
};

$read(strlen("zINSTREAM\0"));
while (true) {
    $header = $read(4);
    if (strlen($header) < 4) {
        break;
    }
    $length = unpack('N', $header)[1];
    if ($length === 0) {
        break;
    }
    $read($length);
}

file_put_contents($record, $received);

if ($mode === 'reply') {
    fwrite($client, $reply."\0");
} elseif ($mode === 'sleep') {
    sleep(10);
}

fclose($client);
fclose($server);
