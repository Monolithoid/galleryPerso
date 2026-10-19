<?php
/*
 * Traitement des médias en arrière-plan.
 *
 * Parcourt les galeries, reconnaît le format réel de chaque fichier et
 * prépare dans le cache tout ce dont le site a besoin :
 *   - <nom>.thumb.jpg   vignette (orientation EXIF corrigée)
 *   - <nom>.preview.jpg aperçu pour la visionneuse
 *   - <nom>.full.jpg    version pleine taille des formats que les
 *                       navigateurs n'affichent pas (HEIC, TIFF...)
 *   - <nom>.mp4         vidéo convertie, seulement si l'original n'est
 *                       pas lisible par les navigateurs
 *   - album.json        fiche de l'album lue par le site
 *
 * Les originaux ne sont jamais modifiés. Un fichier déjà traité et
 * inchangé (taille et date) n'est pas retraité.
 *
 * Usage : php bin/process-media.php [--once] [--interval=60] [-v]
 */

if (PHP_SAPI !== 'cli')
{
        http_response_code(404);
        exit;
}

require_once __DIR__ . '/../lib/media.php';

define('PROCESSOR_VERSION', 1);

$Options = getopt('v', array('once', 'interval:'));
$Verbose = isset($Options['v']);
$Interval = isset($Options['interval']) ? max(5, (int) $Options['interval']) : 0;

if (isset($Options['once']))
{
        $Interval = 0;
}

function LogLine($Message)
{
        fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $Message . PHP_EOL);
}

function DebugLine($Message)
{
        global $Verbose;

        if ($Verbose === TRUE)
        {
                LogLine($Message);
        }
}

function RunCommand($Arguments, &$Output = NULL)
{
        $Command = implode(' ', array_map('escapeshellarg', $Arguments));
        $Descriptors = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
        $Process = proc_open($Command, $Descriptors, $Pipes);

        if (is_resource($Process) === FALSE)
        {
                $Output = '';

                return 127;
        }

        $Output = stream_get_contents($Pipes[1]);
        $Errors = stream_get_contents($Pipes[2]);
        fclose($Pipes[1]);
        fclose($Pipes[2]);
        $ExitCode = proc_close($Process);

        if ($ExitCode !== 0)
        {
                DebugLine('Échec (' . $ExitCode . ') : ' . $Command . PHP_EOL . trim($Errors));
        }

        return $ExitCode;
}

function EnsureDirectory($Path)
{
        if ((is_dir($Path) === FALSE) && (@mkdir($Path, 0775, TRUE) === FALSE) && (is_dir($Path) === FALSE))
        {
                throw new RuntimeException('Impossible de créer ' . $Path);
        }
}

function WriteFileAtomically($Path, $Content)
{
        $TemporaryPath = $Path . '.tmp' . getmypid();
        file_put_contents($TemporaryPath, $Content);
        rename($TemporaryPath, $Path);
}

/*
 * Images
 */

// vipsthumbnail applique l'orientation EXIF et sait lire JPEG, PNG, WebP, HEIC, AVIF, TIFF, GIF
function CreateJpegVersion($SourcePath, $DestinationPath, $Size)
{
        $TemporaryPath = dirname($DestinationPath) . '/.tmp' . getmypid() . '-' . basename($DestinationPath);
        $Target = $TemporaryPath . '[Q=82,strip]';
        $Arguments = array('vipsthumbnail', $SourcePath, '--size', $Size . 'x' . $Size . '>', '-o', $Target);

        if ($Size === 0)
        {
                $Arguments = array('vips', 'autorot', $SourcePath, $Target);
        }

        if ((RunCommand($Arguments) !== 0) || (is_file($TemporaryPath) === FALSE))
        {
                @unlink($TemporaryPath);

                return FALSE;
        }

        rename($TemporaryPath, $DestinationPath);

        return TRUE;
}

function CleanMetadataText($Value)
{
        if (is_array($Value))
        {
                $Value = implode(' ', $Value);
        }

        $Value = (string) $Value;

        // Windows XPComment / XPTitle : UTF-16LE
        if ((strlen($Value) > 1) && (strpos($Value, "\0") !== FALSE))
        {
                $Value = mb_convert_encoding($Value, 'UTF-8', 'UTF-16LE');
        }

        // UserComment : préfixe de 8 octets indiquant le jeu de caractères
        if (preg_match('/^(ASCII|UNICODE|JIS)\0{0,3}/', $Value, $Matches) === 1)
        {
                $Value = substr($Value, 8);
        }

        if (preg_match('//u', $Value) !== 1)
        {
                $Value = mb_convert_encoding($Value, 'UTF-8', 'ISO-8859-1');
        }

        $Value = trim(str_replace("\0", '', $Value));

        // Textes génériques écrits par les appareils photo
        if (preg_match('/^(OLYMPUS DIGITAL CAMERA|SONY DSC|DCIM|default|untitled|Image)$/i', $Value) === 1)
        {
                return '';
        }

        return $Value;
}

