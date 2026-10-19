<?php
require_once __DIR__ . '/functions.php';

$GalleryRequest = isset($_GET['Gallery']) ? $_GET['Gallery'] : NULL;
$DiaporamaRequest = isset($_GET['Diaporama']) ? $_GET['Diaporama'] : NULL;

$GalleryInfo = ResolveGalleryDirectoryPath($GalleryRequest);
$DiaporamaInfo = ResolveGalleryDirectoryPath($DiaporamaRequest);

$SanitizedDiaporama = '';
$DiaporamaTitle = '';
$InvalidDiaporamaRequest = FALSE;

if (($DiaporamaRequest !== NULL)
        && ($DiaporamaRequest !== '')
        && ($DiaporamaInfo === NULL))
{
        $InvalidDiaporamaRequest = TRUE;
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
                $InvalidDiaporamaRequest = TRUE;
                $DiaporamaInfo = NULL;
        }
}

if ($DiaporamaInfo !== NULL)
{
        $SanitizedDiaporama = $DiaporamaInfo['relative'];
        $DiaporamaTitle = basename($DiaporamaInfo['absolute']);
}

if ($InvalidDiaporamaRequest === TRUE)
{
        if (function_exists('http_response_code'))
        {
                http_response_code(400);
        }

        if (!headers_sent())
        {
                header('HTTP/1.1 400 Bad Request', TRUE, 400);
        }

        $SanitizedDiaporama = '';
}
?>
<center>
<?php
if ($SanitizedDiaporama === '')
{
        if ($InvalidDiaporamaRequest === TRUE)
        {
                echo 'La galerie demandée est invalide.';
        }
        else
        {
                echo 'Pas d\'images';
        }
}
else
{
        echo '<big><B>' . htmlspecialchars($DiaporamaTitle, ENT_QUOTES, 'UTF-8') . '</B></big><br><hr>';

        echo DisplayDiaporama($SanitizedDiaporama, K_NBIMAGEBYROW, K_SMALLSIZE, K_MIDDLESIZE);
}
?>
</center>
