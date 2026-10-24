<?php
/*
 * « J'aime » sur les photos et vidéos. Chaque personne connectée (adresse
 * transmise par oauth2-proxy) aime un média d'un clic et retire son
 * « j'aime » d'un second clic.
 *
 * Tout tient dans un petit fichier JSON du dossier de configuration
 * (/config/likes/likes.json par défaut, SITEIMAGEPERSO_LIKES_FILE pour un
 * autre emplacement) :
 *   { "GalleryPhotos/2024/Plage/IMG_0001.jpg": { "a@exemple.fr": "2026-10-23T08:00:00+00:00" } }
 * La clé d'un média est le chemin de son album suivi de son nom de fichier.
 */

require_once __DIR__ . '/media.php';


function GetLikesFilePath()
{
        $Configured = getenv('SITEIMAGEPERSO_LIKES_FILE');

        if (($Configured !== FALSE) && ($Configured !== ''))
        {
                return $Configured;
        }

        return '/config/likes/likes.json';
}

// Adresse de la personne connectée, transmise par oauth2-proxy ; vide hors Docker
function GetConnectedEmail()
{
        return strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_EMAIL'] ?? '')));
}

function GetEmailsFilePath()
{
        $Configured = getenv('SITEIMAGEPERSO_EMAILS_FILE');

        if (($Configured !== FALSE) && ($Configured !== ''))
        {
                return $Configured;
        }

        return '/config/emails.txt';
}

/*
 * Prénoms de la liste des adresses autorisées (emails.txt, lu aussi par
 * oauth2-proxy, qui n'en garde que la première colonne) :
 *   adresse@gmail.com, Prénom
 * Renvoie array(adresse en minuscules => prénom).
 */
function LoadFirstNames()
{
        static $Names = NULL;

        if ($Names !== NULL)
        {
                return $Names;
        }

        $Names = array();
        $Lines = @file(GetEmailsFilePath(), FILE_IGNORE_NEW_LINES);

        foreach (($Lines === FALSE) ? array() : $Lines as $Line)
        {
                $Fields = explode(',', $Line);
                $Email = strtolower(trim($Fields[0]));

                if (($Email === '') || ($Email[0] === '#') || (count($Fields) < 2) || (trim($Fields[1]) === ''))
                {
                        continue;
                }

                $Names[$Email] = trim($Fields[1]);
        }

        return $Names;
}

// Nom affiché : le prénom de emails.txt, sinon la partie de l'adresse avant « @ »
function GetLikerLabel($Email)
{
        $Names = LoadFirstNames();

        if (isset($Names[strtolower($Email)]))
        {
                return $Names[strtolower($Email)];
        }

        $Parts = explode('@', $Email, 2);

        return $Parts[0];
}

function GetMediaLikeKey($AlbumPath, $Name)
{
        return GetMediaKey($AlbumPath, $Name);
}

function ParseLikesData($Json)
{
        $Data = json_decode((string) $Json, TRUE);

        if (is_array($Data) === FALSE)
        {
                return array();
        }

        $Likes = array();

        foreach ($Data as $Key => $Likers)
        {
                if (is_array($Likers) && (count($Likers) > 0))
                {
                        $Likes[(string) $Key] = $Likers;
                }
        }

        return $Likes;
}

// Lu une seule fois par requête ($Reload : relire le fichier, pour les tests)
function LoadLikes($Reload = FALSE)
{
        static $Likes = NULL;

        if (($Likes === NULL) || $Reload)
        {
                $FilePath = GetLikesFilePath();
                $Likes = is_file($FilePath) ? ParseLikesData(@file_get_contents($FilePath)) : array();
        }

        return $Likes;
}

/*
 * Ajoute ou retire le « j'aime » de $Email sur un média. Le fichier est
 * verrouillé le temps de la modification, puis remplacé d'un coup pour
 * qu'une lecture ne tombe jamais sur un fichier à moitié écrit.
 * Renvoie array('liked' => bool, 'count' => int), ou NULL si le fichier
 * ne peut pas être écrit.
 */
