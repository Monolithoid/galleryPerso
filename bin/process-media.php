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
 * La liste des médias en erreur est réécrite à chaque passage dans
 * SITEIMAGEPERSO_ERROR_REPORT (/config/journal/medias-en-erreur.txt par
 * défaut), si son dossier existe.
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

// Version 2 : légendes EXIF « ASCII » et « UNICODE » mal décodées en version 1
// Version 3 : données binaires des appareils prises pour des légendes
define('PROCESSOR_VERSION', 3);
// Fiches plus anciennes dont seules les légendes sont à relire
define('PROCESSOR_CAPTION_FIX_VERSIONS', array(1, 2));

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

// Raison du dernier échec, reprise dans la liste des médias en erreur
$FailureReason = '';

function NoteFailure($Reason)
{
        global $FailureReason;

        $FailureReason = $Reason;
}

// Première ligne utile de la sortie d'erreur d'un outil, sans le chemin du fichier
function SummarizeToolError($Errors, $SourcePath)
{
        $Errors = str_replace($SourcePath, basename($SourcePath), (string) $Errors);

        foreach (preg_split('/\R/', $Errors) as $Line)
        {
                // « [mov,mp4,m4a @ 0x55d0c8e2] moov atom not found » : garde le message
                $Line = trim(preg_replace('/^\[[^\]]* @ 0x[0-9a-f]+\]\s*/', '', $Line));

                if ($Line !== '')
                {
                        return mb_strimwidth($Line, 0, 200, '…');
                }
        }

        return '';
}

function RunCommand($Arguments, &$Output = NULL, &$Errors = NULL)
{
        // Sans shell et en locale UTF-8 : sinon les noms accentués
        // (« Noël », « Vacances à la mer ») sont refusés ou abîmés
        $Command = implode(' ', $Arguments);
        $Descriptors = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
        // VIPS_CONCURRENCY=1 : une image à la fois, mémoire minimale
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

        $Errors = '';

        if ((RunCommand($Arguments, $Output, $Errors) !== 0) || (is_file($TemporaryPath) === FALSE))
        {
                @unlink($TemporaryPath);
                $Detail = SummarizeToolError($Errors, $SourcePath);
                NoteFailure('image illisible par vips' . (($Detail !== '') ? ' : ' . $Detail : ''));

                return FALSE;
        }

        rename($TemporaryPath, $DestinationPath);

        return TRUE;
}

// Ordre des octets d'un texte UTF-16 : 'UTF-16LE', 'UTF-16BE' ou NULL si ce n'en est pas
function DetectUtf16($Value)
{
        // Les octets nuls de fin ne sont que du remplissage
        $Core = rtrim($Value, "\0");

        if ($Core === '')
        {
                return NULL;
        }

        if ((strlen($Core) % 2) === 1)
        {
                $Core .= "\0";
        }

        $Pairs = strlen($Core) / 2;
        $EvenZeros = 0;
        $OddZeros = 0;

        for ($Index = 0; $Index < strlen($Core); $Index += 2)
        {
                $EvenZeros += ($Core[$Index] === "\0") ? 1 : 0;
                $OddZeros += ($Core[$Index + 1] === "\0") ? 1 : 0;
        }

        // Texte latin : un octet sur deux est nul, toujours du même côté
        if (($EvenZeros === 0) && ($OddZeros * 2 >= $Pairs))
        {
                return 'UTF-16LE';
        }

        if (($OddZeros === 0) && ($EvenZeros * 2 >= $Pairs))
        {
                return 'UTF-16BE';
        }

        return NULL;
}

