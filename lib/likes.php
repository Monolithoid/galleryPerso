<?php
/*
 * Likes on photos and videos. Each logged-in person (address
 * passed on by oauth2-proxy) likes a media item with one click and removes
 * the like with a second click.
 *
 * Everything fits in a small JSON file in the configuration folder
 * (/config/likes/likes.json by default, SITEIMAGEPERSO_LIKES_FILE for
 * another location):
 *   { "GalleryPhotos/2024/Plage/IMG_0001.jpg": { "a@exemple.fr": "2026-10-23T08:00:00+00:00" } }
 * A media item's key is its album path followed by its file name.
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

// Address of the logged-in person, passed on by oauth2-proxy; empty outside Docker
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
 * First names from the list of allowed addresses (emails.txt, also read by
 * oauth2-proxy, which only keeps the first column):
 *   address@gmail.com, FirstName
 * Returns array(lower-case address => first name).
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

// Display name: the first name from emails.txt, otherwise the part of the address before "@"
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

// Read only once per request ($Reload: reread the file, for tests)
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
 * Adds or removes $Email's like on a media item. The file is
 * locked during the change, then replaced in one go so that
 * a reader never sees a half-written file.
 * Returns array('liked' => bool, 'count' => int), or NULL if the file
 * cannot be written.
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

// album.json record kept in memory for the duration of the request
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
 * Finds a key's media item in its album record. Returns NULL if
 * the key is not in a gallery or if the media item no longer exists.
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
 * Home page sections: the media liked by everyone who has already
 * liked something (as soon as there are at least two of them), then each
 * person's favourites, the logged-in person first. In each section,
 * the most liked first, then the most recently liked.
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
         * Unanimous favourites: every media item with the highest number of
         * likes (if it is 3, all those with 3), whoever
         * liked them.
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
