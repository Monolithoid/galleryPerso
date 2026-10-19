<?php

if (!defined('VIDEO_MEDIA_ROOT'))
{
        define('VIDEO_MEDIA_ROOT', __DIR__);
}

if (!defined('VIDEO_ALLOWED_EXTENSIONS'))
{
        define(
                'VIDEO_ALLOWED_EXTENSIONS',
                array(
                        'mp4',
                        'm4v',
                        'mov',
                        'webm',
                        'ogg',
                        'ogv'
                )
        );
}

function getVideoMediaRoot()
{
        $configuredRoot = getenv('VIDEO_MEDIA_ROOT');

        if (($configuredRoot === false)
                || ($configuredRoot === ''))
        {
                $configuredRoot = VIDEO_MEDIA_ROOT;
        }

        $resolvedRoot = realpath($configuredRoot);

        if ($resolvedRoot === false)
        {
                return NULL;
        }

        return rtrim($resolvedRoot, DIRECTORY_SEPARATOR);
}

function pathIsWithinRoot($absolutePath, $rootPath)
{
        if ($rootPath === NULL)
        {
                return FALSE;
        }

        $root = rtrim($rootPath, DIRECTORY_SEPARATOR);

        if ($root === '')
        {
                return FALSE;
        }

        if ($absolutePath === $root)
        {
                return TRUE;
        }

        $rootWithSeparator = $root . DIRECTORY_SEPARATOR;
        $length = strlen($rootWithSeparator);

        return (strncasecmp($absolutePath, $rootWithSeparator, $length) === 0);
}

function normalizeRequestedMediaPath($requestedPath)
{
        $normalization = array(
                'success' => FALSE,
                'path' => NULL,
                'error' => 'missing_parameter'
        );

        if ($requestedPath === NULL)
        {
                return $normalization;
        }

        $normalizedInput = trim((string) $requestedPath);

        if ($normalizedInput === '')
        {
                $normalization['error'] = 'empty_parameter';

                return $normalization;
        }

        $normalizedInput = str_replace('\\', '/', $normalizedInput);
        $normalizedInput = ltrim($normalizedInput, '/');

        $normalization['success'] = TRUE;
        $normalization['path'] = $normalizedInput;
        $normalization['error'] = NULL;

        return $normalization;
}

function resolveRequestedMediaPath($requestedPath)
{
        $mediaRoot = getVideoMediaRoot();
        $result = array(
                'status' => 404,
                'path' => NULL,
                'error' => 'not_found'
        );

        if ($mediaRoot === NULL)
        {
                $result['error'] = 'media_root_unavailable';

                return $result;
        }

        $normalization = normalizeRequestedMediaPath($requestedPath);

        if ($normalization['success'] === FALSE)
        {
                $result['error'] = $normalization['error'];

                return $result;
        }

        $normalizedInput = $normalization['path'];

        $candidatePath = $mediaRoot . DIRECTORY_SEPARATOR . $normalizedInput;

        $directoryName = dirname($candidatePath);
        $resolvedDirectory = realpath($directoryName);

        if ($resolvedDirectory === false)
        {
                return $result;
        }

        $targetPath = $resolvedDirectory . DIRECTORY_SEPARATOR . basename($candidatePath);

        if (pathIsWithinRoot($targetPath, $mediaRoot) === FALSE)
        {
                $result['status'] = 403;
                $result['error'] = 'outside_media_root';

                return $result;
        }

        if (is_file($targetPath) === FALSE)
        {
                return $result;
        }

        $targetPath = realpath($targetPath);

        if ($targetPath === false)
        {
                return $result;
        }

        if (pathIsWithinRoot($targetPath, $mediaRoot) === FALSE)
        {
                $result['status'] = 403;
                $result['error'] = 'outside_media_root';

                return $result;
        }

        $extension = strtolower((string) pathinfo($targetPath, PATHINFO_EXTENSION));

        if (in_array($extension, VIDEO_ALLOWED_EXTENSIONS, TRUE) === FALSE)
        {
                $result['status'] = 403;
                $result['error'] = 'extension_not_allowed';

                return $result;
        }

        $result['status'] = 200;
        $result['path'] = $targetPath;
        $result['error'] = NULL;

        return $result;
}

