<?php
/*
 * Bascule le « j'aime » de la personne connectée sur un média.
 * Appelé par script/viewer.js : POST, champ « media » = clé du média
 * (chemin de l'album suivi du nom du fichier). Répond en JSON
 * { "liked": true|false, "count": n }.
 */
require 'functions.php';
require_once __DIR__ . '/lib/likes.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function SendLikeResponse($Status, $Body)
{
        http_response_code($Status);
        echo json_encode($Body, JSON_UNESCAPED_UNICODE);
        exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')
{
        header('Allow: POST');
        SendLikeResponse(405, array('error' => 'Méthode non autorisée.'));
}

// En-tête posé par la page elle-même : un autre site ne peut pas l'envoyer
if (($_SERVER['HTTP_X_GALERIE_LIKE'] ?? '') !== '1')
{
        SendLikeResponse(403, array('error' => 'Requête refusée.'));
}

$Email = GetConnectedEmail();

if ($Email === '')
{
        SendLikeResponse(401, array('error' => 'Connexion nécessaire.'));
}

$Key = isset($_POST['media']) ? (string) $_POST['media'] : '';

if (FindLikedMedia($Key) === NULL)
{
        SendLikeResponse(404, array('error' => 'Média introuvable.'));
}

$Result = ToggleMediaLike($Key, $Email);

if ($Result === NULL)
{
        SendLikeResponse(500, array('error' => 'Impossible d\'enregistrer le « j\'aime ».'));
}

SendLikeResponse(200, $Result);
