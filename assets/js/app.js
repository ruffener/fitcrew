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

    const moreToggle = document.querySelector('.mobile-more-toggle');
    const moreMenu = document.getElementById('mobile-more-menu');

    if (moreToggle && moreMenu) {
        moreToggle.addEventListener('click', () => {
            const expanded = moreToggle.getAttribute('aria-expanded') === 'true';
            moreToggle.setAttribute('aria-expanded', String(!expanded));
            moreMenu.hidden = expanded;
        });

        document.addEventListener('click', (event) => {
            if (moreMenu.hidden || moreMenu.contains(event.target) || moreToggle.contains(event.target)) {
                return;
            }
            moreToggle.setAttribute('aria-expanded', 'false');
            moreMenu.hidden = true;
        });
    }
})();
