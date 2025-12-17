<?php
require_once __DIR__.'/../lib/auth.php';
require_once __DIR__.'/../lib/helpers.php';
require_once __DIR__.'/../lib/fx.php';
require_once __DIR__.'/../lib/settings.php';

auth_require();

if (!isset($title))  { $title  = ''; }
if (!isset($active)) { $active = ''; }

function nav_active($key, $active) { return $active === $key ? 'active" aria-current="page' : ''; }

// Smart link for "Выписки": try several filenames to avoid 404
$href_statement = '/admin/index.php';
if (file_exists(__DIR__.'/statement.php'))      $href_statement = '/admin/statement.php';
elseif (file_exists(__DIR__.'/statements.php')) $href_statement = '/admin/statements.php';
elseif (file_exists(__DIR__.'/statement/index.php')) $href_statement = '/admin/statement/index.php';
?><!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= h($title ?: 'Панель управления') ?></title>
  <link rel="stylesheet" href="/assets/app.css">
  <link rel="stylesheet" href="/assets/mobile.css" media="(max-width: 960px)">
  <script src="/assets/app.js" defer></script>
  <style>/* --- Ensure readable text colors on desktop --- */
body.dark{ background:var(--bg); color:var(--text) !important; }
@media (min-width: 961px){
  body.dark .content, body.dark .card, body.dark .tile, body.dark table, 
  body.dark th, body.dark td, body.dark .breadcrumbs, body.dark .segmented .seg {
    color: var(--text) !important;
  }
  body.dark .muted, body.dark .t-sub { color: var(--muted) !important; }
  body.dark input, body.dark select, body.dark textarea {
    background: var(--panel, #0f131a);
    color: var(--text) !important;
    border-color: #333;
  }
}
    :root{
      --bg:#0b1017; --panel:#0e1520; --panel-2:#101a27; --border:#223149;
      --text:#e5efff; --muted:#9db1c9; --accent:#6cb6ff;
    }
    body.dark{ background:var(--bg); color:var(--text); }
    .layout{ display:grid; grid-template-columns: 260px 1fr; min-height:100vh; }
    .sidebar{ background:linear-gradient(180deg,#0f1622,#0b111a); border-right:1px solid #1c2b44; position:relative; z-index:1001; }
    .sidebar .brand{ font-weight:800; letter-spacing:.3px; padding:16px 16px 10px; color:#cde0ff; }
    .sidebar .brand small{ display:block; color:#9db1c9; font-weight:600; margin-top:2px; }
    .sidebar ul{ list-style:none; margin:8px 0 16px; padding:0; }
    .sidebar a{ display:block; padding:10px 14px; margin:6px 12px; color:#cfe1ff; text-decoration:none; border-radius:12px; border:1px solid transparent; }
    .sidebar a:hover{ background:#111a29; border-color:#223149; }
    .sidebar a.active{ background:linear-gradient(180deg,#132032,#0e1622); border-color:#2a3a57; box-shadow: inset 0 1px 0 rgba(255,255,255,.04), 0 8px 18px rgba(0,0,0,.25); }
    .sidebar a.dashboard{ font-weight:700; }
    .content{ padding:22px; }

    /* mobile defaults (hidden) */
    .mobile-topbar{ display:none; }
    .backdrop{ display:none; }

    /* Mobile: show top bar + slide-in sidebar */
    @media (max-width: 960px){
      .layout{ grid-template-columns: 1fr; }
      .sidebar{
        position: fixed; left:0; top:0; bottom:0; width: 85vw; max-width: 320px;
        transform: translateX(-100%); transition: transform .25s ease;
        box-shadow: 10px 0 22px rgba(0,0,0,.45);
      }
      .sidebar.open{ transform: translateX(0); }
      .content{ padding: 70px 16px 18px; }
      .mobile-topbar{
        display:flex;
        position: fixed; left:0; right:0; top:0; height:56px; z-index: 1002;
        align-items:center; gap:12px;
        padding:0 12px; background: linear-gradient(180deg,#0f1622,#0a1017); border-bottom:1px solid #1b2840;
      }
      .mobile-topbar .hamb{ width:38px; height:38px; border-radius:10px; border:1px solid #223149; background:#0f1724; color:#cfe1ff; font-size:18px; }
      .mobile-topbar .ttl{ font-weight:800; letter-spacing:.2px; color:#e5efff; }
      .backdrop{
        display:none;
        position: fixed; inset:0; background: rgba(0,0,0,.45); z-index: 1000;
      }
      .backdrop.show{ display:block; }
    }
  </style>
</head>
<body class="dark">
  <div class="layout">
    <nav id="sidebar" class="sidebar" aria-label="Главное меню">
      <div class="brand">CARD Wallet<small>Admin</small></div>
      <ul role="list">
        <li><a href="/admin/index.php" class="dashboard <?= nav_active('dashboard', $active) ?>">⏰ Напоминалка</a></li>
        <li><a href="/admin/teams.php"           class="<?= nav_active('teams', $active) ?>">Команды</a></li>
        <li><a href="/admin/cards.php"           class="<?= nav_active('cards', $active) ?>">Карты</a></li>
        <li><a href="/admin/payments.php"        class="<?= nav_active('payments', $active) ?>">Оплаты/Пополнения</a></li>
        <li><a href="/admin/drops.php"           class="<?= nav_active('drops', $active) ?>">Аккаунты работников</a></li>
        <li><a href="/admin/scanner/index.php" class="<?= nav_active('scanner', $active) ?>">Сканер заявок</a></li>
        <li><a href="/admin/processing.php"      class="<?= nav_active('processing', $active) ?>">На оформлении</a></li>
        <li><a href="/admin/awaiting_refund.php" class="<?= nav_active('awaiting_refund', $active) ?>">Ожидают возврат</a></li>
                <li><a href="/admin/calculator.php"  class="<?= nav_active('calculator', $active) ?>">Калькулятор доходности</a></li>
        <li><a href="<?= h($href_statement) ?>"  class="<?= nav_active('statement', $active) ?>">Выписки</a></li>
        <li><a href="/admin/archive.php"         class="<?= nav_active('archive', $active) ?>">Архив</a></li>
        <li><a href="/admin/telegram.php"      class="<?= nav_active('telegram', $active) ?>">Telegram</a></li>
        <li><a href="/admin/clients.php"      class="<?= nav_active('clients', $active) ?>">Клиенты</a></li>
        <li><a href="/admin/settings.php"        class="<?= nav_active('settings', $active) ?>">Настройки</a></li>
      </ul>
    </nav>
    <div id="backdrop" class="backdrop" hidden></div>
    <header class="mobile-topbar" role="banner">
      <button id="btnMenu" class="hamb" aria-label="Открыть меню">☰</button>
      <div class="ttl">Меню</div>
    </header>
    <main class="content" role="main">
<script>
  (function(){
    var sidebar = document.getElementById('sidebar');
    var btn = document.getElementById('btnMenu');
    var backdrop = document.getElementById('backdrop');
    if (!sidebar || !btn || !backdrop) return;

    function openMenu(){
      sidebar.classList.add('open'); backdrop.classList.add('show'); backdrop.hidden = false;
      document.body.style.overflow='hidden';
    }
    function closeMenu(){
      sidebar.classList.remove('open'); backdrop.classList.remove('show'); backdrop.hidden = true;
      document.body.style.overflow='';
    }
    btn.addEventListener('click', function(){
      if (sidebar.classList.contains('open')) closeMenu(); else openMenu();
    });
    backdrop.addEventListener('click', closeMenu);
    sidebar.addEventListener('click', function(e){
      var a = e.target.closest('a'); if (a) closeMenu();
    });
    window.addEventListener('resize', function(){ if (window.innerWidth > 960) closeMenu(); });
  })();
</script>