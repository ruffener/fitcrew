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

    const dateForms = document.querySelectorAll('[data-challenge-dates]');

    dateForms.forEach((form) => {
        const startInput = form.querySelector('[data-planned-start]');
        const endInput = form.querySelector('[data-planned-end]');
        const durationInput = form.querySelector('[data-duration-days]');
        const summary = form.querySelector('[data-duration-summary]');
        const defaultDays = Number.parseInt(form.getAttribute('data-default-duration') || '84', 10);

        if (!(startInput instanceof HTMLInputElement)
            || !(endInput instanceof HTMLInputElement)
            || !(durationInput instanceof HTMLInputElement)) {
            return;
        }

        const parseDate = (value) => {
            if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
                return null;
            }

            const [year, month, day] = value.split('-').map(Number);
            return new Date(Date.UTC(year, month - 1, day));
        };

        const formatDate = (date) => {
            const year = date.getUTCFullYear();
            const month = String(date.getUTCMonth() + 1).padStart(2, '0');
            const day = String(date.getUTCDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        };

        const durationText = (days) => {
            if (!Number.isInteger(days) || days <= 0) {
                return '';
            }

            if (days % 7 === 0) {
                const weeks = days / 7;
                return `${weeks} ${weeks === 1 ? 'week' : 'weeks'} · ${days} days`;
            }

            const weeks = Math.floor(days / 7);
            const remaining = days % 7;
            if (weeks > 0) {
                return `${weeks} ${weeks === 1 ? 'week' : 'weeks'} + ${remaining} ${remaining === 1 ? 'day' : 'days'} · ${days} days`;
            }

            return `${days} days`;
        };

        const updateDuration = () => {
            const start = parseDate(startInput.value);
            endInput.min = startInput.value || '';

            if (start && !endInput.value) {
                const defaultEnd = new Date(start.getTime());
                defaultEnd.setUTCDate(defaultEnd.getUTCDate() + defaultDays);
                endInput.value = formatDate(defaultEnd);
                endInput.dataset.autofilled = 'true';
            }

            const resolvedEnd = parseDate(endInput.value);
            if (!start || !resolvedEnd) {
                durationInput.value = String(defaultDays);
                if (summary) {
                    summary.textContent = durationText(defaultDays);
                }
                return;
            }

            const days = Math.round((resolvedEnd.getTime() - start.getTime()) / 86400000);
            durationInput.value = String(days);

            if (summary) {
                summary.textContent = days >= 7 && days <= 365
                    ? durationText(days)
                    : 'Choose an end date 7–365 days after the start.';
            }
        };

        startInput.addEventListener('change', () => {
            if (endInput.dataset.autofilled === 'true') {
                endInput.value = '';
            }
            updateDuration();
        });

        endInput.addEventListener('change', () => {
            endInput.dataset.autofilled = 'false';
            updateDuration();
        });

        updateDuration();
    });

})();
