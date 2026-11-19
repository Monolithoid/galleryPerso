<?php
/*
 * Tools shared between the site and the background processing
 * (bin/process-media.php): location of the galleries and the cache,
 * media format detection, preparation of the JPEG versions.
 *
 * Originals are never modified: everything that is computed
 * (thumbnails, previews, converted videos, album.json record) is stored
 * in the cache, which mirrors the gallery tree.
 */

define('MEDIA_THUMB_SIZE', 400);
define('MEDIA_PREVIEW_SIZE', 1600);
define('MEDIA_VIDEO_MAX_WIDTH', 1920);
define('MEDIA_MANIFEST_NAME', 'album.json');

// Technical folders of the old site, ignored by navigation and processing
define('MEDIA_IGNORED_DIRECTORIES', array('Small', 'Middle', 'Legend'));

// Formats that browsers display without conversion
define('MEDIA_BROWSER_IMAGE_TYPES', array('image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'));

define('MEDIA_VIDEO_EXTENSIONS', array(
        'mp4', 'm4v', 'mov', 'qt', 'avi', 'mkv', 'webm', 'wmv', 'flv', 'mpg', 'mpeg',
        'mts', 'm2ts', 'ts', '3gp', '3g2', 'divx', 'ogv', 'vob'
));

function GetMediaBaseDirectory()
{
        $Configured = getenv('SITEIMAGEPERSO_MEDIA_BASE');

        if (($Configured !== FALSE) && ($Configured !== ''))
        {
                $Resolved = realpath($Configured);

                if ($Resolved !== FALSE)
                {
                        return rtrim($Resolved, DIRECTORY_SEPARATOR);
                }
        }

        return rtrim(realpath(__DIR__ . '/..'), DIRECTORY_SEPARATOR);
}

function GetMediaCacheDirectory()
{
        $Configured = getenv('SITEIMAGEPERSO_CACHE_DIR');

        if (($Configured === FALSE) || ($Configured === ''))
        {
                $Configured = GetMediaBaseDirectory() . '/cache';
        }

        return rtrim($Configured, DIRECTORY_SEPARATOR);
}

// URL (relative to the site root) under which the cache is served
function GetMediaCacheUrl()
{
        $Configured = getenv('SITEIMAGEPERSO_CACHE_URL');

        if (($Configured === FALSE) || ($Configured === ''))
        {
                return './cache';
        }

        return rtrim($Configured, '/');
}

function GetGalleryRootNames()
{
        $Configured = getenv('SITEIMAGEPERSO_GALLERY_ROOTS');

        if (($Configured === FALSE) || (trim($Configured) === ''))
        {
                $Configured = 'GalleryPhotos,GalleryCreative';
        }

        $Names = array();

        foreach (explode(',', $Configured) as $Name)
        {
                $Name = trim($Name, " \t/");

                if (($Name !== '') && (strpos($Name, '..') === FALSE))
                {
                        $Names[] = $Name;
                }
        }

        return $Names;
}

// Display name of a gallery, for example SITEIMAGEPERSO_GALLERY_LABELS="GalleryPhotos=Galerie des gosses"
function GetGalleryLabel($RootName)
{
        $Labels = array(
                'GalleryPhotos' => 'Galerie des gosses',
                'GalleryCreative' => 'Galerie des glaces'
        );
        $Configured = getenv('SITEIMAGEPERSO_GALLERY_LABELS');

        if (($Configured !== FALSE) && ($Configured !== ''))
        {
                foreach (explode(';', $Configured) as $Pair)
                {
                        $Parts = explode('=', $Pair, 2);

                        if (count($Parts) === 2)
                        {
                                $Labels[trim($Parts[0])] = trim($Parts[1]);
                        }
                }
        }

        return isset($Labels[$RootName]) ? $Labels[$RootName] : $RootName;
}

