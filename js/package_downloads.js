import * as echarts from 'echarts/core';
import { LineChart } from 'echarts/charts';
import { GridComponent, TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { formatDay, formatDigit } from './chartUtils';

echarts.use([LineChart, GridComponent, TooltipComponent, CanvasRenderer]);

(function () {
    "use strict";

    const instances = new Set();

    window.addEventListener('resize', function () {
        instances.forEach(function (chart) {
            chart.resize();
        });
    });

    window.initPackageDownloads = function (selector, statsUrl, label) {
        var el = document.querySelector(selector);
        if (!el) {
            return;
        }

        fetch(statsUrl, {headers: {Accept: 'application/json'}})
            .then(function (response) {
                return response.ok ? response.json() : null;
            })
            .then(function (res) {
                var seriesName = res && res.labels && res.labels.length ? Object.keys(res.values || {})[0] : null;
                if (!seriesName) {
                    return;
                }

                var values = res.values[seriesName] || [];
                var points = res.labels.map(function (dateLabel, index) {
                    var parts = dateLabel.split('-');
                    var date = new Date(+parts[0], parts[1] - 1, +parts[2]);
                    return [date.getTime(), parseInt(values[index], 10) || 0];
                });

                var chart = echarts.getInstanceByDom(el);
                if (!chart) {
                    chart = echarts.init(el);
                    instances.add(chart);
                }

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

                            return formatDay(value[0]) + '<br>' + echarts.format.encodeHTML(label).replace('%count%', '<strong>' + formatDigit(value[1]) + '</strong>');
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
                }, true);
            })
            .catch(function () {});
    };
})();
