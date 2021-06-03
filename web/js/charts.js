(function ($) {
    "use strict";

    var colors = [
        '#f28d1a',
        '#2d2d32'
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

    function initPackagistChart(svg, labels, series, withDatePicker, type) {
        type = type || 'line';
        var format = d3.time.format("%Y-%m-%d");
        if (labels[0].match(/^\d+-\d+$/)) {
            format = d3.time.format("%Y-%m");
        }

        var chartData = [];
        series.map(function (serie, index) {
            var points = [];
            labels.map(function (label, index) {
                points.push({x: format.parse(label), y: parseInt(serie.values[index]) || 0});
            })
            chartData.push({
                values: points,
                key: serie.name,
                color: colors[serie.name] || colors[index],
            });
        })

        if (withDatePicker && $(window).width() < 767) {
            withDatePicker = false;
        }

        nv.addGraph(function() {
            var chart;
            if (type === 'line') {
                if (withDatePicker) {
                    chart = nv.models.lineWithFocusChart();
                } else {
                    chart = nv.models.lineChart();
                }
                chart.useInteractiveGuideline(true);
            } else if (type === 'area') {
                chart = nv.models.stackedAreaChart();
                chart.showControls(false);
                chart.useInteractiveGuideline(true);
                chart.rightAlignYAxis(true);
                chart.tooltip.valueFormatter((d) => d + '%')
                chart.stacked.style('expand');
            } else {
                throw new Error('Unknown type: ' + type);
            }

            function formatDate(a,b) {
                if (!(a instanceof Date)) {
                    a = new Date(a);
                }
                return format(a,b);
            }
            function formatDigit(a,b) {
                if (a > 1000000) {
                    return (a/1000000).toFixed(1) + 'mio';
                }
                if (a > 1000) {
                    return (a/1000).toFixed(1) + 'K';
                }
                return a;
            }

            chart.xAxis.tickFormat(formatDate);
            chart.yAxis.tickFormat(formatDigit);

            if (withDatePicker) {
                chart.x2Axis.tickFormat(formatDate);
                chart.y2Axis.tickFormat(formatDigit);
            }

            d3.select(svg)
                .datum(chartData)
                .transition().duration(100)
                .call(chart);

            nv.utils.windowResize(chart.update);

            return chart;
        });
    };

    $('svg[data-labels]').each(function () {
        initPackagistChart(
            this,
            $(this).attr('data-labels').split(','),
            $(this).attr('data-values').split('|').map(function (values) {
                values = values.split(':');
                return {
                    name: values[0],
                    values: values[1].split(',')
                };
            })
        );
    });

    window.initPackageStats = function (average, date, versions, statsUrl, versionStatsUrl) {
        colors = [
            '#f28d1a',
            '#1765f4',
            '#ed1f96',
            '#ee1e23',
            '#b817f4',
            '#c4f516',
            '#804040',
            '#ff8040',
            '#008080',
            '#004080',
            '#8080ff',
            '#800040',
            '#800000',
        ];

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
                true
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
                $('.package .versions-expander').removeClass('hidden').on('click', function () {
                    $(this).addClass('hidden');
                    $(versionsList).css('max-height', 'inherit');
                });
            } else {
                $('.package .versions-expander').addClass('hidden')
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

    window.initPhpStats = function (average, date, versions, versionStatsUrl) {
        colors = PHP_VERSION_COLORS;

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
                'area'
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
                $('.package .versions-expander').removeClass('hidden').on('click', function () {
                    $(this).addClass('hidden');
                    $(versionsList).css('max-height', 'inherit');
                });
            } else {
                $('.package .versions-expander').addClass('hidden')
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
        colors = PHP_VERSION_COLORS;

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
            'area'
        );
    };
})(jQuery);
