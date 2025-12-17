<?php
require_once __DIR__.'/../lib/auth.php';
$title = 'Лог действий'; $active = 'audit';
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/helpers.php';
@require_once __DIR__.'/../lib/log.php'; // на случай bootstrap() внутри

auth_require(); auth_require_admin();

/* ---------- helpers: информация о схеме ----------- */
function tbl_exists(string $t): bool {
  try {
    return (int)db_exec(
      "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?",
      [$t]
    )->fetchColumn() > 0;
  } catch (Throwable $e) { return false; }
}
function col_exists(string $t, string $c): bool {
  try {
    return (int)db_exec(
      "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?",
      [$t,$c]
    )->fetchColumn() > 0;
  } catch (Throwable $e) { return false; }
}

/* ---------- определить таблицу и колонки ---------- */
$tbl = null;
if (tbl_exists('audit_logs')) {
  $tbl = 'audit_logs';
} elseif (tbl_exists('audit_log')) {
  $tbl = 'audit_log';
}

$rows = [];
$err  = null;

if ($tbl) {
  // подобрать имена колонок с безопасными алиасами
  $col_id   = col_exists($tbl,'id') ? 'id' : null;
  $col_dt   = col_exists($tbl,'created_at') ? 'created_at' : (col_exists($tbl,'ts') ? 'ts' : (col_exists($tbl,'created') ? 'created' : null));
  $col_ent  = col_exists($tbl,'entity_type') ? 'entity_type' : (col_exists($tbl,'entity') ? 'entity' : null);
  $col_act  = col_exists($tbl,'action') ? 'action' : (col_exists($tbl,'act') ? 'act' : null);
  $col_eid  = col_exists($tbl,'entity_id') ? 'entity_id' : (col_exists($tbl,'target_id') ? 'target_id' : null);
  $col_uid  = col_exists($tbl,'user_id') ? 'user_id' : null;
  $col_pay  = col_exists($tbl,'payload_json') ? 'payload_json' : (col_exists($tbl,'payload') ? 'payload' : null);

  // собрать SELECT c алиасами
  $select = [];
  $select[] = $col_id ? "`$col_id` AS id" : "NULL AS id";
  $select[] = $col_dt ? "`$col_dt` AS created_at" : "NULL AS created_at";
  $select[] = $col_ent ? "`$col_ent` AS entity_type" : "NULL AS entity_type";
  $select[] = $col_act ? "`$col_act` AS action" : "NULL AS action";
  $select[] = $col_eid ? "`$col_eid` AS entity_id" : "NULL AS entity_id";
  $select[] = $col_uid ? "`$col_uid` AS user_id" : "NULL AS user_id";
  $select[] = $col_pay ? "`$col_pay` AS payload_raw" : "NULL AS payload_raw";

  $sql = "SELECT ".implode(", ", $select)." FROM `$tbl` ORDER BY ".($col_id ? "`$col_id`" : ($col_dt ? "`$col_dt`" : "1"))." DESC LIMIT 500";

  try {
    $rows = db_exec($sql)->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
} else {
  $err = "Таблица аудита не найдена (ни audit_logs, ни audit_log).";
}

/* ---------- prettify payload ---------- */
function pretty_payload(?string $raw): string {
  if ($raw === null) return '';
  $raw = trim($raw);
  // пробуем распарсить JSON
  $j = json_decode($raw, true);
  if (json_last_error() === JSON_ERROR_NONE) {
    return json_encode($j, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
  }
  return $raw; // не JSON — показываем как есть
}

require __DIR__.'/_layout.php';
?>
<div class="card">
  <div class="card-body">
    <h2 style="margin:0 0 12px 0">Лог действий</h2>
    <?php if ($err): ?>
      <div class="alert alert-danger"><?= h($err) ?></div>
    <?php endif; ?>
    <div class="table-wrap">
      <table class="table table-borders">
        <thead>
          <tr>
            <th class="num">ID</th>
            <th>Дата</th>
            <th>Польз.</th>
            <th>Сущность</th>
            <th>Действие</th>
            <th>entity_id</th>
            <th>Payload</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($rows): foreach ($rows as $r): ?>
            <tr>
              <td class="num"><?= h($r['id']) ?></td>
              <td class="muted"><?= h($r['created_at']) ?></td>
              <td class="muted"><?= h($r['user_id'] ?? '') ?></td>
              <td><?= h($r['entity_type']) ?></td>
              <td><?= h($r['action']) ?></td>
              <td><?= h($r['entity_id']) ?></td>
              <td><pre style="white-space:pre-wrap;max-width:520px"><?= h(pretty_payload($r['payload_raw'] ?? '')) ?></pre></td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="7" class="muted">Пока записей нет.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <p class="muted" style="margin-top:8px">
      Источник: <?= h($tbl ?: '—') ?> (колонки автоматически определены).
    </p>
  </div>
</div>
<?php include __DIR__.'/_layout_footer.php'; ?>
