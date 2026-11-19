<?php
/*
 * Checks the media processing (bin/process-media.php) and
 * the rendering of an album from the cache.
 *
 * Usage: php tests/MediaProcessingTest.php
 * Cases that need vipsthumbnail or ffmpeg are skipped if these
 * tools are not installed. In the Docker image, everything must pass:
 * this is the check to run after every change to the image.
 */

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

function commandExists($command)
{
        return trim((string) shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null')) !== '';
}

function removeDirectoryRecursively($directory)
{
        if (!is_dir($directory) || is_link($directory))
        {
                return;
        }

        foreach (scandir($directory) as $item)
        {
                if ($item === '.' || $item === '..')
                {
                        continue;
                }

                $path = $directory . DIRECTORY_SEPARATOR . $item;

                if (is_dir($path) && !is_link($path))
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

// 200x100 JPEG with Orientation = 6 (to rotate by 90°), an EXIF caption
// and, if given, a raw UserComment (character set prefix included)
function createJpegWithExif($path, $description, $userComment = NULL)
{
        $image = imagecreatetruecolor(200, 100);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 40, 40));
        ob_start();
        imagejpeg($image);
        $jpeg = ob_get_clean();
        imagedestroy($image);

        // More than 4 bytes: the value is stored after the IFD, not in the entry
        $text = str_pad($description . "\0", 5, "\0");
        $count = ($userComment === NULL) ? 2 : 3;
        $dataOffset = 8 + 2 + $count * 12 + 4;
        $entries = pack('vvVV', 0x010E, 2, strlen($text), $dataOffset)
                . pack('vvVvv', 0x0112, 3, 1, 6, 0);
        $data = $text . ((strlen($text) % 2) ? "\0" : '');

        if ($userComment !== NULL)
        {
                // Exif sub-IFD containing only UserComment
                $exifOffset = $dataOffset + strlen($data);
                $entries .= pack('vvVV', 0x8769, 4, 1, $exifOffset);
                $data .= pack('v', 1) . pack('vvVV', 0x9286, 7, strlen($userComment), $exifOffset + 2 + 12 + 4) . pack('V', 0) . $userComment;
        }

        $tiff = 'II' . pack('v', 42) . pack('V', 8) . pack('v', $count) . $entries . pack('V', 0) . $data;
        $segment = "Exif\0\0" . $tiff;
        $app1 = "\xFF\xE1" . pack('n', strlen($segment) + 2) . $segment;

        file_put_contents($path, substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2));
}

function runProcessor($projectRoot, $environment)
{
        $command = 'php ' . escapeshellarg($projectRoot . '/bin/process-media.php') . ' --once';
        $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $projectRoot, $environment);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0)
        {
                throw new RuntimeException('Processing failed: ' . $output);
        }

        return $output;
}

$exitCode = 0;
$projectRoot = realpath(__DIR__ . '/..');
$galleryName = 'MediaFixtures' . getmypid();
$galleryRoot = $projectRoot . '/' . $galleryName;
$cacheRoot = sys_get_temp_dir() . '/siteimageperso-cache-' . getmypid();

