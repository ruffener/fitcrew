(() => {
    document.documentElement.classList.add('fitcrew-js-ready');

    const modalTriggers = document.querySelectorAll('[data-modal-open]');
    const modalCloseButtons = document.querySelectorAll('[data-modal-close]');
    let modalReturnFocus = null;

    const closeFitCrewModal = (modal) => {
        if (!(modal instanceof HTMLDialogElement) || !modal.open) {
            return;
        }

        modal.close();
    };

    modalTriggers.forEach((trigger) => {
        trigger.addEventListener('click', () => {
            const modalId = trigger.getAttribute('data-modal-open');
            const modal = modalId ? document.getElementById(modalId) : null;

            if (!(modal instanceof HTMLDialogElement)) {
                return;
            }

            modalReturnFocus = trigger;
            document.body.classList.add('fc-modal-open');
            modal.showModal();

            const closeButton = modal.querySelector('[data-modal-close]');
            if (closeButton instanceof HTMLElement) {
                closeButton.focus();
            }
        });
    });

    modalCloseButtons.forEach((button) => {
        button.addEventListener('click', () => {
            closeFitCrewModal(button.closest('dialog'));
        });
    });

    document.querySelectorAll('[data-fitcrew-modal]').forEach((modal) => {
        if (!(modal instanceof HTMLDialogElement)) {
            return;
        }

        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeFitCrewModal(modal);
            }
        });

        modal.addEventListener('close', () => {
            document.body.classList.remove('fc-modal-open');

            if (modalReturnFocus instanceof HTMLElement) {
                modalReturnFocus.focus();
            }
            modalReturnFocus = null;
        });
    });

    const clickableCards = document.querySelectorAll('[data-submit-form]');

    const submitClickableCard = (card) => {
        const formId = card.getAttribute('data-submit-form');
        const form = formId ? document.getElementById(formId) : null;

        if (form instanceof HTMLFormElement) {
            form.requestSubmit();
        }
    };

    clickableCards.forEach((card) => {
        card.addEventListener('click', (event) => {
            if (event.target instanceof Element && event.target.closest('button, a, input, select, textarea, label, form')) {
                return;
            }
            submitClickableCard(card);
        });

        card.addEventListener('keydown', (event) => {
            if (event.target !== card || (event.key !== 'Enter' && event.key !== ' ')) {
                return;
            }
            event.preventDefault();
            submitClickableCard(card);
        });
    });

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
        const durationWeeksInput = form.querySelector('[data-duration-weeks]');
        const calculatedEnd = form.querySelector('[data-calculated-end]');
        const finishModeInputs = Array.from(form.querySelectorAll('[data-finish-mode]'));
        const finishPanels = Array.from(form.querySelectorAll('[data-finish-panel]'));
        const defaultDays = Number.parseInt(form.getAttribute('data-default-duration') || '84', 10);

        if (!(startInput instanceof HTMLInputElement) || !(durationInput instanceof HTMLInputElement)) {
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

        const displayDate = (date) => new Intl.DateTimeFormat('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            timeZone: 'UTC',
        }).format(date);

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

        // New Challenge setup: the Owner deliberately chooses either a duration
        // in whole weeks or an exact end date. Both resolve to canonical days.
        if (finishModeInputs.length > 0 && durationWeeksInput instanceof HTMLInputElement && endInput instanceof HTMLInputElement) {
            const currentMode = () => {
                const checked = finishModeInputs.find((input) => input instanceof HTMLInputElement && input.checked);
                return checked instanceof HTMLInputElement ? checked.value : 'duration';
            };

            const syncPanels = () => {
                const mode = currentMode();
                finishPanels.forEach((panel) => {
                    if (!(panel instanceof HTMLElement)) {
                        return;
                    }
                    panel.hidden = panel.dataset.finishPanel !== mode;
                });
                endInput.disabled = mode !== 'end_date';
                durationWeeksInput.disabled = mode !== 'duration';
            };

            const updateFromWeeks = () => {
                const start = parseDate(startInput.value);
                const weeks = Number.parseInt(durationWeeksInput.value || '12', 10);
                const safeWeeks = Number.isInteger(weeks) && weeks >= 1 && weeks <= 52 ? weeks : 12;
                const days = safeWeeks * 7;
                durationInput.value = String(days);

                if (!start) {
                    if (calculatedEnd) {
                        calculatedEnd.textContent = 'Choose a begin date';
                    }
                    return;
                }

                const end = new Date(start.getTime());
                end.setUTCDate(end.getUTCDate() + days);
                if (calculatedEnd) {
                    calculatedEnd.textContent = displayDate(end);
                }
            };

            const updateFromEndDate = () => {
                const start = parseDate(startInput.value);
                const end = parseDate(endInput.value);
                endInput.min = startInput.value || '';

                if (!start || !end) {
                    durationInput.value = String(defaultDays);
                    if (summary) {
                        summary.textContent = start ? 'Choose an end date' : 'Choose a begin date first';
                    }
                    return;
                }

                const days = Math.round((end.getTime() - start.getTime()) / 86400000);
                durationInput.value = String(days);
                if (summary) {
                    summary.textContent = days >= 7 && days <= 365
                        ? durationText(days)
                        : 'Choose an end date 7–365 days after the begin date.';
                }
            };

            const update = () => {
                syncPanels();
                if (currentMode() === 'end_date') {
                    updateFromEndDate();
                } else {
                    updateFromWeeks();
                }
            };

            finishModeInputs.forEach((input) => input.addEventListener('change', update));
            startInput.addEventListener('change', update);
            durationWeeksInput.addEventListener('input', updateFromWeeks);
            endInput.addEventListener('change', updateFromEndDate);
            update();
            return;
        }

        // Existing Rule-update forms retain the simpler bidirectional date
        // behavior until the post-Wave-1 Challenge-management pass.
        if (!(endInput instanceof HTMLInputElement)) {
            return;
        }

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
