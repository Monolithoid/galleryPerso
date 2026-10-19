<?php
// Accueil : les galeries, puis les nouvelles de la famille
$Galleries = array();

foreach (GetGalleryRootNames() as $RootName)
{
        if (ResolveGalleryDirectoryPath('./' . $RootName) !== NULL)
        {
                $Galleries[] = array('label' => GetGalleryLabel($RootName), 'path' => './' . $RootName, 'gallery' => './' . $RootName);
        }
}

// Les plus récentes en premier
$News = array(
        array('Bienvenue', 'Les nouvelles de la famille s\'affichent ici.')
);
?>
<?php if (is_file('./ImagesSite/MainPhoto.jpg')): ?>
<section class="home-hero">
  <img src="./ImagesSite/MainPhoto.jpg" alt="La famille">
</section>
<?php endif; ?>

<section class="home-galleries">
  <h2>Les galeries</h2>
<?php echo RenderAlbumCards('', $Galleries); ?>
</section>

<section class="home-news">
  <h2>Nouvelles</h2>
  <ul class="news">
<?php foreach ($News as $Entry): ?>
    <li><strong><?php echo EscapeHtml($Entry[0]); ?></strong><p><?php echo EscapeHtml($Entry[1]); ?></p></li>
<?php endforeach; ?>
  </ul>
</section>
