<?php
ob_start();

require 'functions.php';

$Selection = ResolveGallerySelection(
        isset($_GET['Gallery']) ? $_GET['Gallery'] : NULL,
        isset($_GET['Diaporama']) ? $_GET['Diaporama'] : NULL
);

// Un album sans galerie : la galerie est le premier dossier de son chemin
if (($Selection['invalid'] === FALSE) && ($Selection['gallery'] === '') && ($Selection['album'] !== ''))
{
        $Segments = explode('/', NormalizeAlbumRelativePath($Selection['album']));
        $Selection['gallery'] = './' . $Segments[0];
}

if ($Selection['invalid'] === TRUE)
{
        http_response_code(400);
}

$IsHome = ($Selection['invalid'] === FALSE) && ($Selection['gallery'] === '');
$PageTitle = 'Galerie photos de famille';

if ($IsHome === FALSE && $Selection['invalid'] === FALSE)
{
        $CurrentPath = ($Selection['album'] !== '') ? $Selection['album'] : $Selection['gallery'];
        $CurrentName = ($CurrentPath === $Selection['gallery']) ? GetGalleryLabel(basename(NormalizeAlbumRelativePath($CurrentPath))) : basename($CurrentPath);
        $PageTitle = $CurrentName . ' · ' . $PageTitle;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#375D81">
  <title><?php echo EscapeHtml($PageTitle); ?></title>
  <link rel="stylesheet" href="css/style.css">
  <link rel="icon" href="favicon.ico">
</head>
<body class="<?php echo $IsHome ? 'page-home' : 'page-album'; ?>">
<header class="site-header">
<?php require 'header.php'; ?>
<?php if ($IsHome === FALSE): ?>
  <button type="button" class="nav-toggle" aria-expanded="false" aria-controls="site-nav">Albums</button>
<?php endif; ?>
</header>
<div class="layout">
<?php if ($IsHome === FALSE): ?>
  <aside class="site-nav" id="site-nav">
<?php require 'Gallery.php'; ?>
  </aside>
<?php endif; ?>
  <main class="site-main">
<?php
if ($Selection['invalid'] === TRUE)
{
        echo '<div class="error-message">La galerie demandée est invalide.</div>';
        echo '<p><a href="index.php">Retour à l\'accueil</a></p>';
}
else if ($IsHome === TRUE)
{
        require 'main.php';
}
else
{
        echo RenderAlbum($Selection['gallery'], $Selection['album']);
}
?>
  </main>
</div>
<?php require 'footer.php'; ?>
<script src="script/viewer.js" defer></script>
</body>
</html>
