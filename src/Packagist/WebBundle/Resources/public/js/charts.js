(function ($) {
    "use strict";

    var colors = [
        'rgba(0,0,255,1)',
        'rgba(255,153,0,1)'
    ];

    $('canvas[data-labels]').each(function () {
        var element = $(this);
        var labels = element.attr('data-labels').split(',');
        var values = element.attr('data-values').split('|');
        var ctx = this.getContext("2d");
        var data = {
            labels: labels,
            datasets: []
        };
        var opts = {
            bezierCurve: false
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
                            return parseInt(value, 10);
                        })
                }
            );
        }

        new Chart(ctx).Line(data, opts);
    });
})(jQuery);
