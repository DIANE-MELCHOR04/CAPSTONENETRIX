document.addEventListener('DOMContentLoaded', () => {
    const injectedGlobal = typeof global !== 'undefined' ? global : null;
    const notiflix = window.Notiflix || (injectedGlobal && injectedGlobal.Notiflix) || null;

    if (notiflix && !window.Notiflix) {
        window.Notiflix = notiflix;
    }

    if (notiflix) {
        const fontFamily = '"IBM Plex Mono", "Cascadia Code", Consolas, monospace';

        notiflix.Notify.init({
            position: 'right-top',
            distance: '18px',
            width: '340px',
            borderRadius: '4px',
            timeout: 4200,
            clickToClose: true,
            pauseOnHover: true,
            showOnlyTheLastOne: false,
            fontFamily,
            fontSize: '13px',
            cssAnimationStyle: 'from-right',
            success: { background: '#237a52', textColor: '#ffffff', notiflixIconColor: '#8fe1b9' },
            failure: { background: '#a43b3b', textColor: '#ffffff', notiflixIconColor: '#ffb3a9' },
            warning: { background: '#9c6918', textColor: '#ffffff', notiflixIconColor: '#ffd37a' },
            info: { background: '#126f78', textColor: '#ffffff', notiflixIconColor: '#82eef0' }
        });

        notiflix.Confirm.init({
            width: '360px',
            borderRadius: '4px',
            backgroundColor: '#f7f3e9',
            backOverlayColor: 'rgba(9, 15, 21, 0.74)',
            titleColor: '#111820',
            messageColor: '#46505a',
            okButtonBackground: '#d88d20',
            okButtonColor: '#111820',
            cancelButtonBackground: '#d7d1c4',
            cancelButtonColor: '#111820',
            fontFamily,
            cssAnimationStyle: 'zoom'
        });

        notiflix.Report.init({
            width: '380px',
            borderRadius: '4px',
            backgroundColor: '#f7f3e9',
            backOverlayColor: 'rgba(9, 15, 21, 0.74)',
            fontFamily
        });

        notiflix.Loading.init({
            backgroundColor: 'rgba(9, 15, 21, 0.84)',
            svgColor: '#f0a83a',
            messageColor: '#f7f3e9',
            fontFamily
        });

        notiflix.Block.init({
            backgroundColor: 'rgba(247, 243, 233, 0.91)',
            svgColor: '#d88d20',
            messageColor: '#111820',
            fontFamily,
            cssAnimationDuration: 180
        });
    }

    document.querySelectorAll('[data-notify]').forEach((fallback) => {
        if (!notiflix) return;

        const type = fallback.dataset.notify || 'info';
        const method = type === 'danger' || type === 'error' ? 'failure' : type;
        const notify = notiflix.Notify[method] || notiflix.Notify.info;
        notify(fallback.textContent.trim());
        fallback.closest('.flash-fallback-wrap')?.remove();
        fallback.remove();
    });

    const notificationHost = document.querySelector('[data-notification-poll-url]');
    if (notificationHost) {
        const storageKey = 'inventory_forecasting_seen_notifications';
        const notificationLink = notificationHost.querySelector('.notification-pill');

        const storedSeen = (() => {
            try {
                return new Set(JSON.parse(sessionStorage.getItem(storageKey) || '[]').map(Number));
            } catch (error) {
                return new Set();
            }
        })();

        const saveSeenNotifications = () => {
            try {
                sessionStorage.setItem(storageKey, JSON.stringify(Array.from(storedSeen).slice(-80)));
            } catch (error) {
                // Browser storage can be disabled; alerts still work without persistence.
            }
        };

        const updateNotificationBadge = (count) => {
            const unread = Math.max(0, Number(count) || 0);
            notificationHost.dataset.unreadNotifications = String(unread);
            if (!notificationLink) return;

            let badge = notificationLink.querySelector('[data-notification-count]');
            if (unread <= 0) {
                badge?.remove();
                return;
            }

            if (!badge) {
                badge = document.createElement('b');
                badge.dataset.notificationCount = '';
                notificationLink.appendChild(badge);
            }
            badge.textContent = unread > 99 ? '99+' : String(unread);
        };

        const showUnreadNotifications = (latest) => {
            if (!notiflix || !Array.isArray(latest)) return;

            latest.slice().reverse().forEach((item) => {
                const id = Number(item.id);
                if (!Number.isFinite(id) || storedSeen.has(id)) {
                    return;
                }

                storedSeen.add(id);
                const severity = item.severity === 'danger' ? 'failure' : (item.severity || 'info');
                const notify = notiflix.Notify[severity] || notiflix.Notify.info;
                notify(`${item.title}: ${item.message}`);
            });

            saveSeenNotifications();
        };

        const pollNotifications = async () => {
            try {
                const response = await fetch(notificationHost.dataset.notificationPollUrl, {
                    cache: 'no-store',
                    headers: { Accept: 'application/json' }
                });
                if (!response.ok) {
                    return;
                }

                const payload = await response.json();
                updateNotificationBadge(payload.unread);
                showUnreadNotifications(payload.latest);
            } catch (error) {
                // Notification polling is a convenience layer; the page badge remains the source of truth.
            }
        };

        updateNotificationBadge(notificationHost.dataset.unreadNotifications);
        pollNotifications();
        setInterval(pollNotifications, 60000);
    }

    const sidebarCollapseToggle = document.querySelector('[data-sidebar-collapse-toggle]');
    const sidebarCollapseLabel = sidebarCollapseToggle?.querySelector('[data-sidebar-collapse-label]');
    const sidebarStorageKey = 'inventory_forecasting_sidebar_collapsed';
    const canCollapseSidebar = () => {
        if (!window.matchMedia) {
            return true;
        }
        return window.matchMedia('(min-width: 921px)').matches;
    };

    const readSidebarPreference = () => {
        try {
            return window.localStorage?.getItem(sidebarStorageKey) === '1';
        } catch (error) {
            return false;
        }
    };

    const saveSidebarPreference = (collapsed) => {
        try {
            window.localStorage?.setItem(sidebarStorageKey, collapsed ? '1' : '0');
        } catch (error) {
            // The visual state still works when browser storage is unavailable.
        }
    };

    const setSidebarCollapsed = (collapsed, options = {}) => {
        const applyCollapsed = collapsed && canCollapseSidebar();
        document.body.classList.toggle('sidebar-collapsed', applyCollapsed);

        if (sidebarCollapseToggle) {
            sidebarCollapseToggle.setAttribute('aria-expanded', applyCollapsed ? 'false' : 'true');
            sidebarCollapseToggle.setAttribute('aria-label', applyCollapsed ? 'Expand sidebar' : 'Collapse sidebar');
            sidebarCollapseToggle.title = applyCollapsed ? 'Expand sidebar' : 'Collapse sidebar';
        }

        if (sidebarCollapseLabel) {
            sidebarCollapseLabel.textContent = applyCollapsed ? 'Expand' : 'Collapse';
        }

        if (options.persist) {
            saveSidebarPreference(Boolean(collapsed));
        }

        if (options.notify && notiflix) {
            notiflix.Notify.info(applyCollapsed ? 'Sidebar collapsed.' : 'Sidebar expanded.');
        }
    };

    if (sidebarCollapseToggle) {
        setSidebarCollapsed(readSidebarPreference());

        if (typeof window.addEventListener === 'function') {
            window.addEventListener('resize', () => setSidebarCollapsed(readSidebarPreference()));
        }
    }

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-sidebar-collapse-toggle]');
        if (!trigger) return;

        event.preventDefault();
        const nextCollapsed = !document.body.classList.contains('sidebar-collapsed');
        setSidebarCollapsed(nextCollapsed, { persist: true, notify: true });
    });

    const toggle = document.querySelector('[data-sidebar-toggle]');
    const mobileSidebarClose = document.querySelector('[data-sidebar-close]');
    const setMobileSidebarOpen = (open, restoreFocus = false) => {
        document.body.classList.toggle('sidebar-open', open);
        toggle?.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle?.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
        toggle?.setAttribute('title', open ? 'Close navigation' : 'Open navigation');

        if (open) {
            document.body.classList.remove('sidebar-collapsed');
            mobileSidebarClose?.focus({ preventScroll: true });
        } else if (restoreFocus) {
            toggle?.focus({ preventScroll: true });
        }
    };

    if (toggle) {
        toggle.addEventListener('click', () => {
            setMobileSidebarOpen(!document.body.classList.contains('sidebar-open'));
        });
    }

    mobileSidebarClose?.addEventListener('click', () => setMobileSidebarOpen(false, true));
    document.querySelector('[data-mobile-nav-overlay]')?.addEventListener('click', () => setMobileSidebarOpen(false, true));

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && document.body.classList.contains('sidebar-open')) {
            setMobileSidebarOpen(false, true);
        }
    });

    document.querySelectorAll('.side-link, .sidebar-quick-link').forEach((link) => {
        link.addEventListener('click', () => setMobileSidebarOpen(false));
    });

    document.querySelector('.app-main')?.addEventListener('click', (event) => {
        if (event.target.closest('[data-sidebar-toggle]')) return;
        if (document.body.classList.contains('sidebar-open')) {
            setMobileSidebarOpen(false);
        }
    });

    document.querySelectorAll('[data-add-row]').forEach((button) => {
        button.addEventListener('click', () => {
            const target = document.querySelector(button.dataset.addRow);
            const templateSelector = button.dataset.template || button.dataset.rowTemplate;
            const template = document.querySelector(templateSelector);
            if (!target || !template) return;
            target.insertAdjacentHTML('beforeend', template.innerHTML.trim());
            notiflix?.Notify.info('Material line added.');
        });
    });

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-confirm]');
        if (!trigger || trigger.dataset.confirmed === 'true' || !notiflix) return;

        event.preventDefault();
        const title = trigger.dataset.confirmTitle || 'Please confirm';
        const message = trigger.dataset.confirm;

        notiflix.Confirm.show(title, message, 'Continue', 'Cancel', () => {
            trigger.dataset.confirmed = 'true';

            if (trigger instanceof HTMLAnchorElement) {
                window.location.assign(trigger.href);
                return;
            }

            const form = trigger.form;
            if (form) {
                form.requestSubmit(trigger);
            }
        });
    });

    document.querySelectorAll('form').forEach((form) => {
        form.addEventListener('submit', () => {
            if (!notiflix) return;
            const message = form.dataset.blockOnSubmit || (form.method.toLowerCase() === 'post' ? 'Saving changes' : '');
            if (message) {
                notiflix.Block.standard([form], message);
            }
        });
    });

    document.querySelectorAll('form[data-filter-form]').forEach((form) => {
        form.addEventListener('submit', () => {
            notiflix?.Notify.info('Filters applied.');
        });
    });

    document.querySelectorAll('[data-clear-filters]').forEach((link) => {
        link.addEventListener('click', () => {
            notiflix?.Notify.info('Filters cleared.');
        });
    });

    document.querySelectorAll('[data-saved-filter-form]').forEach((savedFilterForm) => {
        savedFilterForm.addEventListener('submit', () => {
            notiflix?.Notify.info('Saving filter preset.');
        });
    });

    const parseCellValue = (cell) => {
        const text = (cell?.textContent || '').trim();
        const numeric = Number(text.replace(/[^0-9.-]/g, ''));
        if (text !== '' && Number.isFinite(numeric) && /[0-9]/.test(text)) {
            return numeric;
        }
        return text.toLowerCase();
    };

    const setupSmartTables = () => {
        document.querySelectorAll('table[data-sortable-table], table.data.modern-table').forEach((table) => {
            if (table.dataset.smartTableReady === 'true') return;
            table.dataset.smartTableReady = 'true';

            const tbody = table.tBodies[0];
            if (!tbody) return;

            const pageSize = Math.max(5, Number(table.dataset.tablePageSize) || Number(table.dataset.pageSize) || 15);
            let currentPage = 1;
            let rows = Array.from(tbody.rows);

            const tableShell = table.closest('.ops-table-shell');
            const existingControls = tableShell?.querySelector('[data-table-pagination]');
            const controls = existingControls || document.createElement('div');
            controls.className = 'smart-table-controls ops-pagination';
            controls.replaceChildren();
            const summary = document.createElement('span');
            const prev = document.createElement('button');
            const next = document.createElement('button');
            prev.type = 'button';
            next.type = 'button';
            prev.className = 'btn secondary compact';
            next.className = 'btn secondary compact';
            prev.textContent = 'Prev';
            next.textContent = 'Next';
            controls.append(summary, prev, next);
            if (!existingControls) {
                table.closest('.table-wrap')?.after(controls);
            }

            const renderPage = () => {
                rows = Array.from(tbody.rows);
                const totalPages = Math.max(1, Math.ceil(rows.length / pageSize));
                currentPage = Math.max(1, Math.min(currentPage, totalPages));
                const start = (currentPage - 1) * pageSize;
                const end = start + pageSize;
                rows.forEach((row, index) => {
                    row.hidden = index < start || index >= end;
                });
                summary.textContent = `Page ${currentPage} of ${totalPages} / ${rows.length} row(s)`;
                prev.disabled = currentPage <= 1;
                next.disabled = currentPage >= totalPages;
            };

            table.querySelectorAll('thead th').forEach((header, index) => {
                header.tabIndex = 0;
                header.dataset.sortDirection = '';
                header.classList.add('sortable-heading');

                const sortRows = () => {
                    const direction = header.dataset.sortDirection === 'asc' ? 'desc' : 'asc';
                    table.querySelectorAll('thead th').forEach((item) => item.dataset.sortDirection = '');
                    header.dataset.sortDirection = direction;
                    rows = Array.from(tbody.rows).sort((a, b) => {
                        const left = parseCellValue(a.cells[index]);
                        const right = parseCellValue(b.cells[index]);
                        if (left < right) return direction === 'asc' ? -1 : 1;
                        if (left > right) return direction === 'asc' ? 1 : -1;
                        return 0;
                    });
                    rows.forEach((row) => tbody.appendChild(row));
                    currentPage = 1;
                    renderPage();
                    notiflix?.Notify.info('Table sorted.');
                };

                header.addEventListener('click', sortRows);
                header.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        sortRows();
                    }
                });
            });

            prev.addEventListener('click', () => {
                currentPage -= 1;
                renderPage();
            });
            next.addEventListener('click', () => {
                currentPage += 1;
                renderPage();
            });

            renderPage();
        });
    };

    setupSmartTables();

    document.querySelectorAll('form[data-forecast-run]').forEach((form) => {
        form.addEventListener('submit', () => {
            notiflix?.Loading.standard('Running forecast model');
        });
    });

    const chartPalette = ['#159f94', '#39bdb1', '#6bcfc5', '#126f78', '#73a9a4', '#4b8f89', '#9adbd4', '#2e9b66'];
    const riskPalette = {
        critical: '#c64b4b',
        high: '#f0a83a',
        medium: '#39bdb1',
        stable: '#2e9b66'
    };

    const liveChartKeys = {
        'risk-donut': 'risk_distribution',
        'demand-line': 'demand_trend',
        'movement-bars': 'movement_flow',
        'location-stock': 'location_stock',
        'procurement-bars': 'procurement_flow'
    };

    const compactNumber = new Intl.NumberFormat('en-US', {
        maximumFractionDigits: 0
    });

    const parseChartPayload = (canvas) => {
        try {
            const parsed = JSON.parse(canvas.dataset.chartData || '[]');
            return parsed && typeof parsed === 'object' ? parsed : [];
        } catch (error) {
            return [];
        }
    };

    const normalizeChartRows = (payload) => {
        if (Array.isArray(payload)) {
            return payload.map((row, index) => {
                const value = Number(row.value ?? row.forecast_qty ?? row.recommended_order_qty ?? row.qty_out ?? row.qty_in ?? 0);
                return {
                    label: String(row.label ?? row.material_code ?? row.name ?? `Item ${index + 1}`),
                    value: Number.isFinite(value) ? value : 0,
                    risk: String(row.risk ?? row.risk_level ?? '').toLowerCase()
                };
            }).filter((row) => row.label && Number.isFinite(row.value));
        }

        return Object.keys(payload).map((key) => {
            const value = Number(payload[key]);
            return {
                label: key,
                value: Number.isFinite(value) ? value : 0,
                risk: key.toLowerCase()
            };
        });
    };

    const prettyLabel = (label) => String(label)
        .replace(/[_-]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .replace(/\b\w/g, (letter) => letter.toUpperCase());

    const setupChartCanvas = (canvas) => {
        const context = canvas.getContext('2d');
        if (!context) return null;

        const declaredWidth = Number(canvas.dataset.chartWidth)
            || Number(canvas.getAttribute('width'))
            || 360;
        const declaredHeight = Number(canvas.dataset.chartHeight)
            || Number(canvas.getAttribute('height'))
            || 210;
        canvas.dataset.chartWidth = String(declaredWidth);
        canvas.dataset.chartHeight = String(declaredHeight);

        const displayedWidth = Math.round(
            Number(canvas.clientWidth)
            || Number(canvas.getBoundingClientRect?.().width)
            || declaredWidth
        );
        const width = Math.max(1, displayedWidth);
        const height = Math.max(1, Math.round(width * (declaredHeight / declaredWidth)));
        const ratio = Math.min(3, Math.max(2, Number(window.devicePixelRatio) || 1));

        canvas.width = Math.round(width * ratio);
        canvas.height = Math.round(height * ratio);
        canvas.style.width = '100%';
        canvas.style.height = `${height}px`;
        canvas.dataset.chartRenderWidth = String(width);

        if (typeof context.setTransform === 'function') {
            context.setTransform(ratio, 0, 0, ratio, 0, 0);
        } else {
            context.scale(ratio, ratio);
        }

        context.clearRect(0, 0, width, height);
        const chartBackground = context.createLinearGradient(0, 0, width, height);
        chartBackground.addColorStop(0, '#fbfefe');
        chartBackground.addColorStop(1, '#edf9f7');
        context.fillStyle = chartBackground;
        context.fillRect(0, 0, width, height);
        context.strokeStyle = '#d6ebe8';
        context.lineWidth = 1;

        for (let x = 18; x < width; x += 36) {
            context.beginPath();
            context.moveTo(x, 0);
            context.lineTo(x, height);
            context.stroke();
        }

        for (let y = 24; y < height; y += 36) {
            context.beginPath();
            context.moveTo(0, y);
            context.lineTo(width, y);
            context.stroke();
        }

        return { context, width, height };
    };

    const writeChartText = (context, text, x, y, options = {}) => {
        context.fillStyle = options.color || '#111820';
        context.font = `${options.weight || 800} ${options.size || 10}px "IBM Plex Mono", Consolas, monospace`;
        context.textAlign = options.align || 'left';
        context.textBaseline = options.baseline || 'alphabetic';
        context.fillText(String(text), x, y);
    };

    const drawNoData = (canvas, message = 'NO DATA') => {
        const prepared = setupChartCanvas(canvas);
        if (!prepared) return;
        const { context, width, height } = prepared;
        writeChartText(context, message, width / 2, height / 2, {
            color: '#65717b',
            size: 12,
            align: 'center',
            baseline: 'middle'
        });
    };

    const colorForRow = (row, index) => riskPalette[row.risk] || chartPalette[index % chartPalette.length];

    const drawDonutChart = (canvas, rows) => {
        const prepared = setupChartCanvas(canvas);
        if (!prepared) return;
        const { context, width, height } = prepared;
        const total = rows.reduce((sum, row) => sum + Math.max(row.value, 0), 0);
        if (total <= 0) {
            drawNoData(canvas);
            return;
        }

        const centerX = width * 0.36;
        const centerY = height * 0.5;
        const radius = Math.min(width, height) * 0.28;
        let startAngle = -Math.PI / 2;

        rows.forEach((row, index) => {
            const slice = (Math.max(row.value, 0) / total) * Math.PI * 2;
            context.beginPath();
            context.moveTo(centerX, centerY);
            context.arc(centerX, centerY, radius, startAngle, startAngle + slice);
            context.closePath();
            context.fillStyle = colorForRow(row, index);
            context.fill();
            startAngle += slice;
        });

        context.globalCompositeOperation = 'destination-out';
        context.beginPath();
        context.arc(centerX, centerY, radius * 0.58, 0, Math.PI * 2);
        context.fill();
        context.globalCompositeOperation = 'source-over';

        writeChartText(context, compactNumber.format(total), centerX, centerY - 2, {
            color: '#111820',
            size: 21,
            align: 'center',
            baseline: 'middle'
        });
        writeChartText(context, 'TOTAL', centerX, centerY + 22, {
            color: '#65717b',
            size: 9,
            align: 'center',
            baseline: 'middle'
        });

        rows.forEach((row, index) => {
            const y = 42 + (index * 31);
            context.fillStyle = colorForRow(row, index);
            context.fillRect(width * 0.64, y - 10, 14, 14);
            writeChartText(context, prettyLabel(row.label), (width * 0.64) + 22, y, {
                color: '#111820',
                size: 10
            });
            writeChartText(context, compactNumber.format(row.value), width - 16, y, {
                color: '#111820',
                size: 11,
                align: 'right'
            });
        });
    };

    const drawLineChart = (canvas, rows) => {
        const prepared = setupChartCanvas(canvas);
        if (!prepared) return;
        const { context, width, height } = prepared;
        if (rows.length < 2) {
            drawNoData(canvas);
            return;
        }

        const padding = { top: 24, right: 18, bottom: 42, left: 38 };
        const values = rows.map((row) => row.value);
        const max = Math.max(...values, 1);
        const min = Math.min(...values, 0);
        const span = Math.max(max - min, 1);
        const plotWidth = width - padding.left - padding.right;
        const plotHeight = height - padding.top - padding.bottom;
        const points = rows.map((row, index) => ({
            x: padding.left + ((plotWidth / Math.max(rows.length - 1, 1)) * index),
            y: padding.top + (plotHeight - (((row.value - min) / span) * plotHeight)),
            row
        }));

        context.beginPath();
        points.forEach((point, index) => {
            if (index === 0) {
                context.moveTo(point.x, point.y);
            } else {
                context.lineTo(point.x, point.y);
            }
        });
        context.lineTo(points[points.length - 1].x, height - padding.bottom);
        context.lineTo(points[0].x, height - padding.bottom);
        context.closePath();
        context.fillStyle = 'rgba(57, 189, 177, 0.16)';
        context.fill();

        context.beginPath();
        points.forEach((point, index) => {
            if (index === 0) {
                context.moveTo(point.x, point.y);
            } else {
                context.lineTo(point.x, point.y);
            }
        });
        context.strokeStyle = '#118f84';
        context.lineWidth = 3;
        context.stroke();

        points.forEach((point, index) => {
            context.fillStyle = index === points.length - 1 ? '#126f78' : '#39bdb1';
            context.beginPath();
            context.arc(point.x, point.y, index === points.length - 1 ? 5 : 3, 0, Math.PI * 2);
            context.fill();
        });

        writeChartText(context, compactNumber.format(max), 9, padding.top + 5, { color: '#65717b', size: 9 });
        writeChartText(context, compactNumber.format(min), 9, height - padding.bottom + 4, { color: '#65717b', size: 9 });
        writeChartText(context, rows[0].label, padding.left, height - 16, { color: '#65717b', size: 9 });
        writeChartText(context, rows[rows.length - 1].label, width - 12, height - 16, {
            color: '#65717b',
            size: 9,
            align: 'right'
        });
    };

    const drawVerticalBars = (canvas, rows) => {
        const prepared = setupChartCanvas(canvas);
        if (!prepared) return;
        const { context, width, height } = prepared;
        if (rows.length === 0) {
            drawNoData(canvas);
            return;
        }

        const visibleRows = rows.slice(0, 8);
        const max = Math.max(...visibleRows.map((row) => row.value), 1);
        const padding = { top: 24, right: 18, bottom: 52, left: 22 };
        const plotWidth = width - padding.left - padding.right;
        const plotHeight = height - padding.top - padding.bottom;
        const gap = 9;
        const barWidth = Math.max(16, (plotWidth - (gap * (visibleRows.length - 1))) / visibleRows.length);

        visibleRows.forEach((row, index) => {
            const barHeight = Math.max(3, (Math.max(row.value, 0) / max) * plotHeight);
            const x = padding.left + (index * (barWidth + gap));
            const y = padding.top + plotHeight - barHeight;
            context.fillStyle = 'rgba(17, 143, 132, 0.08)';
            context.fillRect(x, padding.top, barWidth, plotHeight);
            context.fillStyle = colorForRow(row, index);
            context.fillRect(x, y, barWidth, barHeight);
            writeChartText(context, compactNumber.format(row.value), x + (barWidth / 2), y - 7, {
                color: '#111820',
                size: 9,
                align: 'center'
            });
            writeChartText(context, prettyLabel(row.label).slice(0, 10), x + (barWidth / 2), height - 24, {
                color: '#65717b',
                size: 8,
                align: 'center'
            });
        });
    };

    const drawHorizontalBars = (canvas, rows) => {
        const prepared = setupChartCanvas(canvas);
        if (!prepared) return;
        const { context, width, height } = prepared;
        if (rows.length === 0) {
            drawNoData(canvas);
            return;
        }

        const visibleRows = rows.slice(0, 8);
        const max = Math.max(...visibleRows.map((row) => row.value), 1);
        const top = 22;
        const left = Math.min(174, Math.max(104, width * 0.27));
        const right = 20;
        const rowGap = 7;
        const rowHeight = Math.max(16, ((height - top - 18) / visibleRows.length) - rowGap);
        const plotWidth = width - left - right;

        visibleRows.forEach((row, index) => {
            const y = top + (index * (rowHeight + rowGap));
            const barWidth = Math.max(3, (Math.max(row.value, 0) / max) * plotWidth);
            writeChartText(context, prettyLabel(row.label).slice(0, 24), left - 10, y + (rowHeight / 2), {
                color: '#111820',
                size: 9,
                align: 'right',
                baseline: 'middle'
            });
            context.fillStyle = 'rgba(17, 143, 132, 0.08)';
            context.fillRect(left, y, plotWidth, rowHeight);
            context.fillStyle = colorForRow(row, index);
            context.fillRect(left, y, barWidth, rowHeight);
            const valueText = compactNumber.format(row.value);
            const outsideX = left + barWidth + 7;
            const valueWidth = typeof context.measureText === 'function' ? context.measureText(valueText).width : valueText.length * 7;
            const valueInside = outsideX + valueWidth > width - 10;
            writeChartText(context, valueText, valueInside ? Math.max(left + 8, left + barWidth - 7) : outsideX, y + (rowHeight / 2), {
                color: valueInside ? '#ffffff' : '#111820',
                size: 9,
                align: valueInside ? 'right' : 'left',
                baseline: 'middle'
            });
        });
    };

    const renderDashboardChart = (canvas, nextPayload = null) => {
        const payload = nextPayload ?? parseChartPayload(canvas);
        if (nextPayload !== null) {
            canvas.dataset.chartData = JSON.stringify(nextPayload);
        }
        const rows = normalizeChartRows(payload);
        const type = canvas.dataset.chart || 'bars';

        if (type.includes('donut') || type.includes('risk') || type.includes('urgency')) {
            drawDonutChart(canvas, rows);
            return;
        }

        if (type.includes('line')) {
            drawLineChart(canvas, rows);
            return;
        }

        if (type.includes('location') || type.includes('order-bars')) {
            drawHorizontalBars(canvas, rows);
            return;
        }

        drawVerticalBars(canvas, rows);
    };

    const dashboardChartCanvases = [...document.querySelectorAll('canvas[data-chart]')];

    dashboardChartCanvases.forEach((canvas) => {
        renderDashboardChart(canvas);
    });

    if (typeof window.ResizeObserver === 'function' && dashboardChartCanvases.length > 0) {
        const pendingCharts = new Set();
        let resizeFrame = null;
        const flushChartResize = () => {
            resizeFrame = null;
            pendingCharts.forEach((canvas) => renderDashboardChart(canvas));
            pendingCharts.clear();
        };
        const chartResizeObserver = new window.ResizeObserver((entries) => {
            entries.forEach((entry) => {
                const nextWidth = Math.round(Number(entry.contentRect?.width) || 0);
                const previousWidth = Number(entry.target.dataset.chartRenderWidth) || 0;
                if (nextWidth > 0 && Math.abs(nextWidth - previousWidth) > 1) {
                    pendingCharts.add(entry.target);
                }
            });

            if (pendingCharts.size > 0 && resizeFrame === null) {
                if (typeof window.requestAnimationFrame === 'function') {
                    resizeFrame = window.requestAnimationFrame(flushChartResize);
                } else {
                    flushChartResize();
                }
            }
        });

        dashboardChartCanvases.forEach((canvas) => chartResizeObserver.observe(canvas));
    }

    document.querySelectorAll('canvas[data-chart][data-drilldown-url]').forEach((canvas) => {
        canvas.classList.add('chart-clickable');
        canvas.title = 'Open related records';
        canvas.addEventListener('click', () => {
            notiflix?.Notify.info('Opening chart drill-down.');
            window.location.assign(canvas.dataset.drilldownUrl);
        });
    });

    document.querySelectorAll('[data-live-dashboard]').forEach((root) => {
        const liveUrl = root.dataset.liveUrl;
        if (!liveUrl) return;

        const refresh = async () => {
            try {
                const response = await fetch(liveUrl, {
                    cache: 'no-store',
                    headers: { Accept: 'application/json' }
                });
                if (!response.ok) {
                    throw new Error(`Live dashboard request failed with ${response.status}`);
                }

                const snapshot = await response.json();
                const metrics = snapshot.metrics || {};

                root.querySelectorAll('[data-live-value]').forEach((element) => {
                    const key = element.dataset.liveValue;
                    if (Object.prototype.hasOwnProperty.call(metrics, key)) {
                        element.textContent = compactNumber.format(Number(metrics[key]) || 0);
                    }
                });

                root.querySelectorAll('canvas[data-chart]').forEach((canvas) => {
                    const dataKey = liveChartKeys[canvas.dataset.chart];
                    if (dataKey && Object.prototype.hasOwnProperty.call(snapshot, dataKey)) {
                        renderDashboardChart(canvas, snapshot[dataKey]);
                    }
                });
            } catch (error) {
                notiflix?.Notify.warning('Live dashboard data is temporarily unavailable.');
            }
        };

        refresh();
        const delay = Math.max(15000, Number(root.dataset.refreshMs) || 45000);
        setInterval(refresh, delay);
    });

    document.querySelectorAll('canvas[data-sparkline]').forEach((canvas) => {
        const context = canvas.getContext('2d');
        if (!context) return;

        let values = [];
        try {
            values = JSON.parse(canvas.dataset.sparkline || '[]').map(Number).filter((value) => Number.isFinite(value));
        } catch (error) {
            values = [];
        }

        const width = Number(canvas.getAttribute('width')) || 150;
        const height = Number(canvas.getAttribute('height')) || 42;
        const ratio = Math.min(3, Math.max(2, Number(window.devicePixelRatio) || 1));
        canvas.width = width * ratio;
        canvas.height = height * ratio;
        canvas.style.width = `${width}px`;
        canvas.style.height = `${height}px`;
        context.scale(ratio, ratio);
        context.clearRect(0, 0, width, height);

        context.fillStyle = '#f8fbf7';
        context.fillRect(0, 0, width, height);
        context.strokeStyle = '#dce6e3';
        context.lineWidth = 1;
        for (let x = 10; x < width; x += 20) {
            context.beginPath();
            context.moveTo(x, 0);
            context.lineTo(x, height);
            context.stroke();
        }

        if (values.length < 2) {
            context.fillStyle = '#65717b';
            context.font = '10px "IBM Plex Mono", Consolas, monospace';
            context.fillText('NO DATA', 47, 25);
            return;
        }

        const min = Math.min(...values);
        const max = Math.max(...values);
        const span = Math.max(max - min, 1);
        const step = width / (values.length - 1);

        context.strokeStyle = '#118f84';
        context.lineWidth = 2;
        context.beginPath();
        values.forEach((value, index) => {
            const x = index * step;
            const y = height - 5 - (((value - min) / span) * (height - 10));
            if (index === 0) {
                context.moveTo(x, y);
            } else {
                context.lineTo(x, y);
            }
        });
        context.stroke();

        const last = values[values.length - 1];
        const lastY = height - 5 - (((last - min) / span) * (height - 10));
        context.fillStyle = '#f0a83a';
        context.beginPath();
        context.arc(width - 2, lastY, 3, 0, Math.PI * 2);
        context.fill();
    });

    document.querySelectorAll('[data-ai-preview]').forEach((button) => {
        button.addEventListener('click', () => {
            const title = button.dataset.aiTitle || 'Recommendation';
            const message = button.dataset.aiPreview || '';
            if (notiflix) {
                notiflix.Report.info(title, message, 'Close');
            }
        });
    });
});
