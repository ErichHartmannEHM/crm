<?php
require_once __DIR__.'/../lib/auth.php';
$title='Ожидают Возврата'; $active='await';
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/helpers.php';
require_once __DIR__.'/../lib/log.php';

auth_require(); auth_require_admin(); csrf_check();

/* ---------- schema ensure ---------- */
function db_has_column(string $t,string $c): bool {
  try { return db_exec("SHOW COLUMNS FROM `{$t}` LIKE ?",[$c])->fetch()?true:false; }
  catch(Throwable $e){ return false; }
}
function ensure_meta(): void {
  // таблица для выписок
  try{
    db_exec("CREATE TABLE IF NOT EXISTS `card_statements`(
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      `card_id` INT NOT NULL,
      `file_name_orig` VARCHAR(255) NOT NULL,
      `file_path` VARCHAR(255) NOT NULL,
      `mime` VARCHAR(127) NOT NULL,
      `size_bytes` INT NOT NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX(`card_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  }catch(Throwable $e){}
  // служебные колонки
  try{ if(!db_has_column('cards','processing_source_buyer_id')) db_exec("ALTER TABLE cards ADD COLUMN processing_source_buyer_id INT NULL AFTER buyer_id"); }catch(Throwable $e){}
  try{ if(!db_has_column('cards','processing_source_team_id'))  db_exec("ALTER TABLE cards ADD COLUMN processing_source_team_id  INT NULL AFTER processing_source_buyer_id"); }catch(Throwable $e){}
  try{ if(!db_has_column('cards','balance_at_processing_uah'))  db_exec("ALTER TABLE cards ADD COLUMN balance_at_processing_uah DECIMAL(14,2) NULL AFTER balance_uah"); }catch(Throwable $e){}
  try{ if(!db_has_column('cards','setup_amount_uah'))           db_exec("ALTER TABLE cards ADD COLUMN setup_amount_uah DECIMAL(14,2) NULL AFTER balance_at_processing_uah"); }catch(Throwable $e){}
  try{ if(!db_has_column('cards','comment'))                    db_exec("ALTER TABLE cards ADD COLUMN comment TEXT NULL"); }catch(Throwable $e){}
}
ensure_meta();

/* ---------- POST ---------- */
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(isset($_POST['save_comment'])){
    $id=(int)$_POST['card_id']; $comment=trim((string)($_POST['comment']??''));
    try{ db_exec("UPDATE cards SET comment=? WHERE id=?",[$comment,$id]); log_op('card','comment',$id,[]);}catch(Throwable $e){}
    header('Location:/admin/awaiting_refund.php'); exit;
  }
  if(isset($_POST['archive_card'])){
    $id=(int)$_POST['card_id'];
    try{ db_exec("UPDATE cards SET status='archived' WHERE id=?",[$id]); log_op('card','archive',$id,[]);}catch(Throwable $e){}
    header('Location:/admin/awaiting_refund.php'); exit;
  }
}

/* ---------- LIST ---------- */
require __DIR__.'/_layout.php';
$cards=db_exec("SELECT * FROM cards WHERE IFNULL(status,'waiting')='await_refund' ORDER BY id DESC")->fetchAll();
?>
<div class="card">
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th class="num">ID</th>
          <th>PAN</th>
          <th>Источник (Команда → Баер)</th>
          <th class="num">Баланс при переносе</th>
          <th class="num">Сумма оформления</th>
          <th>Выписки</th>
          <th>Комментарий</th>
          <th>Действия</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($cards as $c):
        // источник: команда/баер, к которым карта была привязана ДО переноса на оформление
        $src = null;
        if (!empty($c['processing_source_buyer_id'])) {
          $src = db_row("SELECT b.name buyer_name, t.name team_name
                           FROM buyers b LEFT JOIN teams t ON t.id=b.team_id
                          WHERE b.id=?",[(int)$c['processing_source_buyer_id']]);
        }
        $srcLabel = $src ? ($src['team_name'].' → '.$src['buyer_name']) : '—';

        // выписки прикреплены к карте по card_id
        $files=db_exec("SELECT * FROM card_statements WHERE card_id=? ORDER BY id DESC",[$c['id']])->fetchAll();

        $last4=card_last4_from_row($c);
        $balAt = $c['balance_at_processing_uah'] ?? null;
        $setup = $c['setup_amount_uah'] ?? null;
      ?>
        <tr>
          <td class="num"><?= (int)$c['id'] ?></td>
          <td class="td-mono"><?= mask_pan_last4($last4) ?></td>
          <td><?= h($srcLabel) ?></td>

          <!-- Баланс при переносе -->
          <td class="num">
            <?= $balAt===null ? '<span class="muted">—</span>' : money_uah($balAt) ?>
          </td>

          <!-- Сумма оформления -->
          <td class="num">
            <?= $setup===null ? '<span class="muted">—</span>' : money_uah($setup) ?>
          </td>

          <!-- Выписки -->
          <td>
            <?php if ($files): foreach($files as $f): ?>
              <div><a href="/admin/statement.php?id=<?= (int)$f['id'] ?>"><?= h($f['file_name_orig']) ?></a></div>
            <?php endforeach; else: ?>
              <span class="muted">нет файлов</span>
            <?php endif; ?>
          </td>

          <!-- Комментарий (редактируемый) -->
          <td>
            <form method="post">
              <?= csrf_field(); ?>
              <input type="hidden" name="card_id" value="<?= (int)$c['id'] ?>">
              <textarea name="comment" rows="2" style="min-width:240px"><?= h($c['comment']??'') ?></textarea>
              <button class="btn" name="save_comment" value="1">Сохранить</button>
            </form>
          </td>

          <!-- Действия -->
          <td class="table-actions">
            <form method="post" onsubmit="return confirm('Переместить в архив?')">
              <?= csrf_field(); ?>
              <input type="hidden" name="card_id" value="<?= (int)$c['id'] ?>">
              <button class="btn btn-danger" name="archive_card" value="1">В архив</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($cards)): ?>
        <tr><td colspan="8" class="muted">Нет карт в статусе «Ожидают Возврата»</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__.'/_layout_footer.php'; ?>
