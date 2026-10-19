<?php
require_once __DIR__ . '/lib/media.php';

function PsExec($commandJob, $pidfile = NULL)
{ 

        $command = $commandJob.' > /dev/null 2>&1 & echo $!'; 
		
        exec($command ,$op); 
        $pid = $op[0];
                
		if ($pidfile != NULL)
		{
			$handle = fopen($pidfile,'w');
			fwrite( $handle, $pid);
			fclose($handle);
		}

		return $pid; 
//        if($pid!="") return $pid; 
//
//        return false; 
} 

function PsExists($pid)
{
	$check_pid = shell_exec('ps h -o pid '.$pid);

	if ((int)$check_pid == (int)$pid)
		return true;

	return false; 
} 

function PsExistsFromFile($pidfile)
{ 
	$pid = shell_exec('cat '. $pidfile);
	
	if (!empty($pid))
	{
		$check_pid = shell_exec('ps h -o pid '.$pid);

		if ((int)$check_pid == (int)$pid)
			return true;
	}
	
	return false; 
}

function PsKill($pid, $signal)
{ 
    exec("kill -" . $signal . " " . $pid, $output); 
}

function PsKillExistsFromFile($pidfile)
{
	$pid = shell_exec('cat '. $pidfile);
	
	if (!empty($pid))
	{
		if (PsExists($pid)) PsKill($pid, '9');
	}
}

function Puissance($x,$p)
{
	while ($p>1)
	{
		$p--;
		$x *= $x;
	}

	return $x;
}

function GetNotEmpty($Value, $DefaultValue)
{
	if (!empty($Value))
	{
		return($Value);
	}
	return($DefaultValue);
}

function ExchangeValue(&$Value1, &$Value2)
{
        $tmp = $Value1;
        $Value1 = $Value2;
        $Value2 = $tmp;
}

function GetGalleryBaseDirectory()
{
        static $BaseDirectory = NULL;

        if ($BaseDirectory === NULL)
        {
                $ResolvedBase = realpath(__DIR__);

                if ($ResolvedBase === FALSE)
                {
                        $BaseDirectory = NULL;
                }
                else
                {
                        $BaseDirectory = rtrim($ResolvedBase, DIRECTORY_SEPARATOR);
                }
        }

        return $BaseDirectory;
}

function NormalizeGalleryInputPath($RequestedPath)
{
        if (is_string($RequestedPath) === FALSE)
        {
                return NULL;
        }

        $TrimmedValue = trim($RequestedPath);

        if ($TrimmedValue === '')
        {
                return NULL;
        }

        $NormalizedValue = str_replace('\\', '/', $TrimmedValue);

        if (strpos($NormalizedValue, './') === 0)
        {
                $NormalizedValue = substr($NormalizedValue, 2);
        }

        $NormalizedValue = ltrim($NormalizedValue, '/');

        if ($NormalizedValue === '')
        {
                return NULL;
        }

        return $NormalizedValue;
}

function ResolveGalleryDirectoryPath($RequestedPath)
{
        $BaseDirectory = GetGalleryBaseDirectory();

        if ($BaseDirectory === NULL)
        {
                return NULL;
        }

        $NormalizedValue = NormalizeGalleryInputPath($RequestedPath);

        if ($NormalizedValue === NULL)
        {
                return NULL;
        }

        $CandidatePath = $BaseDirectory . DIRECTORY_SEPARATOR . $NormalizedValue;

        $ResolvedPath = realpath($CandidatePath);

        if ($ResolvedPath === FALSE)
        {
                return NULL;
        }

        if (is_dir($ResolvedPath) === FALSE)
        {
                return NULL;
        }

        $BaseWithSeparator = $BaseDirectory . DIRECTORY_SEPARATOR;
        $BaseLength = strlen($BaseWithSeparator);

        if (strncmp($ResolvedPath, $BaseWithSeparator, $BaseLength) !== 0)
        {
                return NULL;
        }

        $RelativePath = substr($ResolvedPath, $BaseLength);

        if ($RelativePath === FALSE)
        {
                return NULL;
        }

        if ($RelativePath === '')
        {
                        return NULL;
        }

        $RelativePath = str_replace(DIRECTORY_SEPARATOR, '/', $RelativePath);

        return array(
                'absolute' => $ResolvedPath,
                'relative' => './' . $RelativePath
        );
}

