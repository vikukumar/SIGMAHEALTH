<?php
date_default_timezone_set('Asia/Kolkata');

// Configuration
$healthFile = __DIR__ . '/data/health.json';
$title = "Server Health Status Dashboard";

// Load health data with proper error handling
$healthData = [];
if (file_exists($healthFile)) {
    $healthData = json_decode(file_get_contents($healthFile), true) ?: [];
}
$servers = $healthData['servers'] ?? [];

// Process filters
$filters = [
    'server' => $_GET['server'] ?? null,
    'status' => $_GET['status'] ?? null,
    'from_date' => $_GET['from_date'] ?? null,
    'to_date' => $_GET['to_date'] ?? null,
    'min_latency' => $_GET['min_latency'] ?? null,
    'max_latency' => $_GET['max_latency'] ?? null
];

// Filter data function with proper error handling
function filterHistory($servers, $filters)
{
    $filtered = [];
    foreach ($servers as $url => $server) {
        if ($filters['server'] && $url != $filters['server']) {
            continue;
        }

        $filteredServer = [
            'name' => $server['name'] ?? $url,
            'icon' => $server['icon'] ?? '🌐',
            'current_status' => $server['current_status'] ?? null,
            'health_history' => []
        ];

        foreach ($server['health_history'] ?? [] as $check) {
            if (!is_array($check))
                continue;

            // Apply filters
            if ($filters['status'] && ($check['status'] ?? '') != $filters['status']) {
                continue;
            }
            if ($filters['from_date'] && strtotime($check['timestamp'] ?? '') < strtotime($filters['from_date'])) {
                continue;
            }
            if ($filters['to_date'] && strtotime($check['timestamp'] ?? '') > strtotime($filters['to_date'])) {
                continue;
            }
            if ($filters['min_latency'] !== null && ($check['latency_ms'] ?? 0) < $filters['min_latency']) {
                continue;
            }
            if ($filters['max_latency'] !== null && ($check['latency_ms'] ?? 0) > $filters['max_latency']) {
                continue;
            }

            $filteredServer['health_history'][] = $check;
        }

        if (!empty($filteredServer['health_history']) || !empty($filteredServer['current_status'])) {
            $filtered[$url] = $filteredServer;
        }
    }
    return $filtered;
}

$filteredData = filterHistory($servers, $filters);

// Prepare chart data with proper error handling
$chartData = [];
foreach ($filteredData as $url => $server) {
    $chartData[$url] = [
        'name' => $server['name'],
        'icon' => $server['icon'],
        'data' => []
    ];

    // Add current status if available
    if (!empty($server['current_status']) && is_array($server['current_status'])) {
        $chartData[$url]['data'][] = [
            'x' => $server['current_status']['timestamp'] ?? date('Y-m-d H:i:s'),
            'y' => $server['current_status']['latency_ms'] ?? 0,
            'status' => $server['current_status']['status'] ?? 'unknown',
            'details' => $server['current_status']
        ];
    }

    // Add historical data
    foreach ($server['health_history'] as $check) {
        if (!is_array($check))
            continue;

        $chartData[$url]['data'][] = [
            'x' => $check['timestamp'] ?? date('Y-m-d H:i:s'),
            'y' => $check['latency_ms'] ?? 0,
            'status' => $check['status'] ?? 'unknown',
            'details' => $check
        ];
    }
}