function ReadImageMetadata($SourcePath)
{
        $Metadata = array('taken' => NULL, 'caption' => '');

        if (function_exists('exif_read_data') === FALSE)
        {
                return $Metadata;
        }

        $Exif = @exif_read_data($SourcePath, NULL, FALSE);

        if (is_array($Exif) === FALSE)
        {
                return $Metadata;
        }

        foreach (array('DateTimeOriginal', 'DateTimeDigitized', 'DateTime') as $Key)
        {
                if (isset($Exif[$Key]) && (preg_match('/^(\d{4}):(\d{2}):(\d{2}) (\d{2}):(\d{2}):(\d{2})/', $Exif[$Key], $Parts) === 1)
                        && ($Parts[1] !== '0000'))
                {
                        $Metadata['taken'] = $Parts[1] . '-' . $Parts[2] . '-' . $Parts[3] . 'T' . $Parts[4] . ':' . $Parts[5] . ':' . $Parts[6];
                        break;
                }
        }

        foreach (array('ImageDescription', 'XPComment', 'UserComment', 'XPTitle') as $Key)
        {
                if (isset($Exif[$Key]))
                {
                        $Caption = CleanMetadataText($Exif[$Key]);

                        if ($Caption !== '')
                        {
                                $Metadata['caption'] = $Caption;
                                break;
                        }
                }
        }

        return $Metadata;
}

function ProcessImage($SourcePath, $CacheDirectory, $AlbumCacheUrl, $Name, $Mime)
{
        $ThumbName = $Name . '.thumb.jpg';
        $PreviewName = $Name . '.preview.jpg';

        if ((CreateJpegVersion($SourcePath, $CacheDirectory . '/' . $ThumbName, MEDIA_THUMB_SIZE) === FALSE)
                || (CreateJpegVersion($SourcePath, $CacheDirectory . '/' . $PreviewName, MEDIA_PREVIEW_SIZE) === FALSE))
        {
                return NULL;
        }

        $Item = array(
                'kind' => 'image',
                'mime' => $Mime,
                'thumb' => $AlbumCacheUrl . '/' . rawurlencode($ThumbName),
                'preview' => $AlbumCacheUrl . '/' . rawurlencode($PreviewName),
                'full' => NULL
        );

        // GIF animés : la visionneuse affiche l'original pour garder l'animation
        if ($Mime === 'image/gif')
        {
                $Item['preview'] = NULL;
        }

        if (in_array($Mime, MEDIA_BROWSER_IMAGE_TYPES, TRUE) === FALSE)
        {
                $FullName = $Name . '.full.jpg';

                if (CreateJpegVersion($SourcePath, $CacheDirectory . '/' . $FullName, 0) === TRUE)
                {
                        $Item['full'] = $AlbumCacheUrl . '/' . rawurlencode($FullName);
                }
        }

        $ThumbSize = @getimagesize($CacheDirectory . '/' . $ThumbName);
        $PreviewSize = @getimagesize($CacheDirectory . '/' . $PreviewName);
        $Item['thumb_width'] = $ThumbSize ? $ThumbSize[0] : MEDIA_THUMB_SIZE;
        $Item['thumb_height'] = $ThumbSize ? $ThumbSize[1] : MEDIA_THUMB_SIZE;
        $Item['width'] = $PreviewSize ? $PreviewSize[0] : MEDIA_PREVIEW_SIZE;
        $Item['height'] = $PreviewSize ? $PreviewSize[1] : MEDIA_PREVIEW_SIZE;

        return array_merge($Item, ReadImageMetadata($SourcePath));
}

/*
 * Vidéos
 */

