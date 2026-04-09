(function () {
    if (window.__appUnifiedNavbarReady) {
        return;
    }
    window.__appUnifiedNavbarReady = true;

    function closeAllDropdowns() {
        var dropdowns = document.querySelectorAll('.top-nav .dropdown');
        dropdowns.forEach(function (dropdown) {
            dropdown.classList.remove('show');
            var toggle = dropdown.querySelector('.dropdown-toggle');
            var menu = dropdown.querySelector('.dropdown-menu');
            if (toggle) {
                toggle.setAttribute('aria-expanded', 'false');
            }
            if (menu) {
                menu.classList.remove('show');
                menu.style.display = '';
            }
        });
    }

    function bindFallbackDropdowns() {
        var toggles = document.querySelectorAll('.top-nav .dropdown-toggle');
        toggles.forEach(function (toggle) {
            if (toggle.dataset.appFallbackBound === '1') {
                return;
            }
            toggle.dataset.appFallbackBound = '1';

            toggle.addEventListener('click', function (event) {
                if (window.bootstrap && window.bootstrap.Dropdown) {
                    return;
                }

                var dropdown = toggle.closest('.dropdown');
                var menu = dropdown ? dropdown.querySelector('.dropdown-menu') : null;
                if (!dropdown || !menu) {
                    return;
                }

                event.preventDefault();
                var isOpen = dropdown.classList.contains('show');
                closeAllDropdowns();

                if (!isOpen) {
                    dropdown.classList.add('show');
                    toggle.setAttribute('aria-expanded', 'true');
                    menu.classList.add('show');
                    menu.style.display = 'block';
                }
            });
        });

        if (!document.body.dataset.appFallbackDocBound) {
            document.body.dataset.appFallbackDocBound = '1';
            document.addEventListener('click', function (event) {
                if (!event.target.closest('.top-nav .dropdown')) {
                    closeAllDropdowns();
                }
            });
        }
    }

    function initBootstrapDropdowns() {
        if (!window.bootstrap || !window.bootstrap.Dropdown) {
            return false;
        }

        var toggles = document.querySelectorAll('.top-nav .dropdown-toggle');
        toggles.forEach(function (toggle) {
            window.bootstrap.Dropdown.getOrCreateInstance(toggle);
        });
        return true;
    }

    function ensureDropdownReady() {
        if (initBootstrapDropdowns()) {
            return;
        }

        bindFallbackDropdowns();

        var existing = document.querySelector('script[src="/assets/js/bootstrap.bundle.min.js"]');
        if (existing) {
            existing.addEventListener('load', initBootstrapDropdowns, { once: true });
            return;
        }

        var script = document.createElement('script');
        script.src = '/assets/js/bootstrap.bundle.min.js';
        script.defer = true;
        script.onload = initBootstrapDropdowns;
        document.head.appendChild(script);
    }

    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            window.location.reload();
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ensureDropdownReady);
    } else {
        ensureDropdownReady();
    }
})();
