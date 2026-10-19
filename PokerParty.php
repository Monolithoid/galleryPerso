<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN">
<html lang="fr-fr">
<head>
  <meta content="text/html; charset=ISO-8859-1" http-equiv="content-type">
  <title>Poker Party</title>
</head>
<body>
<CENTER>
<?php
ini_set( "include_path", "/var/www/html/");
require 'functions.php';

define("K_C", 1);
define("K_UV", 2);
define("K_Q", 3);
?>
<form method="post" action="PokerParty.php">
<?php

$ListOfColor = array(1 => "Blanc", "Rouge", "Bleu", "Noir", "Vert", "Jaune");
$ListOfColorChip = array(1 => "Blanc", "Rouge", "Bleu", "Noir", "Vert", "Jaune");
$ListOfUnitaryValue = array(1 => 10, 50, 100, 200, 500, 1000);
$ListOfQuantity = array(1 => 150, 150, 100, 100, 50, 50);
$ListOfChip = array(1 => $ListOfColorChip, $ListOfUnitaryValue, $ListOfQuantity);

$NbColors = GetNotEmpty($_POST['NbColors'], 3);
$NbPlayers = GetNotEmpty($_POST['NbPlayers'], 6);
$NbSteps = GetNotEmpty($_POST['NbSteps'], 9);
$TimeSteps = GetNotEmpty($_POST['TimeSteps'], 30);

for ($i = 1; $i <= $NbColors; $i++)
{
	$Str = 'ColorChip' . $i;
	$ListOfChip[K_C][$i] = GetNotEmpty($_POST[$Str], $ListOfColor[$i]);

	$Str = 'ValueChip' . $i;
	$ListOfChip[K_UV][$i] = GetNotEmpty($_POST[$Str], $ListOfChip[K_UV][$i]);
	
	$Str = 'NbChip' . $i;
	$ListOfChip[K_Q][$i] = GetNotEmpty($_POST[$Str], $ListOfChip[K_Q][$i]);
	
}
?>
Pour realiser une petite partie de Poker entre amis.<br><br><br>
<hr>
<big><big><B><U>Configuration de la mallette</U></B></big></big><br>
La mallette contient <input name="NbColors" size="1" value="<?php echo $NbColors ?>" type="value"> couleurs de jetons.<br>
<?php

// Sort chip value
do
{
	$oneChangeDone = 0;
	
	// Parse value array to find one change to do
	for ($i = 1; $i < $NbColors; $i++)
	{
		// a change need to be done
		if ($ListOfChip[K_UV][$i] > $ListOfChip[K_UV][$i + 1] )
		{
			// Exchange all array boxes
			ExchangeValue($ListOfChip[K_C][$i], $ListOfChip[K_C][$i + 1]);
			ExchangeValue($ListOfChip[K_UV][$i], $ListOfChip[K_UV][$i + 1]);
			ExchangeValue($ListOfChip[K_Q][$i], $ListOfChip[K_Q][$i + 1]);
			
			// Put the flag
			$oneChangeDone = 1;
		}
	}
}
// Loop until no change done
while ($oneChangeDone == 1);

// Get the minimum unitary chip value
$MinChip = $ListOfChip[K_UV][1];

// Compute total chips value
$SumChips  = 0;
for ($i = 1; $i <= $NbColors; $i++)
	$SumChips += $ListOfChip[K_UV][$i] * $ListOfChip[K_Q][$i];

?>
  <table border="0" cellpadding="2" cellspacing="2" height="85" width="261">
    <tbody>
      <tr>
        <td align="center" bgcolor="#afa6c6">Couleur</td>
        <td align="center" bgcolor="#afa6c6">Valeur unitaire </td>
        <td align="center" bgcolor="#afa6c6">Quantite</td>
        <td align="center" bgcolor="#afa6c6">Somme </td>
      </tr>
<?php
for ($i = 1; $i  <= $NbColors; $i++)
{
?>
      <tr>

        <td align="right">
        <select name="ColorChip<?php echo $i; ?>">
<?php
	foreach ($ListOfColor as $iColor)
	{
		echo '<option';
		if ($iColor == $ListOfChip[K_C][$i])	echo ' selected="selected"';
		echo '>' . $iColor . '</option>';
	}
?>
        </select>
        </td>
        <td> <input name="ValueChip<?php echo $i; ?>" size="4" value="<?php echo $ListOfChip[K_UV][$i] ?>" type="value"></td>
        <td> <input name="NbChip<?php echo $i; ?>" size="4" value="<?php echo $ListOfChip[K_Q][$i] ?>" type="value"></td>
        <td align="right"><?php echo $ListOfChip[K_UV][$i] * $ListOfChip[K_Q][$i] . '$'; ?>
        </td>
      </tr>
<?php
}
?>
    </tbody>
  </table>
  <input value="Valider la mallette" name="Submit" type="submit">
  <br><br><br>
  <hr>
  <big><big><B><U>Configuration de la partie</U></B></big></big><br>
  <table border="0" cellpadding="2" cellspacing="2" height="85" width="261">
    <tbody>
      <tr>
        <td align="right" bgcolor="#afa6c6">Total mallette : </td>
        <td><?php echo $SumChips; ?>$</td>
      </tr>
      <tr>
        <td align="right" bgcolor="#afa6c6">Valeur du plus petit chip : </td>
        <td><?php echo $MinChip ?>$</td>
      </tr>
      <tr>
        <td align="right" bgcolor="#afa6c6">Nombre de joueurs : </td>
        <td> <input name="NbPlayers" size="2" value="<?php echo $NbPlayers; ?>" type="value"></td>
      </tr>
      <tr>
        <td align="right" bgcolor="#afa6c6">Nombre de paliers : </td>
        <td> <input name="NbSteps" size="2" value="<?php echo $NbSteps; ?>" type="value"></td>
      </tr>
      <tr>
        <td align="right" bgcolor="#afa6c6">Duree des paliers (min) : </td>
        <td> <input name="TimeSteps" size="2" value="<?php echo $TimeSteps; ?>" type="value"></td>
      </tr>      
    </tbody>
  </table>
  <input name="Submit" value="Calculer" type="submit">