function sendHttpStatus($statusCode)
{
        if (function_exists('http_response_code'))
        {
                http_response_code($statusCode);

                return;
        }

        if (!headers_sent())
        {
                header(' ', TRUE, $statusCode);
        }
}

function handleVideoRequest()
{
        $resolution = resolveRequestedMediaPath(
                isset($_GET['path']) ? $_GET['path'] : NULL
        );

        if ($resolution['status'] !== 200)
        {
                sendHttpStatus($resolution['status']);

                if ($resolution['status'] === 403)
                {
                        echo 'Forbidden';
                }
                else
                {
                        echo 'Not Found';
                }

                return;
        }

        $stream = new VideoStream($resolution['path']);
        $stream->start();
}

class VideoStream
{
        private $path = "";
        private $stream = "";
        private $buffer = 102400;
        private $start  = -1;
        private $end    = -1;
        private $size   = 0;

        function __construct($filePath)
        {
                $this->path = $filePath;
        }

        /**
         * Open stream
         */
        private function open()
        {
                if (!($this->stream = fopen($this->path, 'rb')))
                {
                        die('Could not open stream for reading');
                }

        }

        /**
         * Set proper header to serve the video content
         */
        private function setHeader()
        {
                ob_get_clean();

                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimetype = finfo_file($finfo, $this->path);

                //header("Content-Type: video/mp4");
                header("Content-Type: " . $mimetype);
                header("Cache-Control: max-age=2592000, public");
                header("Expires: ".gmdate('D, d M Y H:i:s', time()+2592000) . ' GMT');
                header("Last-Modified: ".gmdate('D, d M Y H:i:s', @filemtime($this->path)) . ' GMT' );
                $this->start = 0;
                $this->size  = filesize($this->path);
                $this->end   = $this->size - 1;
                header("Accept-Ranges: 0-".$this->end);

                if (isset($_SERVER['HTTP_RANGE']))
                {

                        $c_start = $this->start;
                        $c_end = $this->end;

                        list(, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);
                        if (strpos($range, ',') !== false)
                        {
                                header('HTTP/1.1 416 Requested Range Not Satisfiable');
                                header("Content-Range: bytes $this->start-$this->end/$this->size");
                                exit;
                        }
                        if ($range == '-')
                        {
                                $c_start = $this->size - substr($range, 1);
                        }
                        else
                        {
                                $range = explode('-', $range);
                                $c_start = $range[0];

                                $c_end = (isset($range[1]) && is_numeric($range[1])) ? $range[1] : $c_end;
                        }
                        $c_end = ($c_end > $this->end) ? $this->end : $c_end;
                        if ($c_start > $c_end || $c_start > $this->size - 1 || $c_end >= $this->size)
                        {
                                header('HTTP/1.1 416 Requested Range Not Satisfiable');
                                header("Content-Range: bytes $this->start-$this->end/$this->size");
                                exit;
                        }
                        $this->start = $c_start;
                        $this->end = $c_end;
                        $length = $this->end - $this->start + 1;
                        fseek($this->stream, $this->start);
                        header('HTTP/1.1 206 Partial Content');
                        header("Content-Length: ".$length);
                        header("Content-Range: bytes $this->start-$this->end/".$this->size);
                }
                else
                {
                        header("Content-Length: ".$this->size);
                }

        }

        /**
         * close curretly opened stream
         */
        private function end()
        {
                fclose($this->stream);
                exit;
        }

        /**
         * perform the streaming of calculated range
         */
        private function stream()
        {
                $i = $this->start;
                set_time_limit(0);
                while(!feof($this->stream) && $i <= $this->end)
                {
                        $bytesToRead = $this->buffer;
                        if(($i+$bytesToRead) > $this->end)
                        {
                                $bytesToRead = $this->end - $i + 1;
                        }
                        $data = fread($this->stream, $bytesToRead);
                        echo $data;
                        flush();
                        $i += $bytesToRead;
                }
        }

        /**
         * Start streaming video content
         */
        function start()
        {
                $this->open();
                $this->setHeader();
                $this->stream();
                $this->end();
        }
}

handleVideoRequest();

?>