// Ensure we have at least one server with data
$hasData = false;
foreach ($chartData as $serverData) {
    if (!empty($serverData['data'])) {
        $hasData = true;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? '') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/luxon@3.0.1"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-luxon@1.2.0"></script>
    <style>
        :root {
            --tomato: #ff6347;
            --tomato-light: #ff8c7a;
            --tomato-dark: #e6392b;
            --bg-color: #fff5f2;
            --card-bg: #ffffff;
            --sidebar-width: 280px;
        }

        body {
            background-color: var(--bg-color);
            padding-top: 20px;
        }

        .card {
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            background-color: var(--card-bg);
            border: none;
        }

        .card-header {
            background-color: var(--tomato);
            color: white;
        }

        .btn-primary {
            background-color: var(--tomato);
            border-color: var(--tomato);
        }

        .btn-primary:hover {
            background-color: var(--tomato-dark);
            border-color: var(--tomato-dark);
        }

        .chart-container {
            position: relative;
            height: 400px;
            min-height: 400px;
        }

        .status-badge {
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 12px;
        }

        .status-up {
            background-color: rgba(40, 167, 69, 0.2);
            color: #28a745;
        }

        .status-down {
            background-color: rgba(220, 53, 69, 0.2);
            color: #dc3545;
        }

        .status-unknown {
            background-color: rgba(108, 117, 125, 0.2);
            color: #6c757d;
        }

        #historyTable tbody tr {
            cursor: pointer;
        }

        #historyTable tbody tr:hover {
            background-color: #f8f9fa;
        }

        .filter-section {
            background-color: var(--card-bg);
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .no-data-message {
            text-align: center;
            padding: 40px;
            color: #6c757d;
        }

        /* Sidebar styles */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            width: var(--sidebar-width);
            background-color: #343a40;
            color: white;
            padding: 20px 0;
            overflow-y: auto;
            z-index: 1000;
            transition: all 0.3s;
        }

        .sidebar-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-item {
            padding: 10px 20px;
            cursor: pointer;
            transition: all 0.2s;
            border-left: 3px solid transparent;
        }

        .sidebar-item:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }

        .sidebar-item.active {
            background-color: rgba(255, 255, 255, 0.1);
            border-left: 3px solid var(--tomato);
        }

        .sidebar-item-icon {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }

        .heartbeat {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 5px;
            animation: pulse 2s infinite;
        }

        .heartbeat.up {
            background-color: #28a745;
        }

        .heartbeat.down {
            background-color: #dc3545;
        }

        .heartbeat.unknown {
            background-color: #6c757d;
        }

        @keyframes pulse {
            0% {
                transform: scale(0.95);
                box-shadow: 0 0 0 0 rgba(0, 0, 0, 0.7);
            }

            70% {
                transform: scale(1);
                box-shadow: 0 0 0 5px rgba(0, 0, 0, 0);
            }

            100% {
                transform: scale(0.95);
                box-shadow: 0 0 0 0 rgba(0, 0, 0, 0);
            }
        }

        .main-content {
            margin-left: var(--sidebar-width);
            padding: 20px;
            transition: all 0.3s;
        }

        .sidebar-toggle {
            position: fixed;
            left: var(--sidebar-width);
            top: 10px;
            z-index: 1001;
            background: var(--tomato);
            color: white;
            border: none;
            width: 30px;
            height: 30px;
            border-radius: 0 50% 50% 0;
            cursor: pointer;
            display: none;
        }

        @media (max-width: 992px) {
            .sidebar {
                left: calc(-1 * var(--sidebar-width));
            }

            .sidebar.active {
                left: 0;
            }

            .main-content {
                margin-left: 0;
            }

            .sidebar-toggle {
                display: block;
            }
        }
    </style>
</head>

