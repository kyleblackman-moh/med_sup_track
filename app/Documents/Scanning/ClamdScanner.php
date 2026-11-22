<?php

namespace App\Documents\Scanning;

use Throwable;

/**
 * clamd over TCP using the INSTREAM protocol, in pure PHP:
 *   "zINSTREAM\0", then chunks each prefixed by a 4-byte big-endian length,
 *   then a zero-length chunk; the reply ends with \0.
 *
 * Fails safe: anything other than exactly "stream: OK" is never clean.
 */
final class ClamdScanner implements Scanner
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly int $connectTimeout = 5,
        private readonly int $readTimeout = 60,
        private readonly int $chunkSize = 8192,
    ) {}

    public function scan(string $absolutePath): ScanResult
    {
        $socket = null;
        $file = null;

        try {
            $socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $errstr, $this->connectTimeout);
            if ($socket === false) {
                return ScanResult::error('Scanner unavailable.');
            }
            stream_set_timeout($socket, $this->readTimeout);

            $file = @fopen($absolutePath, 'rb');
            if ($file === false) {
                return ScanResult::error('File could not be read for scanning.');
            }

            if (! $this->write($socket, "zINSTREAM\0")) {
                return ScanResult::error('Scanner connection failed.');
            }

            while (! feof($file)) {
                $chunk = fread($file, $this->chunkSize);
                if ($chunk === false) {
                    return ScanResult::error('File could not be read for scanning.');
                }
                if ($chunk === '') {
                    continue;
                }
                if (! $this->write($socket, pack('N', strlen($chunk)).$chunk)) {
                    return ScanResult::error('Scanner connection failed.');
                }
            }

            if (! $this->write($socket, pack('N', 0))) {
                return ScanResult::error('Scanner connection failed.');
            }

            $reply = '';
            while (! feof($socket) && ! str_contains($reply, "\0")) {
                $data = fread($socket, 4096);
                if (stream_get_meta_data($socket)['timed_out']) {
                    return ScanResult::error('Scanner timed out.');
                }
                if ($data === false || $data === '') {
                    break;
                }
                $reply .= $data;
            }

            return $this->interpret($reply);
        } catch (Throwable) {
            return ScanResult::error('Scanner failed.');
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    /**
     * IT-ADMIN: is clamd answering? Sends zPING and expects PONG within
     * $timeout seconds (connect and read). Never throws.
     */
    public function ping(int $timeout = 2): bool
    {
        $socket = null;

        try {
            $socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $errstr, $timeout);
            if ($socket === false) {
                return false;
            }
            stream_set_timeout($socket, $timeout);

            if (! $this->write($socket, "zPING\0")) {
                return false;
            }

            $reply = '';
            while (! feof($socket) && ! str_contains($reply, "\0") && strlen($reply) < 64) {
                $data = fread($socket, 64);
                if ($data === false || $data === '' || stream_get_meta_data($socket)['timed_out']) {
                    break;
                }
                $reply .= $data;
            }

            return trim(strstr($reply, "\0", true) ?: $reply) === 'PONG';
        } catch (Throwable) {
            return false;
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    private function interpret(string $reply): ScanResult
    {
        $reply = trim(strstr($reply, "\0", true) ?: $reply);

        if ($reply === 'stream: OK') {
            return ScanResult::clean();
        }

        if (preg_match('/^stream: (.+) FOUND$/', $reply, $match)) {
            return ScanResult::infected(mb_substr($match[1], 0, 200));
        }

        return ScanResult::error('Unexpected scanner reply.');
    }

    /** @param resource $socket */
    private function write($socket, string $data): bool
    {
        while ($data !== '') {
            $written = @fwrite($socket, $data);
            if ($written === false || $written === 0) {
                return false;
            }
            $data = substr($data, $written);
        }

        return true;
    }
}
