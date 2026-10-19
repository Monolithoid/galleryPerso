<?php
ob_start();

if (empty($_GET["resolution"]))
	define("K_NBIMAGEBYROW", 6);
else
{
	$resolution = explode('x', $_GET["resolution"]);
	define("K_NBIMAGEBYROW", (($resolution[0] - 300) / 110));
}

define("K_SMALLSIZE",100);
define("K_MIDDLESIZE",800);
define("K_IMAGESIZE",600);

//ini_set( "include_path", "/var/www/html/");
require 'functions.php';


?>
<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN">
<html lang="fr-fr">
<head>
  <meta content="text/html; charset=UTF-8" http-equiv="content-type">
  <script type="text/javascript" src="script/jquery.min.js"></script>
  <script type="text/javascript" src="script/jquery.easing.1.3.js"></script>
  <script type="text/javascript" src="script/jQueryRotate.2.2.js"></script>
  <script type='text/javascript' src='script/script.js'></script>
  <link rel="stylesheet" href="css/style.css" />
  <title>Galerie photos de famille</title>
</head>
<body>
<!-- Start Snapshot -->
<div id="next" onClick="imagechange(1);"></div>
<div id="previous" onClick="imagechange((-1));"></div>
<div id="right" onClick="imagerotation(90);"></div>
<div id="left" onClick="imagerotation((-90));"></div>
<div id="close" onClick="DisplaySnapshot(null);"></div>
<div id="zoom" onClick="window.open(Medias[currentimage].src);return false;"></div>
<div id="legend" ></div>
<div class="snapshot" id="snapshot"></div>
<!-- Start Content -->
<div class="page">
	<div class="content">
		<div class="header">
			<?php
			require 'header.php';
			?>
		</div>
		<div class="menu">
			<?php
			if ( (empty($_GET['Gallery']))
				&& (empty($_GET['Diaporama'])) )
				require 'menu.php';
			else
				require 'Gallery.php';
			?>
		</div>
		<div class="main">
			<?php
			if (empty($_GET['Diaporama']))
				require 'main.php';
			else
				require 'Diaporama.php';
			?>
		</div>
	</div>
<!--
	<div class="footer">
		<?php
		require 'footer.php';
		?>
	</div>
-->
</div>
</body>
</html>
