(() => {
    'use strict';

    const NAV_GROUP_STORAGE_KEY = 'metriccon_ops_nav_groups';

    const readExpandedGroups = () => {
        try {
            const saved = JSON.parse(localStorage.getItem(NAV_GROUP_STORAGE_KEY) || '[]');
            return Array.isArray(saved) ? saved.filter((value) => typeof value === 'string') : [];
        } catch (error) {
            return [];
        }
    };

    const writeExpandedGroups = (groups) => {
        try {
            localStorage.setItem(NAV_GROUP_STORAGE_KEY, JSON.stringify(groups));
        } catch (error) {
            // The navigation still works when storage is unavailable.
        }
    };

    const setGroupExpanded = (group, expanded) => {
        const toggle = group.querySelector('[data-nav-group-toggle]');
        const links = group.querySelector('[data-nav-group-links]');
        if (!toggle || !links) return;

        toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        links.hidden = !expanded;
        group.classList.toggle('is-expanded', expanded);
    };

    document.addEventListener('DOMContentLoaded', () => {
        const enhanceLegacySurfaces = () => {
            document.querySelectorAll('.page-content').forEach((page) => {
                if (page.querySelector('.filter-card')) {
                    page.classList.add('ops-monitoring-page');
                }

                if (page.querySelector('.form-card form')) {
                    page.classList.add('ops-guided-page');
                    page.querySelectorAll('.form-card .form-actions').forEach((actions) => {
                        actions.classList.add('ops-sticky-actions');
                    });
                }

                const documentIntro = page.querySelector('.monitoring-top-card');
                const isDocumentPage = documentIntro
                    && !page.querySelector('.filter-card')
                    && (page.querySelector('input[name="id"], input[name="release_id"], input[name="request_id"]')
                        || documentIntro.querySelector('a[href*="print_"]'));
                if (isDocumentPage) {
                    page.classList.add('ops-document-page');
                    documentIntro.classList.add('ops-document-summary');
                    documentIntro.querySelector('.hero-actions')?.classList.add('ops-sticky-actions');
                }

                page.querySelectorAll('.monitoring-top-card').forEach((intro) => {
                    intro.classList.add('ops-page-intro');
                });

                page.querySelectorAll('.filter-card').forEach((filter) => {
                    filter.classList.add('ops-filter-bar');
                    filter.setAttribute('data-filter-drawer', '');
                });

                page.querySelectorAll('.content-card').forEach((section) => {
                    section.classList.add(section.querySelector('table') ? 'ops-results-section' : 'ops-section');
                });

                page.querySelectorAll('.form-card').forEach((section) => {
                    section.classList.add('ops-section', 'ops-form-section');
                });

                page.querySelectorAll('.table-wrap').forEach((wrap) => {
                    if (!wrap.closest('.ops-table-shell')) {
                        wrap.classList.add('ops-table-shell');
                    }
                });

                page.querySelectorAll('table.data.modern-table').forEach((table) => {
                    table.classList.add('ops-table');
                    table.setAttribute('data-sortable-table', '');
                    table.dataset.pageSize ||= '15';

                    const tbody = table.tBodies[0];
                    if (tbody && tbody.rows.length === 0) {
                        const row = tbody.insertRow();
                        const cell = row.insertCell();
                        cell.colSpan = Math.max(1, table.tHead?.rows[0]?.cells.length || 1);
                        cell.className = 'ops-table-empty';
                        cell.textContent = 'No records are available for this view.';
                    }

                    const shell = table.closest('.ops-table-shell');
                    const adjacentControls = shell?.nextElementSibling?.classList.contains('smart-table-controls')
                        ? shell.nextElementSibling
                        : null;
                    if (adjacentControls) {
                        adjacentControls.classList.add('ops-pagination');
                        adjacentControls.setAttribute('data-table-pagination', '');
                    } else if (shell && !shell.querySelector('[data-table-pagination]')) {
                        const pagination = document.createElement('div');
                        pagination.className = 'ops-pagination';
                        pagination.setAttribute('data-table-pagination', '');
                        shell.appendChild(pagination);
                    }
                });
            });
        };

        enhanceLegacySurfaces();

        const enhanceLegacyCharts = () => {
            const drilldownFor = (type) => {
                if (type.includes('risk') || type.includes('urgency')) return 'reports_forecast.php';
                if (type.includes('procurement') || type.includes('order')) return 'procurement_intelligence.php';
                if (type.includes('abc') || type.includes('aging') || type.includes('location')) return 'inventory_intelligence.php';
                if (type.includes('movement')) return 'movements.php';
                return 'advanced_forecasting.php';
            };

            document.querySelectorAll('canvas[data-chart]').forEach((chart) => {
                const title = chart.closest('.live-card, .content-card')?.querySelector('h2')?.textContent?.trim() || 'Data visualization';
                chart.setAttribute('aria-label', chart.getAttribute('aria-label') || `${title} chart`);

                if (!chart.hasAttribute('data-drilldown-url')) {
                    const url = drilldownFor(chart.dataset.chart || '');
                    chart.setAttribute('data-drilldown-url', url);
                    chart.classList.add('chart-clickable');
                    chart.addEventListener('click', () => window.location.assign(url));
                }

                if (chart.closest('[data-live-dashboard]')) return;
                try {
                    const payload = JSON.parse(chart.dataset.chartData || '[]');
                    const hasData = Array.isArray(payload)
                        ? payload.length > 0
                        : payload && typeof payload === 'object' && Object.keys(payload).length > 0;
                    if (!hasData) {
                        chart.hidden = true;
                        const empty = document.createElement('div');
                        empty.className = 'ops-chart-empty';
                        empty.textContent = 'No chart data is available for this period.';
                        chart.after(empty);
                    }
                } catch (error) {
                    chart.setAttribute('aria-description', 'Chart data is temporarily unavailable.');
                }
            });
        };

        enhanceLegacyCharts();

        const groups = [...document.querySelectorAll('[data-nav-group]')];
        const savedGroups = readExpandedGroups();
        const sidebar = document.querySelector('[data-sidebar]');
        const sidebarTooltip = document.querySelector('[data-sidebar-tooltip]');

        const hideSidebarTooltip = () => {
            if (sidebarTooltip) sidebarTooltip.hidden = true;
        };

        const showSidebarTooltip = (trigger) => {
            if (!sidebarTooltip || !document.body.classList.contains('sidebar-collapsed')) return;
            const label = trigger?.dataset.sidebarLabel || trigger?.getAttribute('aria-label') || '';
            if (!label) return;

            const rect = trigger.getBoundingClientRect();
            sidebarTooltip.textContent = label;
            sidebarTooltip.hidden = false;
            const tooltipHeight = sidebarTooltip.getBoundingClientRect().height;
            sidebarTooltip.style.left = `${Math.round(rect.right + 10)}px`;
            sidebarTooltip.style.top = `${Math.round(Math.max(8, Math.min(window.innerHeight - tooltipHeight - 8, rect.top + (rect.height - tooltipHeight) / 2)))}px`;
        };

        sidebar?.addEventListener('mouseover', (event) => {
            const trigger = event.target.closest('[data-sidebar-label]');
            if (trigger) showSidebarTooltip(trigger);
        });
        sidebar?.addEventListener('mouseout', hideSidebarTooltip);
        sidebar?.addEventListener('focusin', (event) => {
            const trigger = event.target.closest('[data-sidebar-label]');
            if (trigger) showSidebarTooltip(trigger);
        });
        sidebar?.addEventListener('focusout', hideSidebarTooltip);

        sidebar?.addEventListener('keydown', (event) => {
            if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
            const controls = [...sidebar.querySelectorAll('button:not([disabled]), a[href]')]
                .filter((control) => control.offsetParent !== null);
            if (controls.length === 0) return;

            const currentIndex = controls.indexOf(document.activeElement);
            let nextIndex = currentIndex;
            if (event.key === 'Home') nextIndex = 0;
            if (event.key === 'End') nextIndex = controls.length - 1;
            if (event.key === 'ArrowDown') nextIndex = currentIndex < 0 ? 0 : (currentIndex + 1) % controls.length;
            if (event.key === 'ArrowUp') nextIndex = currentIndex <= 0 ? controls.length - 1 : currentIndex - 1;

            event.preventDefault();
            controls[nextIndex].focus({ preventScroll: true });
            controls[nextIndex].scrollIntoView({ block: 'nearest' });
        });

        groups.forEach((group) => {
            const key = group.dataset.navGroup || '';
            const containsActive = group.classList.contains('contains-active');
            const hasSavedState = savedGroups.length > 0;
            const expanded = containsActive || (hasSavedState
                ? savedGroups.includes(key)
                : group.querySelector('[data-nav-group-toggle]')?.getAttribute('aria-expanded') === 'true');

            setGroupExpanded(group, expanded);
        });

        const filterDrawers = [...document.querySelectorAll('[data-filter-drawer]')];
        const countActiveFilters = (drawer) => [...drawer.querySelectorAll('input[name], select[name], textarea[name]')]
            .filter((control) => {
                if (control.disabled || control.type === 'hidden') return false;
                if ((control.type === 'checkbox' || control.type === 'radio') && !control.checked) return false;
                const value = String(control.value || '').trim();
                return value !== '' && value !== '0';
            }).length;

        const refreshFilterCount = (drawer) => {
            const bar = drawer.closest('.ops-filter-bar');
            const count = countActiveFilters(drawer);
            bar?.querySelectorAll('[data-active-filter-count]').forEach((element) => {
                element.textContent = String(count);
                element.hidden = count === 0;
            });
        };

        filterDrawers.forEach((drawer) => {
            refreshFilterCount(drawer);
            drawer.addEventListener('input', () => refreshFilterCount(drawer));
            drawer.addEventListener('change', () => refreshFilterCount(drawer));
        });

        document.querySelectorAll('[data-guided-form]').forEach((form) => {
            const steps = [...form.querySelectorAll('[data-form-step]')];
            const progress = form.querySelector('[data-guided-progress]');
            const indicators = [...form.querySelectorAll('[data-guided-indicator]')];
            if (steps.length === 0) return;

            let currentIndex = 0;
            const showStep = (index) => {
                currentIndex = Math.max(0, Math.min(index, steps.length - 1));
                form.classList.add('is-guided-enhanced');
                steps.forEach((step, stepIndex) => {
                    const active = stepIndex === currentIndex;
                    step.hidden = !active;
                    step.setAttribute('aria-hidden', active ? 'false' : 'true');
                });
                indicators.forEach((indicator, indicatorIndex) => {
                    indicator.classList.toggle('active', indicatorIndex === currentIndex);
                    indicator.classList.toggle('complete', indicatorIndex < currentIndex);
                });
                if (progress) {
                    progress.textContent = `Step ${currentIndex + 1} of ${steps.length}`;
                }
                steps[currentIndex]?.querySelector('input, select, textarea, button')?.focus({ preventScroll: true });
            };

            form.addEventListener('click', (event) => {
                const next = event.target.closest('[data-step-next]');
                if (next) {
                    const currentStep = steps[currentIndex];
                    const invalidControl = currentStep.querySelector('input:invalid, select:invalid, textarea:invalid');
                    if (!invalidControl) {
                        showStep(currentIndex + 1);
                    } else {
                        invalidControl.reportValidity();
                    }
                    return;
                }

                if (event.target.closest('[data-step-back]')) {
                    showStep(currentIndex - 1);
                }
            });

            showStep(0);
        });

        document.addEventListener('click', (event) => {
            const filterToggle = event.target.closest('[data-filter-toggle]');
            if (filterToggle) {
                const drawerId = filterToggle.getAttribute('aria-controls');
                const drawer = drawerId ? document.getElementById(drawerId) : filterToggle.closest('.ops-filter-bar')?.querySelector('[data-filter-drawer]');
                if (!drawer) return;

                const expanded = filterToggle.getAttribute('aria-expanded') !== 'true';
                filterToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                drawer.hidden = !expanded;
                if (expanded) {
                    drawer.querySelector('input:not([type="hidden"]), select, textarea')?.focus();
                }
                return;
            }

            const toggle = event.target.closest('[data-nav-group-toggle]');
            if (toggle) {
                const group = toggle.closest('[data-nav-group]');
                if (!group) return;

                const expanded = toggle.getAttribute('aria-expanded') !== 'true';
                setGroupExpanded(group, expanded);
                writeExpandedGroups(groups
                    .filter((item) => item.querySelector('[data-nav-group-toggle]')?.getAttribute('aria-expanded') === 'true')
                    .map((item) => item.dataset.navGroup)
                    .filter(Boolean));
                return;
            }

            if (event.target.closest('[data-mobile-nav-overlay]')) {
                document.body.classList.remove('sidebar-open');
                const mobileToggle = document.querySelector('[data-sidebar-toggle]');
                mobileToggle?.setAttribute('aria-expanded', 'false');
                mobileToggle?.setAttribute('aria-label', 'Open navigation');
                mobileToggle?.focus();
            }
        });

        document.addEventListener('keydown', (event) => {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                const search = document.querySelector('[data-command-search]');
                if (search) {
                    event.preventDefault();
                    search.focus();
                    search.select();
                }
            }

            if (event.key === 'Escape' && document.body.classList.contains('sidebar-open')) {
                document.body.classList.remove('sidebar-open');
                const mobileToggle = document.querySelector('[data-sidebar-toggle]');
                mobileToggle?.setAttribute('aria-expanded', 'false');
                mobileToggle?.setAttribute('aria-label', 'Open navigation');
                mobileToggle?.focus();
            }
        });
    });
})();
