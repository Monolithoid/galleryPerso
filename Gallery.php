<?php
// Arborescence des albums de la galerie courante ($Selection vient de index.php)
$GalleryTreeJson = json_encode(
        BuildGalleryTree($Selection['gallery']),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
);

if ($GalleryTreeJson === FALSE)
{
        $GalleryTreeJson = '[]';
}
?>
<a class="site-nav__home" href="index.php">← Accueil</a>
<a class="site-nav__gallery" href="<?php echo EscapeHtml(GetAlbumUrl($Selection['gallery'])); ?>"><?php echo EscapeHtml(GetGalleryLabel(basename(NormalizeAlbumRelativePath($Selection['gallery'])))); ?></a>
<form name="form_Gallery" method="get" action="index.php">
<input name="Diaporama" value="<?php echo EscapeHtml($Selection['album']); ?>" type="hidden">
<input name="Gallery" value="<?php echo EscapeHtml($Selection['gallery']); ?>" type="hidden">
<div class="gallery-tree-container">
        <div
                id="gallery-tree"
                data-root-path="<?php echo EscapeHtml($Selection['gallery']); ?>"
                data-active-path="<?php echo EscapeHtml($Selection['album']); ?>"
        >
                <p class="gallery-tree__placeholder">Chargement de la liste des albums...</p>
        </div>
        <script type="application/json" id="gallery-tree-data">
<?php echo $GalleryTreeJson; ?>
        </script>
</div>
<link rel="stylesheet" href="css/gallery-tree.css">
<script src="script/galleryTree.js"></script>
</form>
