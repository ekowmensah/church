<?php
// This partial expects $all_result to be a mysqli_result of health_records for the member
// Output a Chart.js line graph of Blood Pressure readings over time
$bp_data = [];
$labels = [];
if ($all_result && $all_result->num_rows > 0) {
    $all_result->data_seek(0); // rewind result set
    while($rec = $all_result->fetch_assoc()) {
        $v = json_decode($rec['vitals'], true) ?: [];
        if (!empty($v['bp'])) {
            $labels[] = date('Y-m-d', strtotime($rec['recorded_at']));
            $bp = $v['bp']; // e.g. '120/80'
            if (strpos($bp, '/') !== false) {
                list($sys, $dia) = explode('/', $bp, 2);
                $bp_data['sys'][] = (int)$sys;
                $bp_data['dia'][] = (int)$dia;
            } else {
                $bp_data['sys'][] = (int)$bp;
                $bp_data['dia'][] = null;
            }
        }
    }
}
?><?php if (empty($bp_data['sys'])): ?>
    <div class="chart-container text-center">
        <div class="mb-3">
            <i class="fas fa-chart-line text-muted" style="font-size: 3rem;"></i>
        </div>
        <h5 class="text-muted mb-2">No Blood Pressure Data Available</h5>
        <p class="text-muted">Blood pressure trends will appear here once you have multiple checkups recorded.</p>
    </div>
<?php else: ?>
    <div class="chart-container">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <div>
                <h5 class="mb-0 font-weight-bold text-dark">
                    <i class="fas fa-chart-line mr-2 text-info"></i>
                    Blood Pressure Trend Analysis
                </h5>
                <small class="text-muted">Track your blood pressure changes over time</small>
            </div>
            <div class="text-right d-none d-md-block">
                <div class="text-muted small">Data Points</div>
                <div class="h5 mb-0 font-weight-bold text-info"><?= count($bp_data['sys']) ?></div>
            </div>
        </div>
        <div style="position: relative; height: 300px;">
            <canvas id="bpChart"></canvas>
        </div>
        <div class="row mt-3">
            <div class="col-md-6">
                <div class="d-flex align-items-center">
                    <div style="width: 20px; height: 3px; background: linear-gradient(135deg, #e74c3c, #c0392b); margin-right: 8px; border-radius: 2px;"></div>
                    <small class="text-muted">Systolic Pressure</small>
                </div>
            </div>
            <div class="col-md-6">
                <div class="d-flex align-items-center">
                    <div style="width: 20px; height: 3px; background: linear-gradient(135deg, #3498db, #2980b9); margin-right: 8px; border-radius: 2px;"></div>
                    <small class="text-muted">Diastolic Pressure</small>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
<?php if (!empty($bp_data['sys'])): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const bpLabels = <?= json_encode(array_reverse($labels)) ?>;
const sysData = <?= json_encode(array_reverse($bp_data['sys'])) ?>;
const diaData = <?= json_encode(array_reverse($bp_data['dia'])) ?>;
const ctx = document.getElementById('bpChart').getContext('2d');

// Create gradients for the chart
const sysGradient = ctx.createLinearGradient(0, 0, 0, 300);
sysGradient.addColorStop(0, 'rgba(231, 76, 60, 0.8)');
sysGradient.addColorStop(1, 'rgba(231, 76, 60, 0.1)');

const diaGradient = ctx.createLinearGradient(0, 0, 0, 300);
diaGradient.addColorStop(0, 'rgba(52, 152, 219, 0.8)');
diaGradient.addColorStop(1, 'rgba(52, 152, 219, 0.1)');

const bpChart = new Chart(ctx, {
    type: 'line',
    data: {
        labels: bpLabels,
        datasets: [
            {
                label: 'Systolic',
                data: sysData,
                borderColor: '#e74c3c',
                backgroundColor: sysGradient,
                borderWidth: 3,
                tension: 0.4,
                fill: true,
                pointRadius: 5,
                pointHoverRadius: 8,
                pointBackgroundColor: '#e74c3c',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2,
                pointHoverBackgroundColor: '#c0392b',
                pointHoverBorderColor: '#ffffff',
                pointHoverBorderWidth: 3,
            },
            {
                label: 'Diastolic',
                data: diaData,
                borderColor: '#3498db',
                backgroundColor: diaGradient,
                borderWidth: 3,
                tension: 0.4,
                fill: true,
                pointRadius: 5,
                pointHoverRadius: 8,
                pointBackgroundColor: '#3498db',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2,
                pointHoverBackgroundColor: '#2980b9',
                pointHoverBorderColor: '#ffffff',
                pointHoverBorderWidth: 3,
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
            intersect: false,
            mode: 'index'
        },
        plugins: {
            legend: {
                display: false // We're using custom legend below
            },
            tooltip: {
                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                titleColor: '#ffffff',
                bodyColor: '#ffffff',
                borderColor: '#e9ecef',
                borderWidth: 1,
                cornerRadius: 8,
                displayColors: true,
                callbacks: {
                    title: function(context) {
                        return 'Date: ' + context[0].label;
                    },
                    label: function(context) {
                        return context.dataset.label + ': ' + context.parsed.y + ' mmHg';
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: false,
                min: Math.min(...sysData, ...diaData) - 10,
                max: Math.max(...sysData, ...diaData) + 10,
                grid: {
                    color: 'rgba(0, 0, 0, 0.05)',
                    drawBorder: false
                },
                ticks: {
                    color: '#6c757d',
                    font: {
                        size: 12,
                        family: 'Source Sans Pro'
                    },
                    callback: function(value) {
                        return value + ' mmHg';
                    }
                },
                title: {
                    display: true,
                    text: 'Blood Pressure (mmHg)',
                    color: '#495057',
                    font: {
                        size: 13,
                        weight: '600',
                        family: 'Source Sans Pro'
                    }
                }
            },
            x: {
                grid: {
                    display: false
                },
                ticks: {
                    color: '#6c757d',
                    font: {
                        size: 12,
                        family: 'Source Sans Pro'
                    },
                    maxTicksLimit: 8
                },
                title: {
                    display: true,
                    text: 'Date',
                    color: '#495057',
                    font: {
                        size: 13,
                        weight: '600',
                        family: 'Source Sans Pro'
                    }
                }
            }
        },
        elements: {
            line: {
                borderJoinStyle: 'round'
            }
        }
    }
});

// Add reference lines for normal BP ranges
const normalSysLine = {
    id: 'normalSysLine',
    afterDatasetsDraw: function(chart) {
        const ctx = chart.ctx;
        const yAxis = chart.scales.y;
        const xAxis = chart.scales.x;
        
        // Normal systolic line (120)
        ctx.save();
        ctx.strokeStyle = 'rgba(46, 204, 113, 0.6)';
        ctx.lineWidth = 2;
        ctx.setLineDash([5, 5]);
        ctx.beginPath();
        ctx.moveTo(xAxis.left, yAxis.getPixelForValue(120));
        ctx.lineTo(xAxis.right, yAxis.getPixelForValue(120));
        ctx.stroke();
        
        // Normal diastolic line (80)
        ctx.strokeStyle = 'rgba(52, 152, 219, 0.6)';
        ctx.beginPath();
        ctx.moveTo(xAxis.left, yAxis.getPixelForValue(80));
        ctx.lineTo(xAxis.right, yAxis.getPixelForValue(80));
        ctx.stroke();
        
        ctx.restore();
    }
};

bpChart.register(normalSysLine);
</script>
<?php endif; ?>
