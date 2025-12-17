<?php
require_once __DIR__.'/../lib/auth.php';
$title='На оформлении'; $active='processing';
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/helpers.php';
require_once __DIR__.'/../lib/log.php';

auth_require(); auth_require_admin(); csrf_check();

/* --- schema ensure --- */
function db_has_column(string $t,string $c): bool { try { return db_exec("SHOW COLUMNS FROM `{$t}` LIKE ?",[$c])->fetch()?true:false; } catch(Throwable $e){ return false; } }
function ensure_cards_status_values(): void {
  try {
    $row = db_row("SHOW COLUMNS FROM `cards` LIKE 'status'");
    if (!$row) return;
    $type = strtolower((string)($row['Type']??''));
    if (strpos($type,'enum(')===0 && preg_match_all("/'([^']*)'/",$type,$m)) {
      $vals=$m[1]; $need=['waiting','in_work','processing','await_refund','archived'];
      $merged=array_values(array_unique(array_merge($vals,$need)));
      if ($merged!==$vals){ $enum=implode("','",array_map(fn($v)=>str_replace("'","\\'",$v),$merged));
        db_exec("ALTER TABLE `cards` MODIFY COLUMN `status` ENUM('{$enum}') NOT NULL DEFAULT 'waiting'");
      }
    }
  } catch(Throwable $e){}
}
function ensure_processing_schema(): void {
  try{ if(!db_has_column('cards','processing_source_buyer_id')) db_exec("ALTER TABLE cards ADD COLUMN processing_source_buyer_id INT NULL AFTER buyer_id"); }catch(Throwable $e){}
  try{ if(!db_has_column('cards','processing_source_team_id'))  db_exec("ALTER TABLE cards ADD COLUMN processing_source_team_id  INT NULL AFTER processing_source_buyer_id"); }catch(Throwable $e){}
  try{ if(!db_has_column('cards','balance_at_processing_uah'))  db_exec("ALTER TABLE cards ADD COLUMN balance_at_processing_uah DECIMAL(14,2) NULL AFTER balance_uah"); }catch(Throwable $e){}
  try{ if(!db_has_column('cards','processing_at'))              db_exec("ALTER TABLE cards ADD COLUMN processing_at DATETIME NULL AFTER balance_at_processing_uah"); }catch(Throwable $e){}
  try{ if(!db_has_column('cards','setup_amount_uah'))           db_exec("ALTER TABLE cards ADD COLUMN setup_amount_uah DECIMAL(14,2) NULL AFTER processing_at"); }catch(Throwable $e){}
  try{ if(!db_has_column('cards','comment'))                    db_exec("ALTER TABLE cards ADD COLUMN comment TEXT NULL"); }catch(Throwable $e){}
  try{
    db_exec("CREATE TABLE IF NOT EXISTS card_statements(
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      card_id INT NOT NULL,
      file_name_orig VARCHAR(255) NOT NULL,
      file_path VARCHAR(255) NOT NULL,
      mime VARCHAR(127) NOT NULL,
      size_bytes INT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX(card_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  }catch(Throwable $e){}
  ensure_cards_status_values();
}
ensure_processing_schema();

/* --- POST --- */
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(isset($_POST['set_setup_sum'])){
    $id=(int)$_POST['card_id']; $sum=(float)($_POST['setup_amount_uah']??0);
    try{ db_exec("UPDATE cards SET setup_amount_uah=? WHERE id=?",[$sum,$id]); log_op('card','setup_amount',$id,['setup_amount_uah'=>$sum]); }catch(Throwable $e){}
    header('Location:/admin/processing.php'); exit;
  }
  if(isset($_POST['attach_statement'])){
    $id=(int)$_POST['card_id'];
    $dir=__DIR__.'/../storage/uploads/statements'; if(!is_dir($dir)) @mkdir($dir,0775,true);
    if(isset($_FILES['statement']) && $_FILES['statement']['error']===UPLOAD_ERR_OK){
      $fn=$_FILES['statement']['name']; $tmp=$_FILES['statement']['tmp_name']; $size=(int)$_FILES['statement']['size'];
      $mime=@mime_content_type($tmp)?:''; $ok=['application/pdf','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/vnd.ms-excel'];
      if($size<=15*1024*1024 && in_array($mime,$ok,true)){
        $ext=pathinfo($fn,PATHINFO_EXTENSION); $rnd=bin2hex(random_bytes(8)).'.'.$ext; $path=$dir.'/'.$rnd; @move_uploaded_file($tmp,$path);
        try{ db_exec("INSERT INTO card_statements(card_id,file_name_orig,file_path,mime,size_bytes,created_at) VALUES(?,?,?,?,?,NOW())",[$id,$fn,$rnd,$mime,$size]); log_op('card','attach_statement',$id,['file'=>$fn]); }catch(Throwable $e){}
      }
    }
    header('Location:/admin/processing.php'); exit;
  }
  if(isset($_POST['save_comment'])){
    $id=(int)$_POST['card_id']; $comment=trim((string)($_POST['comment']??''));
    try{ db_exec("UPDATE cards SET comment=? WHERE id=?",[$comment,$id]); log_op('card','comment',$id,[]);}catch(Throwable $e){}
    header('Location:/admin/processing.php'); exit;
  }
  if(isset($_POST['to_await'])){
    $id=(int)$_POST['card_id'];
    try{
      ensure_cards_status_values(); ensure_processing_schema();
      // страхуем сохранение источника и отвязку
      db_exec("UPDATE cards c LEFT JOIN buyers b ON b.id=c.buyer_id
                 SET c.processing_source_buyer_id = COALESCE(c.processing_source_buyer_id, c.buyer_id),
                     c.processing_source_team_id  = COALESCE(c.processing_source_team_id,  b.team_id),
                     c.buyer_id = NULL,
                     c.status   = 'await_refund'
               WHERE c.id=?",[$id]);
      log_op('card','await_refund',$id,[]);
    }catch(Throwable $e){}
    header('Location:/admin/awaiting_refund.php'); exit;
  }
  if(isset($_POST['delete_card'])){
    $id=(int)$_POST['card_id'];
    try{ ensure_cards_status_values(); db_exec("UPDATE cards SET status='archived' WHERE id=?",[$id]); log_op('card','archive',$id,[]);}catch(Throwable $e){}
    header('Location:/admin/processing.php'); exit;
  }
}

/* --- LIST --- */
require __DIR__.'/_layout.php';
$cards=db_exec("SELECT * FROM cards WHERE IFNULL(status,'waiting')='processing' ORDER BY id DESC")->fetchAll();
?>
<style>
/* базовые */
.card{ background:#0b1220; border:1px solid rgba(148,163,184,.2); border-radius:12px; }
.table{ width:100%; border-collapse:collapse; }
.table thead th{ text-align:left; border-bottom:1px solid rgba(148,163,184,.25); padding:10px; }
.table tbody td{ border-bottom:1px dashed rgba(148,163,184,.18); padding:10px; vertical-align:top; }
.table .num{ text-align:right; }
.table-actions{ white-space:nowrap; }
.td-mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New",monospace; }

/* формы */
.form-row{ display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; }
.form-row input[type="number"],
.form-row input[type="file"],
.form-row textarea{ max-width:100%; }
.form-row .btn{ height:38px; }
textarea{ width:100%; min-width:240px; }

/* список файлов */
.files{ display:flex; flex-direction:column; gap:6px; margin-top:6px; }
.files a{ color:#cbd5e1; text-decoration:underline; }

/* доступность и мобайл */
:root{ --tap-size:44px; }
@media (max-width:600px){
  button,.btn,input,select,textarea{ min-height:var(--tap-size); font-size:15px; }
}

/* ——— Плитки на мобиле ——— */
@media (max-width: 980px){
  .card > .table-wrap{ overflow:visible; }
  .table.table-processing{ display:block; border:0; min-width:0 !important; }
  .table.table-processing thead{ display:none; }
  .table.table-processing tbody{ display:grid; gap:12px; }

  .table.table-processing tbody tr{
    display:grid; grid-template-columns:1fr;
    background:#0f172a; border:1px solid #222; border-radius:12px; padding:12px;
    box-shadow:0 6px 18px rgba(0,0,0,.25);
  }
  .table.table-processing tbody tr > td{
    display:grid; grid-template-columns:auto 1fr;
    gap:8px; padding:6px 0; border:0; vertical-align:top;
  }
  .table.table-processing tbody tr > td::before{
    content:''; color:#8b93a7; font-size:12px; line-height:1.2; padding-top:4px; white-space:nowrap;
  }
  .table.table-processing tbody tr > td:nth-child(1)::before{ content:"ID"; }
  .table.table-processing tbody tr > td:nth-child(2)::before{ content:"PAN"; }
  .table.table-processing tbody tr > td:nth-child(3)::before{ content:"Источник (Команда → Баер)"; }
  .table.table-processing tbody tr > td:nth-child(4)::before{ content:"Баланс при переносе"; }
  .table.table-processing tbody tr > td:nth-child(5)::before{ content:"Сумма оформления"; }
  .table.table-processing tbody tr > td:nth-child(6)::before{ content:"Выписки"; }
  .table.table-processing tbody tr > td:nth-child(7)::before{ content:"Комментарий"; }
  .table.table-processing tbody tr > td:nth-child(8)::before{ content:"Действия"; }

  /* инпуты/кнопки на всю ширину в карточке */
  .form-row input[type="number"],
  .form-row input[type="file"],
  .form-row textarea,
  .form-row .btn{ width:100%; }
  .table-actions .btn{ width:100%; }

  .table-actions .form-row{ width:100%; }
}

/* средние ширины — липкий заголовок */
@media (min-width:981px) and (max-width:1200px){
  .table.table-processing thead th{ position:sticky; top:0; background:#0b1220; z-index:1; }
}
</style>

<div class="card">
  <div class="table-wrap">
    <table class="table table-processing">
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
        $src = null;
        if (!empty($c['processing_source_buyer_id'])) {
          $src = db_row("SELECT b.name buyer_name, t.name team_name FROM buyers b LEFT JOIN teams t ON t.id=b.team_id WHERE b.id=?",[(int)$c['processing_source_buyer_id']]);
        }
        $srcLabel = $src ? ($src['team_name'].' → '.$src['buyer_name']) : '—';
        $files=db_exec("SELECT * FROM card_statements WHERE card_id=? ORDER BY id DESC",[$c['id']])->fetchAll();
        $last4=card_last4_from_row($c);
      ?>
        <tr>
          <td class="num"><?= (int)$c['id'] ?></td>
          <td class="td-mono"><?= mask_pan_last4($last4) ?></td>
          <td><?= h($srcLabel) ?></td>
          <td class="num"><?= money_uah($c['balance_at_processing_uah'] ?? 0) ?></td>

          <td class="num">
            <form method="post" class="form-row" autocomplete="off" aria-label="Сохранить сумму оформления для карты #<?= (int)$c['id'] ?>">
              <?= csrf_field(); ?>
              <input type="hidden" name="card_id" value="<?= (int)$c['id'] ?>">
              <input type="number" step="0.01" name="setup_amount_uah" value="<?= h($c['setup_amount_uah']??'') ?>" style="width:140px" inputmode="decimal" placeholder="0.00">
              <button class="btn" name="set_setup_sum" value="1" type="submit">Сохранить</button>
            </form>
          </td>

          <td>
            <form method="post" enctype="multipart/form-data" class="form-row" aria-label="Прикрепить выписку к карте #<?= (int)$c['id'] ?>">
              <?= csrf_field(); ?>
              <input type="hidden" name="card_id" value="<?= (int)$c['id'] ?>">
              <input type="file" name="statement" accept=".pdf,.xlsx,.xls">
              <button class="btn" name="attach_statement" value="1" type="submit">Прикрепить</button>
            </form>
            <?php if ($files): ?>
              <div class="files">
                <?php foreach($files as $f): ?>
                  <a href="/admin/statement.php?id=<?= (int)$f['id'] ?>" title="Открыть файл"><?= h($f['file_name_orig']) ?></a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </td>

          <td>
            <form method="post" autocomplete="off" aria-label="Сохранить комментарий по карте #<?= (int)$c['id'] ?>">
              <?= csrf_field(); ?>
              <input type="hidden" name="card_id" value="<?= (int)$c['id'] ?>">
              <textarea name="comment" rows="2"><?= h($c['comment']??'') ?></textarea>
              <div class="form-row" style="margin-top:6px">
                <button class="btn" name="save_comment" value="1" type="submit">Сохранить</button>
              </div>
            </form>
          </td>

          <td class="table-actions">
            <form method="post" class="form-row" aria-label="Действия по карте #<?= (int)$c['id'] ?>">
              <?= csrf_field(); ?>
              <input type="hidden" name="card_id" value="<?= (int)$c['id'] ?>">
              <button class="btn" name="to_await" value="1" onclick="return confirm('Переместить в «Ожидает возврат»?')" type="submit">В Ожидает возврат</button>
              <button class="btn btn-danger" name="delete_card" value="1" onclick="return confirm('Переместить в архив?')" type="submit">В архив</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($cards)): ?>
        <tr><td colspan="8" class="muted">Нет карт в статусе «На оформлении»</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__.'/_layout_footer.php'; ?>
