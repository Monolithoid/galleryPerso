<?php
/*
 * Outils partagés entre le site et le traitement en arrière-plan
 * (bin/process-media.php) : emplacement des galeries et du cache,
 * détection du format des médias, lecture des légendes.
 *
 * Les originaux ne sont jamais modifiés : tout ce qui est calculé
 * (vignettes, aperçus, vidéos converties, fiche album.json) est rangé
 * dans le cache, qui reproduit l'arborescence des galeries.
 */

define('MEDIA_THUMB_SIZE', 400);
define('MEDIA_PREVIEW_SIZE', 1600);
define('MEDIA_VIDEO_MAX_WIDTH', 1920);
define('MEDIA_MANIFEST_NAME', 'album.json');

// Dossiers techniques de l'ancien site, ignorés dans la navigation et le traitement
define('MEDIA_IGNORED_DIRECTORIES', array('Small', 'Middle', 'Legend'));

// Formats que les navigateurs affichent sans conversion
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

// URL (relative à la racine du site) sous laquelle le cache est servi
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

// Nom affiché d'une galerie, par exemple SITEIMAGEPERSO_GALLERY_LABELS="GalleryPhotos=Galerie des gosses"
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

        // « Legend » comme « legend » : la casse varie selon les albums
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
 * Détermine le type d'un fichier d'après son contenu (et non son extension) :
 * renvoie array('kind' => 'image'|'video'|NULL, 'mime' => ...).
 * Les vidéos à l'extension connue mais au type MIME générique sont
 * confirmées par ffprobe dans le traitement en arrière-plan.
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
                // Les SVG ou icônes ne sont pas des photos
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

?>
