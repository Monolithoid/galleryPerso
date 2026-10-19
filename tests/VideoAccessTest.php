<?php

function assertSameValue($expected, $actual, $message)
{
        if ($expected !== $actual)
        {
                throw new RuntimeException(
                        $message . ' (expected: ' . var_export($expected, TRUE) . ', actual: ' . var_export($actual, TRUE) . ')'
                );
        }
}

function assertTrueValue($condition, $message)
{
        if ($condition !== TRUE)
        {
                throw new RuntimeException($message);
        }
}

function findAvailablePort($startPort, $endPort)
{
        $port = $startPort;

        while ($port <= $endPort)
        {
                $socket = @stream_socket_server('tcp://127.0.0.1:' . $port, $errorNumber, $errorMessage);

                if ($socket !== FALSE)
                {
                        fclose($socket);

                        return $port;
                }

                $port++;
        }

        throw new RuntimeException('Unable to find an available TCP port for the PHP built-in server.');
}

function waitForServer($port, $timeoutSeconds)
{
        $deadline = microtime(TRUE) + $timeoutSeconds;

        while (microtime(TRUE) < $deadline)
        {
                $connection = @fsockopen('127.0.0.1', $port);

                if ($connection !== FALSE)
                {
                        fclose($connection);

                        return TRUE;
                }

                usleep(100000);
        }

        return FALSE;
}

function performHttpRequest($port, $relativePath)
{
        $context = stream_context_create(
                array(
                        'http' => array(
                                'ignore_errors' => TRUE
                        )
                )
        );

        $body = file_get_contents('http://127.0.0.1:' . $port . $relativePath, FALSE, $context);
        $headers = array();

        if (isset($http_response_header))
        {
                $headers = $http_response_header;
        }

        $status = 0;

        foreach ($headers as $header)
        {
                if (preg_match('/^HTTP\/[^\s]+\s+(\d+)/', $header, $matches) === 1)
                {
                        $status = (int) $matches[1];
                }
        }

        return array(
                'status' => $status,
                'body' => $body,
                'headers' => $headers
        );
}

$exitCode = 0;
$serverProcess = NULL;
$serverOutputLog = NULL;
$serverErrorLog = NULL;

try
{
        $projectRoot = realpath(__DIR__ . '/..');

        if ($projectRoot === FALSE)
        {
                throw new RuntimeException('Unable to determine project root directory.');
        }

        $fixturesRoot = $projectRoot . '/tests/fixtures';
        $mediaRoot = $fixturesRoot . '/media';

        if (!is_dir($mediaRoot))
        {
                if (!mkdir($mediaRoot, 0777, TRUE) && !is_dir($mediaRoot))
                {
                        throw new RuntimeException('Unable to create media fixtures directory.');
                }
        }

        if (!is_dir($fixturesRoot))
        {
                if (!mkdir($fixturesRoot, 0777, TRUE) && !is_dir($fixturesRoot))
                {
                        throw new RuntimeException('Unable to create fixtures directory.');
                }
        }

        $validMediaPath = $mediaRoot . '/sample.mp4';
        $textFilePath = $mediaRoot . '/notes.txt';
        $outsideMediaPath = $fixturesRoot . '/outside.mp4';

        file_put_contents($validMediaPath, 'sample video content');
        file_put_contents($textFilePath, 'not a video');
        file_put_contents($outsideMediaPath, 'outside media');

        putenv('VIDEO_MEDIA_ROOT=' . $mediaRoot);

        $serverPort = findAvailablePort(8000, 8999);

        $serverCommand = sprintf(
                'php -S 127.0.0.1:%d -t %s',
                $serverPort,
                escapeshellarg($projectRoot)
        );

        $serverOutputLog = tempnam(sys_get_temp_dir(), 'video_server_output');
        $serverErrorLog = tempnam(sys_get_temp_dir(), 'video_server_error');

        $descriptorSpec = array(
                0 => array('pipe', 'r'),
                1 => array('file', $serverOutputLog, 'w'),
                2 => array('file', $serverErrorLog, 'w')
        );

        $serverProcess = proc_open($serverCommand, $descriptorSpec, $pipes, $projectRoot);

        if (!is_resource($serverProcess))
        {
                throw new RuntimeException('Unable to start PHP built-in server for integration tests.');
        }

        fclose($pipes[0]);

        assertTrueValue(waitForServer($serverPort, 5), 'The PHP built-in server did not start in time.');

        $validResponse = performHttpRequest($serverPort, '/video.php?path=sample.mp4');
        assertSameValue(200, $validResponse['status'], 'Valid media request must return HTTP 200.');
        assertSameValue(file_get_contents($validMediaPath), $validResponse['body'], 'Valid media request must return the media content.');

        $disallowedResponse = performHttpRequest($serverPort, '/video.php?path=notes.txt');
        assertSameValue(403, $disallowedResponse['status'], 'Requests for disallowed extensions must be rejected with HTTP 403.');

        $traversalResponse = performHttpRequest($serverPort, '/video.php?path=../outside.mp4');
        assertSameValue(403, $traversalResponse['status'], 'Directory traversal attempts must be rejected with HTTP 403.');

        $encodedTraversalResponse = performHttpRequest(
                $serverPort,
                '/video.php?path=%2E%2E%2Foutside.mp4'
        );
        assertSameValue(
                403,
                $encodedTraversalResponse['status'],
                'URL-encoded directory traversal attempts must be rejected with HTTP 403.'
        );

        $missingResponse = performHttpRequest($serverPort, '/video.php?path=missing.mp4');
        assertSameValue(404, $missingResponse['status'], 'Requests for missing files must return HTTP 404.');

        echo "All video access tests passed." . PHP_EOL;
}
catch (Throwable $exception)
{
        $exitCode = 1;
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);
}
finally
{
        if (is_resource($serverProcess))
        {
                proc_terminate($serverProcess);
                proc_close($serverProcess);
        }

        if ($serverOutputLog !== NULL && file_exists($serverOutputLog))
        {
                unlink($serverOutputLog);
        }

        if ($serverErrorLog !== NULL && file_exists($serverErrorLog))
        {
                unlink($serverErrorLog);
        }

        if (isset($validMediaPath) && file_exists($validMediaPath))
        {
                unlink($validMediaPath);
        }

        if (isset($textFilePath) && file_exists($textFilePath))
        {
                unlink($textFilePath);
        }

        if (isset($outsideMediaPath) && file_exists($outsideMediaPath))
        {
                unlink($outsideMediaPath);
        }

        putenv('VIDEO_MEDIA_ROOT');
}

exit($exitCode);
