<?php

// Minimal clamd stand-in for tests: php clamd_stub.php <unix-socket-path>
// Speaks just enough of the INSTREAM protocol: reads length-prefixed chunks until a zero-length chunk, then replies.
$path = $argv[1];
@unlink($path);
$server = stream_socket_server('unix://'.$path, $errno, $error);
echo "ready\n";
$conn = stream_socket_accept($server, 15);
$command = '';
while (strlen($command) < 10) {
    $command .= fread($conn, 10 - strlen($command));
}
$data = '';
while (true) {
    $len = '';
    while (strlen($len) < 4) {
        $part = fread($conn, 4 - strlen($len));
        if ($part === '' || $part === false) {
            exit(1);
        }
        $len .= $part;
    }
    $n = unpack('N', $len)[1];
    if ($n === 0) {
        break;
    }
    $chunk = '';
    while (strlen($chunk) < $n) {
        $chunk .= fread($conn, $n - strlen($chunk));
    }
    $data .= $chunk;
}
fwrite($conn, str_contains($data, 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE') ? "stream: Eicar-Test-Signature FOUND\0" : "stream: OK\0");
fclose($conn);
