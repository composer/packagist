(function ($) {
    "use strict";

    var colors = [
        'rgba(242, 141, 26, 1)',
        'rgba(45, 45, 50, 1)'
    ];

    Chart.defaults.global.responsive = true;
    Chart.defaults.global.animationSteps = 10;
    Chart.defaults.global.tooltipYPadding = 10;

    function initPackagistChart(canvas, labels, values, scale, tooltips) {
        var ctx = canvas.getContext("2d");
        var data = {
            labels: labels.map(function (val, index, arr) {
                return index % Math.round(arr.length / 50) == 0 ? val : '';
            }),
            datasets: []
        };
        var scale = parseInt(scale, 10);
        var scaleUnit = '';
        switch (scale) {
            case 1000:
                scaleUnit = 'K';
                break;
            case 1000000:
                scaleUnit = 'mio';
                break;
        }

        var opts = {
            bezierCurve: false,
            scaleLabel: " <%=value%>" + scaleUnit,
            tooltipTemplate: "<%if (label){%><%=label%>: <%}%><%= value %>" + scaleUnit,
            pointDot: false
        };

        if (!tooltips || labels.length > 50 || labels.length <= 2) {
            opts.showTooltips = false;
        }

        for (var i = 0; i < values.length; i++) {
            data.datasets.push(
                {
                    fillColor: "rgba(0,0,0,0)",
                    strokeColor: colors[i],
                    pointColor: colors[i],
                    pointStrokeColor: "#fff",
                    data: values[i].map(function (value) {
                        return Math.round(parseInt(value, 10) / scale, 2);
                    })
                }
            );
        }

        new Chart(ctx).Line(data, opts);
    };

    $('canvas[data-labels]').each(function () {
        initPackagistChart(
            this,
            $(this).attr('data-labels').split(','),
            $(this).attr('data-values').split('|').map(function (values) { return values.split(','); }),
            $(this).attr('data-scale'),
            true
        );
    });

    window.initPackageStats = function (average, date, versions, statsUrl, versionStatsUrl) {
        var match,
            hash = document.location.hash,
            versionCache = {},
            ongoingRequest = false;

        $.ajax({
            url: statsUrl,
            success: function (res) {
                initPackagistChart($('.js-all-dls')[0], res.labels, [res.values], Math.max.apply(res.values) > 10000 ? 1000 : 1, false);
            }
        })
        function loadVersionChart(versionId) {
            ongoingRequest = true;
            $.ajax({
                url: versionStatsUrl.replace('_VERSION_', versionId) + '?average=' + average + '&from=' + date,
                success: function (res) {
                    initPackagistChart($('.js-version-dls')[0], res.labels, [res.values], Math.max.apply(res.values) > 10000 ? 1000 : 1, false);
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
                initPackagistChart($('.js-version-dls')[0], res.labels, [res.values], Math.max.apply(res.values) > 10000 ? 1000 : 1, false);
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
            $('.version-stats-chart').css('top', Math.max(0, window.scrollY - $('.version-stats').offset().top + 80) + 'px');
        });

        // initialize version list expander
        var versionsList = $('.package .versions')[0];
        if (versionsList.offsetHeight < versionsList.scrollHeight) {
            $('.package .versions-expander').removeClass('hidden').on('click', function () {
                $(this).addClass('hidden');
                $(versionsList).css('overflow-y', 'visible')
                    .css('max-height', 'inherit');
            });
        }
    };
})(jQuery);
