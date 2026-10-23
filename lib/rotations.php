<?php
/*
 * Rotation des photos d'un quart de tour, depuis la visionneuse. L'original
 * n'est jamais modifié : seuls la vignette, l'aperçu et la version pleine
 * taille du cache sont refaits, tournés.
 *
 * Les rotations sont gardées hors du cache, qui peut être effacé et
 * reconstruit sans les perdre, dans un petit fichier JSON du dossier de
 * configuration (/config/rotations/rotations.json par défaut,
 * SITEIMAGEPERSO_ROTATIONS_FILE pour un autre emplacement) :
 *   { "GalleryPhotos/2024/Plage/IMG_0001.jpg": 90 }
 * en degrés, dans le sens des aiguilles d'une montre. La clé d'un média
 * est la même que pour les « j'aime » (voir GetMediaKey).
 *
 * La fiche album.json note pour chaque photo la rotation appliquée à ses
 * fichiers du cache (champ rotation) : le traitement en arrière-plan
 * refait les photos dont la rotation demandée a changé.
 */

require_once __DIR__ . '/media.php';

function GetRotationsFilePath()
{
        $Configured = getenv('SITEIMAGEPERSO_ROTATIONS_FILE');

        if (($Configured !== FALSE) && ($Configured !== ''))
        {
                return $Configured;
        }

        return '/config/rotations/rotations.json';
}

function ParseRotationsData($Json)
{
        $Data = json_decode((string) $Json, TRUE);
        $Rotations = array();

        if (is_array($Data) === FALSE)
        {
                return $Rotations;
        }

        foreach ($Data as $Key => $Degrees)
        {
                $Degrees = NormalizeMediaRotation($Degrees);

                if ($Degrees !== 0)
                {
                        $Rotations[(string) $Key] = $Degrees;
                }
        }

        return $Rotations;
}

// Relu à chaque appel : le traitement en arrière-plan tourne sans s'arrêter
function ReadRotations()
{
        $FilePath = GetRotationsFilePath();

        return is_file($FilePath) ? ParseRotationsData(@file_get_contents($FilePath)) : array();
}

// Lu une seule fois par requête
function LoadRotations()
{
        static $Rotations = NULL;

        if ($Rotations === NULL)
        {
                $Rotations = ReadRotations();
        }

        return $Rotations;
}

function GetMediaRotation($Key, $Rotations = NULL)
{
        if ($Rotations === NULL)
        {
                $Rotations = LoadRotations();
        }

        return isset($Rotations[$Key]) ? $Rotations[$Key] : 0;
}

// Rotation des fichiers du cache d'après la fiche (absente des fiches plus anciennes : 0)
function GetAppliedRotation($Item)
{
        return isset($Item['rotation']) ? NormalizeMediaRotation($Item['rotation']) : 0;
}

// Photos seulement ; pas les GIF, que la visionneuse montre d'origine pour garder l'animation
function CanRotateMedia($Item)
{
        return ($Item['kind'] === 'image') && (empty($Item['preview']) === FALSE);
}

/*
 * Média de la fiche tel que la page doit le montrer avec la rotation
 * $Rotation : largeur et hauteur échangées si les fichiers du cache ont
 * été refaits depuis la fiche (par RotateMedia), et adresses suivies de
 * « ?r=90 » pour que le navigateur ne ressorte pas l'ancienne vignette.
 */
function ApplyMediaRotation($Item, $Rotation)
{
        if (CanRotateMedia($Item) === FALSE)
        {
                return $Item;
        }

        if ((abs($Rotation - GetAppliedRotation($Item)) % 180) === 90)
        {
                list($Item['width'], $Item['height']) = array($Item['height'], $Item['width']);
                list($Item['thumb_width'], $Item['thumb_height']) = array($Item['thumb_height'], $Item['thumb_width']);
        }

        if ($Rotation !== 0)
        {
                foreach (array('thumb', 'preview', 'full') as $Field)
                {
                        if (empty($Item[$Field]) === FALSE)
                        {
                                $Item[$Field] .= '?r=' . $Rotation;
                        }
                }
        }

        $Item['rotation'] = $Rotation;

        return $Item;
}

/*
 * Tourne une photo de $Degrees (90 ou -90) : refait tout de suite ses
 * fichiers du cache à partir de l'original, puis enregistre la rotation.
 * $AlbumPath et $Item viennent de la fiche de l'album (voir FindLikedMedia).
 * Renvoie la nouvelle rotation, ou NULL en cas d'échec (rien n'est alors
 * enregistré).
 */
function RotateMedia($Key, $AlbumPath, $Item, $Degrees)
{
        $FilePath = GetRotationsFilePath();
        $Directory = dirname($FilePath);

        if ((is_dir($Directory) === FALSE) && (@mkdir($Directory, 0775, TRUE) === FALSE))
        {
                return NULL;
        }

        $Lock = @fopen($FilePath . '.lock', 'c');

        if ($Lock === FALSE)
        {
                return NULL;
        }

        // Le verrou couvre aussi la préparation des images : deux clics
        // rapprochés, même de deux personnes, s'appliquent l'un après l'autre
        flock($Lock, LOCK_EX);
        $Rotations = ReadRotations();
        $OldRotation = GetMediaRotation($Key, $Rotations);
        $Rotation = NormalizeMediaRotation($OldRotation + $Degrees);
        $SourcePath = GetMediaBaseDirectory() . '/' . $Key;
        $CacheDirectory = GetAlbumCacheDirectory($AlbumPath);
        $Versions = array('thumb' => MEDIA_THUMB_SIZE, 'preview' => MEDIA_PREVIEW_SIZE, 'full' => 0);
        $Saved = is_file($SourcePath);

        foreach ($Versions as $Field => $Size)
        {
                if ($Saved && (empty($Item[$Field]) === FALSE))
                {
                        $Saved = (RenderJpegVersion($SourcePath, $CacheDirectory . '/' . $Item['name'] . '.' . $Field . '.jpg', $Size, $Rotation) === '');
                }
        }

        if ($Saved)
        {
                if ($Rotation === 0)
                {
                        unset($Rotations[$Key]);
                }
                else
                {
                        $Rotations[$Key] = $Rotation;
                }

                $TemporaryPath = $FilePath . '.tmp';
                $Written = @file_put_contents($TemporaryPath, json_encode((object) $Rotations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
                $Saved = ($Written !== FALSE) && @rename($TemporaryPath, $FilePath);
        }

        // Échec en route : on remet ce qui a pu être refait comme avant
        if ($Saved === FALSE)
        {
                foreach ($Versions as $Field => $Size)
                {
                        if (is_file($SourcePath) && (empty($Item[$Field]) === FALSE))
                        {
                                RenderJpegVersion($SourcePath, $CacheDirectory . '/' . $Item['name'] . '.' . $Field . '.jpg', $Size, $OldRotation);
                        }
                }
        }

        flock($Lock, LOCK_UN);
        fclose($Lock);

        return $Saved ? $Rotation : NULL;
}
