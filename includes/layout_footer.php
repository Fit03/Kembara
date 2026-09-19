<?php
/**
 * layout_footer.php
 * Centralized footer and global client-side scripts
 */
?>
        <footer class="pt-6 pb-2">
          <div class="text-sm leading-normal text-center text-slate-400">
            © <?= date('Y') ?> Kembara &middot; Perbendaharaan Negeri Selangor
          </div>
        </footer>
      </div>
    </main>

    <!-- Skrip Tukar Mod Tema Terang/Gelap -->
    <script>
        const themeToggleBtn = document.getElementById('theme-toggle');
        const themeToggleIcon = document.getElementById('theme-toggle-icon');

        // The two daisyUI themes this dashboard switches between
        const LIGHT_THEME = 'garden';
        const DARK_THEME  = 'dracula';

        const moonIcon = `<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" /></svg>`;
        const sunIcon = `<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" /></svg>`;

        function applyTheme(theme) {
            document.documentElement.setAttribute('data-theme', theme);
            localStorage.setItem('theme', theme);
            if (themeToggleIcon) {
                themeToggleIcon.innerHTML = theme === DARK_THEME ? sunIcon : moonIcon;
            }

            // Dynamic update for ApexCharts title and legend colors if they exist on the page
            const textColor = getComputedStyle(document.documentElement).getPropertyValue('--color-base-content').trim();

            if (typeof bookingChart !== 'undefined' && typeof vehicleChart !== 'undefined') {
                bookingChart.updateOptions({
                    title: { style: { color: textColor } },
                    legend: { labels: { colors: textColor } },
                    plotOptions: {
                        pie: {
                            donut: {
                                labels: {
                                    total: { color: textColor },
                                    value: { color: textColor }
                                }
                            }
                        }
                    }
                });
                vehicleChart.updateOptions({
                    title: { style: { color: textColor } },
                    legend: { labels: { colors: textColor } },
                    plotOptions: {
                        pie: {
                            donut: {
                                labels: {
                                    total: { color: textColor },
                                    value: { color: textColor }
                                }
                            }
                        }
                    }
                });
            }
        }

        const savedTheme = localStorage.getItem('theme');
        const systemPrefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        const initialTheme = savedTheme || (systemPrefersDark ? DARK_THEME : LIGHT_THEME);

        applyTheme(initialTheme);

        if (themeToggleBtn) {
            themeToggleBtn.addEventListener('click', () => {
                const currentTheme = document.documentElement.getAttribute('data-theme');
                const newTheme = currentTheme === DARK_THEME ? LIGHT_THEME : DARK_THEME;
                applyTheme(newTheme);
            });
        }
    </script>

    <script src="./assets/js/plugins/perfect-scrollbar.min.js" async></script>
    <script>
    (function () {
        function makeClickable(el, clickableClass) {
            if (clickableClass) el.classList.add(clickableClass);
            el.addEventListener('click', function (event) {
                if (event.target.closest('a, button, input, select, textarea')) return;
                window.location.href = el.dataset.href;
            });
            el.setAttribute('tabindex', '0');
            el.addEventListener('keypress', function (event) {
                if (event.key === 'Enter') {
                    el.click();
                }
            });
        }

        document.querySelectorAll('.card[data-href]').forEach(function (card) {
            makeClickable(card, 'clickable');
        });

        document.querySelectorAll('tr[data-href]').forEach(function (row) {
            makeClickable(row);
        });
    })();
    </script>

    <script>
      document.addEventListener('DOMContentLoaded', () => {
        const currentPath = window.location.pathname.split('/').pop() || 'dashboard.php';
        let activeItem = null;

        if (currentPath.includes('dashboard')) activeItem = document.getElementById('nav-dashboard');
        else if (currentPath.includes('bookings') || currentPath.includes('book-vehicle')) activeItem = document.getElementById('nav-bookings');
        else if (currentPath.includes('vehicles')) activeItem = document.getElementById('nav-vehicles');
        else if (currentPath.includes('drivers')) activeItem = document.getElementById('nav-drivers');
        else if (currentPath.includes('users') || currentPath.includes('activity_log')) activeItem = document.getElementById('nav-more');

        function setFloatingActive(element) {
          if (!element) return;

          document.querySelectorAll('.nav-item').forEach(el => {
            el.style.color = 'var(--ta-muted)';
            const svg = el.querySelector('svg');
            if (svg) svg.style.transform = 'translateY(0px)';
          });

          if (element.id === 'nav-dashboard') {
            const label = element.querySelector('span:last-child');
            if (label) label.style.color = 'var(--ta-brand, #007AFF)';
            return;
          }

          element.style.color = 'var(--ta-brand, #007AFF)';
          const svg = element.querySelector('svg');
          if (svg) svg.style.transform = 'translateY(-2px)';
        }

        if (activeItem) setFloatingActive(activeItem);
        window.addEventListener('resize', () => { if (activeItem) setFloatingActive(activeItem); });

        const moreBtn = document.getElementById('nav-more');
        const moreMenu = document.getElementById('more-menu');
        if (moreBtn && moreMenu) {
          const toggleMenu = (open) => {
            const isOpen = open !== undefined ? open : !moreMenu.classList.contains('open');
            moreMenu.classList.toggle('open', isOpen);
            moreBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
          };
          moreBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            toggleMenu();
          });
          moreBtn.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
              e.preventDefault();
              toggleMenu();
            } else if (e.key === 'Escape') {
              toggleMenu(false);
            }
          });
          document.addEventListener('click', (e) => {
            if (!moreBtn.contains(e.target)) toggleMenu(false);
          });
        }
      });
    </script>

    <?php if (isset($extraJS)): ?>
        <?= $extraJS ?>
    <?php endif; ?>
</body>
</html>
