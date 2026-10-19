<footer class="site-footer">
<?php // InfoSite.php répond 404 tant que le diagnostic n'est pas activé ?>
<?php if (in_array(strtolower(trim((string) getenv('SITEIMAGEPERSO_ENABLE_ADMIN_DIAGNOSTICS'))), array('1', 'true', 'yes', 'on'), TRUE)): ?>
  <a href="InfoSite.php" title="Pour l'administrateur du site">Site Info</a>
<?php endif; ?>
</footer>