function remove_fileext($str) {
        list($noext) = explode('.', $str);

        return $noext ;
}

function FormatMediaDuration($Seconds)
{
        $Seconds = (int) round($Seconds);

        if ($Seconds >= 3600)
        {
                return sprintf('%d:%02d:%02d', $Seconds / 3600, ($Seconds / 60) % 60, $Seconds % 60);
        }

        return sprintf('%d:%02d', $Seconds / 60, $Seconds % 60);
}

function FormatMediaText($Text)
{
        return nl2br(htmlspecialchars($Text, ENT_QUOTES, 'UTF-8'));
}

function EscapeHtml($Text)
{
        return htmlspecialchars((string) $Text, ENT_QUOTES, 'UTF-8');
}

/*
 * Valide la galerie et l'album demandés (paramètres Gallery et Diaporama).
 * L'album doit se trouver dans la galerie. Renvoie les chemins relatifs
 * nettoyés ("./GalleryPhotos/2024") et un indicateur de requête invalide.
 */
function ResolveGallerySelection($GalleryRequest, $DiaporamaRequest)
{
        $Selection = array('gallery' => '', 'album' => '', 'invalid' => FALSE);
        $GalleryInfo = ResolveGalleryDirectoryPath($GalleryRequest);
        $DiaporamaInfo = ResolveGalleryDirectoryPath($DiaporamaRequest);

        if ((($GalleryRequest !== NULL) && ($GalleryRequest !== '') && ($GalleryInfo === NULL))
                || (($DiaporamaRequest !== NULL) && ($DiaporamaRequest !== '') && ($DiaporamaInfo === NULL)))
        {
                $Selection['invalid'] = TRUE;

                return $Selection;
        }

        if (($GalleryInfo !== NULL) && ($DiaporamaInfo !== NULL))
        {
                $GalleryAbsolutePrefix = $GalleryInfo['absolute'] . DIRECTORY_SEPARATOR;

                if (($DiaporamaInfo['absolute'] !== $GalleryInfo['absolute'])
                        && (strncmp($DiaporamaInfo['absolute'], $GalleryAbsolutePrefix, strlen($GalleryAbsolutePrefix)) !== 0))
                {
                        $Selection['invalid'] = TRUE;

                        return $Selection;
                }
        }

        if ($GalleryInfo !== NULL)
        {
                $Selection['gallery'] = $GalleryInfo['relative'];
        }

        if ($DiaporamaInfo !== NULL)
        {
                $Selection['album'] = $DiaporamaInfo['relative'];
        }

        return $Selection;
}

function GetAlbumUrl($Gallery, $Album = '')
{
        $Parameters = array('Gallery' => $Gallery);

        if (($Album !== '') && ($Album !== $Gallery))
        {
                $Parameters['Diaporama'] = $Album;
        }

        return 'index.php?' . http_build_query($Parameters);
}

// Sous-dossiers d'un album, triés par nom (les plus récents d'abord pour les années)
function ListSubAlbums($RelativePath)
{
        $ResolvedPath = ResolveGalleryDirectoryPath($RelativePath);

        if ($ResolvedPath === NULL)
        {
                return array();
        }

        $SubAlbums = array();

        foreach (BuildGalleryTreeFromAbsolutePath($ResolvedPath['absolute'], $ResolvedPath['relative'], 0, 0) as $Node)
        {
                $SubAlbums[] = array('label' => $Node['label'], 'path' => $Node['path']);
        }

        usort($SubAlbums, function ($Left, $Right)
        {
                $LeftIsYear = preg_match('/^\d{4}/', $Left['label']) === 1;
                $RightIsYear = preg_match('/^\d{4}/', $Right['label']) === 1;

                if ($LeftIsYear && $RightIsYear)
                {
                        return strnatcasecmp($Right['label'], $Left['label']);
                }

                return strnatcasecmp($Left['label'], $Right['label']);
        });

        return $SubAlbums;
}

