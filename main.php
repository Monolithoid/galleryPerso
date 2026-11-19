<?php
// Home page: the favourites, then the galleries
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

<?php // Each person's favourites and unanimous favourites, once logged in ?>
<?php echo RenderLikesSections(); ?>

<section class="home-galleries">
  <h2>Les galeries</h2>
<?php echo RenderAlbumCards('', $Galleries); ?>
</section>

