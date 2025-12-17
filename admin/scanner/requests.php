<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/classes/Db.php';
auth_require();
$title='Сканер заявок — диагностика заявок'; $active='scanner';
require __DIR__.'/../_layout.php';
$rows = Db::all("SELECT * FROM scanner_requests ORDER BY updated_at DESC, request_id");
?>
<section class="panel">
  <h2>Содержимое таблицы scanner_requests (<?=count($rows)?>)</h2>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>ID заявки</th><th>worker_id</th><th>last_status</th><th>last_dt</th><th>created_at</th><th>updated_at</th></tr></thead>
    <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?=htmlspecialchars($r['request_id'])?></td>
          <td><?=$r['worker_id']?></td>
          <td><?=htmlspecialchars($r['last_status']??'')?></td>
          <td><?=$r['last_dt']?></td>
          <td><?=$r['created_at']?></td>
          <td><?=$r['updated_at']?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<?php include __DIR__.'/../_layout_footer.php'; ?>