function IsIgnoredMediaEntry($Name)
{
        if (($Name === '') || ($Name[0] === '.') || ($Name[0] === '@'))
        {
                return TRUE;
        }

        // "Legend" as well as "legend": the case varies between albums
        return in_array(strtolower($Name), array_map('strtolower', MEDIA_IGNORED_DIRECTORIES), TRUE);
}

// "./GalleryPhotos/2024/Plage" -> "GalleryPhotos/2024/Plage"
function NormalizeAlbumRelativePath($RelativePath)
{
        $RelativePath = str_replace('\\', '/', (string) $RelativePath);

        if (strpos($RelativePath, './') === 0)
        {
                $RelativePath = substr($RelativePath, 2);
        }

        return trim($RelativePath, '/');
}

// A media item's key: its album path followed by its file name
function GetMediaKey($AlbumPath, $Name)
{
        return NormalizeAlbumRelativePath($AlbumPath) . '/' . $Name;
}

function GetAlbumCacheDirectory($RelativePath)
{
        return GetMediaCacheDirectory() . '/' . NormalizeAlbumRelativePath($RelativePath);
}

function GetAlbumCacheUrl($RelativePath)
{
        return GetMediaCacheUrl() . '/' . EncodeUrlPath(NormalizeAlbumRelativePath($RelativePath));
}

function EncodeUrlPath($Path)
{
        $Segments = explode('/', $Path);

        foreach ($Segments as $Index => $Segment)
        {
                if (($Segment !== '.') && ($Segment !== '..'))
                {
                        $Segments[$Index] = rawurlencode($Segment);
                }
        }

        return implode('/', $Segments);
}

function LoadAlbumManifest($RelativePath)
{
        $ManifestPath = GetAlbumCacheDirectory($RelativePath) . '/' . MEDIA_MANIFEST_NAME;

        if (is_file($ManifestPath) === FALSE)
        {
                return NULL;
        }

        $Manifest = json_decode((string) file_get_contents($ManifestPath), TRUE);

        if ((is_array($Manifest) === FALSE) || (isset($Manifest['items']) === FALSE))
        {
                return NULL;
        }

        return $Manifest;
}

/*
 * Determines a file's type from its content (not its extension):
 * returns array('kind' => 'image'|'video'|NULL, 'mime' => ...).
 * Videos with a known extension but a generic MIME type are
 * confirmed by ffprobe in the background processing.
 */
function DetectMediaKind($AbsolutePath, $FileInfo = NULL)
{
        $OwnFileInfo = FALSE;

        if ($FileInfo === NULL)
        {
                $FileInfo = finfo_open(FILEINFO_MIME_TYPE);
                $OwnFileInfo = TRUE;
        }

        $Mime = finfo_file($FileInfo, $AbsolutePath);

        if ($OwnFileInfo === TRUE)
        {
                finfo_close($FileInfo);
        }

        if ($Mime === FALSE)
        {
                $Mime = 'application/octet-stream';
        }

        $Extension = strtolower((string) pathinfo($AbsolutePath, PATHINFO_EXTENSION));

        if (strpos($Mime, 'image/') === 0)
        {
                // SVGs and icons are not photos
                if (in_array($Mime, array('image/svg+xml', 'image/vnd.microsoft.icon', 'image/x-icon'), TRUE))
                {
                        return array('kind' => NULL, 'mime' => $Mime);
                }

                return array('kind' => 'image', 'mime' => $Mime);
        }

        if (in_array($Extension, array('heic', 'heif', 'avif'), TRUE)
                && (strpos($Mime, 'application/octet-stream') === 0))
        {
                return array('kind' => 'image', 'mime' => 'image/' . $Extension);
        }

        if (strpos($Mime, 'video/') === 0)
        {
                return array('kind' => 'video', 'mime' => $Mime);
        }

        if (in_array($Extension, MEDIA_VIDEO_EXTENSIONS, TRUE)
                && ((strpos($Mime, 'application/') === 0) || (strpos($Mime, 'audio/') === 0)))
        {
                return array('kind' => 'video', 'mime' => $Mime, 'needs_probe' => TRUE);
        }

        return array('kind' => NULL, 'mime' => $Mime);
}

