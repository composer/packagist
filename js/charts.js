import * as echarts from 'echarts/core';
import { LineChart } from 'echarts/charts';
import {
    DataZoomInsideComponent,
    DataZoomSliderComponent,
    GridComponent,
    LegendComponent,
    TooltipComponent,
} from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import jQuery from 'jquery';
import '../css/charts.css';

echarts.use([
    LineChart,
    GridComponent,
    LegendComponent,
    TooltipComponent,
    DataZoomInsideComponent,
    DataZoomSliderComponent,
    CanvasRenderer,
]);

(function ($) {
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
        'hhvm': '#cdcdcd',
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

    function formatDate(value) {
        if (!(value instanceof Date)) {
            value = new Date(value);
        }
        var month = ('0' + (value.getMonth() + 1)).slice(-2);
        return value.getFullYear() + '-' + month;
    }

    function formatDay(value) {
        if (!(value instanceof Date)) {
            value = new Date(value);
        }
        var month = ('0' + (value.getMonth() + 1)).slice(-2);
        var day = ('0' + value.getDate()).slice(-2);
        return value.getFullYear() + '-' + month + '-' + day;
    }

    function formatDigit(value) {
        if (!isFinite(value)) {
            return value;
        }
        if (value > 1000000) {
            return (value / 1000000).toFixed(1) + 'mio';
        }
        if (value > 1000) {
            return (value / 1000).toFixed(1) + 'K';
        }
        return value;
    }

    const instances = new Set();

    function trackInstance(chart) {
        instances.add(chart);

        chart.getZr().on('dispose', function () {
            instances.delete(chart);
        });

        return chart;
    }

    $(window).on('resize', function () {
        instances.forEach(function (chart) {
            chart.resize();
        });
    });

    function initPackagistChart(el, labels, series, withDatePicker, type, colorMap) {
        type = type || 'line';
        colorMap = colorMap || null;

        if (!el || !labels.length || !series.length) {
            return;
        }

        if (withDatePicker && $(window).width() < 767) {
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

        var colors = [];
        var chartSeries;

        if (type === 'area') {
            var totals = xValues.map(function (_, index) {
                return series.reduce(function (sum, serie) {
                    return sum + (parseFloat(serie.values[index]) || 0);
                }, 0);
            });

            chartSeries = series.map(function (serie) {
                return {
                    name: serie.name,
                    type: 'line',
                    stack: 'total',
                    large: true,
                    smooth: false,
                    symbol: 'none',
                    emphasis: {focus: 'series'},
                    lineStyle: {width: 0},
                    areaStyle: {opacity: 1.0},
                    data: xValues.map(function (x, index) {
                        var total = totals[index];
                        var value = total ? Math.round((parseFloat(serie.values[index]) || 0) * 1000 / total) / 10 : 0;
                        return [x, value];
                    }),
                };
            });
        } else {
            chartSeries = series.map(function (serie) {
                return {
                    name: serie.name,
                    type: 'line',
                    smooth: false,
                    symbol: 'none',
                    emphasis: {focus: 'series'},
                    lineStyle: {width: 2},
                    data: xValues.map(function (x, index) {
                        return [x, parseInt(serie.values[index], 10) || 0];
                    }),
                };
            });
        }

        // name-based lookup first, then positional palette
        colors = series.map(function (serie, index) {
            if (colorMap && colorMap[serie.name] !== undefined) {
                return colorMap[serie.name];
            }
            if (Array.isArray(colorMap) && colorMap[index] !== undefined) {
                return colorMap[index];
            }
            return MULTI_COLORS[index % MULTI_COLORS.length];
        });

        var grid = {
            left: 10,
            right: 20,
            top: 40,
            bottom: withDatePicker ? 70 : 25,
            containLabel: true
        };

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
            tooltip: {
                trigger: 'axis',
                axisPointer: {type: 'line'},
                backgroundColor: 'rgba(255, 255, 255, 0.96)',
                borderColor: '#ddd',
                textStyle: {color: '#2d2d32', fontSize: 12},
                formatter: function (params) {
                    if (!Array.isArray(params)) {
                        params = [params];
                    }

                    var title = params[0].axisValueLabel || format(params[0].axisValue);
                    var html = title + '<br>';
                    html += params.map(function (param) {
                        var value = Array.isArray(param.value) ? param.value[1] : param.value;
                        if (type === 'area') {
                            value = (isFinite(value) ? value.toFixed(1) : value) + '%';
                        } else {
                            value = formatDigit(value);
                        }
                        return param.marker + ' ' + param.seriesName + ': <strong>' + value + '</strong>';
                    }).join('<br>');

                    return html;
                }
            },
            grid: grid,
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
            option.dataZoom = [
                {
                    type: 'inside',
                    throttle: 50
                },
                {
                    type: 'slider',
                    height: 30,
                    bottom: 10,
                    labelFormatter: isDaily ? format : undefined
                }
            ];
        }

        var existing = echarts.getInstanceByDom(el);
        if (existing) {
            existing.dispose();
        }

        var chart = echarts.init(el);
        chart.setOption(option);

        return trackInstance(chart);
    };

    $('[data-labels]').each(function () {
        initPackagistChart(
            this,
            $(this).attr('data-labels').split(','),
            $(this).attr('data-values').split('|').map(function (values) {
                values = values.split(':');
                return {
                    name: values[0],
                    values: values[1].split(',')
                };
            }),
            false,
            'line',
            DEFAULT_COLORS
        );
    });

    window.initPackageStats = function (average, date, statsUrl, versionStatsUrl) {
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

            initPackagistChart(
                $('.js-'+type+'-dls')[0],
                res.labels,
                series,
                true,
                'line',
                MULTI_COLORS
            );
        }

        $.ajax({
            url: statsUrl,
            success: function (res) {
                initChart('all', res);
            }
        })
        function loadVersionChart(versionId) {
            ongoingRequest = true;
            $.ajax({
                url: versionStatsUrl.replace('_VERSION_', versionId) + '?average=' + average + '&from=' + date,
                success: function (res) {
                    initChart('version', res);
                    versionCache[versionId] = res;
                    ongoingRequest = false;
                }
            });
        }

        function toggleStatsType(statsType) {
            $('.package .stats-toggler.open').removeClass('open');
            $('.package .stats-toggler[data-stats-type=' + statsType + ']').addClass('open');

            $('.package .stats-wrapper').hide();
            $('.package .stats-wrapper[data-stats-type=' + statsType + ']').show();

            initializeVersionListExpander();
        }

        function initializeVersionListExpander() {
            var versionsList = $('.package .versions:visible')[0];
            if (versionsList.offsetHeight < versionsList.scrollHeight) {
                $('.package .versions-expander').removeClass('d-none').on('click', function () {
                    $(this).addClass('d-none');
                    $(versionsList).css('max-height', 'inherit');
                });
            } else {
                $('.package .versions-expander').addClass('d-none')
            }
        }

        // initializer for #<version-id> present on page load
        if (hash.length > 1) {
            hash = hash.substring(1);
            match = $('.package .details-toggler[data-version-id="'+hash+'"]');
            if (match.length) {
                $('.package .details-toggler.open').removeClass('open');
                match.addClass('open');

                toggleStatsType(match.closest('[data-stats-type]').attr('data-stats-type'));
            }
        } else {
            match = $('.package .details-toggler.open');
            toggleStatsType(match.closest('[data-stats-type]').attr('data-stats-type'));
        }

        if ($('.package .details-toggler.open').length) {
            loadVersionChart($('.package .details-toggler.open').attr('data-version-id'));
        }

        $('.package .stats-toggler').on('click', function () {
            var target = $(this);
            toggleStatsType($(this).attr('data-stats-type'));

            $('.package .details-toggler[data-version-id="' + target.attr('href').substr(1) + '"]').trigger('click');
        });

        $('.package .details-toggler').on('click', function () {
            var res, target = $(this), versionId = target.attr('data-version-id');

            if (versionCache[versionId]) {
                res = versionCache[versionId];
                initChart('version', res);
            } else {
                if (ongoingRequest) {
                    return;
                }
                loadVersionChart(versionId);
            }

            $('.package .details-toggler.open').removeClass('open');
            target.addClass('open');
        });

        $(window).on('scroll', function () {
            var basePos = $('.version-stats').offset().top;
            var footerPadding = $(document).height() - basePos - $('footer').height() - $('.version-stats-chart').height() - 50;
            var headerPadding = 80;
            $('.version-stats-chart').css('top', Math.max(0, Math.min(footerPadding, window.scrollY - basePos + headerPadding)) + 'px');
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

            initPackagistChart(
                $('.js-'+type+'-dls')[0],
                res.labels,
                series,
                true,
                'area',
                PHP_VERSION_COLORS
            );
        }

        function loadVersionChart(versionId, type) {
            ongoingRequest = true;
            $.ajax({
                url: versionStatsUrl.replace('_VERSION_', versionId).replace('_TYPE_', type) + '?average=' + average + '&from=' + date,
                success: function (res) {
                    initChart('version', res);
                    versionCache[versionId+type] = res;
                    ongoingRequest = false;
                }
            });
        }

        function switchToChart(versionId) {
            const type = $('#ignore_platform').is(':checked') ? 'effective' : 'platform';

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

        function initializeVersionListExpander() {
            var versionsList = $('.package .versions:visible')[0];
            if (versionsList.offsetHeight < versionsList.scrollHeight) {
                $('.package .versions-expander').removeClass('d-none').on('click', function () {
                    $(this).addClass('d-none');
                    $(versionsList).css('max-height', 'inherit');
                });
            } else {
                $('.package .versions-expander').addClass('d-none')
            }
        }

        // initializer for #<version-id> present on page load
        if (hash.length > 1) {
            hash = hash.substring(1);
            match = $('.package .details-toggler[data-version-id="'+hash+'"]');
            if (match.length) {
                $('.package .details-toggler.open').removeClass('open');
                match.addClass('open');
            }
        }

        if ($('.package .details-toggler.open').length) {
            switchToChart($('.package .details-toggler.open').attr('data-version-id'));
        }

        $('.package .details-toggler').on('click', function () {
            const target = $(this),
                versionId = target.attr('data-version-id');

            if (!switchToChart(versionId)) {
                return;
            }

            $('.package .details-toggler.open').removeClass('open');
            target.addClass('open');
        });

        $('#ignore_platform').on('click', function () {
            const versionId = $('.package .details-toggler.open').attr('data-version-id');

            switchToChart(versionId);
        })

        $(window).on('scroll', function () {
            var basePos = $('.version-stats').offset().top;
            var footerPadding = $(document).height() - basePos - $('footer').height() - $('.version-stats-chart').height() - 50;
            var headerPadding = 80;
            $('.version-stats-chart').css('top', Math.max(0, Math.min(footerPadding, window.scrollY - basePos + headerPadding)) + 'px');
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

        initPackagistChart(
            $(selector)[0],
            res.labels,
            series,
            true,
            'area',
            PHP_VERSION_COLORS
        );
    };
})(jQuery);
