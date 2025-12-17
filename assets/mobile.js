// /assets/mobile.js — мобильные улучшения (бургер-меню и UX)
// Версия: 1.1 (фикс: off-canvas только < 980px; авто-закрытие при переходе на десктоп)

(function () {
  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  document.addEventListener('DOMContentLoaded', () => {
    const layout  = $('.layout');
    const sidebar = $('.sidebar');
    const content = $('.content');
    if (!layout || !sidebar || !content) return;

    // Медиа-гейт: мобильный режим
    const mqMobile = window.matchMedia('(max-width: 979.98px)');

    // Создаём топбар на мобиле (один раз)
    if (!$('.mobile-topbar')) {
      const topbar = document.createElement('div');
      topbar.className = 'mobile-topbar';

      const burger = document.createElement('button');
      burger.className = 'burger';
      burger.type = 'button';
      burger.setAttribute('aria-label', 'Открыть меню');
      burger.innerHTML = '<span></span><span></span><span></span>';

      const titleEl = document.createElement('div');
      titleEl.className = 'topbar-title';
      const h1 = document.querySelector('h1');
      titleEl.textContent = h1 ? h1.textContent.trim() : 'CARD Wallet';

      topbar.appendChild(burger);
      topbar.appendChild(titleEl);
      document.body.prepend(topbar);

      // Оверлей под сайдбар (один раз)
      let overlay = $('.sidebar-overlay');
      if (!overlay) {
        overlay = document.createElement('div');
        overlay.className = 'sidebar-overlay';
        document.body.appendChild(overlay);
      }

      const openSidebar = () => {
        if (!mqMobile.matches) return; // только на мобиле
        sidebar.classList.add('open');
        overlay.classList.add('show');
        document.documentElement.style.overflow = 'hidden';
      };
      const closeSidebar = () => {
        sidebar.classList.remove('open');
        overlay.classList.remove('show');
        document.documentElement.style.overflow = '';
      };

      burger.addEventListener('click', openSidebar);
      overlay.addEventListener('click', closeSidebar);
      // Закрывать сайдбар при переходе по пунктам меню
      $$('.sidebar a').forEach(a => a.addEventListener('click', closeSidebar));

      // При переходе на десктоп — закрываем офф-канвас
      const onViewportChange = () => { if (!mqMobile.matches) closeSidebar(); };
      mqMobile.addEventListener ? mqMobile.addEventListener('change', onViewportChange)
                                : mqMobile.addListener(onViewportChange);

      // Удобство: в длинных формах скроллим к фокусу (только моб)
      document.addEventListener('focusin', (e) => {
        const el = e.target;
        if (!(el instanceof HTMLElement)) return;
        if (mqMobile.matches) {
          el.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
      }, { passive: true });
    }

    // Небольшой фикс: на /admin/cards.php прогресс-бары тянуть на всю ширину
    $$('.progress-box').forEach(b => { b.style.maxWidth = '100%'; });
  });
})();
