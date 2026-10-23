<?php
require_once __DIR__ . '/lib/media.php';
require_once __DIR__ . '/lib/likes.php';

function GetNotEmpty($Value, $DefaultValue)
{
	if (!empty($Value))
	{
		return($Value);
	}
	return($DefaultValue);
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

function FormatMediaDuration($Seconds)
{
        $Seconds = (int) round($Seconds);

        if ($Seconds >= 3600)
        {
                return sprintf('%d:%02d:%02d', $Seconds / 3600, ($Seconds / 60) % 60, $Seconds % 60);
        }

        return sprintf('%d:%02d', $Seconds / 60, $Seconds % 60);
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
        $Manifest = LoadAlbumManifest($ResolvedPath['relative']);
        $SubAlbums = ListSubAlbums($ResolvedPath['relative']);

        $Html = RenderBreadcrumb($Gallery, $Album);
        $Html .= '<h1 class="album-title">' . EscapeHtml($Title) . '</h1>';

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
                $Key = GetMediaLikeKey($ResolvedPath['relative'], $Item['name']);
                $Html .= RenderMediaThumb($Index, $Item, $Key);
                $ViewerItems[] = GetViewerItem($Item, $Key);
        }

        $Html .= '</ul>';

        return $Html . RenderViewerData($ViewerItems);
}

// Une vignette de la grille ; data-index renvoie au média dans RenderViewerData()
function RenderMediaThumb($Index, $Item, $Key)
{
        $Label = pathinfo($Item['name'], PATHINFO_FILENAME);

        $Html = '<li><button type="button" class="media-thumb media-thumb--' . EscapeHtml($Item['kind']) . '" data-index="' . $Index . '" data-media="' . EscapeHtml($Key) . '" title="' . EscapeHtml($Label) . '">';
        $Html .= '<img src="' . EscapeHtml($Item['thumb']) . '" width="' . (int) $Item['thumb_width'] . '" height="' . (int) $Item['thumb_height'] . '" alt="' . EscapeHtml($Label) . '" loading="lazy">';
        $Html .= RenderMediaTypeIcon($Item['kind']);

        if ($Item['kind'] === 'video')
        {
                $Html .= '<span class="media-thumb__badge">' . FormatMediaDuration($Item['duration']) . '</span>';
        }

        // À côté de la vignette et non dedans : un bouton ne peut pas en contenir un autre
        return $Html . '</button>' . RenderLikeBadge($Key) . '</li>';
}

/*
 * Cœur et nombre de « j'aime » dans le coin d'une vignette. Une fois
 * connecté, c'est un bouton (un clic aime, un second retire) ; sinon une
 * simple pastille, cachée s'il n'y a pas de « j'aime ».
 */
function RenderLikeBadge($Key)
{
        $Likes = LoadLikes();
        $Count = isset($Likes[$Key]) ? count($Likes[$Key]) : 0;
        $Mine = isset($Likes[$Key][GetConnectedEmail()]);
        $Content = '<span class="media-thumb__likes-heart" aria-hidden="true">' . ($Mine ? '♥' : '♡') . '</span>'
                . '<span class="media-thumb__likes-count">' . (($Count > 0) ? $Count : '') . '</span>';
        $Classes = 'media-thumb__likes' . ($Mine ? ' is-liked' : '');

        if (GetConnectedEmail() === '')
        {
                return '<span class="' . $Classes . '"' . (($Count === 0) ? ' hidden' : '') . '>' . $Content
                        . '<span class="visually-hidden"> j\'aime</span></span>';
        }

        return '<button type="button" class="' . $Classes . ' media-thumb__likes--button" data-media="' . EscapeHtml($Key) . '"'
                . ' aria-pressed="' . ($Mine ? 'true' : 'false') . '" aria-label="J\'aime">' . $Content . '</button>';
}

// Médias de la page pour la visionneuse (script/viewer.js)
function RenderViewerData($ViewerItems)
{
        $Html = '<script type="application/json" id="album-media"';

        // Sans connexion (hors Docker), pas de bouton « j'aime »
        if (GetConnectedEmail() !== '')
        {
                $Html .= ' data-likes="like.php"';
        }

        return $Html . '>'
                . json_encode($ViewerItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)
                . '</script>';
}