function CleanMetadataText($Value)
{
        if (is_array($Value))
        {
                $Value = implode(' ', $Value);
        }

        $Value = (string) $Value;
        $Encoding = DetectUtf16($Value);

        // UserComment : préfixe de 8 octets indiquant le jeu de caractères,
        // à retirer avant de chercher de l'UTF-16 (sinon « ASCII\0\0\0 »
        // fait lire tout le texte comme de l'UTF-16 et donne des idéogrammes)
        if (preg_match('/^(ASCII\0\0\0|UNICODE\0|JIS\0\0\0\0\0|\0{8})/', $Value, $Matches) === 1)
        {
                $Value = substr($Value, 8);
                $Encoding = DetectUtf16($Value);

                if (($Matches[1] === "UNICODE\0") && ($Encoding === NULL) && (strlen(rtrim($Value, "\0")) > 1))
                {
                        $Encoding = 'UTF-16LE';
                }
        }

        // Windows XPComment / XPTitle, UserComment en UNICODE
        if ($Encoding !== NULL)
        {
                $Value = rtrim($Value, "\0");
                $Value = mb_convert_encoding($Value . ((strlen($Value) % 2) ? "\0" : ''), 'UTF-8', $Encoding);
        }

        // Octets nuls de remplissage en fin de champ
        $Value = rtrim($Value, "\0");

        if (preg_match('//u', $Value) !== 1)
        {
                $Value = mb_convert_encoding($Value, 'UTF-8', 'Windows-1252');
        }

        // Caractères de contrôle : données binaires de l'appareil (réglages,
        // MakerNote...) rangées dans le champ, pas une vraie légende
        if (preg_match('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}\x{FFFD}]/u', $Value) === 1)
        {
                return '';
        }

        $Value = trim($Value);

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

        // exif_read_data nomme XPComment « Comments » et XPTitle « Title »
        foreach (array('ImageDescription', 'Comments', 'XPComment', 'UserComment', 'Title', 'XPTitle') as $Key)
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

// Moins de fils = moins de mémoire : environ 300 Mo au maximum avec 2 fils pour une vidéo 1080p
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

function GetVideoCaption($Tags)
{
        foreach (array('description', 'comment', 'title') as $Key)
        {
                if (isset($Tags[$Key]) && (CleanMetadataText($Tags[$Key]) !== ''))
                {
                        return CleanMetadataText($Tags[$Key]);
                }
        }

        return '';
}

// Relit la légende d'un média déjà traité, sans refaire vignettes ni vidéos
function RefreshMediaCaption($SourcePath, $Item)
{
        if ($Item['kind'] === 'image')
        {
                $Metadata = ReadImageMetadata($SourcePath);
                $Item['caption'] = $Metadata['caption'];
        }
        else
        {
                $Probe = ProbeVideo($SourcePath);
                $Item['caption'] = ($Probe !== NULL) ? GetVideoCaption($Probe['tags']) : '';
        }

        return $Item;
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
                LogLine('Conversion de ' . $SourcePath . ' (' . $Probe['format'] . ', ' . $Probe['video_codec'] . ')');

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

        $Caption = GetVideoCaption($Probe['tags']);

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
        $RefreshCaptions = FALSE;
        $ManifestPath = $CacheDirectory . '/' . MEDIA_MANIFEST_NAME;

        if (is_file($ManifestPath))
        {
                $OldManifest = json_decode((string) file_get_contents($ManifestPath), TRUE);

                if (is_array($OldManifest) && isset($OldManifest['items'])
                        && isset($OldManifest['version'])
                        && (($OldManifest['version'] === PROCESSOR_VERSION) || in_array($OldManifest['version'], PROCESSOR_CAPTION_FIX_VERSIONS, TRUE)))
                {
                        $RefreshCaptions = ($OldManifest['version'] !== PROCESSOR_VERSION);

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

                        if ($RefreshCaptions === TRUE)
                        {
                                $Items[$Entry] = RefreshMediaCaption($EntryPath, $Items[$Entry]);
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
                NoteFailure('format non pris en charge');

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
                        LogLine('Échec du traitement de ' . $EntryPath . ' : ' . $FailureReason);
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

// Médias en erreur au passage précédent, gardés dans la liste tant que le passage en cours ne les a pas revus
$PreviousPassFailures = array();

function RecordFailure(&$Statistics, $RelativePath, $Entry, $Failure)
{
        global $PreviousPassFailures;

        $Path = $RelativePath . '/' . $Entry;
        $Statistics['failures'][$Path] = array(
                'path' => $Path,
                // Échecs enregistrés avant l'apparition de la liste : ni raison ni date
                'reason' => isset($Failure['reason']) ? $Failure['reason'] : 'fichier illisible',
                'date' => isset($Failure['date']) ? $Failure['date'] : NULL
        );

        // Le premier passage peut durer des heures (conversion des vidéos) : un nouvel échec
        // apparaît tout de suite dans la liste, sans attendre la fin du passage
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

// Liste lisible des médias en erreur ; un fichier corrigé ou supprimé en sort au passage suivant
function WriteErrorReport($Failures, $InProgress = FALSE)
{
        $ReportPath = GetErrorReportPath();

        if (is_dir(dirname($ReportPath)) === FALSE)
        {
                DebugLine('Pas de liste des médias en erreur : le dossier ' . dirname($ReportPath) . ' n\'existe pas.');

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

        // Réécrit seulement si la liste change
        if (is_file($ReportPath) && (file_get_contents($ReportPath) === $Content))
        {
                return;
        }

        if (@file_put_contents($ReportPath . '.tmp' . getmypid(), $Content) === FALSE)
        {
                LogLine('Impossible d\'écrire ' . $ReportPath . ' : vérifiez les droits du dossier.');

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

$ErrorReportPath = GetErrorReportPath();

if (is_dir(dirname($ErrorReportPath)) === FALSE)
{
        LogLine('Pas de liste des médias en erreur : le dossier ' . dirname($ErrorReportPath) . ' n\'existe pas (volume non monté ?).');
}
else
{
        LogLine('Liste des médias en erreur : ' . $ErrorReportPath);

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
                LogLine('Erreur : ' . $Error->getMessage());
        }


        if ($Interval > 0)
        {
                sleep($Interval);
        }
}
while ($Interval > 0);
