(() => {
    document.documentElement.classList.add('fitcrew-js-ready');

    const toggle = document.querySelector('.nav-toggle');
    const nav = document.getElementById('primary-navigation');

    if (!toggle || !nav) {
        return;
    }

    const closeMenu = () => {
        toggle.setAttribute('aria-expanded', 'false');
        nav.classList.remove('is-open');
    };

    toggle.addEventListener('click', () => {
        const expanded = toggle.getAttribute('aria-expanded') === 'true';
        toggle.setAttribute('aria-expanded', String(!expanded));
        nav.classList.toggle('is-open', !expanded);
    });

    nav.addEventListener('click', (event) => {
        if (event.target instanceof HTMLAnchorElement) {
            closeMenu();
        }
    });

    window.addEventListener('resize', () => {
        if (window.innerWidth >= 820) {
            closeMenu();
        }
    });
})();
