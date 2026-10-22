  <a class="site-title" href="index.php">Galerie photos de famille</a>
  <p class="site-date">Bonjour, nous sommes le <?php echo date('d-m-Y'); ?></p>
<?php
// Adresse transmise par oauth2-proxy (connexion Google) ; absente hors Docker
$ConnectedEmail = $_SERVER['HTTP_X_FORWARDED_EMAIL'] ?? '';
if ($ConnectedEmail !== ''):
?>
  <p class="site-user">
    <span class="site-user-email"><?php echo htmlspecialchars($ConnectedEmail, ENT_QUOTES, 'UTF-8'); ?></span>
    <a href="/oauth2/sign_out">Déconnexion</a>
  </p>
<?php endif; ?>
