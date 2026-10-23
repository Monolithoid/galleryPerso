<?php
/*
 * Rotation des photos : quarts de tour enregistrés, fichiers du cache
 * refaits tournés sans toucher à l'original, et rotation reprise par le
 * traitement en arrière-plan (bin/process-media.php), même après
 * effacement du cache.
 * Lancement : php tests/RotationsTest.php
 * Les cas qui demandent vipsthumbnail sont ignorés s'il n'est pas installé.
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

// Couleur dominante d'un pixel : 'rouge' ou 'bleu'
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
        assertSameValue(0, $exitCode, 'Le traitement se termine sans erreur : ' . implode(PHP_EOL, $output));
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
        assertSameValue(270, NormalizeMediaRotation(-90), '-90 degrés = 270');
        assertSameValue(90, NormalizeMediaRotation(450), '450 degrés = 90');
        assertSameValue(0, NormalizeMediaRotation(45), 'Pas de rotation hors quart de tour');
        assertSameValue(array('a.jpg' => 180), ParseRotationsData('{"a.jpg": 180, "b.jpg": 0, "c.jpg": 33}'), 'Seules les vraies rotations sont gardées');

        $Item = array('name' => 'a.jpg', 'kind' => 'image', 'thumb' => 'cache/a.jpg.thumb.jpg', 'thumb_width' => 400, 'thumb_height' => 300,
                'preview' => 'cache/a.jpg.preview.jpg', 'full' => NULL, 'width' => 1600, 'height' => 1200, 'rotation' => 0);
        $Rotated = ApplyMediaRotation($Item, 90);
        assertSameValue(array('cache/a.jpg.thumb.jpg?r=90', 300, 400, 1200, 1600), array($Rotated['thumb'], $Rotated['thumb_width'], $Rotated['thumb_height'], $Rotated['width'], $Rotated['height']),
                'Cache refait mais fiche pas encore à jour : dimensions échangées, adresse changée');
        $Item['rotation'] = 90;
        $Rotated = ApplyMediaRotation(array('thumb_width' => 300, 'thumb_height' => 400, 'width' => 1200, 'height' => 1600) + $Item, 90);
        assertSameValue(array(300, 400), array($Rotated['thumb_width'], $Rotated['thumb_height']), 'Fiche déjà à jour : dimensions gardées');
        assertSameValue('cache/a.jpg.thumb.jpg', ApplyMediaRotation($Item, 0)['thumb'], 'Sans rotation, adresse d\'origine');
        assertSameValue(FALSE, CanRotateMedia(array('kind' => 'image', 'preview' => NULL)), 'Un GIF animé ne se tourne pas');
        assertSameValue(FALSE, CanRotateMedia(array('kind' => 'video', 'preview' => 'p.jpg')), 'Une vidéo ne se tourne pas');

        if (trim((string) shell_exec('command -v vipsthumbnail 2>/dev/null')) === '')
        {
                echo 'vipsthumbnail absent : tests de rotation des fichiers ignorés.' . PHP_EOL;
        }
        else
        {
                // 300x200 : moitié gauche rouge, moitié droite bleue
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
                assertSameValue(0, GetAppliedRotation($Found['item']), 'Photo traitée sans rotation');
                assertSameValue(array(300, 200), array_slice(getimagesize($Thumb), 0, 2), 'Vignette d\'origine en paysage');

                assertSameValue(90, RotateMedia($Key, $Found['album'], $Found['item'], 90), 'Un quart de tour à droite');
                assertSameValue(array(200, 300), array_slice(getimagesize($Thumb), 0, 2), 'Vignette refaite en portrait');
                assertSameValue(array(200, 300), array_slice(getimagesize($AlbumCache . '/photo.jpg.preview.jpg'), 0, 2), 'Aperçu refait en portrait');
                assertSameValue('rouge', colorAt($Thumb, 100, 20), 'À droite : la gauche de la photo passe en haut');
                assertSameValue(array($Key => 90), json_decode(file_get_contents(GetRotationsFilePath()), TRUE), 'Rotation enregistrée');
                assertSameValue($OriginalHash, md5_file($Original), 'Original intact');

                assertSameValue(0, RotateMedia($Key, $Found['album'], $Found['item'], -90), 'Retour à l\'endroit');
                assertSameValue('{}', trim(file_get_contents(GetRotationsFilePath())), 'Une photo à l\'endroit sort du fichier');
                assertSameValue(270, RotateMedia($Key, $Found['album'], $Found['item'], -90), 'Un quart de tour à gauche');
                assertSameValue('bleu', colorAt($Thumb, 100, 20), 'À gauche : la droite de la photo passe en haut');

                // Le traitement met la fiche à jour d'après la rotation demandée
                runProcessor();
                $Manifest = json_decode(file_get_contents($AlbumCache . '/album.json'), TRUE);
                assertSameValue(array(270, 200, 300), array($Manifest['items'][0]['rotation'], $Manifest['items'][0]['thumb_width'], $Manifest['items'][0]['thumb_height']),
                        'La fiche note la rotation appliquée');

                // Cache effacé : reconstruit avec la rotation
                removeDirectoryRecursively($CacheDirectory);
                runProcessor();
                assertSameValue(array(200, 300), array_slice(getimagesize($Thumb), 0, 2), 'Cache reconstruit avec la photo tournée');
                assertSameValue('bleu', colorAt($Thumb, 100, 20), 'Dans le bon sens');
                assertSameValue($OriginalHash, md5_file($Original), 'Original toujours intact');
        }

        echo 'RotationsTest : OK' . PHP_EOL;
}
catch (Throwable $Error)
{
        fwrite(STDERR, 'RotationsTest : ÉCHEC - ' . $Error->getMessage() . PHP_EOL);
        $exitCode = 1;
}

removeDirectoryRecursively($WorkDirectory);
exit($exitCode);
