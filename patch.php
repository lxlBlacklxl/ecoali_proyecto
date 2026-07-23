<?php
$file = 'dashboard_proveedor.php';
$content = file_get_contents($file);

// 1. Add PHP logic for charts
$search1 = <<<'EOD'
// J. Entregas recibidas
$stmtEntRec = $conn->prepare("SELECT COUNT(*) FROM entregas_cedis WHERE proveedor_id = ? AND estado = 'recibido'");
$stmtEntRec->bind_param("i", $proveedor_id);
$stmtEntRec->execute();
$resEntRec = $stmtEntRec->get_result();
$entregasRecibidas = (int)($resEntRec->fetch_row()[0] ?? 0);
$stmtEntRec->close();
EOD;

$replace1 = $search1 . "\n\n" . <<<'EOD'
// K. Datos para gráficos (Últimos 7 días)
$fechas_7dias = [];
$produccion_7dias = [];
for ($i = 6; $i >= 0; $i--) {
    $fecha = date('Y-m-d', strtotime("-$i days"));
    $fechas_7dias[] = date('d/m', strtotime($fecha));
    
    $stmtG = $conn->prepare("SELECT SUM(cantidad) FROM produccion WHERE proveedor_id = ? AND fecha_produccion = ?");
    $stmtG->bind_param("is", $proveedor_id, $fecha);
    $stmtG->execute();
    $resG = $stmtG->get_result();
    $prod_dia = (int)($resG->fetch_row()[0] ?? 0);
    $produccion_7dias[] = $prod_dia;
    $stmtG->close();
}
EOD;

$content = str_replace($search1, $replace1, $content);

// 2. Add HTML containers
$search2 = <<<'EOD'
    </div>

    <!-- Guía Rápida para el Granjero 🌾 -->
EOD;

$replace2 = <<<'EOD'
    </div>

    <!-- Gráficos Interactivos -->
    <div class="charts-grid" style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px; margin-bottom: 30px;">
      <div class="card chart-card" style="padding: 20px;">
        <h3 style="margin-top: 0; margin-bottom: 15px; font-size: 15px; color: var(--text-dark);">Tendencia de Producción (Últimos 7 días)</h3>
        <div style="position: relative; height: 250px; width: 100%;">
          <canvas id="trendChart"></canvas>
        </div>
      </div>
      <div class="card chart-card" style="padding: 20px;">
        <h3 style="margin-top: 0; margin-bottom: 15px; font-size: 15px; color: var(--text-dark);">Distribución de Calidad (Global)</h3>
        <div style="position: relative; height: 250px; width: 100%; display: flex; justify-content: center;">
          <canvas id="qualityChart"></canvas>
        </div>
      </div>
    </div>

    <!-- Guía Rápida para el Granjero 🌾 -->
EOD;

$content = str_replace($search2, $replace2, $content);

// 3. Add Chart.js scripts
$search3 = <<<'EOD'
</body>
</html>
EOD;

$replace3 = $search3 . "\n\n" . <<<'EOD'
<!-- Scripts para Gráficos -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    // Datos desde PHP
    const fechas = <?php echo json_encode($fechas_7dias); ?>;
    const produccion = <?php echo json_encode($produccion_7dias); ?>;
    
    const viables = <?php echo $huevosProducidos; ?>;
    const noViables = <?php echo $huevosNoViables; ?>;
    const mermas = <?php echo $huevosMermas; ?>;

    // Gráfico de Tendencia (Líneas)
    const ctxTrend = document.getElementById('trendChart').getContext('2d');
    
    // Gradiente para el área
    let gradientTrend = ctxTrend.createLinearGradient(0, 0, 0, 250);
    gradientTrend.addColorStop(0, 'rgba(255, 138, 0, 0.4)');
    gradientTrend.addColorStop(1, 'rgba(255, 138, 0, 0.0)');

    new Chart(ctxTrend, {
        type: 'line',
        data: {
            labels: fechas,
            datasets: [{
                label: 'Huevos Viables',
                data: produccion,
                borderColor: '#ff8a00',
                backgroundColor: gradientTrend,
                borderWidth: 3,
                pointBackgroundColor: '#fff',
                pointBorderColor: '#ff8a00',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6,
                fill: true,
                tension: 0.4 // Curvas suaves
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(0,0,0,0.8)',
                    padding: 10,
                    titleFont: { family: "'Plus Jakarta Sans', sans-serif", size: 13 },
                    bodyFont: { family: "'Manrope', sans-serif", size: 14, weight: 'bold' },
                    displayColors: false,
                    callbacks: {
                        label: function(context) {
                            return context.parsed.y + ' uds';
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(0,0,0,0.04)', drawBorder: false },
                    ticks: { font: { family: "'Manrope', sans-serif", size: 11 }, color: '#64748b' }
                },
                x: {
                    grid: { display: false, drawBorder: false },
                    ticks: { font: { family: "'Manrope', sans-serif", size: 11 }, color: '#64748b' }
                }
            },
            interaction: {
                intersect: false,
                mode: 'index',
            },
        }
    });

    // Gráfico de Calidad (Dona)
    const ctxQuality = document.getElementById('qualityChart').getContext('2d');
    new Chart(ctxQuality, {
        type: 'doughnut',
        data: {
            labels: ['Viables', 'No Viables', 'Mermas'],
            datasets: [{
                data: [viables, noViables, mermas],
                backgroundColor: [
                    '#176a21', // Verde success
                    '#c27c0e', // Naranja warn
                    '#b02500'  // Rojo danger
                ],
                borderWidth: 0,
                hoverOffset: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '70%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        usePointStyle: true,
                        padding: 15,
                        font: { family: "'Manrope', sans-serif", size: 12, weight: '600' },
                        color: '#334155'
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(0,0,0,0.8)',
                    padding: 10,
                    bodyFont: { family: "'Manrope', sans-serif", size: 13, weight: 'bold' },
                    callbacks: {
                        label: function(context) {
                            let label = context.label || '';
                            if (label) { label += ': '; }
                            label += context.parsed + ' uds';
                            return label;
                        }
                    }
                }
            }
        }
    });
});
</script>
EOD;

$content = str_replace($search3, $replace3, $content);

file_put_contents($file, $content);
echo "Patch applied successfully.\n";
?>
