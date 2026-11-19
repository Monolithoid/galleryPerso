  <a class="site-title" href="index.php">Galerie photos de famille</a>
  <p class="site-date">Bonjour, nous sommes le <?php echo date('d-m-Y'); ?></p>
<?php
// Address passed on by oauth2-proxy (Google login); absent outside Docker.
// Shows the first name from emails.txt, the full address on hover
$ConnectedEmail = $_SERVER['HTTP_X_FORWARDED_EMAIL'] ?? '';
if ($ConnectedEmail !== ''):
?>
  <p class="site-user">
    <span class="site-user-email" title="<?php echo EscapeHtml($ConnectedEmail); ?>"><?php echo EscapeHtml(GetLikerLabel($ConnectedEmail)); ?></span>
    <a href="/oauth2/sign_out">Déconnexion</a>
  </p>
<?php endif; ?>
