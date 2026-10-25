<script>
@verbatim
(function () {
    'use strict';

    var d = document;
    var root = d.documentElement;
    var reduceMotion = !!(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches);
    var finePointer = !!(window.matchMedia && matchMedia('(hover: hover) and (pointer: fine)').matches);

    /* Header: gain a hairline once the page scrolls. */
    var header = d.querySelector('[data-header]');
    if (header) {
        var onScroll = function () {
            header.classList.toggle('is-scrolled', (window.pageYOffset || root.scrollTop) > 8);
        };
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
    }

    /* Mobile menu. */
    var toggle = d.querySelector('[data-menu-toggle]');
    var menu = d.getElementById('site-menu');
    if (toggle && menu) {
        var setMenu = function (open) {
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            menu.hidden = !open;
            root.classList.toggle('menu-open', open);
        };
        toggle.addEventListener('click', function () {
            setMenu(toggle.getAttribute('aria-expanded') !== 'true');
        });
        menu.addEventListener('click', function (e) {
            if (e.target.closest && e.target.closest('a')) { setMenu(false); }
        });
        d.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !menu.hidden) { setMenu(false); toggle.focus(); }
        });
        if (window.matchMedia) {
            var wide = matchMedia('(min-width: 900px)');
            var closeOnWide = function (e) { if (e.matches) { setMenu(false); } };
            if (wide.addEventListener) { wide.addEventListener('change', closeOnWide); }
        }
    }

    /* Scroll reveal. Anything already on screen is shown straight away, and
       the hiding styles only switch on once the observer is really running,
       so a failure here can never leave content invisible. */
    try {
        var items = [].slice.call(d.querySelectorAll('.reveal'));
        if ('IntersectionObserver' in window && items.length && !reduceMotion) {
            var io = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) { entry.target.classList.add('in'); io.unobserve(entry.target); }
                });
            }, { rootMargin: '0px 0px -8% 0px', threshold: 0.06 });
            var fold = (window.innerHeight || root.clientHeight) * 0.92;
            items.forEach(function (el) {
                if (el.getBoundingClientRect().top < fold) { el.classList.add('in'); } else { io.observe(el); }
            });
            root.classList.add('reveal-ready');
        }
    } catch (err) { /* content simply stays visible */ }

    /* Tabs (who-it's-for). Without script every panel is simply shown. */
    [].slice.call(d.querySelectorAll('[data-tabs]')).forEach(function (group) {
        var tabs = [].slice.call(group.querySelectorAll('[role="tab"]'));
        var panels = tabs.map(function (tab) { return d.getElementById(tab.getAttribute('aria-controls')); });
        if (!tabs.length || panels.indexOf(null) !== -1) { return; }

        var select = function (index, focus) {
            tabs.forEach(function (tab, k) {
                var on = k === index;
                tab.setAttribute('aria-selected', on ? 'true' : 'false');
                tab.tabIndex = on ? 0 : -1;
                panels[k].hidden = !on;
            });
            if (focus) { tabs[index].focus(); }
        };

        tabs.forEach(function (tab, i) {
            tab.addEventListener('click', function () { select(i, false); });
            tab.addEventListener('keydown', function (e) {
                var count = tabs.length;
                var next = null;
                if (e.key === 'ArrowRight') { next = (i + 1) % count; }
                else if (e.key === 'ArrowLeft') { next = (i - 1 + count) % count; }
                else if (e.key === 'Home') { next = 0; }
                else if (e.key === 'End') { next = count - 1; }
                if (next !== null) { e.preventDefault(); select(next, true); }
            });
        });
        group.classList.add('is-tabbed');
        select(0, false);
    });

    /* Pointer effects: a soft spotlight on feature tiles, and a gentle tilt
       on the hero certificate. Fine pointers only, never with reduced motion. */
    if (finePointer && !reduceMotion) {
        [].slice.call(d.querySelectorAll('.tile')).forEach(function (tile) {
            tile.addEventListener('pointermove', function (e) {
                var box = tile.getBoundingClientRect();
                tile.style.setProperty('--mx', (e.clientX - box.left) + 'px');
                tile.style.setProperty('--my', (e.clientY - box.top) + 'px');
            });
        });

        var stage = d.querySelector('[data-tilt]');
        if (stage) {
            var frame = null;
            stage.addEventListener('pointermove', function (e) {
                var box = stage.getBoundingClientRect();
                var x = (e.clientX - box.left) / box.width - 0.5;
                var y = (e.clientY - box.top) / box.height - 0.5;
                if (frame) { cancelAnimationFrame(frame); }
                frame = requestAnimationFrame(function () {
                    stage.style.setProperty('--ry', (x * 7).toFixed(2) + 'deg');
                    stage.style.setProperty('--rx', (-y * 6).toFixed(2) + 'deg');
                });
            });
            stage.addEventListener('pointerleave', function () {
                stage.style.setProperty('--ry', '0deg');
                stage.style.setProperty('--rx', '0deg');
            });
        }
    }

    /* Live platform status: shown only when the platform's own readiness
       probe answers READY. On any failure the indicator stays hidden. */
    var status = d.querySelector('[data-status]');
    if (status && window.fetch) {
        fetch('/api/health/ready', { headers: { Accept: 'application/json' }, cache: 'no-store', credentials: 'omit' })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (body) { if (body && body.status === 'READY') { status.hidden = false; } })
            .catch(function () { /* stay hidden */ });
    }
})();
@endverbatim
</script>
