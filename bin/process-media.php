<?php
/*
 * Background media processing.
 *
 * Walks through the galleries, recognises the actual format of each file and
 * prepares in the cache everything the site needs:
 *   - <name>.thumb.jpg   thumbnail (EXIF orientation fixed)
 *   - <name>.preview.jpg preview for the viewer
 *   - <name>.full.jpg    full-size version of the formats that
 *                        browsers cannot display (HEIC, TIFF...)
 *   - <name>.mp4         converted video, only if the original cannot
 *                        be played by browsers
 *   - album.json         album record read by the site
 *
 * A photo rotated from the viewer (see lib/rotations.php) gets its
 * thumbnail, preview and full-size version rotated; the original stays
 * as is.
 *
 * The list of failed media is rewritten on every pass in
 * SITEIMAGEPERSO_ERROR_REPORT (/config/journal/medias-en-erreur.txt by
 * default), if its folder exists.
 *
 * Originals are never modified. A file that was already processed and
 * has not changed (size and date) is not processed again.
 *
 * Usage: php bin/process-media.php [--once] [--interval=60] [-v]
 */

if (PHP_SAPI !== 'cli')
{
        http_response_code(404);
        exit;
}

require_once __DIR__ . '/../lib/media.php';
require_once __DIR__ . '/../lib/rotations.php';

// Version 5: no more captions, the caption field is removed from the records
define('PROCESSOR_VERSION', 5);
// Older records, reused without reprocessing once cleaned up
define('PROCESSOR_COMPATIBLE_VERSIONS', array(1, 2, 3, 4));

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

// Reason for the last failure, shown in the list of failed media
$FailureReason = '';

function NoteFailure($Reason)
{
        global $FailureReason;

        $FailureReason = $Reason;
}

function RunCommand($Arguments, &$Output = NULL, &$Errors = NULL)
{
        $ExitCode = RunMediaCommand($Arguments, $Output, $Errors);

        if ($ExitCode !== 0)
        {
                DebugLine('Failed (' . $ExitCode . '): ' . implode(' ', $Arguments) . PHP_EOL . trim($Errors));
        }

        return $ExitCode;
}