// Vignette de couverture : premier média de l'album, ou d'un de ses sous-albums
function FindAlbumCover($RelativePath, $Depth = 0)
{
        $Manifest = LoadAlbumManifest($RelativePath);

        if (($Manifest !== NULL) && (count($Manifest['items']) > 0))
        {
                foreach ($Manifest['items'] as $Item)
                {
                        if ($Item['kind'] === 'image')
                        {
                                return $Item['thumb'];
                        }
                }

                return $Manifest['items'][0]['thumb'];
        }

        if ($Depth >= 3)
        {
                return NULL;
        }

        foreach (ListSubAlbums($RelativePath) as $SubAlbum)
        {
                $Cover = FindAlbumCover($SubAlbum['path'], $Depth + 1);

                if ($Cover !== NULL)
                {
                        return $Cover;
                }
        }

        return NULL;
}

function RenderAlbumCards($Gallery, $Albums)
{
        $Html = '<ul class="album-cards">';

        foreach ($Albums as $Album)
        {
                $Cover = FindAlbumCover($Album['path']);
                $Manifest = LoadAlbumManifest($Album['path']);
                $Count = ($Manifest !== NULL) ? count($Manifest['items']) : 0;

                $AlbumGallery = isset($Album['gallery']) ? $Album['gallery'] : $Gallery;
                $Html .= '<li><a class="album-card" href="' . EscapeHtml(GetAlbumUrl($AlbumGallery, $Album['path'])) . '">';

                if ($Cover !== NULL)
                {
                        $Html .= '<img src="' . EscapeHtml($Cover) . '" alt="" loading="lazy">';
                }
                else
                {
                        $Html .= '<span class="album-card__empty" aria-hidden="true"></span>';
                }

                $Html .= '<span class="album-card__title">' . EscapeHtml($Album['label']) . '</span>';

                if ($Count > 0)
                {
                        $Html .= '<span class="album-card__count">' . $Count . ' ' . (($Count > 1) ? 'éléments' : 'élément') . '</span>';
                }

                $Html .= '</a></li>';
        }

        return $Html . '</ul>';
}

function RenderBreadcrumb($Gallery, $Album)
{
        $GalleryPath = NormalizeAlbumRelativePath($Gallery);
        $AlbumPath = NormalizeAlbumRelativePath(($Album !== '') ? $Album : $Gallery);
        $Html = '<nav class="breadcrumb" aria-label="Fil d\'Ariane"><a href="index.php">Accueil</a>';
        $Html .= '<a href="' . EscapeHtml(GetAlbumUrl($Gallery)) . '">' . EscapeHtml(GetGalleryLabel(basename($GalleryPath))) . '</a>';
        $Current = $GalleryPath;
        $Rest = trim(substr($AlbumPath, strlen($GalleryPath)), '/');

        if ($Rest !== '')
        {
                foreach (explode('/', $Rest) as $Segment)
                {
                        $Current .= '/' . $Segment;
                        $Html .= '<a href="' . EscapeHtml(GetAlbumUrl($Gallery, './' . $Current)) . '">' . EscapeHtml($Segment) . '</a>';
                }
        }

        return $Html . '</nav>';
}

/*
 * Page d'un album : fil d'Ariane, introduction, sous-albums, puis grille
 * des photos et vidéos lue dans la fiche album.json du cache.
 */
