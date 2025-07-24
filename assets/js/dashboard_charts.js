// dashboard_charts.js - Chart.js widgets for AdminLTE dashboard
// Requires Chart.js to be loaded in the layout or this page

document.addEventListener('DOMContentLoaded', function() {
    // Bar Chart: Members by Status
    if (document.getElementById('barChartMembers')) {
        new Chart(document.getElementById('barChartMembers').getContext('2d'), {
            type: 'bar',
            data: {
                labels: ['Full', 'Catechumen', 'Junior', 'Adherent'],
                datasets: [{
                    label: 'Members',
                    data: [
                        window.DASHBOARD_STATS.full_member,
                        window.DASHBOARD_STATS.catechumen,
                        window.DASHBOARD_STATS.junior_members,
                        window.DASHBOARD_STATS.adherent
                    ],
                    backgroundColor: [
                        '#007bff','#28a745','#ffc107','#dc3545'
                    ]
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });
    }

    // Pie Chart: Member Distribution
    if (document.getElementById('pieChartMembers')) {
        new Chart(document.getElementById('pieChartMembers').getContext('2d'), {
            type: 'pie',
            data: {
                labels: ['Full', 'Catechumen', 'Junior', 'Adherent'],
                datasets: [{
                    data: [
                        window.DASHBOARD_STATS.full_member,
                        window.DASHBOARD_STATS.catechumen,
                        window.DASHBOARD_STATS.junior_members,
                        window.DASHBOARD_STATS.adherent
                    ],
                    backgroundColor: [
                        '#007bff','#28a745','#ffc107','#dc3545'
                    ]
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'bottom' } }
            }
        });
    }

    // Line Chart: Payments Over Time (dummy data, replace with AJAX)
    if (document.getElementById('lineChartPayments')) {
        new Chart(document.getElementById('lineChartPayments').getContext('2d'), {
            type: 'line',
            data: {
                labels: window.DASHBOARD_STATS.payments_labels,
                datasets: [{
                    label: 'Payments',
                    data: window.DASHBOARD_STATS.payments_data,
                    borderColor: '#17a2b8',
                    backgroundColor: 'rgba(23,162,184,0.2)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: true } },
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });
    }
});
