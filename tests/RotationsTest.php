<?php
/*
 * Photo rotation: quarter turns recorded, cached files
 * regenerated rotated without touching the original, and rotation picked up by the
 * background processing (bin/process-media.php), even after
 * the cache is deleted.
 * Run: php tests/RotationsTest.php
 * Cases that need vipsthumbnail are skipped if it is not installed.
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

// Dominant colour of a pixel: 'rouge' (red) or 'bleu' (blue)
function colorAt($path, $x, $y)
{
        $image = imagecreatefromjpeg($path);
        $rgb = imagecolorat($image, $x, $y);
        imagedestroy($image);

        return ((($rgb >> 16) & 0xFF) > ($rgb & 0xFF)) ? 'rouge' : 'bleu';
}

function runProcessor()
{
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bin/process-media.php') . ' --once 2>&1', $output, $exitCode);
        assertSameValue(0, $exitCode, 'Processing ends without error: ' . implode(PHP_EOL, $output));
}

$WorkDirectory = sys_get_temp_dir() . '/galerie-rotations-' . getmypid();
$AlbumDirectory = $WorkDirectory . '/GalleryPhotos/Plage';
$CacheDirectory = $WorkDirectory . '/cache';
$AlbumCache = $CacheDirectory . '/GalleryPhotos/Plage';
mkdir($AlbumDirectory, 0777, TRUE);

putenv('SITEIMAGEPERSO_MEDIA_BASE=' . $WorkDirectory);
putenv('SITEIMAGEPERSO_CACHE_DIR=' . $CacheDirectory);
putenv('SITEIMAGEPERSO_GALLERY_ROOTS=GalleryPhotos');
putenv('SITEIMAGEPERSO_ERROR_REPORT=' . $WorkDirectory . '/journal/medias-en-erreur.txt');
putenv('SITEIMAGEPERSO_ROTATIONS_FILE=' . $WorkDirectory . '/rotations/rotations.json');

require __DIR__ . '/../lib/likes.php';
require __DIR__ . '/../lib/rotations.php';

$exitCode = 0;

try
{
        assertSameValue(270, NormalizeMediaRotation(-90), '-90 degrees = 270');
        assertSameValue(90, NormalizeMediaRotation(450), '450 degrees = 90');
        assertSameValue(0, NormalizeMediaRotation(45), 'No rotation other than quarter turns');
        assertSameValue(array('a.jpg' => 180), ParseRotationsData('{"a.jpg": 180, "b.jpg": 0, "c.jpg": 33}'), 'Only real rotations are kept');

        $Item = array('name' => 'a.jpg', 'kind' => 'image', 'thumb' => 'cache/a.jpg.thumb.jpg', 'thumb_width' => 400, 'thumb_height' => 300,
                'preview' => 'cache/a.jpg.preview.jpg', 'full' => NULL, 'width' => 1600, 'height' => 1200, 'rotation' => 0);
        $Rotated = ApplyMediaRotation($Item, 90);
        assertSameValue(array('cache/a.jpg.thumb.jpg?r=90', 300, 400, 1200, 1600), array($Rotated['thumb'], $Rotated['thumb_width'], $Rotated['thumb_height'], $Rotated['width'], $Rotated['height']),
                'Cache regenerated but record not yet updated: dimensions swapped, address changed');
        $Item['rotation'] = 90;
        $Rotated = ApplyMediaRotation(array('thumb_width' => 300, 'thumb_height' => 400, 'width' => 1200, 'height' => 1600) + $Item, 90);
        assertSameValue(array(300, 400), array($Rotated['thumb_width'], $Rotated['thumb_height']), 'Record already updated: dimensions kept');
        assertSameValue('cache/a.jpg.thumb.jpg', ApplyMediaRotation($Item, 0)['thumb'], 'Without rotation, original address');
        assertSameValue(FALSE, CanRotateMedia(array('kind' => 'image', 'preview' => NULL)), 'An animated GIF cannot be rotated');
        assertSameValue(FALSE, CanRotateMedia(array('kind' => 'video', 'preview' => 'p.jpg')), 'A video cannot be rotated');

        if (trim((string) shell_exec('command -v vipsthumbnail 2>/dev/null')) === '')
        {
                echo 'vipsthumbnail missing: file rotation tests skipped.' . PHP_EOL;
        }
        else
        {
                // 300x200: left half red, right half blue
                $Original = $AlbumDirectory . '/photo.jpg';
                $Image = imagecreatetruecolor(300, 200);
                imagefill($Image, 0, 0, imagecolorallocate($Image, 220, 30, 30));
                imagefilledrectangle($Image, 150, 0, 299, 199, imagecolorallocate($Image, 30, 30, 220));
                imagejpeg($Image, $Original, 95);
                imagedestroy($Image);
                touch($Original, time() - 120);
                $OriginalHash = md5_file($Original);
                $Key = 'GalleryPhotos/Plage/photo.jpg';
                $Thumb = $AlbumCache . '/photo.jpg.thumb.jpg';

                runProcessor();
                $Found = FindLikedMedia($Key);
                assertSameValue(0, GetAppliedRotation($Found['item']), 'Photo processed without rotation');
                assertSameValue(array(300, 200), array_slice(getimagesize($Thumb), 0, 2), 'Vignette d\'origine en paysage');

                assertSameValue(90, RotateMedia($Key, $Found['album'], $Found['item'], 90), 'A quarter turn to the right');
                assertSameValue(array(200, 300), array_slice(getimagesize($Thumb), 0, 2), 'Vignette refaite en portrait');
                assertSameValue(array(200, 300), array_slice(getimagesize($AlbumCache . '/photo.jpg.preview.jpg'), 0, 2), 'Preview regenerated in portrait');
                assertSameValue('rouge', colorAt($Thumb, 100, 20), 'Right: the left of the photo goes to the top');
                assertSameValue(array($Key => 90), json_decode(file_get_contents(GetRotationsFilePath()), TRUE), 'Rotation saved');
                assertSameValue($OriginalHash, md5_file($Original), 'Original intact');

                assertSameValue(0, RotateMedia($Key, $Found['album'], $Found['item'], -90), 'Back upright');
                assertSameValue('{}', trim(file_get_contents(GetRotationsFilePath())), 'An upright photo leaves the file');
                assertSameValue(270, RotateMedia($Key, $Found['album'], $Found['item'], -90), 'A quarter turn to the left');
                assertSameValue('bleu', colorAt($Thumb, 100, 20), 'Left: the right of the photo goes to the top');

                // Processing updates the record from the requested rotation
                runProcessor();
                $Manifest = json_decode(file_get_contents($AlbumCache . '/album.json'), TRUE);
                assertSameValue(array(270, 200, 300), array($Manifest['items'][0]['rotation'], $Manifest['items'][0]['thumb_width'], $Manifest['items'][0]['thumb_height']),
                        'The record notes the applied rotation');

                // Cache deleted: rebuilt with the rotation
                removeDirectoryRecursively($CacheDirectory);
                runProcessor();
                assertSameValue(array(200, 300), array_slice(getimagesize($Thumb), 0, 2), 'Cache rebuilt with the rotated photo');
                assertSameValue('bleu', colorAt($Thumb, 100, 20), 'The right way round');
                assertSameValue($OriginalHash, md5_file($Original), 'Original toujours intact');
        }

        echo 'RotationsTest: OK' . PHP_EOL;
}
catch (Throwable $Error)
{
        fwrite(STDERR, 'RotationsTest: FAILED - ' . $Error->getMessage() . PHP_EOL);
        $exitCode = 1;
}

removeDirectoryRecursively($WorkDirectory);
exit($exitCode);
