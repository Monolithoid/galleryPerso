<footer class="site-footer">
<?php // InfoSite.php answers 404 as long as diagnostics are not enabled ?>
<?php if (in_array(strtolower(trim((string) getenv('SITEIMAGEPERSO_ENABLE_ADMIN_DIAGNOSTICS'))), array('1', 'true', 'yes', 'on'), TRUE)): ?>
  <a href="InfoSite.php" title="Pour l'administrateur du site">Site Info</a>
<?php endif; ?>
</footer>