function RenderAlbum($Gallery, $Album)
{
        $AlbumPath = ($Album !== '') ? $Album : $Gallery;
        $ResolvedPath = ResolveGalleryDirectoryPath($AlbumPath);

        if ($ResolvedPath === NULL)
        {
                return '<p class="notice">Pas d\'images.</p>';
        }

        $Title = ($AlbumPath === $Gallery) ? GetGalleryLabel(basename($ResolvedPath['absolute'])) : basename($ResolvedPath['absolute']);
        $Legends = LoadAlbumLegends($ResolvedPath['absolute']);
        $Manifest = LoadAlbumManifest($ResolvedPath['relative']);
        $SubAlbums = ListSubAlbums($ResolvedPath['relative']);

        $Html = RenderBreadcrumb($Gallery, $Album);
        $Html .= '<h1 class="album-title">' . EscapeHtml($Title) . '</h1>';

        if ($Legends['intro'] !== '')
        {
                $Html .= '<p class="album-intro">' . FormatMediaText($Legends['intro']) . '</p>';
        }

        if (count($SubAlbums) > 0)
        {
                $Html .= RenderAlbumCards($Gallery, $SubAlbums);
        }

        if ($Manifest === NULL)
        {
                return $Html . '<p class="notice">Cet album est en cours de préparation, revenez dans quelques minutes.</p>';
        }

        $Items = $Manifest['items'];

        if (count($Items) === 0)
        {
                if (count($SubAlbums) === 0)
                {
                        $Html .= '<p class="notice">Pas de photos ni de vidéos dans cet album.</p>';
                }

                return $Html;
        }

        $Html .= '<p class="album-count">' . count($Items) . ' ' . ((count($Items) > 1) ? 'photos et vidéos' : 'élément') . '</p>';
        $Html .= '<ul class="media-grid">';
        $ViewerItems = array();

        foreach ($Items as $Index => $Item)
        {
                $Caption = GetMediaCaption($Item, $Legends);
                $Label = ($Caption !== '') ? $Caption : pathinfo($Item['name'], PATHINFO_FILENAME);

                $Html .= '<li><button type="button" class="media-thumb media-thumb--' . EscapeHtml($Item['kind']) . '" data-index="' . $Index . '" title="' . EscapeHtml($Label) . '">';
                $Html .= '<img src="' . EscapeHtml($Item['thumb']) . '" width="' . (int) $Item['thumb_width'] . '" height="' . (int) $Item['thumb_height'] . '" alt="' . EscapeHtml($Label) . '" loading="lazy">';

                if ($Item['kind'] === 'video')
                {
                        $Html .= '<span class="media-thumb__badge">▶ ' . FormatMediaDuration($Item['duration']) . '</span>';
                }

                $Html .= '</button>';

                if ($Caption !== '')
                {
                        $Html .= '<p class="media-caption">' . EscapeHtml($Caption) . '</p>';
                }

                $Html .= '</li>';
                $ViewerItems[] = GetViewerItem($Item, $Caption);
        }

        $Html .= '</ul>';
        $Html .= '<script type="application/json" id="album-media">'
                . json_encode($ViewerItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)
                . '</script>';

        return $Html;
}

// Ce dont la visionneuse (script/viewer.js) a besoin pour un média
function GetViewerItem($Item, $Caption)
{
        $Original = empty($Item['full']) ? $Item['original'] : $Item['full'];

        if ($Item['kind'] === 'video')
        {
                return array(
                        'kind' => 'video',
                        'name' => $Item['name'],
                        'src' => $Item['video'],
                        'poster' => $Item['preview'],
                        'original' => $Item['video'],
                        'width' => (int) $Item['width'],
                        'height' => (int) $Item['height'],
                        'caption' => $Caption,
                        'taken' => $Item['taken']
                );
        }

        return array(
                'kind' => 'image',
                'name' => $Item['name'],
                'src' => empty($Item['preview']) ? $Item['original'] : $Item['preview'],
                'original' => $Original,
                'width' => (int) $Item['width'],
                'height' => (int) $Item['height'],
                'caption' => $Caption,
                'taken' => $Item['taken']
        );
}

function BuildGalleryTree($Path)
{
        $ResolvedPath = ResolveGalleryDirectoryPath($Path);

        if ($ResolvedPath === NULL)
        {
                return array();
        }

        // La navigation suit l'arborescence des dossiers, à toutes les profondeurs
        $MaximumDepth = 32;

        return BuildGalleryTreeFromAbsolutePath($ResolvedPath['absolute'], $ResolvedPath['relative'], 0, $MaximumDepth);
}

function BuildGalleryTreeFromAbsolutePath($AbsolutePath, $RelativePath, $CurrentDepth, $MaxDepth)
{
        $DirectoryEntries = scandir($AbsolutePath);

        if ($DirectoryEntries === FALSE)
        {
                return array();
        }

        $Tree = array();

        foreach ($DirectoryEntries as $Entry)
        {
                if (($Entry === '.')
                        || ($Entry === '..')
                        || IsIgnoredMediaEntry($Entry))
                {
                        continue;
                }

                $ChildAbsolutePath = $AbsolutePath . DIRECTORY_SEPARATOR . $Entry;

                if (is_dir($ChildAbsolutePath) === FALSE)
                {
                        continue;
                }

                $ChildRelativePath = $RelativePath . '/' . $Entry;
                $ChildTree = array();

                if ($CurrentDepth < $MaxDepth)
                {
                        $ChildTree = BuildGalleryTreeFromAbsolutePath($ChildAbsolutePath, $ChildRelativePath, $CurrentDepth + 1, $MaxDepth);
                }

                $Tree[] = array(
                        'label' => $Entry,
                        'path' => str_replace(DIRECTORY_SEPARATOR, '/', $ChildRelativePath),
                        'children' => $ChildTree
                );
        }

        if (count($Tree) > 1)
        {
                usort($Tree, 'CompareGalleryTreeNodes');
        }

        return $Tree;
}

