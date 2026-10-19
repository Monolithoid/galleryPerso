<?php
require_once __DIR__ . '/functions.php';

$GalleryRequest = isset($_GET['Gallery']) ? $_GET['Gallery'] : NULL;
$DiaporamaRequest = isset($_GET['Diaporama']) ? $_GET['Diaporama'] : NULL;

$GalleryInfo = ResolveGalleryDirectoryPath($GalleryRequest);
$DiaporamaInfo = ResolveGalleryDirectoryPath($DiaporamaRequest);

$SanitizedGallery = '';
$SanitizedDiaporama = '';
$GalleryErrorMessage = '';
$InvalidGalleryRequest = FALSE;

if (($GalleryRequest !== NULL)
        && ($GalleryRequest !== '')
        && ($GalleryInfo === NULL))
{
        $InvalidGalleryRequest = TRUE;
}

if (($DiaporamaRequest !== NULL)
        && ($DiaporamaRequest !== '')
        && ($DiaporamaInfo === NULL))
{
        $InvalidGalleryRequest = TRUE;
}

if (($GalleryInfo !== NULL)
        && ($DiaporamaInfo !== NULL))
{
        $GalleryAbsolutePath = $GalleryInfo['absolute'];
        $DiaporamaAbsolutePath = $DiaporamaInfo['absolute'];
        $GalleryAbsolutePrefix = $GalleryAbsolutePath . DIRECTORY_SEPARATOR;
        $GalleryPrefixLength = strlen($GalleryAbsolutePrefix);

        if (($DiaporamaAbsolutePath !== $GalleryAbsolutePath)
                && (strncmp($DiaporamaAbsolutePath, $GalleryAbsolutePrefix, $GalleryPrefixLength) !== 0))
        {
                $InvalidGalleryRequest = TRUE;
                $DiaporamaInfo = NULL;
        }
}

if ($GalleryInfo !== NULL)
{
        $SanitizedGallery = $GalleryInfo['relative'];
}

if ($DiaporamaInfo !== NULL)
{
        $SanitizedDiaporama = $DiaporamaInfo['relative'];
}

if ($InvalidGalleryRequest === TRUE)
{
        $GalleryErrorMessage = 'La galerie demandée est invalide.';

        if (function_exists('http_response_code'))
        {
                http_response_code(400);
        }

        if (!headers_sent())
        {
                header('HTTP/1.1 400 Bad Request', TRUE, 400);
        }

        $SanitizedGallery = '';
        $SanitizedDiaporama = '';
}

$GalleryTree = BuildGalleryTree($SanitizedGallery);
$JsonOptionNames = array(
        'JSON_UNESCAPED_UNICODE',
        'JSON_UNESCAPED_SLASHES',
        'JSON_HEX_TAG',
        'JSON_HEX_APOS',
        'JSON_HEX_AMP',
        'JSON_HEX_QUOT'
);
$JsonOptions = 0;

foreach ($JsonOptionNames as $OptionName)
{
        if (defined($OptionName))
        {
                $JsonOptions |= constant($OptionName);
        }
}

$GalleryTreeJson = json_encode($GalleryTree, $JsonOptions);

if ($GalleryTreeJson === FALSE)
{
        $GalleryTreeJson = '[]';
}

$ResolutionValue = '800x600';

if (empty($_GET['resolution']) === FALSE)
{
        $ResolutionValue = $_GET['resolution'];
}
?>
<script type='text/javascript' >
<?php
if ($SanitizedDiaporama !== '')
{
        echo GetJSListOFImage($SanitizedDiaporama);
}
?>
</script>

<?php
if ($GalleryErrorMessage !== '')
{
        echo '<div class="error-message">' . htmlspecialchars($GalleryErrorMessage, ENT_QUOTES, 'UTF-8') . '</div>';
}
?>

<center>
<A Href="index.php"><font  size="4">Retour MENU</font></A><br><br>
</center>

<form name="form_Gallery" method="get" action="index.php">
<input name="resolution" value="<?php echo htmlspecialchars($ResolutionValue, ENT_QUOTES, 'UTF-8'); ?>" type="hidden">
<input name="Diaporama" value="<?php echo htmlspecialchars($SanitizedDiaporama, ENT_QUOTES, 'UTF-8'); ?>"  type="hidden">
<input name="Gallery" value="<?php echo htmlspecialchars($SanitizedGallery, ENT_QUOTES, 'UTF-8'); ?>"  type="hidden">
<div class="gallery-tree-container">
        <div
                id="gallery-tree"
                data-root-path="<?php echo htmlspecialchars($SanitizedGallery, ENT_QUOTES, 'UTF-8'); ?>"
                data-active-path="<?php echo htmlspecialchars($SanitizedDiaporama, ENT_QUOTES, 'UTF-8'); ?>"
        >
                <p class="gallery-tree__placeholder">Chargement de la liste des diaporamas...</p>
        </div>
        <script type="application/json" id="gallery-tree-data">
<?php echo $GalleryTreeJson; ?>
        </script>
</div>
<?php
if (!defined('GALLERY_TREE_ASSETS_INCLUDED'))
{
        define('GALLERY_TREE_ASSETS_INCLUDED', TRUE);
        echo '<link rel="stylesheet" href="css/gallery-tree.css">' . PHP_EOL;
        echo '<script type="text/javascript" src="script/galleryTree.js"></script>' . PHP_EOL;
}
?>

</form>
<center>
<A Href="index.php"><font  size="4">Retour MENU</font></A><br>
</center>
