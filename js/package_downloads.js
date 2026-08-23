import * as echarts from 'echarts/core';
import { LineChart } from 'echarts/charts';
import { GridComponent, TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import jQuery from 'jquery';

echarts.use([LineChart, GridComponent, TooltipComponent, CanvasRenderer]);

(function ($) {
    "use strict";

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

    window.initPackageDownloads = function (selector, statsUrl) {
        var el = $(selector)[0];
        if (!el) {
            return;
        }

        $.ajax({
            url: statsUrl,
            dataType: 'json',
            success: function (res) {
                var seriesName = res && res.labels && res.labels.length ? Object.keys(res.values || {})[0] : null;
                if (!seriesName) {
                    return;
                }

                var values = res.values[seriesName] || [];
                var points = res.labels.map(function (label, index) {
                    var parts = label.split('-');
                    var date = new Date(+parts[0], parts[1] - 1, +parts[2]);
                    return [date.getTime(), parseInt(values[index], 10) || 0];
                });

                var chart = echarts.init(el);
                chart.setOption({
                    animation: false,
                    tooltip: {
                        trigger: 'axis',
                        axisPointer: {type: 'line'},
                        backgroundColor: 'rgba(255, 255, 255, 0.96)',
                        borderColor: '#ddd',
                        textStyle: {color: '#2d2d32', fontSize: 12},
                        formatter: function (params) {
                            params = Array.isArray(params) ? params : [params];
                            var value = params[0].value;

                            return formatDay(value[0]) + '<br><strong>' + formatDigit(value[1]) + '</strong> avg. weekly installs';
                        }
                    },
                    grid: {
                        left: 5,
                        right: 8,
                        top: 10,
                        bottom: 10,
                        containLabel: false
                    },
                    xAxis: {
                        type: 'time',
                        axisLine: {show: false},
                        axisTick: {show: false},
                        splitLine: {show: false},
                        axisLabel: {show: false}
                    },
                    yAxis: {
                        // scale: true picks a sensible non-zero minimum so flat
                        // high-volume charts don't look artificially full
                        type: 'value',
                        scale: true,
                        axisLabel: {show: false},
                        axisTick: {show: false},
                        splitLine: {show: false}
                    },
                    series: [{
                        type: 'line',
                        name: seriesName,
                        data: points,
                        smooth: true,
                        symbol: 'none',
                        lineStyle: {width: 2, color: '#f28d1a'},
                        areaStyle: {
                            color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                                {offset: 0, color: 'rgba(242, 141, 26, 0.35)'},
                                {offset: 1, color: 'rgba(242, 141, 26, 0.02)'}
                            ])
                        }
                    }]
                });

                $(window).on('resize', function () {
                    chart.resize();
                });
            }
        });
    };

    function formatDay(value) {
        var date = new Date(value);
        var month = ('0' + (date.getMonth() + 1)).slice(-2);
        var day = ('0' + date.getDate()).slice(-2);

        return date.getFullYear() + '-' + month + '-' + day;
    }
})(jQuery);
