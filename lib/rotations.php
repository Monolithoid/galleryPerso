<?php
/*
 * Rotating photos by a quarter turn, from the viewer. The original
 * is never modified: only the thumbnail, the preview and the full-size
 * version in the cache are regenerated, rotated.
 *
 * Rotations are kept outside the cache, which can be deleted and
 * rebuilt without losing them, in a small JSON file in the
 * configuration folder (/config/rotations/rotations.json by default,
 * SITEIMAGEPERSO_ROTATIONS_FILE for another location):
 *   { "GalleryPhotos/2024/Plage/IMG_0001.jpg": 90 }
 * in degrees, clockwise. A media item's key
 * is the same as for likes (see GetMediaKey).
 *
 * The album.json record notes, for each photo, the rotation applied to its
 * cached files (rotation field): the background processing
 * regenerates the photos whose requested rotation has changed.
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

// Reread on every call: the background processing runs without stopping
function ReadRotations()
{
        $FilePath = GetRotationsFilePath();

        return is_file($FilePath) ? ParseRotationsData(@file_get_contents($FilePath)) : array();
}

// Read only once per request
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

// Rotation of the cached files according to the record (missing from older records: 0)
function GetAppliedRotation($Item)
{
        return isset($Item['rotation']) ? NormalizeMediaRotation($Item['rotation']) : 0;
}

// Photos only; not GIFs, which the viewer shows as originals to keep the animation
function CanRotateMedia($Item)
{
        return ($Item['kind'] === 'image') && (empty($Item['preview']) === FALSE);
}

/*
 * Media item from the record as the page must show it with rotation
 * $Rotation: width and height swapped if the cached files have
 * been regenerated since the record (by RotateMedia), and addresses followed by
 * "?r=90" so that the browser does not serve the old thumbnail.
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
 * Rotates a photo by $Degrees (90 or -90): immediately regenerates its
 * cached files from the original, then saves the rotation.
 * $AlbumPath and $Item come from the album record (see FindLikedMedia).
 * Returns the new rotation, or NULL on failure (nothing is then
 * saved).
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

        // The lock also covers preparing the images: two clicks
        // close together, even from two people, are applied one after the other
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

        // Failure midway: restore whatever was already regenerated
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
