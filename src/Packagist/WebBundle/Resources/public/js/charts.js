(function ($) {
    "use strict";

    var colors = [
        '#f28d1a',
        '#2d2d32'
    ];

    function initPackagistChart(svg, labels, series, withDatePicker) {
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
                color: colors[index]
            });
        })

        if (withDatePicker && $(window).width() < 767) {
            withDatePicker = false;
        }

        nv.addGraph(function() {
            var chart;
            if (withDatePicker) {
                chart = nv.models.lineWithFocusChart();
            } else {
                chart = nv.models.lineChart();
            }

            function formatDate(a,b) {
                if (!(a instanceof Date)) {
                    a = new Date(a);
                }
                return format(a,b);
            }
            function formatDigit(a,b) {
                if (a > 1000000) {
                    return Math.round(a/1000000) + 'mio';
                }
                if (a > 1000) {
                    return Math.round(a/1000) + 'K';
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
        var match,
            hash = document.location.hash,
            versionCache = {},
            ongoingRequest = false;

        function initChart(type, res) {
            initPackagistChart(
                $('.js-'+type+'-dls')[0],
                res.labels,
                [{name: 'Daily Downloads', values: res.values}],
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
            loadVersionChart($('.package .details-toggler.open').attr('data-version-id'));
        }

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

        // initialize version list expander
        var versionsList = $('.package .versions')[0];
        if (versionsList.offsetHeight < versionsList.scrollHeight) {
            $('.package .versions-expander').removeClass('hidden').on('click', function () {
                $(this).addClass('hidden');
                $(versionsList).css('max-height', 'inherit');
            });
        }
    };
})(jQuery);
