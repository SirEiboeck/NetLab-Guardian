/* Ergänzende, progressive Interaktionen. Sämtliche Formulare funktionieren auch ohne JS. */
(() => {
    'use strict';

    const setEditor = (form, enabled, reset = false) => {
        if (reset) form.reset();
        form.classList.toggle('is-editing', enabled);
        form.querySelectorAll('.device-editor input, .device-editor select, .device-editor textarea').forEach(control => {
            control.disabled = !enabled;
        });
        const actions = form.querySelector('[data-device-actions]');
        if (actions) actions.hidden = !enabled;
        const toggle = document.querySelector('[data-device-edit-toggle]');
        if (toggle) {
            toggle.textContent = enabled ? 'Bearbeitung beenden' : 'Bearbeiten';
            toggle.setAttribute('aria-expanded', String(enabled));
        }
        if (enabled) form.querySelector('.device-editor input:not([disabled])')?.focus({ preventScroll: true });
    };

    document.addEventListener('click', event => {
        const target = event.target instanceof Element ? event.target : null;
        const toggle = target?.closest('[data-device-edit-toggle]');
        if (toggle) {
            const form = document.querySelector('[data-device-editor]');
            if (!form) return;
            event.preventDefault();
            setEditor(form, !form.classList.contains('is-editing'), true);
            return;
        }
        const cancel = target?.closest('[data-device-edit-cancel]');
        if (cancel) {
            const form = cancel.closest('[data-device-editor]');
            // Neue Geräte haben noch keine gespeicherte Leseansicht.
            if (!form || location.pathname.endsWith('/device.php') && !new URLSearchParams(location.search).has('id')) return;
            event.preventDefault();
            setEditor(form, false, true);
        }
    });

    const showDonutDetails = target => {
        const card = target.closest('.donut-card');
        if (!card) return;
        const tooltip = card.querySelector('[data-donut-tooltip]');
        if (tooltip) tooltip.textContent = `${target.dataset.donutLabel}: ${target.dataset.donutValue} (${target.dataset.donutPercent} %)`;
    };
    const resetDonutDetails = target => {
        const tooltip = target.closest('.donut-card')?.querySelector('[data-donut-tooltip]');
        if (tooltip) tooltip.textContent = 'Details beim Hover';
    };
    const detailTarget = node => node instanceof Element ? node.closest('.donut-segment, .donut-legend-row') : null;
    document.addEventListener('pointerover', event => {
        const target = detailTarget(event.target);
        if (target && !target.contains(event.relatedTarget)) showDonutDetails(target);
    });
    document.addEventListener('pointerout', event => {
        const target = detailTarget(event.target);
        if (target && !target.contains(event.relatedTarget)) resetDonutDetails(target);
    });
    document.addEventListener('focusin', event => {
        const target = detailTarget(event.target);
        if (target) showDonutDetails(target);
    });
    document.addEventListener('focusout', event => {
        const target = detailTarget(event.target);
        if (target) resetDonutDetails(target);
    });

    // Aktuelle URL als POST-Endpunkt nutzen, wenn eine Aktion auf derselben Seite
    // stattfindet. Das funktioniert auch bei DirectoryIndex, Aliases und Reverse-
    // Proxys, die / anzeigen, aber /index.php nicht direkt bereitstellen.
    const samePagePath = (target, current = new URL(location.href)) => {
        if (target.origin !== current.origin) return false;
        if (target.pathname === current.pathname) return true;
        return current.pathname.endsWith('/') && target.pathname === `${current.pathname}index.php`;
    };

    const actionEndpoint = form => {
        const declared = new URL(form.getAttribute('action') || location.href, location.href);
        declared.hash = '';
        if (!samePagePath(declared)) return declared;
        const current = new URL(location.href);
        // POST auf die nachweislich erreichbare Adresse der geöffneten Seite.
        // Edit-/Delete-Parameter aus dem Formular müssen dabei erhalten bleiben.
        if (declared.search) current.search = declared.search;
        current.hash = '';
        return current;
    };

    const postForm = (form, submitter) => {
        const data = new FormData(form);
        if (submitter?.name) data.set(submitter.name, submitter.value);
        return fetch(actionEndpoint(form), {
            method: 'POST', body: data, credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            // Niemals unkontrolliert auf den PHP-Redirect GETten: Der POST könnte
            // bereits erfolgreich gewesen sein, auch wenn dessen Ziel-URL 404 gibt.
            redirect: 'manual', cache: 'no-store'
        });
    };

    const serverError = response => {
        if (response.type === 'opaqueredirect') {
            return new Error('Der Server benutzt noch den alten Redirect. Bitte alle Fix-Dateien aktualisieren.');
        }
        return new Error(`HTTP ${response.status}`);
    };

    const submitWithoutJump = async (form, submitter) => {
        const beforeCard = form.closest('.rack-card');
        const rackId = beforeCard?.id || '';
        const previousTop = beforeCard?.getBoundingClientRect().top;
        const previousScroll = { x: window.scrollX, y: window.scrollY };
        const openRackSettings = [...document.querySelectorAll('.rack-card')]
            .filter(card => card.querySelector('.rack-settings')?.open)
            .map(card => card.id);
        const buttons = [...form.querySelectorAll('button[type="submit"], button:not([type])')];
        const oldLabel = submitter?.textContent;
        const errorArea = beforeCard || form.closest('.panel') || form.closest('main.workspace') || form;
        errorArea.querySelectorAll(':scope > .request-error').forEach(notice => notice.remove());
        buttons.forEach(button => { button.disabled = true; });
        if (submitter) submitter.textContent = 'Wird ausgeführt …';
        try {
            let response = await postForm(form, submitter);
            if (!response.ok || response.type === 'opaqueredirect') throw serverError(response);

            let viewUrl = new URL(location.href);
            if (response.status === 204) {
                const redirectTarget = response.headers.get('X-NetLab-Redirect');
                if (!redirectTarget) throw new Error('Antwort ohne Zieladresse. Bitte PHP-Dateien aktualisieren.');
                const destination = new URL(redirectTarget, actionEndpoint(form));
                if (destination.origin !== location.origin) throw new Error('Ungültiges Weiterleitungsziel');
                if (!samePagePath(destination)) {
                    // Gewollter Seitenwechsel (etwa nach Löschen eines Geräts).
                    location.assign(destination.href);
                    return;
                }
                // GET über die bereits funktionierende Seiten-URL, nicht über
                // eine eventuell nicht erreichbare index.php-Alias-URL.
                viewUrl.search = destination.search;
                viewUrl.hash = '';
                response = await fetch(viewUrl, { method: 'GET', credentials: 'same-origin', cache: 'no-store' });
                if (!response.ok) throw serverError(response);
            }

            const responseUrl = new URL(response.url);
            if (responseUrl.pathname.endsWith('/login.php')) {
                location.assign(responseUrl.href);
                return;
            }
            const html = await response.text();
            const parsed = new DOMParser().parseFromString(html, 'text/html');
            const updatedMain = parsed.querySelector('main.workspace');
            const currentMain = document.querySelector('main.workspace');
            if (!updatedMain || !currentMain) throw new Error('Keine gültige Seitenantwort erhalten');
            currentMain.replaceWith(document.importNode(updatedMain, true));
            document.title = parsed.title || document.title;
            for (const id of openRackSettings) {
                const details = document.getElementById(id)?.querySelector('.rack-settings');
                if (details) details.open = true;
            }
            history.replaceState(null, '', viewUrl.pathname + viewUrl.search + location.hash);
            // Nach dem Einsetzen der Daten dieselbe Position wiederherstellen.
            requestAnimationFrame(() => {
                const newCard = rackId ? document.getElementById(rackId) : null;
                const delta = newCard && previousTop !== undefined
                    ? newCard.getBoundingClientRect().top - previousTop : 0;
                window.scrollTo(previousScroll.x, previousScroll.y + delta);
            });
        } catch (error) {
            // Pro Rack/Panel höchstens eine Fehlermeldung, kein Stapeln mehr.
            errorArea.querySelectorAll(':scope > .request-error').forEach(notice => notice.remove());
            const warning = document.createElement('p');
            warning.className = 'notice error request-error';
            warning.setAttribute('role', 'alert');
            warning.textContent = `Aktion fehlgeschlagen: ${error.message}`;
            errorArea.prepend(warning);
            console.error('NetLab Guardian: Formularaktion fehlgeschlagen', error);
        } finally {
            buttons.forEach(button => { if (button.isConnected) button.disabled = false; });
            if (submitter?.isConnected) submitter.textContent = oldLabel;
        }
    };

    document.addEventListener('submit', event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || form.method.toLowerCase() !== 'post' || !form.closest('main.workspace')) return;
        // Confirmation lives in the external script: inline onsubmit is blocked by CSP.
        if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
            event.preventDefault();
            return;
        }
        if (form.classList.contains('rack-dismiss-form')) {
            event.preventDefault();
            const notice = form.closest('.rack-check-notice');
            if (notice) notice.remove();
            postForm(form, event.submitter).then(response => {
                if (!response.ok || response.type === 'opaqueredirect') {
                    console.error('NetLab Guardian: Meldung konnte serverseitig nicht geschlossen werden', response.status);
                }
            }).catch(error => console.error('NetLab Guardian: Meldung konnte nicht geschlossen werden', error));
            return;
        }
        if (form.matches('[data-device-editor]') && !form.classList.contains('is-editing')) {
            event.preventDefault();
            return;
        }
        event.preventDefault();
        submitWithoutJump(form, event.submitter);
    });
})();
