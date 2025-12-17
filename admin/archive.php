<?php
require_once __DIR__.'/../lib/auth.php';
$title='Архив'; $active='archive';
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/helpers.php';
require_once __DIR__.'/../lib/log.php';

auth_require(); auth_require_admin(); csrf_check();

/* ---------- POST actions ---------- */
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(isset($_POST['restore_team'])){
    $id=(int)$_POST['team_id'];
    db_exec("UPDATE teams SET is_archived=0 WHERE id=?",[$id]);
    log_op('team','restore',$id,[]);
    header('Location:/admin/archive.php'); exit;
  }
  if(isset($_POST['delete_team'])){
    $id=(int)$_POST['team_id'];
    db_exec("DELETE FROM teams WHERE id=?",[$id]);
    log_op('team','delete',$id,[]);
    header('Location:/admin/archive.php'); exit;
  }
  if(isset($_POST['restore_buyer'])){
    $id=(int)$_POST['buyer_id'];
    db_exec("UPDATE buyers SET is_archived=0 WHERE id=?",[$id]);
    log_op('buyer','restore',$id,[]);
    header('Location:/admin/archive.php'); exit;
  }
  if(isset($_POST['delete_buyer'])){
    $id=(int)$_POST['buyer_id'];
    db_exec("DELETE FROM buyers WHERE id=?",[$id]);
    log_op('buyer','delete',$id,[]);
    header('Location:/admin/archive.php'); exit;
  }
  if(isset($_POST['restore_card'])){
    $id=(int)$_POST['card_id'];
    db_exec("UPDATE cards SET status='waiting' WHERE id=?",[$id]);
    log_op('card','restore',$id,[]);
    header('Location:/admin/archive.php'); exit;
  }
  if(isset($_POST['delete_card'])){
    $id=(int)$_POST['card_id'];
    db_exec("DELETE FROM cards WHERE id=?",[$id]);
    log_op('card','delete',$id,[]);
    header('Location:/admin/archive.php'); exit;
  }
}

/* ---------- DATA ---------- */
require __DIR__.'/_layout.php';

/* Падение не допускаем: локальные фолбэки */
if (!function_exists('card_last4_from_row')) {
  function card_last4_from_row(array $row): string {
    foreach (['pan_last4','number_last4','last4','card_number','pan','number'] as $k) {
      if (!empty($row[$k])) {
        $d = preg_replace('~\D~','',(string)$row[$k]);
        if ($d!=='') return substr($d,-4);
      }
    }
    return isset($row['id']) ? substr(str_pad((string)$row['id'],4,'0',STR_PAD_LEFT),-4) : '????';
  }
}
if (!function_exists('mask_pan_last4')) {
  function mask_pan_last4(string $l4): string {
    $l4=preg_replace('~\D~','',$l4); $l4=substr($l4,-4);
    return '**** **** **** '.($l4?:'????');
  }
}

$teams  = db_exec("SELECT * FROM teams WHERE IFNULL(is_archived,0)=1 ORDER BY id DESC")->fetchAll();
$buyers = db_exec("SELECT b.*, t.name team_name FROM buyers b JOIN teams t ON t.id=b.team_id WHERE IFNULL(b.is_archived,0)=1 ORDER BY b.id DESC")->fetchAll();
$cards  = db_exec("SELECT * FROM cards WHERE IFNULL(status,'waiting')='archived' ORDER BY id DESC")->fetchAll();

$cntTeams  = count($teams);
$cntBuyers = count($buyers);
$cntCards  = count($cards);
?>

<?php include __DIR__.'/_flash.php'; ?>

<style>
/* ---- Адаптивная сетка разделов ---- */
.archive-grid { display:grid; grid-template-columns: repeat(3, 1fr); gap:16px; }
@media (max-width: 1100px){ .archive-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 720px) { .archive-grid { grid-template-columns: 1fr; } }

