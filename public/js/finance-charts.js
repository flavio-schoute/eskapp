/**
 * Finance dashboard charts: a light band behind the month under the mouse, and a tooltip that lists every series of
 * that month (colour dot, name, amount aligned right), like the original Finance app.
 */
(() => {
    const hoverBand = {
        id: 'financeHoverBand',
        beforeDatasetsDraw(chart) {
            const active = chart.tooltip?.getActiveElements?.() ?? [];

            if (! active.length || ! chart.options.plugins?.financeHoverBand?.enabled) {
                return;
            }

            const x = chart.scales.x;
            const index = active[0].index;
            const step = x.width / Math.max(chart.data.labels.length, 1);
            const center = x.getPixelForValue(index);
            const { ctx, chartArea } = chart;

            ctx.save();
            ctx.fillStyle = 'rgba(127, 127, 127, 0.09)';
            ctx.fillRect(center - step / 2, chartArea.top, step, chartArea.bottom - chartArea.top);
            ctx.restore();
        },
    };

    const euro = (value) => '€ ' + Math.round(value).toLocaleString('nl-NL');

    window.financeChartTooltip = (context) => {
        const { chart, tooltip } = context;
        let element = chart.canvas.parentNode.querySelector('.finance-chart-tooltip');

        if (! element) {
            element = document.createElement('div');
            element.className = 'finance-chart-tooltip';
            element.style.cssText = 'position: absolute; pointer-events: none; z-index: 10; min-width: 12rem; padding: 0.5rem 0.625rem; border-radius: 0.5rem; font-size: 0.75rem; line-height: 1.35; transition: opacity 120ms ease, transform 120ms ease; box-shadow: 0 4px 16px rgb(0 0 0 / 0.12); border: 1px solid rgb(127 127 127 / 0.2);';
            chart.canvas.parentNode.style.position = 'relative';
            chart.canvas.parentNode.appendChild(element);
        }

        const dark = document.documentElement.classList.contains('dark');
        element.style.background = dark ? 'rgb(24 24 27)' : '#fff';
        element.style.color = dark ? '#f4f4f5' : '#18181b';

        if (tooltip.opacity === 0 || ! tooltip.dataPoints?.length) {
            element.style.opacity = '0';

            return;
        }

        const rows = tooltip.dataPoints
            .filter((point) => point.parsed.y !== 0 && point.parsed.y !== null)
            .map((point) => {
                const color = Array.isArray(point.dataset.backgroundColor) ? point.dataset.backgroundColor[point.dataIndex] : point.dataset.backgroundColor;

                return `<div style="display: flex; align-items: center; gap: 0.5rem; padding: 0.0625rem 0;">
                    <span style="width: 0.5rem; height: 0.5rem; border-radius: 9999px; background: ${String(color).slice(0, 7)}; flex-shrink: 0;"></span>
                    <span style="flex: 1; opacity: 0.75; white-space: nowrap;">${point.dataset.label}</span>
                    <span style="font-variant-numeric: tabular-nums; white-space: nowrap; margin-inline-start: 0.75rem;">${euro(point.parsed.y)}</span>
                </div>`;
            })
            .join('');

        element.innerHTML = `<div style="font-weight: 600; margin-bottom: 0.25rem;">${tooltip.title?.[0] ?? ''}</div>${rows || '<div style="opacity: 0.6;">Geen bedragen</div>'}`;

        const { offsetLeft, offsetTop } = chart.canvas;
        const left = offsetLeft + tooltip.caretX + 14;
        const fitsRight = left + element.offsetWidth < chart.canvas.parentNode.clientWidth;

        element.style.opacity = '1';
        element.style.left = (fitsRight ? left : offsetLeft + tooltip.caretX - element.offsetWidth - 14) + 'px';
        element.style.top = Math.max(0, offsetTop + chart.chartArea.top) + 'px';
    };

    window.filamentChartJsPlugins ??= [];
    window.filamentChartJsPlugins.push(hoverBand);
})();
