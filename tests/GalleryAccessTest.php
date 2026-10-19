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

function createDirectoryIfMissing($path)
{
        if (!is_dir($path))
        {
                if (!mkdir($path, 0777, TRUE) && !is_dir($path))
                {
                        throw new RuntimeException('Unable to create directory: ' . $path);
                }
        }
}

function removeDirectoryRecursively($directory)
{
        if (!is_dir($directory))
        {
                return;
        }

        $items = scandir($directory);

        foreach ($items as $item)
        {
                if ($item === '.'
                        || $item === '..')
                {
                        continue;
                }

                $path = $directory . DIRECTORY_SEPARATOR . $item;

                if (is_dir($path))
                {
                        removeDirectoryRecursively($path);
                }
                else
                {
                        unlink($path);
                }
        }

        rmdir($directory);
}

$exitCode = 0;
$serverProcess = NULL;
$serverOutputLog = NULL;
$serverErrorLog = NULL;
$galleryRoot = NULL;

try
{
        $projectRoot = realpath(__DIR__ . '/..');

        if ($projectRoot === FALSE)
        {
                throw new RuntimeException('Unable to determine project root directory.');
        }

        $galleryRoot = $projectRoot . '/GalleryFixtures';
        $categoryOne = $galleryRoot . '/CategoryOne';
        $categoryTwo = $galleryRoot . '/CategoryTwo';
        $albumAlpha = $categoryOne . '/AlbumAlpha';
        $albumBeta = $categoryOne . '/AlbumBeta';
        $albumGamma = $categoryTwo . '/AlbumGamma';

        $directories = array(
                $galleryRoot,
                $categoryOne,
                $albumAlpha,
                $albumAlpha . '/Legend',
                $albumAlpha . '/Middle',
                $albumAlpha . '/Small',
                $albumBeta,
                $categoryTwo,
                $albumGamma
        );

        foreach ($directories as $directory)
        {
                createDirectoryIfMissing($directory);
        }

        $serverPort = findAvailablePort(9000, 9999);

        $serverCommand = sprintf(
                'php -S 127.0.0.1:%d -t %s',
                $serverPort,
                escapeshellarg($projectRoot)
        );

        $serverOutputLog = tempnam(sys_get_temp_dir(), 'gallery_server_output');
        $serverErrorLog = tempnam(sys_get_temp_dir(), 'gallery_server_error');

        $descriptorSpec = array(
                0 => array('pipe', 'r'),
                1 => array('file', $serverOutputLog, 'w'),
                2 => array('file', $serverErrorLog, 'w')
        );

        $serverProcess = proc_open($serverCommand, $descriptorSpec, $pipes, $projectRoot);

        if (!is_resource($serverProcess))
        {
                throw new RuntimeException('Unable to start PHP built-in server for gallery validation tests.');
        }

        fclose($pipes[0]);

        assertTrueValue(waitForServer($serverPort, 5), 'The PHP built-in server did not start in time.');

        $validResponse = performHttpRequest(
                $serverPort,
                '/index.php?Gallery=./GalleryFixtures&Diaporama=./GalleryFixtures/CategoryOne/AlbumAlpha'
        );

        assertSameValue(200, $validResponse['status'], 'Valid gallery request must return HTTP 200.');
        assertTrueValue(
                strpos($validResponse['body'], 'La galerie demandée est invalide.') === FALSE,
                'Valid gallery request must not include an error message.'
        );
        assertTrueValue(
                strpos($validResponse['body'], 'value="./GalleryFixtures/CategoryOne/AlbumAlpha"') !== FALSE,
                'Valid gallery request must preserve the selected diaporama.'
        );

        $galleryTraversalResponse = performHttpRequest(
                $serverPort,
                '/index.php?Gallery=../../..&Diaporama=../../..'
        );

        assertSameValue(400, $galleryTraversalResponse['status'], 'Gallery traversal attempt must return HTTP 400.');
        assertTrueValue(
                strpos($galleryTraversalResponse['body'], 'La galerie demandée est invalide.') !== FALSE,
                'Gallery traversal attempt must display an error message.'
        );

        $diaporamaTraversalResponse = performHttpRequest(
                $serverPort,
                '/index.php?Gallery=./GalleryFixtures&Diaporama=./GalleryFixtures/../../OldSite'
        );

        assertSameValue(400, $diaporamaTraversalResponse['status'], 'Diaporama traversal attempt must return HTTP 400.');
        assertTrueValue(
                strpos($diaporamaTraversalResponse['body'], 'value="./GalleryFixtures/../../OldSite"') === FALSE,
                'Diaporama traversal attempt must not echo the requested path.'
        );

        $mismatchedResponse = performHttpRequest(
                $serverPort,
                '/index.php?Gallery=./GalleryFixtures/CategoryOne&Diaporama=./GalleryFixtures/CategoryTwo/AlbumGamma'
        );

        assertSameValue(400, $mismatchedResponse['status'], 'Mismatched gallery selection must return HTTP 400.');
        assertTrueValue(
                strpos($mismatchedResponse['body'], 'La galerie demandée est invalide.') !== FALSE,
                'Mismatched gallery selection must display an error message.'
        );

        echo 'All gallery path validation tests passed.' . PHP_EOL;
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

        if ($galleryRoot !== NULL)
        {
                removeDirectoryRecursively($galleryRoot);
        }
}

exit($exitCode);