/* ---- Карточки разделов ---- */
.card { background:#0b1220; border:1px solid rgba(148,163,184,.2); border-radius:12px; }
.card > h3 { margin:0; padding:12px 14px; border-bottom:1px dashed rgba(148,163,184,.25); }
.card-body { padding:12px 14px; }

/* ---- Список плиток ---- */
.tiles { display:flex; flex-direction:column; gap:10px; }
.tile {
  display:grid;
  grid-template-columns: 1fr auto auto;
  gap:10px;
  align-items:center;
  padding:10px 12px;
  border:1px solid #222;
  border-radius:10px;
  background:#0f172a;
}
.tile-title { font-weight:600; word-break:break-word; }
.tile-sub   { color:#94a3b8; font-size:12px; }
.tile-actions { display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; }
.btn-ghost { background:transparent; border:1px solid rgba(148,163,184,.35); }
.badge { display:inline-block; padding:2px 6px; border-radius:999px; border:1px solid #1f2937; background:#0b1220; font-size:11px; }

/* ---- Пустые состояния ---- */
.empty { color:#94a3b8; font-size:13px; padding:6px 0; }

/* ---- Мобильность/доступность ---- */
:root { --tap-size: 44px; }
@media (max-width: 640px){
  .tile { grid-template-columns: 1fr; }
  .tile-actions .btn { width:100%; min-height: var(--tap-size); }
  button, .btn, input, select { min-height: var(--tap-size); font-size:15px; }
}
</style>

<div class="archive-grid">

  <!-- Команды -->
  <div class="card">
    <h3>Команды <span class="badge"><?= (int)$cntTeams ?></span></h3>
    <div class="card-body">
      <?php if (!$teams): ?>
        <div class="empty">Архивированных команд нет.</div>
      <?php else: ?>
        <div class="tiles">
          <?php foreach($teams as $t): ?>
            <div class="tile">
              <div class="tile-title"><?= h($t['name']) ?></div>
              <form method="post" class="tile-actions" aria-label="Действия с командой <?= h($t['name']) ?>">
                <?= csrf_field(); ?>
                <input type="hidden" name="team_id" value="<?= (int)$t['id'] ?>">
                <button class="btn btn-ghost" name="restore_team" value="1" type="submit" aria-label="Восстановить команду">Восстановить</button>
                <button class="btn btn-danger" name="delete_team" value="1" type="submit"
                        onclick="return confirm('Удалить команду «<?= h($t['name']) ?>» навсегда? Это действие необратимо.')"
                        aria-label="Удалить команду навсегда">Удалить</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Баеры -->
  <div class="card">
    <h3>Баеры <span class="badge"><?= (int)$cntBuyers ?></span></h3>
    <div class="card-body">
      <?php if (!$buyers): ?>
        <div class="empty">Архивированных баеров нет.</div>
      <?php else: ?>
        <div class="tiles">
          <?php foreach($buyers as $b): ?>
            <div class="tile">
              <div>
                <div class="tile-title"><?= h($b['team_name'].' → '.$b['name']) ?></div>
                <div class="tile-sub">ID: <?= (int)$b['id'] ?></div>
              </div>
              <form method="post" class="tile-actions" aria-label="Действия с баером <?= h($b['name']) ?>">
                <?= csrf_field(); ?>
                <input type="hidden" name="buyer_id" value="<?= (int)$b['id'] ?>">
                <button class="btn btn-ghost" name="restore_buyer" value="1" type="submit" aria-label="Восстановить баера">Восстановить</button>
                <button class="btn btn-danger" name="delete_buyer" value="1" type="submit"
                        onclick="return confirm('Удалить баера «<?= h($b['name']) ?>» навсегда? Это действие необратимо.')"
                        aria-label="Удалить баера навсегда">Удалить</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Карты -->
  <div class="card">
    <h3>Карты <span class="badge"><?= (int)$cntCards ?></span></h3>
    <div class="card-body">
      <?php if (!$cards): ?>
        <div class="empty">Архивированных карт нет.</div>
      <?php else: ?>
        <div class="tiles">
          <?php foreach($cards as $c): ?>
            <?php $last4 = card_last4_from_row($c); ?>
            <div class="tile">
              <div>
                <div class="tile-title"><?= mask_pan_last4($last4) ?></div>
                <div class="tile-sub">ID: <?= (int)$c['id'] ?></div>
              </div>
              <form method="post" class="tile-actions" aria-label="Действия с картой ****<?= h($last4) ?>">
                <?= csrf_field(); ?>
                <input type="hidden" name="card_id" value="<?= (int)$c['id'] ?>">
                <button class="btn btn-ghost" name="restore_card" value="1" type="submit" aria-label="Восстановить карту">Восстановить</button>
                <button class="btn btn-danger" name="delete_card" value="1" type="submit"
                        onclick="return confirm('Удалить карту ****<?= h($last4) ?> навсегда? Это действие необратимо.')"
                        aria-label="Удалить карту навсегда">Удалить</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<?php include __DIR__.'/_layout_footer.php'; ?>
