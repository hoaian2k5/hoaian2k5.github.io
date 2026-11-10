/**
 * UniPortal – Student Dashboard JavaScript
 * Handles: sidebar toggle, GPA line chart, degree donut chart, tab switching.
 * Depends on Chart.js 4.x (loaded via CDN before this script).
 * Data injected by PHP into window.UNIPORTAL object.
 */

'use strict';

document.addEventListener('DOMContentLoaded', function () {

    const D = window.UNIPORTAL || {};

    // ── Sidebar / hamburger toggle (mobile) ──────────────────
    const sidebar    = document.getElementById('sidebar');
    const hamburger  = document.getElementById('hamburger');

    if (hamburger && sidebar) {
        hamburger.addEventListener('click', function () {
            const open = sidebar.classList.toggle('open');
            hamburger.setAttribute('aria-expanded', String(open));
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function (e) {
            if (window.innerWidth > 900) return;
            if (!sidebar.contains(e.target) && !hamburger.contains(e.target)) {
                sidebar.classList.remove('open');
                hamburger.setAttribute('aria-expanded', 'false');
            }
        });
    }

    // ── GPA Trend Chart ───────────────────────────────────────
    const gpaCanvas = document.getElementById('gpaChartCanvas');
    let gpaChart    = null;

    if (gpaCanvas && D.chartLabels && D.chartLabels.length > 0) {

        const semDataset = {
            label       : 'GPA học kỳ',
            data        : D.chartSemGpa,
            borderColor : '#102b50',
            backgroundColor: 'rgba(16,43,80,.08)',
            pointBackgroundColor : '#102b50',
            pointRadius  : 5,
            pointHoverRadius : 7,
            borderWidth  : 2.5,
            tension      : 0.35,
            fill         : true,
        };

        const cumDataset = {
            label       : 'GPA tích lũy',
            data        : D.chartCumGpa,
            borderColor : '#fdcc35',
            backgroundColor: 'rgba(253,204,53,.10)',
            pointBackgroundColor : '#fdcc35',
            pointRadius  : 5,
            pointHoverRadius : 7,
            borderWidth  : 2.5,
            tension      : 0.35,
            fill         : true,
        };

        // Target line annotation (3.20 = "Giỏi")
        const targetPlugin = {
            id: 'targetLine',
            afterDraw(chart) {
                const ctx   = chart.ctx;
                const yAxis = chart.scales.y;
                const xAxis = chart.scales.x;
                if (!yAxis || !xAxis) return;
                const y = yAxis.getPixelForValue(3.20);
                ctx.save();
                ctx.beginPath();
                ctx.setLineDash([6, 4]);
                ctx.moveTo(xAxis.left,  y);
                ctx.lineTo(xAxis.right, y);
                ctx.strokeStyle = '#755b00';
                ctx.lineWidth   = 1.5;
                ctx.stroke();
                ctx.setLineDash([]);
                ctx.font      = '600 8.5px "Be Vietnam Pro", sans-serif';
                ctx.fillStyle = '#755b00';
                ctx.textAlign = 'right';
                ctx.fillText('Ngưỡng mục tiêu Giỏi: 3,20', xAxis.right - 4, y - 4);
                ctx.restore();
            }
        };

        gpaChart = new Chart(gpaCanvas, {
            type   : 'line',
            data   : { labels: D.chartLabels, datasets: [semDataset] },
            options: {
                responsive : true,
                maintainAspectRatio: false,
                plugins: {
                    legend : { display: false },
                    tooltip: {
                        callbacks: {
                            label: ctx => ' GPA: ' + ctx.parsed.y.toFixed(2)
                        }
                    }
                },
                scales: {
                    y: {
                        min       : 2.00,
                        max       : 4.00,
                        ticks     : {
                            stepSize  : 0.40,
                            font      : { family: "'Be Vietnam Pro'", size: 8 },
                            color     : '#74777f',
                            callback  : v => v.toFixed(2)
                        },
                        grid: { color: 'rgba(0,0,0,.05)' }
                    },
                    x: {
                        ticks : {
                            font : { family: "'Be Vietnam Pro'", size: 8 },
                            color: '#74777f'
                        },
                        grid  : { display: false }
                    }
                }
            },
            plugins: [targetPlugin]
        });
    }

    // ── Chart tab switching ───────────────────────────────────
    document.querySelectorAll('.chart-tab').forEach(btn => {
        btn.addEventListener('click', function () {
            if (!gpaChart) return;
            document.querySelectorAll('.chart-tab').forEach(b => b.classList.remove('active'));
            this.classList.add('active');

            const type = this.dataset.chart; // 'semester' | 'cumulative'
            if (type === 'semester') {
                gpaChart.data.datasets[0].data        = D.chartSemGpa;
                gpaChart.data.datasets[0].label       = 'GPA học kỳ';
                gpaChart.data.datasets[0].borderColor = '#102b50';
                gpaChart.data.datasets[0].backgroundColor = 'rgba(16,43,80,.08)';
                gpaChart.data.datasets[0].pointBackgroundColor = '#102b50';
            } else {
                gpaChart.data.datasets[0].data        = D.chartCumGpa;
                gpaChart.data.datasets[0].label       = 'GPA tích lũy';
                gpaChart.data.datasets[0].borderColor = '#fdcc35';
                gpaChart.data.datasets[0].backgroundColor = 'rgba(253,204,53,.10)';
                gpaChart.data.datasets[0].pointBackgroundColor = '#fdcc35';
            }
            gpaChart.update('active');
        });
    });

    // ── Degree progress donut chart ───────────────────────────
    const donutCanvas = document.getElementById('degreeDonutCanvas');
    if (donutCanvas) {
        const earned    = D.earnedCredits || 0;
        const remaining = Math.max(0, (D.totalCredits || 130) - earned);

        new Chart(donutCanvas, {
            type: 'doughnut',
            data: {
                labels  : ['Đã tích lũy', 'Còn lại'],
                datasets: [{
                    data           : [earned, remaining],
                    backgroundColor: ['#102b50', '#e5eeff'],
                    borderWidth    : 0,
                    hoverOffset    : 4,
                }]
            },
            options: {
                responsive : false,
                cutout     : '70%',
                plugins    : {
                    legend : { display: false },
                    tooltip: {
                        callbacks: {
                            label: ctx => ' ' + ctx.label + ': ' + ctx.parsed + ' TC'
                        }
                    }
                },
                animation  : { animateRotate: true, duration: 800 }
            }
        });
    }

    // ── Progress bars: animate in on load ────────────────────
    document.querySelectorAll('.progress-bar__fill').forEach(bar => {
        const target = bar.style.width;
        bar.style.width = '0%';
        requestAnimationFrame(() => {
            setTimeout(() => { bar.style.width = target; }, 80);
        });
    });

});