<br><br><br>
<hr>
<big><big><B><U>Deroulement de la partie</U></B></big></big><br>
<?php
// Compute a from y = a*x^4
$a = ($SumChips / (15 * Puissance($NbSteps,4)));

// Compute the player stack
$PlayerStack = floor(($SumChips / $NbPlayers)/$MinChip)*$MinChip;
?>
<?php echo $SumChips; ?>$ pour <?php echo $NbPlayers; ?> joueurs sur <?php echo $NbSteps; ?> paliers, soit :
<?php
// Compute the set of chip for each player
$PlayerStack_ = $PlayerStack;
?>
  <table border="1" cellpadding="2" cellspacing="2" height="85" width="261">
    <tbody>
	<tr>
        <td align="center" bgcolor="#afa6c6">Couleur</td>
        <td align="center" bgcolor="#afa6c6">Valeur unitaire </td>
        <td align="center" bgcolor="#afa6c6">Quantite</td>
        <td align="center" bgcolor="#afa6c6">Somme </td>
	</tr>
<?php
$i = 1;
while (($PlayerStack_ > 0)
	&& ($i <= $NbColors))
{
	$NbChip_byPlayer = floor($ListOfChip[K_Q][$i] / $NbPlayers);
	$MountChip = $ListOfChip[K_UV][$i] * $NbChip_byPlayer;
	
	if ($MountChip > $PlayerStack_)
	{
		$MountChip = $PlayerStack_;
		$NbChip_byPlayer=floor($PlayerStack_ / $ListOfChip[K_UV][$i]);
	}
	$PlayerStack_ -= $MountChip;
?>
	<tr>
        <td align="center"><?php echo $ListOfChip[K_C][$i]; ?></td>
        <td align="center"><?php echo $ListOfChip[K_UV][$i]; ?></td>
        <td align="center"><?php echo $NbChip_byPlayer; ?></td>
        <td align="center"><?php echo $MountChip; ?></td>
	</tr>   
<?php
	$i++;
}
?>
    </tbody>
  </table>
<?php echo $PlayerStack - $PlayerStack_; ?>$ par joueur.<br>
  <br>
 <B><U>Augmentation des Blinds</U></B><br>
  <table border="1" cellpadding="2" cellspacing="2" height="85" width="261">
    <tbody>
      <tr>
        <td align="center" bgcolor="#afa6c6"><b>Palier</b></td>
        <td align="center" bgcolor="#afa6c6"><b>Temps</b></td>
        <td align="center" bgcolor="#afa6c6"><b>min. Chip</b></td>
        <td align="center" bgcolor="#afa6c6"><b>Small Blind</b></td>
        <td align="center" bgcolor="#afa6c6"><b>Big Blind</b></td>
        <td align="center" bgcolor="#afa6c6"><b>M</b></td>
        <td align="center" bgcolor="#3366ff"><b>20M</b></td>
        <td align="center" bgcolor="#ff0000"><b>10M</b></td>
        <td align="center" bgcolor="#000000"><font color="#ffffff"><b>5M</b></font></td>
      </tr>
<?php
$smallB_ = 1;
		  
for ($e = 1; $e <= $NbSteps; $e++)
{
	// Compute the current step SmallBlind following the theorical function
	//$smallB = ceil( ($a * Puissance($e,4) ) / $MinChip) * $MinChip;
	//$smallB = $a * Puissance($e,4);
	
	$smallB = ceil( ($smallB_ * 1.5) / $MinChip) * $MinChip;
	$smallB = ($smallB_ * 1.5);

	// Search the current step minimum chip
	$i = 1;
//	while ((($ListOfChip[K_UV][$i] * 3) <= $smallB_)
	while ((($ListOfChip[K_UV][$i] * (1 + $NbSteps / $NbColors)) <= $smallB_)
		&& ($i < $NbColors))
	{
		$i++;
	}
	$MinChip = $ListOfChip[K_UV][$i];
	
	$smallB = ceil($smallB / $MinChip) * $MinChip;
	// the SmallBlind obligatory increase
	//while ($smallB_ >= $smallB) 
	//	$smallB += $MinChip;	

?>
      <tr>
        <td align="center"><?php echo $e; ?></td>
        <td align="center"><?php echo date("H:i.s",time() + ($e - 1) * $TimeSteps * 60); ?></td>
        <td align="center"><?php echo $MinChip . '$'; ?></td>
        <td align="center"><?php echo $smallB . '$'; ?></td>
        <td align="center"><?php echo $smallB * 2 . '$'; ?></td>
        <td align="center"><?php echo $smallB * 3 . '$'; ?></td>
        <td align="center" bgcolor="#3366ff"><?php echo $smallB * 3 * 20 . '$'; ?></td>
        <td align="center" bgcolor="#ff0000"><?php echo $smallB * 3 * 10 . '$'; ?></td>
        <td align="center" bgcolor="#000000"><font color="#ffffff"><b><?php echo $smallB * 3 * 5 . '$'; ?></b></font></td>
      </tr>
<?php 
	$smallB_ = $smallB;
}
?>
    </tbody>
  </table>
  <br>
  <input name="Submit" value="Debuter maintenant" type="submit">
  <br>
  <br>
  <br>
  <i>
  small blind = Min Chips ^ (1.5 * Step)
  </i>
  <br>
</form>
</CENTER>
</body>
</html>
