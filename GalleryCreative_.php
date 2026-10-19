<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN">
<html lang="fr-fr">
<head>
  <meta content="text/html; charset=ISO-8859-1" http-equiv="content-type">
  <title>Galerie des glaces</title>
</head>
<body>
<center>
<A Href="menu.php" Target="Menu"><font  size="4">Retour MENU</font></A><br><br>
</center>
<?php
ini_set( "include_path", "/var/www/html/");
require 'functions.php';
?>
<form method="post" action="Diaporama.php" target="Main">
<?php
$Str = AddOptGroupGallery("./GalleryCreative", $NbLine);
?>
<select name="GalleryCreative" size="<?php echo $NbLine; ?>" ondblclick="submit();">
<?php
	echo $Str;
?>
</select>
</form>
<center>
<A Href="menu.php" Target="Menu"><font  size="4">Retour MENU</font></A><br>
</center>
</body>
</html>