function EnsureDirectory($Path)
{
        if ((is_dir($Path) === FALSE) && (@mkdir($Path, 0775, TRUE) === FALSE) && (is_dir($Path) === FALSE))
        {
                throw new RuntimeException('Unable to create ' . $Path);
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

// See RenderJpegVersion() in lib/media.php
function CreateJpegVersion($SourcePath, $DestinationPath, $Size, $Rotation = 0)
{
        $Failure = RenderJpegVersion($SourcePath, $DestinationPath, $Size, $Rotation);

        if ($Failure !== '')
        {
                DebugLine('Failed: ' . $Failure);
                NoteFailure($Failure);

                return FALSE;
        }

        return TRUE;
}

function ReadImageMetadata($SourcePath)
{
        $Metadata = array('taken' => NULL);

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

        return $Metadata;
}

function ProcessImage($SourcePath, $CacheDirectory, $AlbumCacheUrl, $Name, $Mime, $Rotation)
{
        $ThumbName = $Name . '.thumb.jpg';
        $PreviewName = $Name . '.preview.jpg';

        if ((CreateJpegVersion($SourcePath, $CacheDirectory . '/' . $ThumbName, MEDIA_THUMB_SIZE, $Rotation) === FALSE)
                || (CreateJpegVersion($SourcePath, $CacheDirectory . '/' . $PreviewName, MEDIA_PREVIEW_SIZE, $Rotation) === FALSE))
        {
                return NULL;
        }

        $Item = array(
                'kind' => 'image',
                'mime' => $Mime,
                'thumb' => $AlbumCacheUrl . '/' . rawurlencode($ThumbName),
                'preview' => $AlbumCacheUrl . '/' . rawurlencode($PreviewName),
                'full' => NULL,
                // Rotation applied to the cached files
                'rotation' => $Rotation
        );

        // Animated GIFs: the viewer shows the original to keep the animation
        if ($Mime === 'image/gif')
        {
                $Item['preview'] = NULL;
        }

        if (in_array($Mime, MEDIA_BROWSER_IMAGE_TYPES, TRUE) === FALSE)
        {
                $FullName = $Name . '.full.jpg';

                if (CreateJpegVersion($SourcePath, $CacheDirectory . '/' . $FullName, 0, $Rotation) === TRUE)
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
 * Videos
 */

function ProbeVideo($SourcePath)
{
        $Output = '';
        $Errors = '';

        if (RunCommand(array('ffprobe', '-v', 'error', '-threads', '1', '-print_format', 'json', '-show_format', '-show_streams', $SourcePath), $Output, $Errors) !== 0)
        {
                $Detail = SummarizeToolError($Errors, $SourcePath);
                NoteFailure('vidéo illisible par ffprobe' . (($Detail !== '') ? ' : ' . $Detail : ''));

                return NULL;
        }

        $Probe = json_decode($Output, TRUE);

        if (is_array($Probe) === FALSE)
        {
                NoteFailure('vidéo illisible par ffprobe');

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
                NoteFailure('aucune image vidéo dans le fichier');

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

// Playable as is by Chrome, Firefox and Safari?
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

// Fewer threads = less memory: about 300 MB at most with 2 threads for a 1080p video
function GetFfmpegThreads()
{
        $Configured = (int) getenv('SITEIMAGEPERSO_FFMPEG_THREADS');

        return (string) (($Configured > 0) ? $Configured : 2);
}

function TranscodeVideo($SourcePath, $DestinationPath, $HasAudio)
{
        $TemporaryPath = dirname($DestinationPath) . '/.tmp' . getmypid() . '-' . basename($DestinationPath);
        $Arguments = array(
                'nice', '-n', '15',
                'ffmpeg', '-nostdin', '-y', '-v', 'error', '-threads', GetFfmpegThreads(), '-i', $SourcePath,
                '-map', '0:v:0', '-map', '0:a:0?',
                '-vf', "scale='min(" . MEDIA_VIDEO_MAX_WIDTH . ",iw)':-2",
                '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-pix_fmt', 'yuv420p',
                '-threads', GetFfmpegThreads(), '-x264-params', 'rc-lookahead=10',
                '-movflags', '+faststart', '-f', 'mp4'
        );

        if ($HasAudio === TRUE)
        {
                $Arguments = array_merge($Arguments, array('-c:a', 'aac', '-b:a', '160k', '-ac', '2'));
        }

        $Arguments[] = $TemporaryPath;
        $Errors = '';

        if ((RunCommand($Arguments, $Output, $Errors) !== 0) || (is_file($TemporaryPath) === FALSE))
        {
                @unlink($TemporaryPath);
                $Detail = SummarizeToolError($Errors, $SourcePath);
                NoteFailure('conversion en MP4 impossible' . (($Detail !== '') ? ' : ' . $Detail : ''));

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

        if ((RunCommand(array('ffmpeg', '-nostdin', '-y', '-v', 'error', '-threads', GetFfmpegThreads(), '-ss', (string) $SeekSeconds, '-i', $SourcePath, '-frames:v', '1', '-q:v', '3', $PosterPath)) !== 0)
                && (RunCommand(array('ffmpeg', '-nostdin', '-y', '-v', 'error', '-threads', GetFfmpegThreads(), '-i', $SourcePath, '-frames:v', '1', '-q:v', '3', $PosterPath), $Output, $Errors) !== 0))
        {
                $Detail = SummarizeToolError($Errors, $SourcePath);
                NoteFailure('aucune image extraite de la vidéo' . (($Detail !== '') ? ' : ' . $Detail : ''));

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
                LogLine('Converting ' . $SourcePath . ' (' . $Probe['format'] . ', ' . $Probe['video_codec'] . ')');

                if (TranscodeVideo($SourcePath, $CacheDirectory . '/' . $TranscodedName, $Probe['audio_codec'] !== NULL) === FALSE)
                {
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
                'taken' => $Taken
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
                // Unreadable files, retried only if they change
                'failed' => (object) $Failed
        );

        WriteFileAtomically(
                $CacheDirectory . '/' . MEDIA_MANIFEST_NAME,
                json_encode($Manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
}

function ProcessAlbum($AbsolutePath, $RelativePath, &$Statistics)
{
        global $FailureReason;

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
                        && isset($OldManifest['version'])
                        && (($OldManifest['version'] === PROCESSOR_VERSION) || in_array($OldManifest['version'], PROCESSOR_COMPATIBLE_VERSIONS, TRUE)))
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

        // Reread for every album: a rotation requested in the meantime is taken into account
        $Rotations = ReadRotations();
        $FileInfo = finfo_open(FILEINFO_MIME_TYPE);
        $Items = array();
        $Failed = array();
        $SubDirectories = array();
        $Changed = (is_file($ManifestPath) === FALSE);

        foreach ($Entries as $Entry)
        {
                if (IsIgnoredMediaEntry($Entry))
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
                $Rotation = GetMediaRotation(GetMediaKey($RelativePath, $Entry), $Rotations);

                if (isset($Previous[$Entry])
                        && ($Previous[$Entry]['size'] === $Size)
                        && ($Previous[$Entry]['mtime'] === $ModifiedAt)
                        && (($Previous[$Entry]['kind'] === 'video') || (GetAppliedRotation($Previous[$Entry]) === $Rotation))
                        && is_file($CacheDirectory . '/' . $Entry . '.thumb.jpg'))
                {
                        $Items[$Entry] = $Previous[$Entry];

                        // Records older than version 5 still contain a caption
                        if (array_key_exists('caption', $Items[$Entry]))
                        {
                                unset($Items[$Entry]['caption']);
                                $Changed = TRUE;
                        }

                        continue;
                }

                if (isset($PreviousFailed[$Entry])
                        && ($PreviousFailed[$Entry]['size'] === $Size)
                        && ($PreviousFailed[$Entry]['mtime'] === $ModifiedAt))
                {
                        $Failed[$Entry] = $PreviousFailed[$Entry];
                        RecordFailure($Statistics, $RelativePath, $Entry, $Failed[$Entry]);
                        continue;
                }

                // Skip files that are still being copied
                if ((time() - $ModifiedAt) < 10)
                {
                        DebugLine('Still being copied, will retry later: ' . $EntryPath);
                        continue;
                }

                $Detection = DetectMediaKind($EntryPath, $FileInfo);

                if ($Detection['kind'] === NULL)
                {
                        continue;
                }

                EnsureDirectory($CacheDirectory);
                DebugLine('Processing ' . $EntryPath . ' (' . $Detection['mime'] . ')');
                NoteFailure('format non pris en charge');

                if ($Detection['kind'] === 'image')
                {
                        $Item = ProcessImage($EntryPath, $CacheDirectory, $AlbumCacheUrl, $Entry, $Detection['mime'], $Rotation);
                }
                else
                {
                        $Item = ProcessVideo($EntryPath, $CacheDirectory, $AlbumCacheUrl, $AlbumSourceUrl, $Entry, $Detection['mime']);
                }

                if ($Item === NULL)
                {
                        LogLine('Processing failed for ' . $EntryPath . ': ' . $FailureReason);
                        $Failed[$Entry] = array(
                                'size' => $Size,
                                'mtime' => $ModifiedAt,
                                'reason' => $FailureReason,
                                'date' => date('c')
                        );
                        RecordFailure($Statistics, $RelativePath, $Entry, $Failed[$Entry]);
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

                // The album appears on the site as processing goes
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

// Removes from the cache whatever belongs to files or folders that are gone
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
                        // Leftovers of an interrupted run
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

// Media that failed on the previous pass, kept in the list until the current pass has seen them again
$PreviousPassFailures = array();

function RecordFailure(&$Statistics, $RelativePath, $Entry, $Failure)
{
        global $PreviousPassFailures;

        $Path = $RelativePath . '/' . $Entry;
        $Statistics['failures'][$Path] = array(
                'path' => $Path,
                // Failures recorded before the list existed: no reason and no date
                'reason' => isset($Failure['reason']) ? $Failure['reason'] : 'fichier illisible',
                'date' => isset($Failure['date']) ? $Failure['date'] : NULL
        );

        // The first pass can take hours (video conversion): a new failure
        // shows up in the list immediately, without waiting for the end of the pass
        if (isset($PreviousPassFailures[$Path]) === FALSE)
        {
                WriteErrorReport(array_merge($PreviousPassFailures, $Statistics['failures']), TRUE);
        }
}

function GetErrorReportPath()
{
        $Configured = getenv('SITEIMAGEPERSO_ERROR_REPORT');

        if (($Configured === FALSE) || ($Configured === ''))
        {
                return '/config/journal/medias-en-erreur.txt';
        }

        return $Configured;
}

// Human-readable list of failed media; a fixed or deleted file drops out on the next pass
function WriteErrorReport($Failures, $InProgress = FALSE)
{
        $ReportPath = GetErrorReportPath();

        if (is_dir(dirname($ReportPath)) === FALSE)
        {
                DebugLine('No failed-media list: the folder ' . dirname($ReportPath) . ' does not exist.');

                return;
        }

        usort($Failures, function ($Left, $Right)
        {
                return strnatcasecmp($Left['path'], $Right['path']);
        });

        $Content = '# Médias que le traitement n\'a pas pu préparer (ils n\'apparaissent pas sur le site).' . PHP_EOL
                . '# Liste réécrite à chaque passage : un fichier remplacé, corrigé ou supprimé en sort tout seul.' . PHP_EOL
                . '# Date du premier échec | fichier | raison' . PHP_EOL;

        if ($InProgress === TRUE)
        {
                $Content .= '# Passage en cours : la liste sera complète à la fin du passage.' . PHP_EOL;
        }

        $Content .= PHP_EOL;

        if (count($Failures) === 0)
        {
                $Content .= ($InProgress === TRUE) ? 'Aucun média en erreur pour l\'instant.' . PHP_EOL : 'Aucun média en erreur.' . PHP_EOL;
        }

        foreach ($Failures as $Failure)
        {
                $Date = ($Failure['date'] !== NULL) ? date('Y-m-d H:i T', strtotime($Failure['date'])) : 'date inconnue';
                $Content .= $Date . ' | ' . $Failure['path'] . ' | ' . $Failure['reason'] . PHP_EOL;
        }

        // Only rewritten if the list changes
        if (is_file($ReportPath) && (file_get_contents($ReportPath) === $Content))
        {
                return;
        }

        if (@file_put_contents($ReportPath . '.tmp' . getmypid(), $Content) === FALSE)
        {
                LogLine('Unable to write ' . $ReportPath . ': check the folder permissions.');

                return;
        }

        rename($ReportPath . '.tmp' . getmypid(), $ReportPath);
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
        global $PreviousPassFailures;

        $Statistics = array('albums' => 0, 'processed' => 0, 'failed' => 0, 'failures' => array());
        $BaseDirectory = GetMediaBaseDirectory();

        foreach (GetGalleryRootNames() as $RootName)
        {
                $RootPath = $BaseDirectory . '/' . $RootName;

                if (is_dir($RootPath))
                {
                        ProcessTree($RootPath, $RootName, $Statistics);
                }
        }

        WriteErrorReport($Statistics['failures']);
        $PreviousPassFailures = $Statistics['failures'];

        if (($Statistics['processed'] > 0) || ($Statistics['failed'] > 0))
        {
                LogLine($Statistics['processed'] . ' media processed, ' . $Statistics['failed'] . ' failed, ' . $Statistics['albums'] . ' album(s) scanned.');
        }
        else
        {
                DebugLine('Nothing new in ' . $Statistics['albums'] . ' album(s).');
        }
}

EnsureDirectory(GetMediaCacheDirectory());
$LockHandle = fopen(GetMediaCacheDirectory() . '/.process-media.lock', 'c');

if (($LockHandle === FALSE) || (flock($LockHandle, LOCK_EX | LOCK_NB) === FALSE))
{
        LogLine('A processing run is already in progress.');
        exit(0);
}

$ErrorReportPath = GetErrorReportPath();

if (is_dir(dirname($ErrorReportPath)) === FALSE)
{
        LogLine('No failed-media list: the folder ' . dirname($ErrorReportPath) . ' does not exist (volume not mounted?).');
}
else
{
        LogLine('Failed-media list: ' . $ErrorReportPath);

        if (is_file($ErrorReportPath) === FALSE)
        {
                WriteErrorReport(array(), TRUE);
        }
}

do
{
        try
        {
                RunOnce();
        }
        catch (Throwable $Error)
        {
                LogLine('Error: ' . $Error->getMessage());
        }


        if ($Interval > 0)
        {
                sleep($Interval);
        }
}
while ($Interval > 0);