function CompareGalleryTreeNodes($LeftNode, $RightNode)
{
        $LeftLabel = '';
        $RightLabel = '';

        if (is_array($LeftNode)
                && array_key_exists('label', $LeftNode))
        {
                $LeftLabel = $LeftNode['label'];
        }

        if (is_array($RightNode)
                && array_key_exists('label', $RightNode))
        {
                $RightLabel = $RightNode['label'];
        }

        return strcasecmp($LeftLabel, $RightLabel);
}

function AddOptionGallery($Path, &$NbLine, $CurrentDiaporama = '')
{
        $ResolvedPath = ResolveGalleryDirectoryPath($Path);

        if ($ResolvedPath === NULL)
        {
                return '';
        }

        $AbsolutePath = $ResolvedPath['absolute'];
        $RelativePath = $ResolvedPath['relative'];
        $ListeDir = scandir($AbsolutePath, 1);

        if ($ListeDir === FALSE)
        {
                return '';
        }

        $Str = '';

        foreach ($ListeDir as $file)
        {
                if (($file == "..")
                        || ($file == ".")
                        || IsIgnoredMediaEntry($file))
                {
                        continue;
                }

                $CandidateRelative = $RelativePath . '/' . $file;
                $CandidatePath = ResolveGalleryDirectoryPath($CandidateRelative);

                if ($CandidatePath === NULL)
                {
                        continue;
                }

                $NbLine++;
                $ChildRelativePath = $CandidatePath['relative'];
                $EscapedFileName = htmlspecialchars($file, ENT_QUOTES, 'UTF-8');

                if ($ChildRelativePath === $CurrentDiaporama)
                {
                        $Str = $Str . '<div class="optioncurrent" ><a href="javascript:document.forms[\'form_Gallery\'].Diaporama.value=\''. $ChildRelativePath .'\';document.forms[\'form_Gallery\'].submit();">' . $EscapedFileName . '</a></div>' . PHP_EOL;
                }
                else
                {
                        $Str = $Str . '<div class="option"  onmouseOver="javascript:this.className=\'optionhighlight\';" onmouseOut="javascript:this.className=\'option\';"><a href="javascript:document.forms[\'form_Gallery\'].Diaporama.value=\''. $ChildRelativePath .'\';document.forms[\'form_Gallery\'].submit();">' . $EscapedFileName . '</a></div>' . PHP_EOL;
                }
        }

        return $Str;
}

function AddOptGroupGallery($Path, &$NbLine, $CurrentDiaporama = '')
{
        $ResolvedPath = ResolveGalleryDirectoryPath($Path);

        if ($ResolvedPath === NULL)
        {
                return '';
        }

        $AbsolutePath = $ResolvedPath['absolute'];
        $RelativePath = $ResolvedPath['relative'];
        $ListeDir = scandir($AbsolutePath, 1);

        if ($ListeDir === FALSE)
        {
                return '';
        }

        $Str = '';

        foreach ($ListeDir as $file)
        {
                if (($file == "..")
                        || ($file == ".")
                        || IsIgnoredMediaEntry($file))
                {
                        continue;
                }

                $CandidateRelative = $RelativePath . '/' . $file;
                $CandidatePath = ResolveGalleryDirectoryPath($CandidateRelative);

                if ($CandidatePath === NULL)
                {
                        continue;
                }

                $NbLine++;
                $EscapedFileName = htmlspecialchars($file, ENT_QUOTES, 'UTF-8');
                $Str = $Str . '<div class="optgroup" >' . $EscapedFileName . '</div>' ;
                $Str = $Str . AddOptionGallery($CandidatePath['relative'], $NbLine, $CurrentDiaporama);
        }

        return $Str;
}

?>
