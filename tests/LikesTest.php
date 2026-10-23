<?php
/*
 * « J'aime » : bascule aimé / plus aimé, rubriques de l'accueil
 * (unanimité, coups de cœur) et médias disparus ignorés.
 * Lancement : php tests/LikesTest.php
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

$Item = function ($Name)
{
        return array('name' => $Name, 'kind' => 'image', 'thumb' => 'cache/' . $Name, 'thumb_width' => 400, 'thumb_height' => 300,
                'preview' => '', 'original' => $Name, 'full' => '', 'width' => 800, 'height' => 600, 'taken' => NULL);
};
file_put_contents($AlbumCache . '/album.json', json_encode(array('items' => array($Item('a.jpg'), $Item('c.jpg')))));

require __DIR__ . '/../lib/likes.php';

$exitCode = 0;

try
{
        $A = 'GalleryPhotos/Plage/a.jpg';
        $B = 'GalleryPhotos/Plage/b.jpg';
        $C = 'GalleryPhotos/Plage/c.jpg';

        assertSameValue(NULL, FindLikedMedia($B), 'Un média absent de la fiche est refusé');
        assertSameValue(NULL, FindLikedMedia('lib/../GalleryPhotos/Plage/a.jpg'), 'Un chemin avec « .. » est refusé');
        assertSameValue(NULL, FindLikedMedia('cache/Plage/a.jpg'), 'Un chemin hors des galeries est refusé');
        assertSameValue('a.jpg', FindLikedMedia($A)['item']['name'], 'Un média de la fiche est trouvé');

        assertSameValue(array('liked' => TRUE, 'count' => 1), ToggleMediaLike($A, 'lea@exemple.fr'), 'Premier clic : aimé');
        assertSameValue(array('liked' => FALSE, 'count' => 0), ToggleMediaLike($A, 'lea@exemple.fr'), 'Second clic : plus aimé');
        assertSameValue('{}', trim(file_get_contents(GetLikesFilePath())), 'Un média sans « j\'aime » sort du fichier');

        ToggleMediaLike($A, 'lea@exemple.fr');
        ToggleMediaLike($A, 'tom@exemple.fr');
        ToggleMediaLike($B, 'tom@exemple.fr');
        ToggleMediaLike($C, 'lea@exemple.fr');
        // b.jpg n'est pas (plus) dans la fiche : média supprimé, ignoré à l'affichage
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
                'Les coups de cœur de lea' => array($A, $C)
        ), $Summary, 'Rubriques de l\'accueil');

        echo "LikesTest : OK\n";
}
catch (Throwable $Error)
{
        fwrite(STDERR, 'LikesTest : ÉCHEC : ' . $Error->getMessage() . "\n");
        $exitCode = 1;
}

exec('rm -rf ' . escapeshellarg($WorkDirectory));
exit($exitCode);
