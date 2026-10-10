<?php
require __DIR__ . '/includes/bootstrap.php';
layout_header('My List', ['page' => 'list', 'body' => 'browse']);
?>
<div class="page-head">
  <div class="page-title"><h1>My List</h1></div>
  <div class="chips" id="listFilter">
    <button class="chip active" data-f="all">All</button>
    <button class="chip" data-f="movie">Movies</button>
    <button class="chip" data-f="tv">TV Shows</button>
  </div>
</div>
<div class="grid" id="myListGrid"></div>
<div class="empty-state" id="myListEmpty" hidden>
  <div class="empty-ico"><?= icon('list') ?></div>
  <h2>Your list is empty</h2>
  <p>Add movies and shows to your list by tapping the <b>+</b> button, and they'll show up here.</p>
  <a class="btn btn-play" href="index.php"><?= icon('home') ?><span>Browse titles</span></a>
</div>

<section class="row row-wide" id="historyRow" hidden>
  <div class="row-head"><h2>Watch History</h2><button class="row-more" id="clearHistory">Clear history</button></div>
  <div class="row-wrap">
    <button class="row-arrow left" aria-label="Previous"><?= icon('left') ?></button>
    <div class="row-track"></div>
    <button class="row-arrow right" aria-label="Next"><?= icon('right') ?></button>
  </div>
</section>
<?php layout_footer(); ?>
