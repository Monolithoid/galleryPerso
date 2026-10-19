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

// Dimensions d'un média ramenées dans un carré de $BoxSize pixels
function FitMediaSize($Width, $Height, $BoxSize)
{
        $Width = max(1, (int) $Width);
        $Height = max(1, (int) $Height);

        if ($Width > $Height)
        {
                return array($BoxSize * 1.00, round($BoxSize * $Height / $Width));
        }

        return array(round($BoxSize * $Width / $Height), $BoxSize * 1.00);
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

/*
 * Grille de vignettes d'un album, à partir de la fiche album.json
 * préparée par bin/process-media.php.
 */
function DisplayDiaporama($PathDiaporama, $RowSize, $SmallSize, $MiddleSize)
{
        $ResolvedPath = ResolveGalleryDirectoryPath($PathDiaporama);

        if ($ResolvedPath === NULL)
        {
                return 'Pas d\'images';
        }

        $Legends = LoadAlbumLegends($ResolvedPath['absolute']);
        $Manifest = LoadAlbumManifest($ResolvedPath['relative']);
        $Rows = '';

        if ($Legends['intro'] !== '')
        {
                $Rows .= '<div class="album-intro">' . FormatMediaText($Legends['intro']) . '</div><br>';
        }

        if ($Manifest === NULL)
        {
                return $Rows . '<p>Cet album est en cours de pr&eacute;paration, revenez dans quelques minutes.</p>';
        }

        $Items = $Manifest['items'];

        if (count($Items) === 0)
        {
                return $Rows . '<p>Pas de photos ni de vid&eacute;os dans cet album.</p>';
        }

        $RowSize = max(1, (int) $RowSize);

        // Write the number of images
        if (count($Items) > ($RowSize * 2))
        {
                $Rows .= count($Items) . ' photos et vid&eacute;os<br>';
        }

        $Rows .= '<table><tbody><tr>';

        foreach ($Items as $Index => $Item)
        {
                list($ThumbWidth, $ThumbHeight) = FitMediaSize($Item['thumb_width'], $Item['thumb_height'], $SmallSize);
                $EscapedName = htmlspecialchars($Item['name'], ENT_QUOTES, 'UTF-8');
                $FullLink = $Item['original'];
                $FullLabel = 'Qualite photo';

                if ($Item['kind'] === 'video')
                {
                        $FullLink = $Item['video'];
                        $FullLabel = 'Video ' . FormatMediaDuration($Item['duration']);
                }
                else if (empty($Item['full']) === FALSE)
                {
                        $FullLink = $Item['full'];
                }

                $Caption = GetMediaCaption($Item, $Legends);

                $Rows .= '<td align=center width=' . $ThumbWidth . ' height=' . $ThumbHeight . '>';
                $Rows .= '<center>' . PHP_EOL;
                $Rows .= '<IMG name="img_' . $Index . '" class="media-' . $Item['kind'] . '" Src="' . htmlspecialchars($Item['thumb'], ENT_QUOTES, 'UTF-8') . '" Width="' . $ThumbWidth . '" Height="' . $ThumbHeight . '" alt="' . $EscapedName . '" loading="lazy" onClick="DisplaySnapshot(Medias[' . ($Index + 1) . ']);" >';
                $Rows .= '<br>' . htmlspecialchars(pathinfo($Item['name'], PATHINFO_FILENAME), ENT_QUOTES, 'UTF-8') . '<br>' . PHP_EOL;
                $Rows .= '<A Href="' . htmlspecialchars($FullLink, ENT_QUOTES, 'UTF-8') . '" Target="_blank"><small><I>(' . $FullLabel . ')</I></small></A>';
                $Rows .= '</center>';

                if ($Caption !== '')
                {
                        $Rows .= '<br>' . FormatMediaText($Caption);
                }

                $Rows .= '</td>';

                // Verify if the row is over
                if (((($Index + 1) % $RowSize) === 0) && (($Index + 1) < count($Items)))
                {
                        $Rows .= '</tr><tr>';
                }
        }

        $Rows .= '</tr></tbody></table>';

        return $Rows;
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

// Liste des médias de l'album pour la visionneuse (script/script.js)
function GetJSListOFImage($Path)
{
        $NbMedia = 0;
        $NbImage = 0;

        $Str = 'var View = new Array();'.PHP_EOL;
        $Str .= 'var Medias = new Array();'.PHP_EOL;
        $Str .= 'var NbImage = 0;'.PHP_EOL;
        $Str .= 'var NbMedia = 0;'.PHP_EOL;

        $ResolvedPath = ResolveGalleryDirectoryPath($Path);

        if ($ResolvedPath === NULL)
        {
                return $Str;
        }

        $Manifest = LoadAlbumManifest($ResolvedPath['relative']);

        if ($Manifest === NULL)
        {
                return $Str;
        }

        $Legends = LoadAlbumLegends($ResolvedPath['absolute']);
        $JsonOptions = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;

        foreach ($Manifest['items'] as $Item)
        {
                $NbMedia++;
                list($Width, $Height) = FitMediaSize($Item['width'], $Item['height'], K_MIDDLESIZE);

                if ($Item['kind'] === 'video')
                {
                        $Source = $Item['video'];
                        $Middle = $Item['preview'];
                        $Type = 'video/mp4';
                }
                else
                {
                        $NbImage++;
                        $Source = empty($Item['full']) ? $Item['original'] : $Item['full'];
                        $Middle = empty($Item['preview']) ? $Item['original'] : $Item['preview'];
                        $Type = $Item['mime'];
                }

                $Arguments = array(
                        $Item['name'],
                        $Source,
                        $Middle,
                        FormatMediaText(GetMediaCaption($Item, $Legends)),
                        $Width,
                        $Height,
                        $Type
                );

                $Str .= 'Medias[' . $NbMedia . '] = new media(' . implode(',', array_map(function ($Value) use ($JsonOptions)
                {
                        return json_encode($Value, $JsonOptions);
                }, $Arguments)) . ');' . PHP_EOL;
        }

        $Str .= 'NbImage='. $NbImage .';'.PHP_EOL;
        $Str .= 'NbMedia='. $NbMedia .';'.PHP_EOL;

        return $Str;
}
?>
