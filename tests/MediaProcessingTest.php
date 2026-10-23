<?php
/*
 * Vérifie le traitement des médias (bin/process-media.php) et
 * l'affichage d'un album à partir du cache.
 *
 * Usage : php tests/MediaProcessingTest.php
 * Les cas qui demandent vipsthumbnail ou ffmpeg sont ignorés si ces
 * outils ne sont pas installés. Dans l'image Docker, tout doit passer :
 * c'est la vérification à faire après chaque changement de l'image.
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

// JPEG 200x100 avec Orientation = 6 (à tourner de 90°), une légende EXIF
// et, si donné, un UserComment brut (préfixe de jeu de caractères compris)
function createJpegWithExif($path, $description, $userComment = NULL)
{
        $image = imagecreatetruecolor(200, 100);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 40, 40));
        ob_start();
        imagejpeg($image);
        $jpeg = ob_get_clean();
        imagedestroy($image);

        // Plus de 4 octets : la valeur est rangée après l'IFD, pas dans l'entrée
        $text = str_pad($description . "\0", 5, "\0");
        $count = ($userComment === NULL) ? 2 : 3;
        $dataOffset = 8 + 2 + $count * 12 + 4;
        $entries = pack('vvVV', 0x010E, 2, strlen($text), $dataOffset)
                . pack('vvVvv', 0x0112, 3, 1, 6, 0);
        $data = $text . ((strlen($text) % 2) ? "\0" : '');

        if ($userComment !== NULL)
        {
                // Sous-IFD Exif contenant seulement UserComment
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
                throw new RuntimeException('Le traitement a échoué : ' . $output);
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

        // Les dossiers de l'ancien site ne sont pas des albums, quelle que soit leur casse
        assertTrueValue(IsIgnoredMediaEntry('Legend') && IsIgnoredMediaEntry('legend'), 'Le dossier Legend n\'est pas un album');

        // Détection du format d'après le contenu
        $album = $galleryRoot . '/2024/Plage';
        mkdir($album . '/Small', 0777, TRUE);
        file_put_contents($album . '/pas-une-image.jpg', 'texte');
        assertSameValue(NULL, DetectMediaKind($album . '/pas-une-image.jpg')['kind'], 'Un faux .jpg n\'est pas une image');

        // Liste des médias en erreur : une vidéo tronquée y figure avec sa raison
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
                assertTrueValue(preg_match('#^\d{4}-\d{2}-\d{2} \d{2}:\d{2} \S+ \| ' . preg_quote($galleryName, '#') . '/Abimes/coupee\.mp4 \| \S#m', $report) === 1, 'La vidéo abîmée est dans la liste des erreurs');
                assertTrueValue(strpos($report, $errorAlbum) === FALSE, 'La raison ne contient pas le chemin complet');

                // Retirée de la liste une fois le fichier supprimé
                unlink($errorAlbum . '/coupee.mp4');
                runProcessor($projectRoot, $errorEnvironment);
                assertTrueValue(strpos(file_get_contents($reportDirectory . '/medias-en-erreur.txt'), 'Aucun média en erreur.') !== FALSE, 'La liste se vide quand le fichier disparaît');
                removeDirectoryRecursively($errorAlbum);
                removeDirectoryRecursively($reportDirectory);
        }

        if (!commandExists('vipsthumbnail'))
        {
                echo 'vipsthumbnail absent : tests de traitement ignorés.' . PHP_EOL;
        }
        else
        {
                createJpegWithExif($album . '/coucher.jpg', 'Coucher de soleil');
                mkdir($galleryRoot . '/2024/Noël à la mer', 0777, TRUE);
                createJpegWithExif($galleryRoot . '/2024/Noël à la mer/sapin.jpg', '');
                touch($galleryRoot . '/2024/Noël à la mer/sapin.jpg', time() - 60);
                // L'ancien dossier Legend/ n'est ni lu ni modifié
                mkdir($album . '/Legend', 0777, TRUE);
                file_put_contents($album . '/Legend/coucher.html', 'Le soir & la mer');
                $expectedNames = array('coucher.jpg');

                // Photo HEIC (format des iPhone) : vérifie que vips sait la décoder
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

                // Fichiers déjà copiés (le traitement attend la fin des copies en cours)
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
                assertTrueValue(is_array($manifest), 'La fiche album.json existe');
                sort($expectedNames);
                $names = array_map(function ($item) { return $item['name']; }, $manifest['items']);
                sort($names);
                assertSameValue($expectedNames, $names, 'Seuls les vrais médias sont listés');
                assertSameValue('Le soir & la mer', file_get_contents($album . '/Legend/coucher.html'), 'Legend/ est conservé tel quel');
                assertTrueValue(!is_file($album . '/coucher.jpg.thumb.jpg'), 'Rien n\'est écrit dans le dossier de l\'album');
                assertTrueValue(is_file($cacheRoot . '/' . $galleryName . '/2024/album.json'), 'Une fiche est créée pour chaque dossier');
                $accentManifest = LoadAlbumManifest('./' . $galleryName . '/2024/Noël à la mer');
                assertSameValue(1, count($accentManifest['items']), 'Un album au nom accentué est traité');
                assertTrueValue(!is_dir($cacheRoot . '/' . $galleryName . '/2024/Plage/Small'), 'Les dossiers de l\'ancien site sont ignorés');

                $items = array();

                foreach ($manifest['items'] as $item)
                {
                        $items[$item['name']] = $item;
                }

                $photo = $items['coucher.jpg'];
                assertSameValue(array(100, 200), array($photo['width'], $photo['height']), 'L\'orientation EXIF est appliquée');
                assertTrueValue(!array_key_exists('caption', $photo), 'Aucune légende dans la fiche');
                list($thumbWidth, $thumbHeight) = getimagesize($cacheRoot . '/' . $galleryName . '/2024/Plage/coucher.jpg.thumb.jpg');
                assertTrueValue($thumbHeight > $thumbWidth, 'La vignette est tournée');

                assertTrueValue(is_string($items['iphone.heic']['full']), 'Une version JPEG du HEIC est créée');

                if (isset($items['lisible.mp4']))
                {
                        assertSameValue(FALSE, $items['lisible.mp4']['transcoded'], 'Une vidéo lisible n\'est pas convertie');
                        assertSameValue(TRUE, $items['ancienne.avi']['transcoded'], 'Une vidéo AVI est convertie');
                        assertTrueValue(is_file($cacheRoot . '/' . $galleryName . '/2024/Plage/ancienne.avi.mp4'), 'Le MP4 converti est dans le cache');
                }

                // Affichage : aucune légende, ni de Legend/ ni de l'EXIF
                $html = RenderAlbum('./' . $galleryName, './' . $galleryName . '/2024/Plage');
                assertTrueValue(strpos($html, 'Le soir') === FALSE, 'Legend/ n\'est pas lu');
                assertTrueValue(strpos($html, 'Coucher de soleil') === FALSE, 'La légende EXIF n\'est pas affichée');
                assertTrueValue(strpos($html, 'media-caption') === FALSE, 'Pas de légende sous les vignettes');
                assertTrueValue(preg_match('#<script type="application/json" id="album-media">(.*?)</script>#s', $html, $viewerData) === 1, 'La liste de la visionneuse est présente');
                $viewerItems = json_decode($viewerData[1], TRUE);
                assertSameValue(count($expectedNames), count($viewerItems), 'La visionneuse reçoit tous les médias');
                assertSameValue(substr_count($html, 'class="media-thumb '), count($viewerItems), 'Une vignette par média');

                // L'album parent montre le sous-album avec sa couverture
                $parentHtml = RenderAlbum('./' . $galleryName, './' . $galleryName . '/2024');
                assertTrueValue(strpos($parentHtml, 'class="album-card"') !== FALSE, 'Le sous-album est proposé');
                assertTrueValue(strpos($parentHtml, '2024/Plage/') !== FALSE, 'La couverture vient du sous-album');

                // Un second passage ne retraite rien ; un fichier supprimé disparaît du cache
                $generated = $manifest['generated'];
                sleep(1);
                runProcessor($projectRoot, $environment);
                assertSameValue($generated, LoadAlbumManifest('./' . $galleryName . '/2024/Plage')['generated'], 'Rien n\'est retraité sans changement');

                // Une fiche d'une version précédente perd ses légendes, sans refaire les vignettes
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

                assertTrueValue(!array_key_exists('caption', $fixedItems['coucher.jpg']), 'L\'ancienne légende est retirée de la fiche');
                assertSameValue(5, json_decode(file_get_contents($manifestPath), TRUE)['version'], 'La fiche passe en version 5');
                clearstatcache();
                assertSameValue($thumbTime, filemtime($thumbPath), 'Les vignettes ne sont pas refaites');

                unlink($album . '/coucher.jpg');
                runProcessor($projectRoot, $environment);
                assertTrueValue(!is_file($cacheRoot . '/' . $galleryName . '/2024/Plage/coucher.jpg.thumb.jpg'), 'Le cache d\'un fichier supprimé est nettoyé');
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
