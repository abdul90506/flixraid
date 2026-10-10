<?php
/**
 * CINEFLIX – Netflix has no separate title page.
 * Old title links are redirected to the watch page in "info" mode
 * (pop-up modal on desktop, full title sheet on mobile).
 */
require __DIR__ . '/includes/bootstrap.php';
$type = q('type') === 'tv' ? 'tv' : 'movie';
$id   = qi('id');
header('Location: ' . ($id ? title_url($type, $id) : 'index.php'), true, 301);
exit;
