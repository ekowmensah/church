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
    <div class="alert alert-warning mt-2">
        No BP data found for this member.<br>
        <small>Debug: labels = <?= htmlspecialchars(json_encode($labels)) ?><br>
        sys = <?= htmlspecialchars(json_encode($bp_data['sys'] ?? [])) ?><br>
        dia = <?= htmlspecialchars(json_encode($bp_data['dia'] ?? [])) ?></small>
    </div>
    <?php endif; ?>
<div class="card mb-4">
    <div class="card-header bg-info text-white">
        <strong>Blood Pressure Trend</strong>
    </div>
    <div class="card-body">
        <canvas id="bpChart" height="100"></canvas>
    </div>
</div>
<?php if (!empty($bp_data['sys'])): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const bpLabels = <?= json_encode(array_reverse($labels)) ?>;
const sysData = <?= json_encode(array_reverse($bp_data['sys'])) ?>;
const diaData = <?= json_encode(array_reverse($bp_data['dia'])) ?>;
const ctx = document.getElementById('bpChart').getContext('2d');
const bpChart = new Chart(ctx, {
    type: 'line',
    data: {
        labels: bpLabels,
        datasets: [
            {
                label: 'Systolic (SYS)',
                data: sysData,
                borderColor: 'rgba(255, 99, 132, 1)',
                backgroundColor: 'rgba(255, 99, 132, 0.2)',
                tension: 0.3,
                fill: false,
                pointRadius: 3,
                pointHoverRadius: 5,
            },
            {
                label: 'Diastolic (DIA)',
                data: diaData,
                borderColor: 'rgba(54, 162, 235, 1)',
                backgroundColor: 'rgba(54, 162, 235, 0.2)',
                tension: 0.3,
                fill: false,
                pointRadius: 3,
                pointHoverRadius: 5,
            }
        ]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'top' },
            title: { display: false }
        },
        scales: {
            y: {
                beginAtZero: false,
                title: { display: true, text: 'mmHg' }
            },
            x: {
                title: { display: true, text: 'Date' }
            }
        }
    }
});
</script>
<?php endif; ?>