/*
 * Rubriques « j'aime » de l'accueil (voir BuildLikesSections) : une grille
 * par rubrique et une seule visionneuse pour toutes. Un média aimé apparaît
 * dans plusieurs rubriques ; la visionneuse le montre avec son album.
 */
function RenderLikesSections()
{
        $Email = GetConnectedEmail();

        if ($Email === '')
        {
                return '';
        }

        $Sections = BuildLikesSections($Email);
        $Html = '';
        $ViewerItems = array();

        if (count($Sections) === 0)
        {
                return '<section class="home-likes"><h2>Coups de cœur</h2>'
                        . '<p class="notice">Ouvrez une photo ou une vidéo et touchez ♡ pour la mettre en avant ici. Un second clic retire le « j\'aime ».</p></section>';
        }

        foreach ($Sections as $Section)
        {
                $Total = count($Section['media']);
                $Html .= '<section class="home-likes" id="' . EscapeHtml($Section['id']) . '"><h2>' . EscapeHtml($Section['title']) . '</h2>';

                if ($Total === 0)
                {
                        $Html .= '<p class="notice">Aucun « j\'aime » pour le moment.</p></section>';
                        continue;
                }

                $Html .= '<ul class="media-grid">';

                foreach (array_slice($Section['media'], 0, LIKES_HOME_LIMIT) as $Entry)
                {
                        $Index = count($ViewerItems);
                        $ViewerItem = GetViewerItem($Entry['item'], $Entry['key']);
                        $ViewerItem['ref'] = $Entry['key'];
                        $ViewerItem['album'] = basename($Entry['album']);
                        $ViewerItem['albumUrl'] = GetAlbumUrl('./' . explode('/', $Entry['album'])[0], './' . $Entry['album']);
                        $ViewerItems[] = $ViewerItem;
                        $Html .= RenderMediaThumb($Index, $Entry['item'], $Entry['key']);
                }

                $Html .= '</ul>';

                if ($Total > LIKES_HOME_LIMIT)
                {
                        $Html .= '<p class="home-likes__more">et ' . ($Total - LIKES_HOME_LIMIT) . ' autre' . (($Total - LIKES_HOME_LIMIT > 1) ? 's' : '') . '</p>';
                }

                $Html .= '</section>';
        }

        return $Html . RenderViewerData($ViewerItems);
}

// Pastille dans le coin d'une vignette : caméra pour une vidéo, photo pour une image
function RenderMediaTypeIcon($Kind)
{
        if ($Kind === 'video')
        {
                $Label = 'Vidéo';
                $Shape = '<rect x="2" y="6" width="14" height="12" rx="2"/><path d="M16 10.5 22 7v10l-6-3.5z"/>';
        }
        else
        {
                $Label = 'Photo';
                $Shape = '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9.5" r="1.8"/><path d="m21 16-5-5-9 9"/>';
        }

        return '<span class="media-thumb__type">'
                . '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">' . $Shape . '</svg>'
                . '<span class="visually-hidden">' . $Label . '</span></span>';
}

// Ce dont la visionneuse (script/viewer.js) a besoin pour un média
function GetViewerItem($Item, $Key)
{
        $Original = empty($Item['full']) ? $Item['original'] : $Item['full'];
        $Likes = LoadLikes();
        $Like = array(
                'key' => $Key,
                'likes' => isset($Likes[$Key]) ? count($Likes[$Key]) : 0,
                'liked' => isset($Likes[$Key][GetConnectedEmail()])
        );

        if ($Item['kind'] === 'video')
        {
                return $Like + array(
                        'kind' => 'video',
                        'name' => $Item['name'],
                        'src' => $Item['video'],
                        'poster' => $Item['preview'],
                        'original' => $Item['video'],
                        'width' => (int) $Item['width'],
                        'height' => (int) $Item['height'],
                        'taken' => $Item['taken']
                );
        }

        return $Like + array(
                'kind' => 'image',
                'name' => $Item['name'],
                'src' => empty($Item['preview']) ? $Item['original'] : $Item['preview'],
                'original' => $Original,
                'width' => (int) $Item['width'],
                'height' => (int) $Item['height'],
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

?>
