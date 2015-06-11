(function ($) {
    "use strict";

    var colors = [
        'rgba(242, 141, 26, 1)',
        'rgba(45, 45, 50, 1)'
    ];

    Chart.defaults.global.responsive = true;
    Chart.defaults.global.animationSteps = 10;
    Chart.defaults.global.tooltipYPadding = 10;

    $('canvas[data-labels]').each(function () {
        var element = $(this);
        var labels = element.attr('data-labels').split(',');
        var values = element.attr('data-values').split('|');
        var ctx = this.getContext("2d");
        var data = {
            labels: labels,
            datasets: []
        };
        var scale = parseInt(element.attr('data-scale'), 10);
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
            scaleLabel: " <%=value%>" + scaleUnit
        };

        for (var i = 0; i < values.length; i++) {
            data.datasets.push(
                {
                    fillColor: "rgba(0,0,0,0)",
                    strokeColor: colors[i],
                    pointColor: colors[i],
                    pointStrokeColor: "#fff",
                    data: values[i].split(',')
                        .map(function (value) {
                            return Math.round(parseInt(value, 10) / scale, 2);
                        })
                }
            );
        }

        new Chart(ctx).Line(data, opts);
    });
})(jQuery);
