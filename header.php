  <a class="site-title" href="index.php">Galerie photos de famille</a>
  <p class="site-date">Bonjour, nous sommes le <?php echo date('d-m-Y'); ?></p>
<?php
// Adresse transmise par oauth2-proxy (connexion Google) ; absente hors Docker.
// On affiche le prénom de emails.txt, l'adresse complète au survol
$ConnectedEmail = $_SERVER['HTTP_X_FORWARDED_EMAIL'] ?? '';
if ($ConnectedEmail !== ''):
?>
  <p class="site-user">
    <span class="site-user-email" title="<?php echo EscapeHtml($ConnectedEmail); ?>"><?php echo EscapeHtml(GetLikerLabel($ConnectedEmail)); ?></span>
    <a href="/oauth2/sign_out">Déconnexion</a>
  </p>
<?php endif; ?>