try
{
        putenv('SITEIMAGEPERSO_MEDIA_BASE=' . $projectRoot);
        putenv('SITEIMAGEPERSO_GALLERY_ROOTS=' . $galleryName);
        putenv('SITEIMAGEPERSO_CACHE_DIR=' . $cacheRoot);
        require $projectRoot . '/functions.php';

        // The old site's folders are not albums, whatever their case
        assertTrueValue(IsIgnoredMediaEntry('Legend') && IsIgnoredMediaEntry('legend'), 'The Legend folder is not an album');

        // Format detection from the content
        $album = $galleryRoot . '/2024/Plage';
        mkdir($album . '/Small', 0777, TRUE);
        file_put_contents($album . '/pas-une-image.jpg', 'texte');
        assertSameValue(NULL, DetectMediaKind($album . '/pas-une-image.jpg')['kind'], 'A fake .jpg is not an image');

        // Failed-media list: a truncated video appears in it with its reason
        if (commandExists('ffmpeg'))
        {
                $errorAlbum = $galleryRoot . '/Abimes';
                $reportDirectory = $cacheRoot . '-journal';
                mkdir($errorAlbum, 0777, TRUE);
                mkdir($reportDirectory, 0777, TRUE);
                shell_exec('ffmpeg -v error -f lavfi -i testsrc=size=320x240:rate=25 -t 2 -c:v libx264 -pix_fmt yuv420p ' . escapeshellarg($errorAlbum . '/coupee.mp4'));
                $truncated = substr(file_get_contents($errorAlbum . '/coupee.mp4'), 0, 2000);
                file_put_contents($errorAlbum . '/coupee.mp4', $truncated);
                touch($errorAlbum . '/coupee.mp4', time() - 60);
                $errorEnvironment = array(
                        'PATH' => getenv('PATH'),
                        'SITEIMAGEPERSO_MEDIA_BASE' => $projectRoot,
                        'SITEIMAGEPERSO_GALLERY_ROOTS' => $galleryName,
                        'SITEIMAGEPERSO_CACHE_DIR' => $cacheRoot,
                        'SITEIMAGEPERSO_ERROR_REPORT' => $reportDirectory . '/medias-en-erreur.txt'
                );
                runProcessor($projectRoot, $errorEnvironment);
                $report = (string) @file_get_contents($reportDirectory . '/medias-en-erreur.txt');
                assertTrueValue(preg_match('#^\d{4}-\d{2}-\d{2} \d{2}:\d{2} \S+ \| ' . preg_quote($galleryName, '#') . '/Abimes/coupee\.mp4 \| \S#m', $report) === 1, 'The damaged video is in the failure list');
                assertTrueValue(strpos($report, $errorAlbum) === FALSE, 'The reason does not contain the full path');

                // Removed from the list once the file is deleted
                unlink($errorAlbum . '/coupee.mp4');
                runProcessor($projectRoot, $errorEnvironment);
                assertTrueValue(strpos(file_get_contents($reportDirectory . '/medias-en-erreur.txt'), 'Aucun média en erreur.') !== FALSE, 'The list empties when the file disappears');
                removeDirectoryRecursively($errorAlbum);
                removeDirectoryRecursively($reportDirectory);
        }

        if (!commandExists('vipsthumbnail'))
        {
                echo 'vipsthumbnail missing: processing tests skipped.' . PHP_EOL;
        }
        else
        {
                createJpegWithExif($album . '/coucher.jpg', 'Coucher de soleil');
                mkdir($galleryRoot . '/2024/Noël à la mer', 0777, TRUE);
                createJpegWithExif($galleryRoot . '/2024/Noël à la mer/sapin.jpg', '');
                touch($galleryRoot . '/2024/Noël à la mer/sapin.jpg', time() - 60);
                // The old Legend/ folder is neither read nor modified
                mkdir($album . '/Legend', 0777, TRUE);
                file_put_contents($album . '/Legend/coucher.html', 'Le soir & la mer');
                $expectedNames = array('coucher.jpg');

                // HEIC photo (iPhone format): checks that vips can decode it
                copy($projectRoot . '/tests/samples/iphone.heic', $album . '/iphone.heic');
                $expectedNames[] = 'iphone.heic';

                if (commandExists('ffmpeg'))
                {
                        $lavfi = '-f lavfi -i testsrc=size=320x240:rate=25 -f lavfi -i sine -t 2';
                        shell_exec('ffmpeg -v error ' . $lavfi . ' -c:v libx264 -pix_fmt yuv420p -c:a aac ' . escapeshellarg($album . '/lisible.mp4'));
                        shell_exec('ffmpeg -v error ' . $lavfi . ' -c:v mpeg4 -c:a mp3 ' . escapeshellarg($album . '/ancienne.avi'));
                        $expectedNames[] = 'ancienne.avi';
                        $expectedNames[] = 'lisible.mp4';
                }

                // Files already copied (processing waits for ongoing copies to finish)
                foreach (scandir($album) as $entry)
                {
                        if (is_file($album . '/' . $entry))
                        {
                                touch($album . '/' . $entry, time() - 60);
                        }
                }

                $environment = array(
                        'PATH' => getenv('PATH'),
                        'SITEIMAGEPERSO_MEDIA_BASE' => $projectRoot,
                        'SITEIMAGEPERSO_GALLERY_ROOTS' => $galleryName,
                        'SITEIMAGEPERSO_CACHE_DIR' => $cacheRoot
                );
                runProcessor($projectRoot, $environment);

                $manifest = LoadAlbumManifest('./' . $galleryName . '/2024/Plage');
                assertTrueValue(is_array($manifest), 'The album.json record exists');
                sort($expectedNames);
                $names = array_map(function ($item) { return $item['name']; }, $manifest['items']);
                sort($names);
                assertSameValue($expectedNames, $names, 'Only real media are listed');
                assertSameValue('Le soir & la mer', file_get_contents($album . '/Legend/coucher.html'), 'Legend/ is kept as is');
                assertTrueValue(!is_file($album . '/coucher.jpg.thumb.jpg'), 'Nothing is written into the album folder');
                assertTrueValue(is_file($cacheRoot . '/' . $galleryName . '/2024/album.json'), 'A record is created for each folder');
                $accentManifest = LoadAlbumManifest('./' . $galleryName . '/2024/Noël à la mer');
                assertSameValue(1, count($accentManifest['items']), 'An album with an accented name is processed');
                assertTrueValue(!is_dir($cacheRoot . '/' . $galleryName . '/2024/Plage/Small'), 'The old site folders are ignored');

                $items = array();

                foreach ($manifest['items'] as $item)
                {
                        $items[$item['name']] = $item;
                }

                $photo = $items['coucher.jpg'];
                assertSameValue(array(100, 200), array($photo['width'], $photo['height']), 'The EXIF orientation is applied');
                assertTrueValue(!array_key_exists('caption', $photo), 'No caption in the record');
                list($thumbWidth, $thumbHeight) = getimagesize($cacheRoot . '/' . $galleryName . '/2024/Plage/coucher.jpg.thumb.jpg');
                assertTrueValue($thumbHeight > $thumbWidth, 'The thumbnail is rotated');

                assertTrueValue(is_string($items['iphone.heic']['full']), 'A JPEG version of the HEIC is created');

                if (isset($items['lisible.mp4']))
                {
                        assertSameValue(FALSE, $items['lisible.mp4']['transcoded'], 'A playable video is not converted');
                        assertSameValue(TRUE, $items['ancienne.avi']['transcoded'], 'An AVI video is converted');
                        assertTrueValue(is_file($cacheRoot . '/' . $galleryName . '/2024/Plage/ancienne.avi.mp4'), 'The converted MP4 is in the cache');
                }

                // Rendering: no caption, neither from Legend/ nor from EXIF
                $html = RenderAlbum('./' . $galleryName, './' . $galleryName . '/2024/Plage');
                assertTrueValue(strpos($html, 'Le soir') === FALSE, 'Legend/ is not read');
                assertTrueValue(strpos($html, 'Coucher de soleil') === FALSE, 'The EXIF caption is not shown');
                assertTrueValue(strpos($html, 'media-caption') === FALSE, 'No caption under the thumbnails');
                assertTrueValue(preg_match('#<script type="application/json" id="album-media">(.*?)</script>#s', $html, $viewerData) === 1, 'The viewer list is present');
                $viewerItems = json_decode($viewerData[1], TRUE);
                assertSameValue(count($expectedNames), count($viewerItems), 'The viewer receives all the media');
                assertSameValue(substr_count($html, 'class="media-thumb '), count($viewerItems), 'One thumbnail per media item');

                // The parent album shows the sub-album with its cover
                $parentHtml = RenderAlbum('./' . $galleryName, './' . $galleryName . '/2024');
                assertTrueValue(strpos($parentHtml, 'class="album-card"') !== FALSE, 'The sub-album is offered');
                assertTrueValue(strpos($parentHtml, '2024/Plage/') !== FALSE, 'The cover comes from the sub-album');

                // A second pass reprocesses nothing; a deleted file disappears from the cache
                $generated = $manifest['generated'];
                sleep(1);
                runProcessor($projectRoot, $environment);
                assertSameValue($generated, LoadAlbumManifest('./' . $galleryName . '/2024/Plage')['generated'], 'Nothing is reprocessed without a change');

                // A record from a previous version loses its captions, without regenerating the thumbnails
                $manifestPath = $cacheRoot . '/' . $galleryName . '/2024/Plage/album.json';
                $oldManifest = json_decode(file_get_contents($manifestPath), TRUE);
                $oldManifest['version'] = 4;

                foreach ($oldManifest['items'] as $index => $item)
                {
                        if ($item['name'] === 'coucher.jpg')
                        {
                                $oldManifest['items'][$index]['caption'] = '十䥃I敌⁳慶畧獥';
                        }
                }

                file_put_contents($manifestPath, json_encode($oldManifest));
                $thumbPath = $cacheRoot . '/' . $galleryName . '/2024/Plage/coucher.jpg.thumb.jpg';
                touch($thumbPath, time() - 3600);
                clearstatcache();
                $thumbTime = filemtime($thumbPath);
                runProcessor($projectRoot, $environment);
                $fixedItems = array();

                foreach (LoadAlbumManifest('./' . $galleryName . '/2024/Plage')['items'] as $item)
                {
                        $fixedItems[$item['name']] = $item;
                }

                assertTrueValue(!array_key_exists('caption', $fixedItems['coucher.jpg']), 'The old caption is removed from the record');
                assertSameValue(5, json_decode(file_get_contents($manifestPath), TRUE)['version'], 'The record moves to version 5');
                clearstatcache();
                assertSameValue($thumbTime, filemtime($thumbPath), 'The thumbnails are not regenerated');

                unlink($album . '/coucher.jpg');
                runProcessor($projectRoot, $environment);
                assertTrueValue(!is_file($cacheRoot . '/' . $galleryName . '/2024/Plage/coucher.jpg.thumb.jpg'), 'The cache of a deleted file is cleaned up');
        }

        echo 'All media processing tests passed.' . PHP_EOL;
}
catch (Throwable $error)
{
        $exitCode = 1;
        fwrite(STDERR, $error->getMessage() . PHP_EOL);
}

removeDirectoryRecursively($galleryRoot);
removeDirectoryRecursively($cacheRoot);

exit($exitCode);