function ProbeVideo($SourcePath)
{
        $Output = '';

        if (RunCommand(array('ffprobe', '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $SourcePath), $Output) !== 0)
        {
                return NULL;
        }

        $Probe = json_decode($Output, TRUE);

        if (is_array($Probe) === FALSE)
        {
                return NULL;
        }

        $Video = NULL;
        $Audio = NULL;

        foreach (isset($Probe['streams']) ? $Probe['streams'] : array() as $Stream)
        {
                $Type = isset($Stream['codec_type']) ? $Stream['codec_type'] : '';
                $IsCoverArt = isset($Stream['disposition']['attached_pic']) && ($Stream['disposition']['attached_pic'] === 1);

                if (($Type === 'video') && ($Video === NULL) && ($IsCoverArt === FALSE))
                {
                        $Video = $Stream;
                }
                else if (($Type === 'audio') && ($Audio === NULL))
                {
                        $Audio = $Stream;
                }
        }

        if ($Video === NULL)
        {
                return NULL;
        }

        $Width = isset($Video['width']) ? (int) $Video['width'] : 0;
        $Height = isset($Video['height']) ? (int) $Video['height'] : 0;
        $Rotation = 0;

        if (isset($Video['tags']['rotate']))
        {
                $Rotation = (int) $Video['tags']['rotate'];
        }

        foreach (isset($Video['side_data_list']) ? $Video['side_data_list'] : array() as $SideData)
        {
                if (isset($SideData['rotation']))
                {
                        $Rotation = (int) $SideData['rotation'];
                }
        }

        if ((abs($Rotation) % 180) === 90)
        {
                list($Width, $Height) = array($Height, $Width);
        }

        $Format = isset($Probe['format']) ? $Probe['format'] : array();
        $Tags = isset($Format['tags']) ? array_change_key_case($Format['tags'], CASE_LOWER) : array();

        return array(
                'format' => isset($Format['format_name']) ? $Format['format_name'] : '',
                'duration' => isset($Format['duration']) ? (float) $Format['duration'] : 0.0,
                'video_codec' => isset($Video['codec_name']) ? $Video['codec_name'] : '',
                'pixel_format' => isset($Video['pix_fmt']) ? $Video['pix_fmt'] : '',
                'audio_codec' => ($Audio !== NULL) && isset($Audio['codec_name']) ? $Audio['codec_name'] : NULL,
                'width' => $Width,
                'height' => $Height,
                'tags' => $Tags
        );
}

// Lisible tel quel par Chrome, Firefox et Safari ?
function IsBrowserPlayable($Probe)
{
        $Format = $Probe['format'];
        $Audio = $Probe['audio_codec'];

        if (strpos($Format, 'mp4') !== FALSE)
        {
                return ($Probe['video_codec'] === 'h264')
                        && in_array($Probe['pixel_format'], array('yuv420p', 'yuvj420p'), TRUE)
                        && (($Audio === NULL) || in_array($Audio, array('aac', 'mp3'), TRUE));
        }

        if (strpos($Format, 'webm') !== FALSE)
        {
                return in_array($Probe['video_codec'], array('vp8', 'vp9', 'av1'), TRUE)
                        && (($Audio === NULL) || in_array($Audio, array('opus', 'vorbis'), TRUE));
        }

        return FALSE;
}

function TranscodeVideo($SourcePath, $DestinationPath, $HasAudio)
{
        $TemporaryPath = dirname($DestinationPath) . '/.tmp' . getmypid() . '-' . basename($DestinationPath);
        $Arguments = array(
                'nice', '-n', '15',
                'ffmpeg', '-nostdin', '-y', '-v', 'error', '-i', $SourcePath,
                '-map', '0:v:0', '-map', '0:a:0?',
                '-vf', "scale='min(" . MEDIA_VIDEO_MAX_WIDTH . ",iw)':-2",
                '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-pix_fmt', 'yuv420p',
                '-movflags', '+faststart', '-f', 'mp4'
        );

        if ($HasAudio === TRUE)
        {
                $Arguments = array_merge($Arguments, array('-c:a', 'aac', '-b:a', '160k', '-ac', '2'));
        }

        $Arguments[] = $TemporaryPath;

        if ((RunCommand($Arguments) !== 0) || (is_file($TemporaryPath) === FALSE))
        {
                @unlink($TemporaryPath);

                return FALSE;
        }

        rename($TemporaryPath, $DestinationPath);

        return TRUE;
}

function ProcessVideo($SourcePath, $CacheDirectory, $AlbumCacheUrl, $AlbumSourceUrl, $Name, $Mime)
{
        $Probe = ProbeVideo($SourcePath);

        if ($Probe === NULL)
        {
                return NULL;
        }

        $PosterPath = $CacheDirectory . '/' . $Name . '.poster.jpg';
        $SeekSeconds = ($Probe['duration'] > 2) ? 1 : 0;

        if ((RunCommand(array('ffmpeg', '-nostdin', '-y', '-v', 'error', '-ss', (string) $SeekSeconds, '-i', $SourcePath, '-frames:v', '1', '-q:v', '3', $PosterPath)) !== 0)
                && (RunCommand(array('ffmpeg', '-nostdin', '-y', '-v', 'error', '-i', $SourcePath, '-frames:v', '1', '-q:v', '3', $PosterPath)) !== 0))
        {
                return NULL;
        }

        $ThumbName = $Name . '.thumb.jpg';
        $PreviewName = $Name . '.preview.jpg';

        if ((CreateJpegVersion($PosterPath, $CacheDirectory . '/' . $ThumbName, MEDIA_THUMB_SIZE) === FALSE)
                || (CreateJpegVersion($PosterPath, $CacheDirectory . '/' . $PreviewName, MEDIA_PREVIEW_SIZE) === FALSE))
        {
                @unlink($PosterPath);

                return NULL;
        }

        @unlink($PosterPath);

        $Source = $AlbumSourceUrl . '/' . rawurlencode($Name);
        $Transcoded = FALSE;

        if (IsBrowserPlayable($Probe) === FALSE)
        {
                $TranscodedName = $Name . '.mp4';
                LogLine('Conversion de ' . $SourcePath . ' (' . $Probe['format'] . ', ' . $Probe['video_codec'] . ')');

                if (TranscodeVideo($SourcePath, $CacheDirectory . '/' . $TranscodedName, $Probe['audio_codec'] !== NULL) === FALSE)
                {
                        LogLine('Conversion impossible : ' . $SourcePath);

                        return NULL;
                }

                $Source = $AlbumCacheUrl . '/' . rawurlencode($TranscodedName);
                $Transcoded = TRUE;
        }

        $ThumbSize = @getimagesize($CacheDirectory . '/' . $ThumbName);
        $Taken = NULL;

        if (isset($Probe['tags']['creation_time']) && (strtotime($Probe['tags']['creation_time']) > 0))
        {
                $Taken = date('Y-m-d\TH:i:s', strtotime($Probe['tags']['creation_time']));
        }

        $Caption = '';

        foreach (array('description', 'comment', 'title') as $Key)
        {
                if (isset($Probe['tags'][$Key]) && (CleanMetadataText($Probe['tags'][$Key]) !== ''))
                {
                        $Caption = CleanMetadataText($Probe['tags'][$Key]);
                        break;
                }
        }

        return array(
                'kind' => 'video',
                'mime' => $Transcoded ? 'video/mp4' : $Mime,
                'thumb' => $AlbumCacheUrl . '/' . rawurlencode($ThumbName),
                'preview' => $AlbumCacheUrl . '/' . rawurlencode($PreviewName),
                'video' => $Source,
                'transcoded' => $Transcoded,
                'thumb_width' => $ThumbSize ? $ThumbSize[0] : MEDIA_THUMB_SIZE,
                'thumb_height' => $ThumbSize ? $ThumbSize[1] : MEDIA_THUMB_SIZE,
                'width' => $Probe['width'],
                'height' => $Probe['height'],
                'duration' => round($Probe['duration'], 1),
                'taken' => $Taken,
                'caption' => $Caption
        );
}

/*
 * Albums
 */

function SaveManifest($CacheDirectory, $RelativePath, $Items, $Failed)
{
        uasort($Items, function ($Left, $Right)
        {
                return strnatcasecmp($Left['name'], $Right['name']);
        });

        $Manifest = array(
                'version' => PROCESSOR_VERSION,
                'path' => $RelativePath,
                'generated' => date('c'),
                'items' => array_values($Items),
                // Fichiers illisibles, retentés seulement s'ils changent
                'failed' => (object) $Failed
        );

        WriteFileAtomically(
                $CacheDirectory . '/' . MEDIA_MANIFEST_NAME,
                json_encode($Manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
}

function ProcessAlbum($AbsolutePath, $RelativePath, &$Statistics)
{
        $CacheDirectory = GetAlbumCacheDirectory($RelativePath);
        $AlbumCacheUrl = GetAlbumCacheUrl($RelativePath);
        $AlbumSourceUrl = './' . EncodeUrlPath($RelativePath);
        $Entries = @scandir($AbsolutePath);

        if ($Entries === FALSE)
        {
                return array();
        }

        $Previous = array();
        $PreviousFailed = array();
        $ManifestPath = $CacheDirectory . '/' . MEDIA_MANIFEST_NAME;

        if (is_file($ManifestPath))
        {
                $OldManifest = json_decode((string) file_get_contents($ManifestPath), TRUE);

                if (is_array($OldManifest) && isset($OldManifest['items'])
                        && isset($OldManifest['version']) && ($OldManifest['version'] === PROCESSOR_VERSION))
                {
                        foreach ($OldManifest['items'] as $OldItem)
                        {
                                $Previous[$OldItem['name']] = $OldItem;
                        }

                        if (isset($OldManifest['failed']) && is_array($OldManifest['failed']))
                        {
                                $PreviousFailed = $OldManifest['failed'];
                        }
                }
        }

        $FileInfo = finfo_open(FILEINFO_MIME_TYPE);
        $Items = array();
        $Failed = array();
        $SubDirectories = array();
        $Changed = (is_file($ManifestPath) === FALSE);

        foreach ($Entries as $Entry)
        {
                if (IsIgnoredMediaEntry($Entry) || ($Entry === MEDIA_LEGEND_FILE))
                {
                        continue;
                }

                $EntryPath = $AbsolutePath . '/' . $Entry;

                if (is_dir($EntryPath))
                {
                        $SubDirectories[] = $Entry;
                        continue;
                }

                if (is_file($EntryPath) === FALSE)
                {
                        continue;
                }

                $Size = filesize($EntryPath);
                $ModifiedAt = filemtime($EntryPath);

                if (isset($Previous[$Entry])
                        && ($Previous[$Entry]['size'] === $Size)
                        && ($Previous[$Entry]['mtime'] === $ModifiedAt)
                        && is_file($CacheDirectory . '/' . $Entry . '.thumb.jpg'))
                {
                        $Items[$Entry] = $Previous[$Entry];
                        continue;
                }

                if (isset($PreviousFailed[$Entry])
                        && ($PreviousFailed[$Entry]['size'] === $Size)
                        && ($PreviousFailed[$Entry]['mtime'] === $ModifiedAt))
                {
                        $Failed[$Entry] = $PreviousFailed[$Entry];
                        continue;
                }

                // Ignore les fichiers encore en cours de copie
                if ((time() - $ModifiedAt) < 10)
                {
                        DebugLine('En cours de copie, repris plus tard : ' . $EntryPath);
                        continue;
                }

                $Detection = DetectMediaKind($EntryPath, $FileInfo);

                if ($Detection['kind'] === NULL)
                {
                        continue;
                }

                EnsureDirectory($CacheDirectory);
                DebugLine('Traitement de ' . $EntryPath . ' (' . $Detection['mime'] . ')');

                if ($Detection['kind'] === 'image')
                {
                        $Item = ProcessImage($EntryPath, $CacheDirectory, $AlbumCacheUrl, $Entry, $Detection['mime']);
                }
                else
                {
                        $Item = ProcessVideo($EntryPath, $CacheDirectory, $AlbumCacheUrl, $AlbumSourceUrl, $Entry, $Detection['mime']);
                }

                if ($Item === NULL)
                {
                        LogLine('Format non pris en charge ou fichier illisible : ' . $EntryPath);
                        $Failed[$Entry] = array('size' => $Size, 'mtime' => $ModifiedAt);
                        $Changed = TRUE;
                        $Statistics['failed']++;
                        continue;
                }

                $Item = array_merge(
                        array(
                                'name' => $Entry,
                                'original' => $AlbumSourceUrl . '/' . rawurlencode($Entry),
                                'size' => $Size,
                                'mtime' => $ModifiedAt
                        ),
                        $Item
                );

                if ($Item['taken'] === NULL)
                {
                        $Item['taken'] = date('Y-m-d\TH:i:s', $ModifiedAt);
                }

                $Items[$Entry] = $Item;
                $Changed = TRUE;
                $Statistics['processed']++;

                // L'album apparaît sur le site au fur et à mesure du traitement
                SaveManifest($CacheDirectory, $RelativePath, $Items, $Failed);
        }

        finfo_close($FileInfo);

        if ((count(array_diff_key($Previous, $Items)) > 0) || (count(array_diff_key($PreviousFailed, $Failed)) > 0))
        {
                $Changed = TRUE;
        }

        if ($Changed === TRUE)
        {
                EnsureDirectory($CacheDirectory);
                SaveManifest($CacheDirectory, $RelativePath, $Items, $Failed);
        }

        RemoveOrphanCacheFiles($CacheDirectory, $Items, $SubDirectories);

        return $SubDirectories;
}

// Supprime du cache ce qui correspond à des fichiers ou dossiers disparus
function RemoveOrphanCacheFiles($CacheDirectory, $Items, $SubDirectories)
{
        $CacheEntries = @scandir($CacheDirectory);

        if ($CacheEntries === FALSE)
        {
                return;
        }

        foreach ($CacheEntries as $CacheEntry)
        {
                if (($CacheEntry === '.') || ($CacheEntry === '..') || ($CacheEntry === MEDIA_MANIFEST_NAME))
                {
                        continue;
                }

                $CachePath = $CacheDirectory . '/' . $CacheEntry;

                if (is_dir($CachePath))
                {
                        if (in_array($CacheEntry, $SubDirectories, TRUE) === FALSE)
                        {
                                RemoveCacheDirectory($CachePath);
                        }

                        continue;
                }

                if (preg_match('/^(.*)\.(thumb\.jpg|preview\.jpg|full\.jpg|poster\.jpg|mp4)$/', $CacheEntry, $Matches) === 1)
                {
                        if (isset($Items[$Matches[1]]) === FALSE)
                        {
                                @unlink($CachePath);
                        }
                }
                else if (strpos($CacheEntry, '.tmp') === 0)
                {
                        // Restes d'un traitement interrompu
                        if ((time() - filemtime($CachePath)) > 3600)
                        {
                                @unlink($CachePath);
                        }
                }
        }
}

function RemoveCacheDirectory($Path)
{
        $CacheRoot = realpath(GetMediaCacheDirectory());
        $Resolved = realpath($Path);

        if (($CacheRoot === FALSE) || ($Resolved === FALSE) || (strpos($Resolved, $CacheRoot . '/') !== 0))
        {
                return;
        }

        foreach (scandir($Resolved) as $Entry)
        {
                if (($Entry === '.') || ($Entry === '..'))
                {
                        continue;
                }

                if (is_dir($Resolved . '/' . $Entry) && (is_link($Resolved . '/' . $Entry) === FALSE))
                {
                        RemoveCacheDirectory($Resolved . '/' . $Entry);
                }
                else
                {
                        @unlink($Resolved . '/' . $Entry);
                }
        }

        @rmdir($Resolved);
}

function ProcessTree($AbsolutePath, $RelativePath, &$Statistics, $Depth = 0)
{
        if ($Depth > 32)
        {
                return;
        }

        $SubDirectories = ProcessAlbum($AbsolutePath, $RelativePath, $Statistics);
        $Statistics['albums']++;

        foreach ($SubDirectories as $SubDirectory)
        {
                ProcessTree($AbsolutePath . '/' . $SubDirectory, $RelativePath . '/' . $SubDirectory, $Statistics, $Depth + 1);
        }
}

function RunOnce()
{
        $Statistics = array('albums' => 0, 'processed' => 0, 'failed' => 0);
        $BaseDirectory = GetMediaBaseDirectory();

        foreach (GetGalleryRootNames() as $RootName)
        {
                $RootPath = $BaseDirectory . '/' . $RootName;

                if (is_dir($RootPath))
                {
                        ProcessTree($RootPath, $RootName, $Statistics);
                }
        }

        if (($Statistics['processed'] > 0) || ($Statistics['failed'] > 0))
        {
                LogLine($Statistics['processed'] . ' média(s) traité(s), ' . $Statistics['failed'] . ' en échec, ' . $Statistics['albums'] . ' album(s) parcouru(s).');
        }
        else
        {
                DebugLine('Rien de nouveau dans ' . $Statistics['albums'] . ' album(s).');
        }
}

EnsureDirectory(GetMediaCacheDirectory());
$LockHandle = fopen(GetMediaCacheDirectory() . '/.process-media.lock', 'c');

if (($LockHandle === FALSE) || (flock($LockHandle, LOCK_EX | LOCK_NB) === FALSE))
{
        LogLine('Un traitement est déjà en cours.');
        exit(0);
}

do
{
        try
        {
                RunOnce();
        }
        catch (Throwable $Error)
        {
                LogLine('Erreur : ' . $Error->getMessage());
        }


        if ($Interval > 0)
        {
                sleep($Interval);
        }
}
while ($Interval > 0);
