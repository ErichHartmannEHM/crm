<?php
require_once __DIR__ . '/bootstrap.php';
$title='Сканер заявок'; $active='scanner';
require __DIR__.'/../_layout.php';
?>
<section class="panel" style="max-width:1280px;">
  <h1 class="h1">Сканер заявок</h1>
  <div class="tabs">
    <button type="button" class="tablink active" data-tab="requests">Заявки</button>
    <button type="button" class="tablink" data-tab="proxies">Прокси</button>
  </div>

  <section id="tab-requests" class="tab active">
    <div class="panel">
      <h2>Импорт заявок</h2>
      <form id="importForm" class="form-compact">
        <?= csrf_field(); ?>
        <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-start;">
          <textarea id="ids" rows="4" placeholder="Вставьте ID через пробел/запятую/перенос строки" style="flex:1 1 520px;"></textarea>
          <div style="display:flex; flex-direction:column; gap:8px;">
            <label>Назначить сотруднику:<br><select id="worker"></select></label>
            <div style="display:flex; gap:8px; align-items:center;">
              <button type="button" id="btnImport" class="btn btn-primary">Загрузить</button>
              <a id="btnExport" href="#" class="btn" role="button">Экспорт CSV</a>
            </div>
          </div>
        </div>
        <div id="importMsg" class="text-muted" style="margin-top:6px;"></div>
      </form>
    </div>

    <div class="panel">
      <h2>Фильтры</h2>
      <div class="form-compact" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <label>Сотрудник:<br><select id="fWorker"></select></label>
        <label>Статус:<br>
          <select id="fStatus">
            <option value="">— любой —</option>
          </select>
        </label>
        <button type="button" id="btnReload" class="btn">Обновить</button>
        <button type="button" id="btnScanAll" class="btn btn-primary">Проверить сейчас</button>
      </div>
    </div>

    <div class="panel">
      <h2>Актуальные заявки</h2>
      <div class="counters">
        <div id="cntStatus"></div>
        <div id="cntWorker"></div>
      </div>
      <div class="table-wrap">
        <table id="tbl" class="table">
          <thead>
            <tr>
              <th><input type="checkbox" id="selAll"></th>
              <th>ID</th>
              <th>Сотрудник</th>
              <th>Статус</th>
              <th>Комментарий</th>
              <th>Дата/время</th>
              <th>Действия</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
      <div class="form-compact" style="display:flex; gap:8px; align-items:center;">
        <label style="margin-right:6px;">Перекинуть выбранные на:</label>
        <select id="moveTo"></select>
        <button type="button" id="btnMove" class="btn">Перенести</button>
        <span id="moveMsg" class="text-muted"></span>
      </div>
    </div>
  </section>

  <section id="tab-proxies" class="tab">
    <div class="panel">
      <h2>Прокси (1 IP ↔ 1 сотрудник)</h2>
      <div class="form-compact" style="display:flex; gap:12px; flex-wrap:wrap;">
        <label>Название<br><input type="text" id="px_title" placeholder="UA Mobile #1"></label>
        <label>Прокси URL<br><input type="text" id="px_proxy" placeholder="http://user:pass@host:port"></label>
        <label>Refresh URL<br><input type="text" id="px_refresh" placeholder="https://provider/rotate?token=..."></label>
        <label>Сотрудник<br><select id="px_worker"></select></label>
        <label>Лимит<br><input type="number" id="px_limit" value="10" min="1"></label>
        <label>Ожидание, сек<br><input type="number" id="px_wait" value="20" min="0"></label>
        <label>Активен<br>
          <select id="px_active"><option value="1">Да</option><option value="0">Нет</option></select>
        </label>
        <div style="display:flex; align-items:end;"><button type="button" id="px_save" class="btn btn-primary">Сохранить/Обновить</button>
        <input type="hidden" id="px_id"></div>
      </div>
    </div>
    <div class="panel">
      <h2>Список прокси</h2>
      <div class="table-wrap">
        <table id="tblProxies" class="table">
          <thead><tr><th>ID</th><th>Название</th><th>Прокси</th><th>Сотрудник</th><th>Лимит</th><th>Wait</th><th>Активен</th><th>Действия</th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </section>
</section>
<link rel="stylesheet" href="/admin/scanner/styles.css">
<script src="/admin/scanner/scanner.js"></script>
<?php include __DIR__.'/../_layout_footer.php'; ?>
