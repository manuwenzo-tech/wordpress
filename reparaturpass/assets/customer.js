(() => {
    'use strict';
    const pass = document.querySelector('.rp-pass');
    const message = pass.querySelector('[data-message]');
    const progress = pass.querySelector('[data-progress]');
    const refresh = pass.querySelector('[data-refresh]');
    const link = /^#rp\/([1-9][0-9]{0,17})\/([a-f0-9]{64})$/.exec(location.hash);
    const timeline = pass.querySelector('.rp-timeline');
    const summary = pass.querySelector('[data-summary]');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

    if (!link) {
        message.textContent = pass.dataset.error;
        return;
    }

    let currentStage;
    const endpoint = pass.dataset.endpoint.replace('RP_ID', link[1]);

    async function loadPass() {
        refresh.disabled = true;
        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({key: link[2]}),
                credentials: 'omit',
                cache: 'no-store',
                referrerPolicy: 'no-referrer'
            });

            if (response.status === 404) {
                progress.hidden = true;
                pass.querySelector('[data-reference]').textContent = '';
                message.textContent = pass.dataset.error;
                return;
            }
            if (!response.ok) {
                throw new Error('Repair pass request failed');
            }

            const { reference, stage, stages, summary: report } = await response.json();
            pass.querySelector('[data-reference]').textContent = reference;
            message.textContent = stages[stage];
            const badge = pass.querySelector('[data-stage]');
            badge.textContent = stages[stage];
            timeline.replaceChildren(...stages.map((label, index) => {
                const entry = document.createElement('li');
                entry.textContent = label;
                entry.classList.toggle('rp-done', index < stage);
                if (index === stage) {
                    entry.setAttribute('aria-current', 'step');
                }
                return entry;
            }));
            summary.hidden = report === '';
            summary.querySelector('p').textContent = report;
            progress.hidden = false;
            progress.querySelector('progress').value = stage + 1;
            if (currentStage !== stage && !reducedMotion.matches && typeof badge.animate === 'function') {
                badge.animate([{ transform: 'translateY(5px)', opacity: 0.55 }, { transform: 'translateY(0)', opacity: 1 }],
                    { duration: 380, easing: 'ease-out' });
            }
            currentStage = stage;
        } catch {
            message.textContent = pass.dataset.retry;
        } finally {
            refresh.hidden = false;
            refresh.disabled = false;
        }
    }

    refresh.addEventListener('click', loadPass);
    loadPass();
})();