function ToggleMediaLike($Key, $Email)
{
        $FilePath = GetLikesFilePath();
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

        flock($Lock, LOCK_EX);
        $Likes = is_file($FilePath) ? ParseLikesData(@file_get_contents($FilePath)) : array();
        $Liked = isset($Likes[$Key][$Email]) === FALSE;

        if ($Liked)
        {
                $Likes[$Key][$Email] = date('c');
        }
        else
        {
                unset($Likes[$Key][$Email]);

                if (count($Likes[$Key]) === 0)
                {
                        unset($Likes[$Key]);
                }
        }

        $TemporaryPath = $FilePath . '.tmp';
        $Written = @file_put_contents($TemporaryPath, json_encode((object) $Likes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
        $Saved = ($Written !== FALSE) && @rename($TemporaryPath, $FilePath);

        flock($Lock, LOCK_UN);
        fclose($Lock);

        if ($Saved === FALSE)
        {
                return NULL;
        }

        return array('liked' => $Liked, 'count' => isset($Likes[$Key]) ? count($Likes[$Key]) : 0);
}

// Fiche album.json gardée en mémoire le temps de la requête
function LoadAlbumManifestCached($AlbumPath)
{
        static $Manifests = array();

        if (array_key_exists($AlbumPath, $Manifests) === FALSE)
        {
                $Manifests[$AlbumPath] = LoadAlbumManifest($AlbumPath);
        }

        return $Manifests[$AlbumPath];
}

/*
 * Retrouve le média d'une clé dans la fiche de son album. Renvoie NULL si
 * la clé n'est pas dans une galerie ou si le média n'existe plus.
 */
function FindLikedMedia($Key)
{
        $Key = (string) $Key;
        $Slash = strrpos($Key, '/');

        if (($Slash === FALSE) || (strpos($Key, '..') !== FALSE) || (strpos($Key, '\\') !== FALSE))
        {
                return NULL;
        }

        $AlbumPath = substr($Key, 0, $Slash);
        $Name = substr($Key, $Slash + 1);
        $Segments = explode('/', $AlbumPath);

        if (in_array($Segments[0], GetGalleryRootNames(), TRUE) === FALSE)
        {
                return NULL;
        }

        $Manifest = LoadAlbumManifestCached($AlbumPath);

        if ($Manifest === NULL)
        {
                return NULL;
        }

        foreach ($Manifest['items'] as $Item)
        {
                if ($Item['name'] === $Name)
                {
                        return array('album' => $AlbumPath, 'item' => $Item);
                }
        }

        return NULL;
}

/*
 * Rubriques de l'accueil : les médias aimés par tous ceux qui ont déjà
 * aimé quelque chose (dès qu'ils sont au moins deux), puis les coups de
 * cœur de chacun, la personne connectée en premier. Dans chaque rubrique,
 * les plus aimés d'abord, puis les plus récemment aimés.
 */
function BuildLikesSections($CurrentEmail)
{
        $Likes = LoadLikes();
        $Likers = array();
        $Media = array();

        foreach ($Likes as $Key => $LikedBy)
        {
                $Found = FindLikedMedia($Key);

                if ($Found === NULL)
                {
                        continue;
                }

                $Found['key'] = $Key;
                $Found['likers'] = $LikedBy;
                $Found['last'] = max($LikedBy);
                $Media[$Key] = $Found;

                foreach (array_keys($LikedBy) as $Email)
                {
                        $Likers[$Email] = TRUE;
                }
        }

        uasort($Media, function ($Left, $Right)
        {
                $ByCount = count($Right['likers']) - count($Left['likers']);

                return ($ByCount !== 0) ? $ByCount : strcmp($Right['last'], $Left['last']);
        });

        $Sections = array();
        $Everyone = array_keys($Likers);

        /*
         * Font l'unanimité : tous les médias qui ont le plus grand nombre de
         * « j'aime » (s'il est de 3, tous ceux qui en ont 3), quelles que
         * soient les personnes qui les ont aimés.
         */
        $Best = 0;

        foreach ($Media as $Entry)
        {
                $Best = max($Best, count($Entry['likers']));
        }

        if ($Best > 0)
        {
                $Top = array();

                foreach ($Media as $Entry)
                {
                        if (count($Entry['likers']) === $Best)
                        {
                                $Top[] = $Entry;
                        }
                }

                $Note = 'Les plus aimés : ' . $Best . ' « j\'aime »' . (($Best > 1) ? ' chacun.' : '.');
                $Sections[] = array('id' => 'unanimite', 'title' => 'Font l\'unanimité', 'media' => $Top, 'collapsed' => FALSE, 'note' => $Note);
        }

        usort($Everyone, function ($Left, $Right) use ($CurrentEmail)
        {
                if (($Left === $CurrentEmail) || ($Right === $CurrentEmail))
                {
                        return ($Left === $CurrentEmail) ? -1 : 1;
                }

                return strcmp($Left, $Right);
        });

        foreach ($Everyone as $Email)
        {
                $Favorites = array();

                foreach ($Media as $Entry)
                {
                        if (isset($Entry['likers'][$Email]))
                        {
                                $Favorites[] = $Entry;
                        }
                }

                $Title = ($Email === $CurrentEmail) ? 'Mes coups de cœur' : 'Les coups de cœur de ' . GetLikerLabel($Email);
                $Sections[] = array('id' => 'coups-de-coeur-' . md5($Email), 'title' => $Title, 'media' => $Favorites, 'collapsed' => TRUE);
        }

        return $Sections;
}
