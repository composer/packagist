import * as echarts from 'echarts/core';
import { HeatmapChart, LineChart } from 'echarts/charts';
import {
    DataZoomInsideComponent,
    DataZoomSliderComponent,
    GridComponent,
    LegendComponent,
    TooltipComponent,
    VisualMapComponent,
} from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { formatDate, formatDay, formatDigit } from './chartUtils';
import '../css/charts.css';

echarts.use([
    HeatmapChart,
    LineChart,
    GridComponent,
    LegendComponent,
    TooltipComponent,
    VisualMapComponent,
    DataZoomInsideComponent,
    DataZoomSliderComponent,
    CanvasRenderer,
]);

(function () {
    "use strict";

    const DEFAULT_COLORS = [
        '#f28d1a',
        '#2d2d32'
    ];

    const MULTI_COLORS = [
        '#f28d1a',
        '#1765f4',
        '#ee1e23',
        '#c4f516',
        '#b817f4',
        '#804040',
        '#008080',
        '#ff8040',
        '#ed1f96',
        '#004080',
        '#800040',
        '#8080ff',
        '#800000',
    ];

    const PHP_VERSION_COLORS = {
        '5.2': '#ffbd74',
        '5.3': '#ee8e33',
        '5.4': '#e77f02',
        '5.5': '#9a601e',
        '5.6': '#8d4a00',
        '7.0': '#a2fd88',
        '7.1': '#56f52d',
        '7.2': '#2cec00',
        '7.3': '#4f903b',
        '7.4': '#33a316',
        '8.0': '#7171ff',
        '8.1': '#2e2ef3',
        '8.2': '#111192',
        '8.3': '#5a4ac1',
        '8.4': '#4a5ce7',
        '8.5': '#1f77b4',
        '8.6': '#2f8fd6',
        'hhvm': '#cdcdcd',
    };

    const TOOLTIP_STYLE = {
        backgroundColor: 'rgba(255, 255, 255, 0.96)',
        borderColor: '#ddd',
        textStyle: {color: '#2d2d32', fontSize: 12},
    };

    function phpVersionSort(a, b) {
        if (a.name === 'hhvm') {
            return 1;
        }
        if (b.name === 'hhvm') {
            return -1;
        }

        return b.name.localeCompare(a.name, undefined, {numeric: true});
    }

    const instances = new Set();

    function getChart(el) {
        var chart = echarts.getInstanceByDom(el);
        if (!chart) {
            chart = echarts.init(el);
            instances.add(chart);
        }

        return chart;
    }

    function getJson(url) {
        return fetch(url, {headers: {'Accept': 'application/json'}}).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        });
    }

    // rendered, i.e. not under a display:none ancestor
    function isVisible(el) {
        return el.getClientRects().length > 0;
    }

    function findToggler(versionId) {
        return Array.from(document.querySelectorAll('.package .details-toggler')).find(function (el) {
            return el.getAttribute('data-version-id') === versionId;
        });
    }

    function openToggler() {
        return document.querySelector('.package .details-toggler.open');
    }

    function setOpenToggler(el) {
        document.querySelectorAll('.package .details-toggler.open').forEach(function (open) {
            open.classList.remove('open');
        });
        el.classList.add('open');
    }

    function initializeVersionListExpander() {
        var versionsList = Array.from(document.querySelectorAll('.package .versions')).find(isVisible);
        var expanders = document.querySelectorAll('.package .versions-expander');
        if (versionsList.offsetHeight < versionsList.scrollHeight) {
            expanders.forEach(function (el) {
                el.classList.remove('d-none');
                el.addEventListener('click', function () {
                    el.classList.add('d-none');
                    versionsList.style.maxHeight = 'inherit';
                });
            });
        } else {
            expanders.forEach(function (el) {
                el.classList.add('d-none');
            });
        }
    }

    window.addEventListener('resize', function () {
        instances.forEach(function (chart) {
            chart.resize();
        });
    });

    function initPackagistChart(el, labels, series, options) {
        options = options || {};
        var type = options.type || 'line';
        var colorMap = options.colorMap || null;
        var palette = options.palette || MULTI_COLORS;
        var withDatePicker = !!options.withDatePicker;
        var valueLabel = options.valueLabel || null;
        var defaultDays = options.defaultDays || 0;

        if (!el) {
            return;
        }

        if (!labels.length || !series.length) {
            var existing = echarts.getInstanceByDom(el);
            if (existing) {
                existing.clear();
            }
            return;
        }

        if (withDatePicker && document.documentElement.clientWidth < 767) {
            withDatePicker = false;
        }

        var isDaily = labels[0].indexOf('-') !== -1 && labels[0].split('-').length === 3;
        var format = isDaily ? formatDay : formatDate;

        // daily charts use a real time scale, monthly ones a category axis
        // (a time axis generates sub-month ticks when zooming in, which would
        // render as repeated identical month-only labels)
        var xValues = labels.map(function (label) {
            if (!isDaily) {
                return label;
            }
            var parts = label.split('-');
            return new Date(+parts[0], parts[1] - 1, +parts[2]).getTime();
        });

        var rawValues = series.map(function (serie) {
            return xValues.map(function (_, index) {
                return (type === 'area' ? parseFloat(serie.values[index]) : parseInt(serie.values[index], 10)) || 0;
            });
        });

        function areaSeriesData(selected) {
            var totals = xValues.map(function (_, index) {
                return rawValues.reduce(function (sum, values, serieIndex) {
                    return selected[serieIndex] ? sum + values[index] : sum;
                }, 0);
            });

            return rawValues.map(function (values) {
                return xValues.map(function (x, index) {
                    var total = totals[index];
                    return [x, total ? Math.round(values[index] * 1000 / total) / 10 : 0];
                });
            });
        }

        var chartSeries;
        if (type === 'area') {
            var initialData = areaSeriesData(series.map(function () { return true; }));
            chartSeries = series.map(function (series, index) {
                return {
                    name: series.name,
                    type: 'line',
                    stack: 'total',
                    large: true,
                    smooth: false,
                    symbol: 'none',
                    emphasis: {focus: 'series'},
                    lineStyle: {width: 0},
                    areaStyle: {opacity: 1.0},
                    data: initialData[index],
                };
            });
        } else {
            chartSeries = series.map(function (series, index) {
                return {
                    name: series.name,
                    type: 'line',
                    smooth: false,
                    symbol: 'none',
                    emphasis: {focus: 'series'},
                    lineStyle: {width: 2},
                    data: xValues.map(function (x, pointIndex) {
                        return [x, rawValues[index][pointIndex]];
                    }),
                };
            });
        }

        var colors = series.map(function (serie, index) {
            if (colorMap && Object.prototype.hasOwnProperty.call(colorMap, serie.name)) {
                return colorMap[serie.name];
            }
            return palette[index % palette.length];
        });

        var option = {
            animation: false,
            color: colors,
            legend: {
                type: 'scroll',
                top: 0,
                icon: 'roundRect',
                itemWidth: 12,
                itemHeight: 6
            },
            tooltip: Object.assign({
                trigger: 'axis',
                axisPointer: {type: 'line'},
                formatter: function (params) {
                    if (!Array.isArray(params)) {
                        params = [params];
                    }

                    var title = isDaily ? format(params[0].axisValue) : params[0].axisValue;
                    var html = echarts.format.encodeHTML(String(title)) + '<br>';
                    html += params.map(function (param) {
                        var value = Array.isArray(param.value) ? param.value[1] : param.value;
                        if (type === 'area') {
                            value = (isFinite(value) ? value.toFixed(1) : value) + '%';
                        } else {
                            value = formatDigit(value);
                        }
                        var rendered = valueLabel
                            ? echarts.format.encodeHTML(valueLabel).replace('%count%', '<strong>' + value + '</strong>')
                            : '<strong>' + value + '</strong>';

                        return param.marker + ' ' + echarts.format.encodeHTML(param.seriesName) + ': ' + rendered;
                    }).join('<br>');

                    return html;
                }
            }, TOOLTIP_STYLE),
            grid: {
                left: 10,
                right: 20,
                top: 40,
                bottom: withDatePicker ? 70 : 25,
                containLabel: true
            },
            xAxis: isDaily ? {
                type: 'time',
                axisLabel: {formatter: format, hideOverlap: true},
                axisLine: {show: false},
                splitLine: {show: false}
            } : {
                type: 'category',
                data: labels,
                boundaryGap: false,
                axisLabel: {hideOverlap: true},
                axisLine: {show: false},
                splitLine: {show: false}
            },
            yAxis: {
                type: 'value',
                axisLabel: {
                    formatter: type === 'area' ? '{value}%' : formatDigit
                },
                max: type === 'area' ? 100 : null,
                splitLine: {lineStyle: {color: '#eee'}}
            },
            series: chartSeries
        };

        if (withDatePicker) {
            var inside = {
                type: 'inside',
                throttle: 50,
                zoomOnMouseWheel: 'ctrl',
                moveOnMouseWheel: false
            };
            var slider = {
                type: 'slider',
                height: 30,
                bottom: 10,
                labelFormatter: isDaily ? format : undefined
            };

            // years of daily points render sub-pixel, and the antialiased seams
            // between stacked bands then show through as white speckle - open on a
            // readable window and leave the rest reachable via the slider
            if (defaultDays && isDaily && xValues.length > defaultDays) {
                inside.startValue = slider.startValue = xValues[xValues.length - defaultDays];
            }

            option.dataZoom = [inside, slider];
        }

        var chart = getChart(el);
        chart.setOption(option, true);

        chart.off('legendselectchanged');
        if (type === 'area') {
            chart.on('legendselectchanged', function (event) {
                var data = areaSeriesData(series.map(function (serie) {
                    return event.selected[serie.name] !== false;
                }));
                chart.setOption({
                    series: series.map(function (serie, index) {
                        return {name: serie.name, data: data[index]};
                    })
                });
            });
        }

        return chart;
    }

    document.querySelectorAll('[data-labels]').forEach(function (el) {
        initPackagistChart(
            el,
            el.getAttribute('data-labels').split(','),
            el.getAttribute('data-values').split('|').map(function (values) {
                values = values.split(':');
                return {
                    name: values[0],
                    values: values[1].split(',')
                };
            }),
            {palette: DEFAULT_COLORS}
        );
    });

    window.initPackageStats = function (average, date, statsUrl, versionStatsUrl, perDayLabel) {
        var match,
            hash = document.location.hash,
            versionCache = {},
            ongoingRequest = false;

        function initChart(type, res) {
            var key, series = [];

            for (key in res.values) {
                if (res.values.hasOwnProperty(key)) {
                    series.push({name: key, values: res.values[key]});
                }
            }

            series.sort(function (a, b) {
                if (a.name.indexOf('.')) {
                    return b.name.replace(/^\d+\./, '').localeCompare(a.name.replace(/^\d+\./, ''), undefined, {numeric: true});
                }
                return b.name.localeCompare(a.name, undefined, {numeric: true});
            })

            initPackagistChart(document.querySelector('.js-'+type+'-dls'), res.labels, series, {
                withDatePicker: true,
                // averaged buckets hold a per-day mean, not a bucket total
                valueLabel: average === 'daily' ? null : perDayLabel
            });
        }

        getJson(statsUrl).then(function (res) {
            initChart('all', res);
        });
        function loadVersionChart(versionId) {
            ongoingRequest = true;
            getJson(versionStatsUrl.replace('_VERSION_', versionId) + '?average=' + average + '&from=' + date).then(function (res) {
                initChart('version', res);
                versionCache[versionId] = res;
                ongoingRequest = false;
            });
        }

        function toggleStatsType(statsType) {
            document.querySelectorAll('.package .stats-toggler').forEach(function (el) {
                el.classList.toggle('open', el.getAttribute('data-stats-type') === statsType);
            });
            document.querySelectorAll('.package .stats-wrapper').forEach(function (el) {
                el.style.display = el.getAttribute('data-stats-type') === statsType ? '' : 'none';
            });

            initializeVersionListExpander();
        }

        // initializer for #<version-id> present on page load
        if (hash.length > 1) {
            hash = hash.substring(1);
            match = findToggler(hash);
            if (match) {
                setOpenToggler(match);

                toggleStatsType(match.closest('[data-stats-type]')?.getAttribute('data-stats-type'));
            }
        } else {
            match = openToggler();
            toggleStatsType(match?.closest('[data-stats-type]')?.getAttribute('data-stats-type'));
        }

        if (openToggler()) {
            loadVersionChart(openToggler().getAttribute('data-version-id'));
        }

        document.querySelectorAll('.package .stats-toggler').forEach(function (el) {
            el.addEventListener('click', function () {
                toggleStatsType(el.getAttribute('data-stats-type'));

                findToggler(el.getAttribute('href').substr(1))?.click();
            });
        });

        document.querySelectorAll('.package .details-toggler').forEach(function (target) {
            target.addEventListener('click', function () {
                var res, versionId = target.getAttribute('data-version-id');

                if (versionCache[versionId]) {
                    res = versionCache[versionId];
                    initChart('version', res);
                } else {
                    if (ongoingRequest) {
                        return;
                    }
                    loadVersionChart(versionId);
                }

                setOpenToggler(target);
            });
        });

        initializeVersionListExpander();
    };

    window.initPhpStats = function (average, date, versionStatsUrl) {
        var match,
            hash = document.location.hash,
            versionCache = {},
            ongoingRequest = false;

        function initChart(type, res) {
            var key, series = [];

            for (key in res.values) {
                if (res.values.hasOwnProperty(key)) {
                    series.push({name: key, values: res.values[key]});
                }
            }

            series.sort(phpVersionSort)

            initPackagistChart(document.querySelector('.js-'+type+'-dls'), res.labels, series, {
                withDatePicker: true,
                type: 'area',
                colorMap: PHP_VERSION_COLORS,
                defaultDays: 365
            });
        }

        function loadVersionChart(versionId, type) {
            ongoingRequest = true;
            getJson(versionStatsUrl.replace('_VERSION_', versionId).replace('_TYPE_', type) + '?average=' + average + '&from=' + date).then(function (res) {
                initChart('version', res);
                versionCache[versionId+type] = res;
                ongoingRequest = false;
            });
        }

        function switchToChart(versionId) {
            const type = document.querySelector('#ignore_platform')?.checked ? 'effective' : 'platform';

            if (versionCache[versionId+type]) {
                const res = versionCache[versionId+type];
                initChart('version', res);
            } else {
                if (ongoingRequest) {
                    return false;
                }
                loadVersionChart(versionId, type);
            }

            return true;
        }

        // initializer for #<version-id> present on page load
        if (hash.length > 1) {
            hash = hash.substring(1);
            match = findToggler(hash);
            if (match) {
                setOpenToggler(match);
            }
        }

        if (openToggler()) {
            switchToChart(openToggler().getAttribute('data-version-id'));
        }

        document.querySelectorAll('.package .details-toggler').forEach(function (target) {
            target.addEventListener('click', function () {
                if (!switchToChart(target.getAttribute('data-version-id'))) {
                    return;
                }

                setOpenToggler(target);
            });
        });

        document.querySelector('#ignore_platform')?.addEventListener('click', function () {
            switchToChart(openToggler()?.getAttribute('data-version-id'));
        });

        initializeVersionListExpander();
    };

    window.initGlobalPhpStats = function (selector, res) {
        var key, series = [];

        for (key in res.values) {
            if (res.values.hasOwnProperty(key)) {
                series.push({name: key, values: res.values[key]});
            }
        }

        series.sort(phpVersionSort)

        initPackagistChart(document.querySelector(selector), res.labels, series, {
            withDatePicker: true,
            type: 'area',
            colorMap: PHP_VERSION_COLORS,
            defaultDays: 365
        });
    };

    function monthNames(style) {
        var formatter = new Intl.DateTimeFormat('en', {month: style});
        var names = [];
        for (var month = 0; month < 12; month++) {
            names.push(formatter.format(new Date(2000, month, 1)));
        }

        return names;
    }

    window.initReleaseStats = function (selector, counts, labels) {
        var el = document.querySelector(selector);
        if (!el) {
            return;
        }

        function hide() {
            el.closest('section')?.classList.add('d-none');
        }

        var keys = Object.keys(counts || {}).sort();
        if (!keys.length) {
            hide();
            return;
        }

        var now = new Date();
        var startYear = parseInt(keys[0].split('-')[0], 10);
        var startMonth = parseInt(keys[0].split('-')[1], 10);
        var endYear = Math.max(now.getFullYear(), startYear);
        var endMonth = endYear === now.getFullYear() ? now.getMonth() + 1 : 12;
        if (endYear === startYear && endMonth < startMonth) {
            hide();
            return;
        }

        var MONTHS_SHORT = monthNames('short');
        var MONTHS_LONG = monthNames('long');

        var years = [];
        for (var year = endYear; year >= startYear; year--) {
            years.push(year);
        }

        // size the container for one row per year before initializing
        el.style.height = (years.length * 34 + 90) + 'px';

        var data = [];
        var max = 0;
        years.forEach(function (year, yearIndex) {
            var firstMonth = year === startYear ? startMonth : 1;
            var lastMonth = year === endYear ? endMonth : 12;
            for (var month = firstMonth; month <= lastMonth; month++) {
                var count = counts[year + '-' + ('0' + month).slice(-2)] || 0;
                max = Math.max(max, count);
                data.push([month - 1, yearIndex, count]);
            }
        });

        var chart = getChart(el);
        chart.setOption({
            animation: false,
            tooltip: Object.assign({
                formatter: function (params) {
                    var value = params.value;
                    var title = MONTHS_LONG[value[0]] + ' ' + years[value[1]];
                    var releases = (value[2] === 1 ? labels.one : labels.many).replace('%count%', value[2]);

                    return echarts.format.encodeHTML(title) + '<br><strong>' + echarts.format.encodeHTML(releases) + '</strong>';
                }
            }, TOOLTIP_STYLE),
            grid: {
                left: 10,
                right: 10,
                top: 10,
                bottom: 45,
                containLabel: true
            },
            xAxis: {
                type: 'category',
                data: MONTHS_SHORT,
                axisLine: {show: false},
                axisTick: {show: false},
                splitArea: {show: false}
            },
            yAxis: {
                type: 'category',
                data: years.map(String),
                inverse: true,
                axisLine: {show: false},
                axisTick: {show: false}
            },
            visualMap: {
                min: 0,
                max: Math.max(max, 1),
                calculable: false,
                orient: 'horizontal',
                left: 'center',
                bottom: 0,
                itemWidth: 12,
                itemHeight: 70,
                text: [String(Math.max(max, 1)), '0'],
                textStyle: {fontSize: 11},
                inRange: {
                    color: ['#f5f5f5', '#fbd9b0', '#f5a94e', '#f28d1a']
                }
            },
            series: [{
                type: 'heatmap',
                data: data,
                itemStyle: {
                    borderColor: '#fff',
                    borderWidth: 2,
                    borderRadius: 3
                },
                emphasis: {
                    itemStyle: {
                        shadowBlur: 4,
                        shadowColor: 'rgba(0, 0, 0, 0.3)'
                    }
                }
            }]
        }, true);

        return chart;
    };
})();
