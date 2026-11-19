<?php
/*
 * Likes: toggling liked / not liked, home page sections
 * (unanimous favourites, each person's favourites) and missing media ignored.
 * Run: php tests/LikesTest.php
 */

function assertSameValue($expected, $actual, $message)
{
        if ($expected !== $actual)
        {
                throw new RuntimeException(
                        $message . ' (expected: ' . var_export($expected, TRUE) . ', actual: ' . var_export($actual, TRUE) . ')'
                );
        }
}

$WorkDirectory = sys_get_temp_dir() . '/galerie-likes-' . getmypid();
$CacheDirectory = $WorkDirectory . '/cache';
$AlbumCache = $CacheDirectory . '/GalleryPhotos/Plage';
mkdir($AlbumCache, 0777, TRUE);

putenv('SITEIMAGEPERSO_CACHE_DIR=' . $CacheDirectory);
putenv('SITEIMAGEPERSO_LIKES_FILE=' . $WorkDirectory . '/likes/likes.json');
putenv('SITEIMAGEPERSO_EMAILS_FILE=' . $WorkDirectory . '/emails.txt');
file_put_contents($WorkDirectory . '/emails.txt', "# Liste\nLea@Exemple.fr, Léa\ntom@exemple.fr\n");

$Item = function ($Name)
{
        return array('name' => $Name, 'kind' => 'image', 'thumb' => 'cache/' . $Name, 'thumb_width' => 400, 'thumb_height' => 300,
                'preview' => '', 'original' => $Name, 'full' => '', 'width' => 800, 'height' => 600, 'taken' => NULL);
};
file_put_contents($AlbumCache . '/album.json', json_encode(array('items' => array($Item('a.jpg'), $Item('c.jpg')))));

require __DIR__ . '/../lib/likes.php';

function FindSection($Sections, $Id)
{
        foreach ($Sections as $Section)
        {
                if ($Section['id'] === $Id)
                {
                        return $Section;
                }
        }

        return NULL;
}

$exitCode = 0;

try
{
        $A = 'GalleryPhotos/Plage/a.jpg';
        $B = 'GalleryPhotos/Plage/b.jpg';
        $C = 'GalleryPhotos/Plage/c.jpg';

        assertSameValue(NULL, FindLikedMedia($B), 'A media item missing from the record is refused');
        assertSameValue(NULL, FindLikedMedia('lib/../GalleryPhotos/Plage/a.jpg'), 'A path with ".." is refused');
        assertSameValue(NULL, FindLikedMedia('cache/Plage/a.jpg'), 'A path outside the galleries is refused');
        assertSameValue('a.jpg', FindLikedMedia($A)['item']['name'], 'A media item from the record is found');

        assertSameValue(array('liked' => TRUE, 'count' => 1), ToggleMediaLike($A, 'lea@exemple.fr'), 'First click: liked');
        assertSameValue(array('liked' => FALSE, 'count' => 0), ToggleMediaLike($A, 'lea@exemple.fr'), 'Second click: no longer liked');
        assertSameValue('{}', trim(file_get_contents(GetLikesFilePath())), 'A media item without likes leaves the file');

        ToggleMediaLike($A, 'lea@exemple.fr');
        ToggleMediaLike($A, 'tom@exemple.fr');
        ToggleMediaLike($B, 'tom@exemple.fr');
        ToggleMediaLike($C, 'lea@exemple.fr');
        // b.jpg is not (no longer) in the record: deleted media, ignored when rendering
        ToggleMediaLike($B, 'lea@exemple.fr');

        $Sections = BuildLikesSections('tom@exemple.fr');
        $Summary = array();

        foreach ($Sections as $Section)
        {
                $Summary[$Section['title']] = array_column($Section['media'], 'key');
        }

        assertSameValue(array(
                'Font l\'unanimité' => array($A),
                'Mes coups de cœur' => array($A),
                'Les coups de cœur de Léa' => array($A, $C)
        ), $Summary, 'Home page sections');

        // The highest number of likes is 2 (a.jpg); zoe likes c.jpg,
        // which then has 2 as well: both are shown, even though they are not
        // the same people
        ToggleMediaLike($C, 'zoe@exemple.fr');
        LoadLikes(TRUE);
        $Top = FindSection(BuildLikesSections('tom@exemple.fr'), 'unanimite');
        assertSameValue(array($A, $C), array_column($Top['media'], 'key'), 'Every media item with the highest number of likes');
        assertSameValue('Les plus aimés : 2 « j\'aime » chacun.', $Top['note'], 'Number of likes shown');

        // A third like on c.jpg: it is alone at the top
        ToggleMediaLike($C, 'tom@exemple.fr');
        LoadLikes(TRUE);
        $Top = FindSection(BuildLikesSections('tom@exemple.fr'), 'unanimite');
        assertSameValue(array($C), array_column($Top['media'], 'key'), 'Only the most liked when it is alone at the top');

        assertSameValue('Léa', GetLikerLabel('lea@exemple.fr'), 'First name read from emails.txt');
        assertSameValue('tom', GetLikerLabel('tom@exemple.fr'), 'Without a first name: part before @');

        echo "LikesTest: OK\n";
}
catch (Throwable $Error)
{
        fwrite(STDERR, 'LikesTest: FAILED: ' . $Error->getMessage() . "\n");
        $exitCode = 1;
}

exec('rm -rf ' . escapeshellarg($WorkDirectory));
exit($exitCode);