<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h4>Server List</h4>
        </div>
        <div id="serverList">
            <?php foreach ($servers as $url => $server):
                $currentStatus = $server['current_status'] ?? [];
                $statusClass = $currentStatus['status'] ?? 'unknown';
                $lastThree = array_slice($server['health_history'] ?? [], 0, 3);
                ?>
                <div class="sidebar-item" onclick="loadServerData('<?= htmlspecialchars($url ?? '') ?>')"
                    data-server="<?= htmlspecialchars($url ?? '') ?>">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="sidebar-item-icon"><?= $server['icon'] ?? '🌐' ?></span>
                            <span><?= htmlspecialchars($server['name'] ?? $url ?? '') ?></span>
                        </div>
                        <span class="heartbeat <?= $statusClass ?>"></span>
                    </div>
                    <div class="mt-2">
                        <?php foreach ($lastThree as $check): ?>
                            <span
                                class="badge <?= $check['status'] === 'up' ? 'bg-success' : ($check['status'] === 'down' ? 'bg-danger' : 'bg-secondary') ?> me-1">
                                <?= $check['latency_ms'] ?? '0' ?>ms
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <button class="sidebar-toggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>

    <div class="main-content" id="mainContent">
        <div class="container-fluid">
            <div class="row mb-4">
                <div class="col-12">
                    <h1 class="text-center" style="color: var(--tomato);"><?= htmlspecialchars($title ?? '') ?></h1>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="row justify-content-center mb-4">
                <div class="col-lg-10">
                    <div class="filter-section card">
                        <div class="card-body">
                            <form method="get" class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label">Server</label>
                                    <select name="server" class="form-select" id="serverSelect">
                                        <option value="">All Servers</option>
                                        <?php foreach ($servers as $url => $server): ?>
                                            <option value="<?= htmlspecialchars($url ?? '') ?>" <?= $filters['server'] == $url ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($server['name'] ?? $url ?? '') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select">
                                        <option value="">All Statuses</option>
                                        <option value="up" <?= $filters['status'] == 'up' ? 'selected' : '' ?>>Up</option>
                                        <option value="down" <?= $filters['status'] == 'down' ? 'selected' : '' ?>>Down
                                        </option>
                                        <option value="unknown" <?= $filters['status'] == 'unknown' ? 'selected' : '' ?>>
                                            Unknown</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">From Date</label>
                                    <input type="datetime-local" name="from_date" class="form-control"
                                        value="<?= htmlspecialchars($filters['from_date'] ?? '') ?>">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">To Date</label>
                                    <input type="datetime-local" name="to_date" class="form-control"
                                        value="<?= htmlspecialchars($filters['to_date'] ?? '') ?>">
                                </div>
                                <div class="col-md-3">
                                    <div class="row">
                                        <div class="col-6">
                                            <label class="form-label">Min Latency</label>
                                            <input type="number" name="min_latency" class="form-control" placeholder="0"
                                                value="<?= htmlspecialchars($filters['min_latency'] ?? '') ?>">
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label">Max Latency</label>
                                            <input type="number" name="max_latency" class="form-control"
                                                placeholder="10000"
                                                value="<?= htmlspecialchars($filters['max_latency'] ?? '') ?>">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-12 text-center">
                                    <button type="submit" class="btn btn-primary me-2">Apply Filters</button>
                                    <a href="status.php" class="btn btn-secondary">Reset</a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!$hasData): ?>
                <div class="row justify-content-center">
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-body no-data-message">
                                <i class="fas fa-server fa-4x mb-3" style="color: var(--tomato);"></i>
                                <h3>No Server Data Available</h3>
                                <p class="mb-0">Please check your health monitoring configuration</p>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="row justify-content-center">
                    <div class="col-lg-10">
                        <!-- Current Status Card -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0">Current Status</h5>
                            </div>
                            <div class="card-body">
                                <div class="row" id="currentStatusContainer">
                                    <!-- Will be filled by JavaScript -->
                                </div>
                            </div>
                        </div>

                        <!-- Latency Chart Card -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0">Performance History</h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="healthChart"></canvas>
                                </div>
                            </div>
                        </div>

                        <!-- History Table Card -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Detailed Check History</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover" id="historyTable">
                                        <thead>
                                            <tr>
                                                <th>Timestamp</th>
                                                <th>Status</th>
                                                <th>Latency (ms)</th>
                                                <th>Status Code</th>
                                                <th>IP Address</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <!-- Will be filled by JavaScript -->
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Details Modal -->
    <div class="modal fade" id="detailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background-color: var(--tomato); color: white;">
                    <h5 class="modal-title">Check Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>
                <div class="modal-body" id="modalDetailsContent">
                    <!-- Will be filled by JavaScript -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Chart data from PHP with proper fallbacks
        const chartData = <?= json_encode($chartData) ?> || {};
        const allServers = <?= json_encode($servers) ?> || {};
        let currentServer = null;
        let healthChart = null;

        // Initialize only if we have data
        document.addEventListener('DOMContentLoaded', function () {
            // Check if we have any server data
            const hasData = Object.keys(chartData).length > 0 &&
                Object.values(chartData).some(server => server.data.length > 0);

            if (!hasData) {
                console.log('No server data available');
                return;
            }

            // Get the first server with data or the filtered server
            currentServer = Object.keys(chartData).find(url => chartData[url].data.length > 0);
            if (!currentServer) return;

            // If a server is selected in filters, use that
            const filteredServer = document.getElementById('serverSelect').value;
            if (filteredServer && chartData[filteredServer]) {
                currentServer = filteredServer;
            }

            // Highlight active server in sidebar
            highlightActiveServer(currentServer);

            // Initialize the dashboard
            loadServerData(currentServer);

            // Auto-refresh every 60 seconds
            setInterval(() => {
                window.location.reload();
            }, 60000);
        });

        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('active');

            const toggleBtn = document.querySelector('.sidebar-toggle');
            if (sidebar.classList.contains('active')) {
                toggleBtn.style.left = (parseInt(getComputedStyle(sidebar).width) + 10) + 'px';
            } else {
                toggleBtn.style.left = '10px';
            }
        }

        function highlightActiveServer(serverUrl) {
            document.querySelectorAll('.sidebar-item').forEach(item => {
                item.classList.remove('active');
                if (item.dataset.server === serverUrl) {
                    item.classList.add('active');
                }
            });
        }

        function loadServerData(serverUrl) {
            if (!chartData[serverUrl] || !chartData[serverUrl].data) {
                console.error('Invalid server data for:', serverUrl);
                return;
            }

            currentServer = serverUrl;
            highlightActiveServer(serverUrl);

            loadCurrentStatus(serverUrl);
            loadChart(serverUrl);
            loadTableData(serverUrl);
        }

        function loadCurrentStatus(serverUrl) {
            const container = document.getElementById('currentStatusContainer');
            if (!container) {
                console.error('Current status container not found');
                return;
            }

            const server = chartData[serverUrl];
            const currentStatus = server.data[0]?.details || {};
            const statusClass = currentStatus.status === 'up' ? 'status-up' :
                currentStatus.status === 'down' ? 'status-down' : 'status-unknown';

            container.innerHTML = `
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="d-flex align-items-center mb-3">
                                <span style="font-size: 2em;">${server.icon || '🌐'}</span>
                                <div class="ms-3">
                                    <h4 class="mb-0">${server.name || serverUrl}</h4>
                                    <small class="text-muted">${serverUrl}</small>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-6">
                                    <div class="mb-2">
                                        <small class="text-muted">Current Status</small>
                                        <div>
                                            <span class="status-badge ${statusClass}">${currentStatus.status || 'unknown'}</span>
                                        </div>
                                    </div>
                                    <div class="mb-2">
                                        <small class="text-muted">Status Code</small>
                                        <div class="fw-bold">${currentStatus.status_code || 'N/A'}</div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="mb-2">
                                        <small class="text-muted">Latency</small>
                                        <div class="fw-bold">${currentStatus.latency_ms || '0'} ms</div>
                                    </div>
                                    <div class="mb-2">
                                        <small class="text-muted">Last Check</small>
                                        <div class="fw-bold">${currentStatus.timestamp || 'Never'}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-body">
                            <h6 class="card-title">Recent Activity</h6>
                            <div class="list-group list-group-flush">
                                ${server.data.slice(0, 3).map(check => `
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between">
                                            <span>${check.x || 'N/A'}</span>
                                            <div>
                                                <span class="badge ${check.status === 'up' ? 'bg-success' : check.status === 'down' ? 'bg-danger' : 'bg-secondary'}">
                                                    ${check.status || 'unknown'}
                                                </span>
                                                <span class="ms-2">${check.y || '0'} ms</span>
                                            </div>
                                        </div>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }

        function parseDate(dateString) {
            // First try strict ISO parsing
            let dt = luxon.DateTime.fromISO(dateString);

            // If invalid, try more forgiving RFC2822 parsing
            if (!dt.isValid) {
                dt = luxon.DateTime.fromRFC2822(dateString);
            }

            // If still invalid, try SQL format
            if (!dt.isValid) {
                dt = luxon.DateTime.fromSQL(dateString);
            }

            // If still invalid, try custom parsing for common formats
            if (!dt.isValid) {
                // Try common formats like "YYYY-MM-DD HH:mm:ss"
                const formats = [
                    "yyyy-MM-dd HH:mm:ss",
                    "yyyy/MM/dd HH:mm:ss",
                    "MM/dd/yyyy HH:mm:ss",
                    "dd-MM-yyyy HH:mm:ss"
                ];

                for (const format of formats) {
                    dt = luxon.DateTime.fromFormat(dateString, format);
                    if (dt.isValid) break;
                }
            }

            // If all parsing fails, use current time as fallback
            if (!dt.isValid) {
                console.warn(`Invalid date string: ${dateString}, using current time as fallback`);
                dt = luxon.DateTime.now();
            }

            return dt.toJSDate();
        }


        function loadChart(serverUrl) {
            const ctx = document.getElementById('healthChart');
            if (!ctx) {
                console.error('Chart canvas not found');
                return;
            }

            // Destroy previous chart if exists
            if (healthChart) {
                healthChart.destroy();
            }
            //console.log(chartData);
            const server = chartData[serverUrl];
            const dataPoints = server.data.map(check => ({
                x: parseDate(check.x),
                y: check.y,
                status: check.status,
                details: check.details
            })).reverse(); // Show oldest to newest
            //console.log(dataPoints);
            // Create new chart
            healthChart = new Chart(ctx, {
                type: 'line',
                data: {
                    datasets: [{
                        label: 'Latency (ms)',
                        data: dataPoints,
                        backgroundColor: dataPoints.map(point =>
                            point.status === 'up' ? 'rgba(40, 167, 69, 0.5)' :
                                point.status === 'down' ? 'rgba(220, 53, 69, 0.5)' :
                                    'rgba(108, 117, 125, 0.5)'
                        ),
                        borderColor: dataPoints.map(point =>
                            point.status === 'up' ? 'rgba(40, 167, 69, 1)' :
                                point.status === 'down' ? 'rgba(220, 53, 69, 1)' :
                                    'rgba(108, 117, 125, 1)'
                        ),
                        borderWidth: 1,
                        pointRadius: 5,
                        pointHoverRadius: 7,
                        fill: false,
                        tension: 0.1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            type: 'time',
                            time: {
                                unit: 'minute',
                                tooltipFormat: 'yyyy-MM-dd HH:mm:ss',
                                displayFormats: {
                                    minute: 'HH:mm',
                                    hour: 'HH:mm',
                                    day: 'MMM d'
                                }
                            },
                            title: {
                                display: true,
                                text: 'Time'
                            }
                        },
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Latency (ms)'
                            }
                        }
                    },
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function (context) {
                                    return `Latency: ${context.parsed.y} ms (${context.raw.status})`;
                                }
                            }
                        }
                    },
                    onClick: (e, elements) => {
                        if (elements.length > 0) {
                            const index = elements[0].index;
                            showDetailsModal(dataPoints[index].details);
                        }
                    }
                }
            });
        }


        function safeJsonForAttribute(data) {
            return btoa(unescape(encodeURIComponent(JSON.stringify(data || {}))));
        }

        // When reading:
        function getDataFromAttribute(encoded) {
            return JSON.parse(decodeURIComponent(escape(atob(encoded))));
        }
        function loadTableData(serverUrl) {
            const tbody = document.querySelector('#historyTable tbody');
            if (!tbody) {
                console.error('Table body not found');
                return;
            }

            const server = chartData[serverUrl];
            tbody.innerHTML = server.data.map(check => {
                const safeDetails = JSON.stringify(check.details)
                    .replace(/</g, '\\u003c')
                    .replace(/>/g, '\\u003e');

                return `
                <tr data-details='${safeJsonForAttribute(safeDetails)}' onclick="window.showDetailsModal(getDataFromAttribute(this.getAttribute('data-details')))">
                    <td >${check.x || 'N/A'}</td>
                    <td>
                        <span class="status-badge ${check.status === 'up' ? 'status-up' : check.status === 'down' ? 'status-down' : 'status-unknown'}">
                            ${check.status || 'unknown'}
                        </span>
                    </td>
                    <td>${check.y || '0'}</td>
                    <td>${check.details.status_code || 'N/A'}</td>
                    <td>${check.details.ip_address || 'N/A'}</td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" 
                                onclick="event.stopPropagation(); showDetailsModal(getDataFromAttribute(this.parentNode.parentNode.getAttribute('data-details')))">
                            <i class="fas fa-info-circle"></i> Details
                        </button>
                    </td>
                </tr>
                `;
            }).join('');
        }

        function showDetailsModal(check) {
            const modalContent = document.getElementById('modalDetailsContent');
            if(typeof check == 'string'){
                check = JSON.parse(check);
            }
            if (!modalContent) {
                console.error('Modal content container not found');
                return;
            }
            const statusClass = check.status === 'up' ? 'status-up' :
                check.status === 'down' ? 'status-down' : 'status-unknown';

            modalContent.innerHTML = `
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <h6>Basic Information</h6>
                            <table class="table table-bordered">
                                <tr>
                                    <th width="30%">Timestamp</th>
                                    <td>${check.timestamp || 'N/A'}</td>
                                </tr>
                                <tr>
                                    <th>Status</th>
                                    <td><span class="status-badge ${statusClass}">${check.status || 'unknown'}</span></td>
                                </tr>
                                <tr>
                                    <th>Status Code</th>
                                    <td>${check.status_code || 'N/A'}</td>
                                </tr>
                                <tr>
                                    <th>Latency</th>
                                    <td>${check.latency_ms || '0'} ms</td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <h6>Network Information</h6>
                            <table class="table table-bordered">
                                <tr>
                                    <th width="30%">Server IP</th>
                                    <td>${check.ip_address || 'N/A'}</td>
                                </tr>
                                <tr>
                                    <th>Client IP</th>
                                    <td>${check.client_ip || 'N/A'}</td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="mb-3">
                    <h6>Response Sample</h6>
                    <div class="p-2 bg-light rounded" style="max-height: 200px; overflow: auto;">
                        <pre>${check.response_sample || 'No response sample available'}</pre>
                    </div>
                </div>
            `;

            const modal = new bootstrap.Modal(document.getElementById('detailsModal'));
            modal.show();
        }

        // Make functions available globally
        window.showDetailsModal = showDetailsModal;
        window.loadServerData = loadServerData;
    </script>
</body>

</html>