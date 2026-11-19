<?php
/*
 * Rotates a photo by a quarter turn (see lib/rotations.php).
 * Called by script/viewer.js: POST, field "media" = media key
 * (album path followed by the file name), field "degrees" = 90
 * (clockwise) or -90. Answers in JSON with the
 * rotation and the media item as the viewer must show it:
 * { "rotation": 90, "thumb": ..., "thumb_width": n, "thumb_height": n, "item": {...} }.
 */
require 'functions.php';
require_once __DIR__ . '/lib/rotations.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function SendRotateResponse($Status, $Body)
{
        http_response_code($Status);
        echo json_encode($Body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')
{
        header('Allow: POST');
        SendRotateResponse(405, array('error' => 'Méthode non autorisée.'));
}

// Header set by the page itself: another site cannot send it
if (($_SERVER['HTTP_X_GALERIE_ROTATE'] ?? '') !== '1')
{
        SendRotateResponse(403, array('error' => 'Requête refusée.'));
}

if (GetConnectedEmail() === '')
{
        SendRotateResponse(401, array('error' => 'Connexion nécessaire.'));
}

$Degrees = isset($_POST['degrees']) ? (int) $_POST['degrees'] : 0;

if (in_array($Degrees, array(90, -90), TRUE) === FALSE)
{
        SendRotateResponse(400, array('error' => 'Rotation de 90 ou -90 degrés seulement.'));
}

$Key = isset($_POST['media']) ? (string) $_POST['media'] : '';
$Found = FindLikedMedia($Key);

if (($Found === NULL) || (CanRotateMedia($Found['item']) === FALSE))
{
        SendRotateResponse(404, array('error' => 'Photo introuvable.'));
}

// Volume missing from the docker-compose: say so clearly rather than failing silently
$RotationsDirectory = dirname(GetRotationsFilePath());

if ((is_dir($RotationsDirectory) === FALSE) || (is_writable($RotationsDirectory) === FALSE))
{
        $Message = 'Le dossier ' . $RotationsDirectory . ' n\'est pas accessible en écriture : ajoutez le volume'
                . ' « <dossier de configuration>/rotations:' . $RotationsDirectory . ' » au conteneur du site.';
        error_log('Rotation impossible : ' . $Message);
        SendRotateResponse(500, array('error' => $Message));
}

$Rotation = RotateMedia($Key, $Found['album'], $Found['item'], $Degrees);

if ($Rotation === NULL)
{
        error_log('Rotation failed: vips failed on ' . $Key);
        SendRotateResponse(500, array('error' => 'Impossible de tourner la photo (vips n\'a pas pu la refaire).'));
}

$Item = ApplyMediaRotation($Found['item'], $Rotation);

SendRotateResponse(200, array(
        'rotation' => $Rotation,
        'thumb' => $Item['thumb'],
        'thumb_width' => (int) $Item['thumb_width'],
        'thumb_height' => (int) $Item['thumb_height'],
        'item' => GetViewerItem($Item, $Key)
));
