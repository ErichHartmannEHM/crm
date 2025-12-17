<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

/**
 * Lightweight flash messaging helpers.
 * Usage:
 *   set_flash('success', 'Saved!');
 *   set_flash('error', 'Something went wrong');
 *   // In layout or page: include _flash.php to render & consume messages.
 */

// Define setters if not already present
if (!function_exists('set_flash')) {
    function set_flash(string $type, string $message): void {
        if (!isset($_SESSION['__flash']) || !is_array($_SESSION['__flash'])) {
            $_SESSION['__flash'] = [];
        }
        $_SESSION['__flash'][] = ['type' => $type, 'message' => $message];
    }
}

// Fetch & clear messages
if (!function_exists('flash_messages')) {
    function flash_messages(): array {
        $msgs = $_SESSION['__flash'] ?? [];
        unset($_SESSION['__flash']);
        return is_array($msgs) ? $msgs : [];
    }
}

$__msgs = flash_messages();
if (!$__msgs) { return; }

// нормализуем типы
function __flash_class(string $t): string {
    $t = strtolower(trim($t));
    return match ($t) {
        'ok' => 'success',
        'warn', 'warning' => 'warning',
        'error', 'danger', 'fail' => 'error',
        'info', 'notice' => 'info',
        'success' => 'success',
        default => 'info',
    };
}

// роль для доступности
function __flash_role(string $cls): string {
    return $cls === 'error' ? 'alert' : 'status';
}
?>
<style>
/* ---- Flash (scoped) ---- */
.flash-wrap { margin: 12px 0; display: flex; flex-direction: column; gap: 8px; }
.flash-item {
  position: relative;
  display: grid;
  grid-template-columns: auto 1fr auto;
  gap: 10px;
  align-items: start;
  padding: 10px 12px;
  border: 1px solid #273043;
  background: #0f172a;
  color: #e5e7eb;
  border-radius: 10px;
  box-shadow: 0 6px 18px rgba(0,0,0,.25);
  transition: opacity .2s ease, transform .2s ease;
}
.flash-item::before {
  content: '';
  position: absolute; left: 0; top: 0; bottom: 0;
  width: 3px; border-top-left-radius: 10px; border-bottom-left-radius: 10px;
  background: #0ea5e9; /* default info */
}
.flash-icon { width: 18px; height: 18px; margin-top: 2px; color: currentColor; opacity: .9; }
.flash-text { line-height: 1.45; }
.flash-close {
  appearance: none; -webkit-appearance: none;
  border: 1px solid #2a3342; background: #0b1220; color: #cbd5e1;
  width: 28px; height: 28px; border-radius: 8px;
  display: inline-flex; align-items: center; justify-content: center;
  cursor: pointer;
}
.flash-close:hover { filter: brightness(1.1); }
.flash-hide { opacity: 0; transform: translateY(-4px); }

/* цветовые варианты */
.flash-item.flash-success { border-color:#14532d; background:#052e1a; color:#d1fae5; }
.flash-item.flash-success::before { background:#22c55e; }

.flash-item.flash-error   { border-color:#7f1d1d; background:#2a0c0c; color:#fee2e2; }
.flash-item.flash-error::before { background:#ef4444; }

.flash-item.flash-warning { border-color:#4d3b0a; background:#221a08; color:#fde68a; }
.flash-item.flash-warning::before { background:#eab308; }

.flash-item.flash-info    { border-color:#1f3145; background:#0b1623; color:#bae6fd; }
.flash-item.flash-info::before { background:#0ea5e9; }

/* мобильность */
@media (max-width: 600px){
  .flash-item { grid-template-columns: auto 1fr auto; }
  .flash-close { width: 36px; height: 36px; }
}
</style>

<div class="flash-wrap" id="flashWrap" aria-live="polite">
  <?php foreach ($__msgs as $m):
      $type = htmlspecialchars($m['type'] ?? 'info', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $msg  = htmlspecialchars($m['message'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $cls  = __flash_class($type);
      $role = __flash_role($cls);
      // авто-скрытие: ошибки не скрываем, остальное — 5с
      $ttl  = ($cls === 'error') ? 0 : 5000;
  ?>
  <div class="flash-item flash-<?= $cls ?>" role="<?= $role ?>" data-ttl="<?= (int)$ttl ?>">
    <span class="flash-icon" aria-hidden="true">
      <?php if ($cls === 'success'): ?>
        <!-- check -->
        <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="m9.55 17.1-4.2-4.2 1.4-1.4 2.8 2.8 7.3-7.3 1.4 1.4-8.7 8.7z"/></svg>
      <?php elseif ($cls === 'error'): ?>
        <!-- x -->
        <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="m7.05 6 4.95 4.95L16.95 6 18 7.05 13.05 12 18 16.95 16.95 18 12 13.05 7.05 18 6 16.95 10.95 12 6 7.05z"/></svg>
      <?php elseif ($cls === 'warning'): ?>
        <!-- warning -->
        <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M1 21h22L12 2 1 21zm12-3h-2v2h2v-2zm0-6h-2v5h2v-5z"/></svg>
      <?php else: ?>
        <!-- info -->
        <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M11 9h2V7h-2v2zm0 8h2v-6h-2v6zm1-16C6.48 1 2 5.48 2 11s4.48 10 10 10 10-4.48 10-10S17.52 1 12 1z"/></svg>
      <?php endif; ?>
    </span>
    <span class="flash-text"><?= $msg ?></span>
    <button class="flash-close" type="button" aria-label="Закрыть уведомление">×</button>
  </div>
  <?php endforeach; ?>
</div>

<script>
(function(){
  const wrap = document.getElementById('flashWrap');
  if (!wrap) return;

  wrap.querySelectorAll('.flash-item').forEach(function(el){
    const ttl = parseInt(el.getAttribute('data-ttl') || '0', 10);
    const closeBtn = el.querySelector('.flash-close');
    const close = () => { el.classList.add('flash-hide'); setTimeout(()=> el.remove(), 200); };

    if (closeBtn) closeBtn.addEventListener('click', close);
    // авто-скрытие (только для info/success/warning)
    if (ttl > 0) setTimeout(close, ttl);
  });
})();
</script>