// Only quarter turns are possible: 0, 90, 180 or 270 (clockwise)
function NormalizeMediaRotation($Degrees)
{
        $Degrees = (((int) $Degrees % 360) + 360) % 360;

        return in_array($Degrees, array(90, 180, 270), TRUE) ? $Degrees : 0;
}

function RunMediaCommand($Arguments, &$Output = NULL, &$Errors = NULL)
{
        // No shell and a UTF-8 locale: otherwise names with accents
        // ("Noël", "Vacances à la mer") are refused or mangled
        $Descriptors = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
        // VIPS_CONCURRENCY=1: one image at a time, minimal memory
        $Environment = array_merge(array('VIPS_CONCURRENCY' => '1'), getenv(), array('LC_ALL' => 'C.UTF-8'));
        $Process = proc_open($Arguments, $Descriptors, $Pipes, NULL, $Environment);

        if (is_resource($Process) === FALSE)
        {
                $Output = '';
                $Errors = $Arguments[0] . ' introuvable';

                return 127;
        }

        $Output = stream_get_contents($Pipes[1]);
        $Errors = stream_get_contents($Pipes[2]);
        fclose($Pipes[1]);
        fclose($Pipes[2]);

        return proc_close($Process);
}

// First useful line of a tool's error output, without the file path
function SummarizeToolError($Errors, $SourcePath)
{
        $Errors = str_replace($SourcePath, basename($SourcePath), (string) $Errors);

        foreach (preg_split('/\R/', $Errors) as $Line)
        {
                // "[mov,mp4,m4a @ 0x55d0c8e2] moov atom not found": keep the message
                $Line = trim(preg_replace('/^\[[^\]]* @ 0x[0-9a-f]+\]\s*/', '', $Line));

                if ($Line !== '')
                {
                        return mb_strimwidth($Line, 0, 200, '…');
                }
        }

        return '';
}

/*
 * JPEG version of an image within a $Size-pixel square ($Size = 0:
 * full size), EXIF orientation fixed, then rotated by $Rotation
 * degrees. vips can read JPEG, PNG, WebP, HEIC, AVIF, TIFF and GIF.
 * Returns '' if all goes well, otherwise the reason for the failure.
 */
function RenderJpegVersion($SourcePath, $DestinationPath, $Size, $Rotation = 0)
{
        $TemporaryPath = dirname($DestinationPath) . '/.tmp' . getmypid() . '-' . basename($DestinationPath);
        $Target = $TemporaryPath . '[Q=82,strip]';
        $Rotation = NormalizeMediaRotation($Rotation);
        // Rotated afterwards: the intermediate step, in vips format, loses nothing
        $Intermediate = ($Rotation !== 0) ? $TemporaryPath . '.v' : NULL;
        $FirstTarget = ($Intermediate !== NULL) ? $Intermediate : $Target;
        $Arguments = array('vipsthumbnail', $SourcePath, '--size', $Size . 'x' . $Size . '>', '-o', $FirstTarget);

        if ($Size === 0)
        {
                $Arguments = array('vips', 'autorot', $SourcePath, $FirstTarget);
        }

        $Errors = '';
        $Success = (RunMediaCommand($Arguments, $Output, $Errors) === 0);

        if ($Success && ($Intermediate !== NULL))
        {
                $Success = (RunMediaCommand(array('vips', 'rot', $Intermediate, $Target, 'd' . $Rotation), $Output, $Errors) === 0);
                @unlink($Intermediate);
        }

        if (($Success === FALSE) || (is_file($TemporaryPath) === FALSE))
        {
                @unlink($TemporaryPath);
                $Detail = SummarizeToolError($Errors, $SourcePath);

                return 'image illisible par vips' . (($Detail !== '') ? ' : ' . $Detail : '');
        }

        rename($TemporaryPath, $DestinationPath);

        return '';
}

?>
