<?php
/**
 * Admin block embedded into /admin/telegram.php
 * - GET-only actions (save/dry/force)
 * - Uses settings(k,val), mirrors to settings(key,value) if that schema exists
 */
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/settings.php';
require_once __DIR__.'/../lib/tg_alerts.php';

function _safe_bool($v){ if ($v===null) return false; return filter_var($v, FILTER_VALIDATE_BOOLEAN) ? true : (bool)$v; }
function _keyvalue_schema_exists(): bool {
    try {
        return (int)db_col("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='settings' AND column_name='key'") > 0;
    } catch (Throwable $e) { return false; }
}
function _kv_put(string $k, $v): void {
    try {
        if (!_keyvalue_schema_exists()) return;
        db_exec("INSERT INTO `settings`(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)", [$k, (string)$v]);
    } catch (Throwable $e) {}
}

$action = isset($_GET['tg_action']) ? (string)$_GET['tg_action'] : '';

if ($action === 'save') {
    // Read inputs
    $enabled_low_balance   = _safe_bool($_GET['enabled_low_balance'] ?? null);
    $threshold_low_balance = (int)($_GET['threshold_low_balance'] ?? 15000);
    $template_low_balance  = (string)($_GET['template_low_balance'] ?? '');

    $enabled_low_limit     = _safe_bool($_GET['enabled_low_limit'] ?? null);
    $threshold_low_limit   = (int)($_GET['threshold_low_limit'] ?? 15000);
    $template_low_limit    = (string)($_GET['template_low_limit'] ?? '');

    $enabled_morning       = _safe_bool($_GET['enabled_morning'] ?? null);
    $morning_time          = (string)($_GET['morning_time'] ?? '09:00');
    $template_morning      = (string)($_GET['template_morning'] ?? '');

    $tz                    = (string)($_GET['tz'] ?? (@date_default_timezone_get() ?: 'Europe/Kyiv'));

    // Save via settings(k,val)
    settings_bootstrap();
    setting_set('tg.enabled_low_balance',  $enabled_low_balance ? '1' : '0');
    setting_set('tg.threshold_low_balance',(string)$threshold_low_balance);
    setting_set('tg.template_low_balance', $template_low_balance);

    setting_set('tg.enabled_low_limit',    $enabled_low_limit ? '1' : '0');
    setting_set('tg.threshold_low_limit',  (string)$threshold_low_limit);
    setting_set('tg.template_low_limit',   $template_low_limit);

    setting_set('tg.enabled_morning',      $enabled_morning ? '1' : '0');
    setting_set('tg.morning_time',         $morning_time);
    setting_set('tg.template_morning',     $template_morning);

    setting_set('tg.tz',                   $tz);

    // Mirror to settings(key,value) if present
    _kv_put('tg.enabled_low_balance',  $enabled_low_balance ? '1' : '0');
    _kv_put('tg.threshold_low_balance',(string)$threshold_low_balance);
    _kv_put('tg.template_low_balance', $template_low_balance);

    _kv_put('tg.enabled_low_limit',    $enabled_low_limit ? '1' : '0');
    _kv_put('tg.threshold_low_limit',  (string)$threshold_low_limit);
    _kv_put('tg.template_low_limit',   $template_low_limit);

    _kv_put('tg.enabled_morning',      $enabled_morning ? '1' : '0');
    _kv_put('tg.morning_time',         $morning_time);
    _kv_put('tg.template_morning',     $template_morning);

    _kv_put('tg.tz',                   $tz);

    echo '<div class="alert success">Настройки сохранены</div>';
}

// Load config to render forms
$cfg = tg_alerts_load_config();

