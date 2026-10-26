<?php
// Accueil : les coups de cœur, puis les galeries
$Galleries = array();

foreach (GetGalleryRootNames() as $RootName)
{
        if (ResolveGalleryDirectoryPath('./' . $RootName) !== NULL)
        {
                $Galleries[] = array('label' => GetGalleryLabel($RootName), 'path' => './' . $RootName, 'gallery' => './' . $RootName);
        }
}
?>
<?php if (is_file('./ImagesSite/MainPhoto.jpg')): ?>
<section class="home-hero">
  <img src="./ImagesSite/MainPhoto.jpg" alt="La famille">
</section>
<?php endif; ?>

<?php // Coups de cœur de chacun et médias qui font l'unanimité, une fois connecté ?>
<?php echo RenderLikesSections(); ?>

<section class="home-galleries">
  <h2>Les galeries</h2>
<?php echo RenderAlbumCards('', $Galleries); ?>
</section>