// Helpers for values/checked
$val = function(string $k, $def='') use ($cfg){
    $v = $cfg[$k] ?? $def; return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$checked = function(string $k) use ($cfg){
    $v = !empty($cfg[$k]); return $v ? 'checked' : '';
};

$action = isset($_GET['tg_action']) ? (string)$_GET['tg_action'] : '';

?>
<style>
  .tg-grid { display:grid; grid-template-columns: 1fr 1fr; gap:16px; }
  .tg-card { border:1px solid #e5e7eb; border-radius:8px; padding:16px; background:#fff; }
  .tg-card h3 { margin:0 0 10px; font-size:16px; }
  .tg-form .form-control { width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; }
  .tg-form textarea.form-control { min-height:110px; font-family: monospace; white-space: pre-wrap; }
  .btn { display:inline-block; padding:8px 12px; border-radius:6px; text-decoration:none; cursor:pointer; border:1px solid #d1d5db; }
  .btn-primary { background:#2563eb; color:#fff; border-color:#2563eb; }
  .btn-secondary { background:#f3f4f6; }
  .btn-danger { background:#dc2626; color:#fff; border-color:#dc2626; }
  .btn-outline { background:#fff; }
  .alert.success { margin:8px 0 16px; padding:10px 12px; background:#ecfdf5; border:1px solid #34d399; border-radius:6px; color:#065f46; }
  .form-grid { display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
  .help { color:#6b7280; font-size:12px; margin-top:4px; }
  @media (max-width: 900px){ .tg-grid{grid-template-columns: 1fr;} .form-grid{grid-template-columns:1fr;} }
</style>

<div class="tg-grid tg-form">

  <div class="tg-card">
    <h3>Баланс &lt; порога</h3>
    <form method="get">
      <input type="hidden" name="tg_action" value="save">
      <label><input type="checkbox" name="enabled_low_balance" value="1" <?= $checked('enabled_low_balance') ?>> Включено</label>
      <div style="margin-top:8px;">
        <label>Порог, грн</label>
        <input type="number" class="form-control" name="threshold_low_balance" value="<?= $val('threshold_low_balance',15000) ?>" placeholder="15000">
      </div>
      <div style="margin-top:8px;">
        <label>Шаблон сообщения</label>
        <textarea class="form-control" name="template_low_balance"><?= $val('template_low_balance') ?></textarea>
        <div class="help">Плейсхолдеры: {bank}, {last4}, {threshold}, {balance}, {limit_remaining}, {tg_nick}</div>
      </div>
      <div style="margin-top:12px;">
        <button class="btn btn-primary">Сохранить</button>
        <a class="btn btn-secondary" href="?tg_action=dry">Проверить сейчас (dry‑run)</a>
        <a class="btn btn-danger" href="?tg_action=force">Отправить сейчас (форс)</a>
        <a class="btn btn-outline" href="/cron/telegram_alerts.php?type=low_balance&dry=1&debug=1" target="_blank">Диагностика JSON</a>
      </div>
    </form>
  </div>

  <div class="tg-card">
    <h3>Остаток лимита &lt; порога</h3>
    <form method="get">
      <input type="hidden" name="tg_action" value="save">
      <label><input type="checkbox" name="enabled_low_limit" value="1" <?= $checked('enabled_low_limit') ?>> Включено</label>
      <div style="margin-top:8px;">
        <label>Порог, грн</label>
        <input type="number" class="form-control" name="threshold_low_limit" value="<?= $val('threshold_low_limit',15000) ?>" placeholder="15000">
      </div>
      <div style="margin-top:8px;">
        <label>Шаблон сообщения</label>
        <textarea class="form-control" name="template_low_limit"><?= $val('template_low_limit') ?></textarea>
        <div class="help">Плейсхолдеры: {bank}, {last4}, {threshold}, {balance}, {limit_remaining}, {tg_nick}</div>
      </div>
      <div style="margin-top:12px;">
        <button class="btn btn-primary">Сохранить</button>
        <a class="btn btn-secondary" href="?tg_action=dry">Проверить сейчас (dry‑run)</a>
        <a class="btn btn-danger" href="?tg_action=force">Отправить сейчас (форс)</a>
        <a class="btn btn-outline" href="/cron/telegram_alerts.php?type=low_limit&dry=1&debug=1" target="_blank">Диагностика JSON</a>
      </div>
    </form>
  </div>

  <div class="tg-card" style="grid-column:1 / span 2;">
    <h3>Ежедневное напоминание для карт со статусом <code>in_work</code></h3>
    <form method="get">
      <input type="hidden" name="tg_action" value="save">
      <div class="form-grid">
        <div>
          <label><input type="checkbox" name="enabled_morning" value="1" <?= $checked('enabled_morning') ?>> Включено</label>
        </div>
        <div>
          <label>Время отправки (по серверу)</label>
          <input type="time" class="form-control" name="morning_time" value="<?= $val('morning_time','09:00') ?>">
        </div>
      </div>
      <div style="margin-top:8px;">
        <label>Шаблон сообщения</label>
        <textarea class="form-control" name="template_morning"><?= $val('template_morning') ?></textarea>
        <div class="help">Плейсхолдеры: {cards}, {tg_nick}</div>
      </div>
      <div style="margin-top:8px;">
        <label>Часовой пояс</label>
        <input type="text" class="form-control" name="tz" value="<?= $val('tz', @date_default_timezone_get() ?: 'Europe/Kyiv') ?>" placeholder="Europe/Kyiv">
      </div>
      <div style="margin-top:12px;">
        <button class="btn btn-primary">Сохранить</button>
        <a class="btn btn-secondary" href="?tg_action=dry">Проверить сейчас (dry‑run)</a>
        <a class="btn btn-danger" href="?tg_action=force">Отправить сейчас (форс)</a>
        <a class="btn btn-outline" href="/cron/telegram_alerts.php?type=morning&dry=1&debug=1" target="_blank">Диагностика JSON</a>
      </div>
    </form>
  </div>

  <?php if ($action === 'dry' || $action === 'force') {
      $opts = ['dry' => ($action==='dry'), 'force' => ($action==='force')];
      $json_payload = tg_alerts_run_all($opts);
  ?>
    <div class="tg-card" style="grid-column:1 / span 2;">
      <h3>Результат</h3>
      <pre style="white-space:pre-wrap;max-height:420px;overflow:auto;"><?php
        echo htmlspecialchars(json_encode($json_payload, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      ?></pre>
    </div>
  <?php } ?>

</div>
