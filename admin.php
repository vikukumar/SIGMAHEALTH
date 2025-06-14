<?php
session_start();

// Configuration
define('AUTH_FILE', __DIR__ . '/data/auth.json');
define('SERVERS_FILE', __DIR__ . '/data/servers.json');
define('STATUS_FILE', __DIR__ . '/data/health.json');
define('CRON_FILE', __DIR__ . '/data/cron.json');

// Initialize files if they don't exist
if (!file_exists(SERVERS_FILE))
  file_put_contents(SERVERS_FILE, '[]');
if (!file_exists(AUTH_FILE))
  file_put_contents(AUTH_FILE, json_encode([
    'username' => 'admin',
    'password' => 'password'
  ]));
if (!file_exists(STATUS_FILE))
  file_put_contents(STATUS_FILE, '{}');
if (!file_exists(CRON_FILE))
  file_put_contents(CRON_FILE, '[]');

ensureHealthCheckCronJob();
// LOGIN HANDLER
if (isset($_POST['login'])) {
  $auth = json_decode(file_get_contents(AUTH_FILE), true);
  if ($_POST['username'] === $auth['username'] && $_POST['password'] === $auth['password']) {
    $_SESSION['loggedin'] = true;
    header("Location: ?");
    exit;
  } else {
    $error = "Invalid credentials.";
  }
}

// LOGOUT
if (isset($_GET['logout'])) {
  session_destroy();
  header("Location: ?loggedout");
  exit;
}

// ACTION HANDLERS
if ($_SESSION['loggedin'] ?? false) {
  $servers = json_decode(file_get_contents(SERVERS_FILE), true) ?: [];
  $statusData = json_decode(file_get_contents(STATUS_FILE), true) ?: [];
  $cronJobs = json_decode(file_get_contents(CRON_FILE), true) ?: [];

  // Add Server
  if (isset($_POST['save_server'])) {
    $new = [
      "url" => $_POST['url'],
      "icon" => $_POST['icon'],
      "success" => parseStatusCodes($_POST['success']),
      "errors" => parseStatusCodes($_POST['errors']),
      "enabled" => isset($_POST['enabled']),
      "name" => $_POST['name'] ?? '',
      "short_success" => $_POST['short_success'] ?? '',
      "short_errors" => $_POST['short_errors'] ?? '',
      "check_interval" => $_POST['check_interval'] ?? 5,
      "cron_enabled" => isset($_POST['cron_enabled'])
    ];
    $servers[] = $new;
    saveServers($servers);
    header("Location: ?");
    exit;
  }

  // Edit Server
  if (isset($_POST['edit_server'])) {
    $index = $_POST['admin'];
    $servers[$index]['url'] = $_POST['url'];
    $servers[$index]['icon'] = $_POST['icon'];
    $servers[$index]['success'] = parseStatusCodes($_POST['success']);
    $servers[$index]['errors'] = parseStatusCodes($_POST['errors']);
    $servers[$index]['enabled'] = isset($_POST['enabled']);
    $servers[$index]['name'] = $_POST['name'] ?? '';
    $servers[$index]['short_success'] = $_POST['short_success'] ?? '';
    $servers[$index]['short_errors'] = $_POST['short_errors'] ?? '';
    $servers[$index]['check_interval'] = $_POST['check_interval'] ?? 5;
    $servers[$index]['cron_enabled'] = isset($_POST['cron_enabled']);
    saveServers($servers);
    header("Location: ?");
    exit;
  }

  // Delete Server
  if (isset($_GET['delete'])) {
    unset($servers[$_GET['delete']]);
    $servers = array_values($servers);
    saveServers($servers);
    header("Location: ?");
    exit;
  }

  // Toggle Server
  if (isset($_GET['toggle'])) {
    $servers[$_GET['toggle']]['enabled'] = !$servers[$_GET['toggle']]['enabled'];
    saveServers($servers);
    header("Location: ?");
    exit;
  }

  // Add Cron Job
  if (isset($_POST['add_cron'])) {
    $cronJobs[] = [
      'server_index' => $_POST['server_index'],
      'schedule' => $_POST['schedule'],
      'command' => $_POST['command'],
      'enabled' => isset($_POST['enabled']),
      'system' => false,
    ];
    
    addCronJob($_POST['schedule'],$_POST['command'])?file_put_contents(CRON_FILE, json_encode($cronJobs, JSON_PRETTY_PRINT)):'';
    header("Location: ?");
    exit;
  }

  // Delete Cron Job
  if (isset($_GET['delete_cron'])) {
    unset($cronJobs[$_GET['delete_cron']]);
    $cronJobs = array_values($cronJobs);
    removeCronJob($cronJobs['command'])?file_put_contents(CRON_FILE, json_encode($cronJobs, JSON_PRETTY_PRINT)):'';
    header("Location: ?");
    exit;
  }
}


// Helper functions
function saveServers($servers)
{
  file_put_contents(SERVERS_FILE, json_encode($servers, JSON_PRETTY_PRINT));
}

function parseStatusCodes($input)
{
  $codes = [];
  $parts = explode(',', $input);

  foreach ($parts as $part) {
    $part = trim($part);
    if (strpos($part, '-') !== false) {
      list($start, $end) = explode('-', $part);
      for ($i = $start; $i <= $end; $i++) {
        $codes[] = $i;
      }
    } else {
      $codes[] = $part;
    }
  }

  return array_unique($codes);
}

function formatStatusCodes($codes, $shortDisplay = '')
{
  if (!empty($shortDisplay)) {
    return $shortDisplay;
  }

  $ranges = [];
  $numericCodes = array_filter($codes, 'is_numeric');
  sort($numericCodes);

  if (empty($numericCodes)) {
    return implode(',', $codes);
  }

  $start = $numericCodes[0];
  $prev = $numericCodes[0];

  for ($i = 1; $i < count($numericCodes); $i++) {
    if ($numericCodes[$i] == $prev + 1) {
      $prev = $numericCodes[$i];
    } else {
      if ($start == $prev) {
        $ranges[] = $start;
      } else {
        $ranges[] = "$start-$prev";
      }
      $start = $numericCodes[$i];
      $prev = $numericCodes[$i];
    }
  }

  if ($start == $prev) {
    $ranges[] = $start;
  } else {
    $ranges[] = "$start-$prev";
  }

  // Add non-numeric codes
  $nonNumeric = array_diff($codes, $numericCodes);
  if (!empty($nonNumeric)) {
    $ranges = array_merge($ranges, $nonNumeric);
  }

  return implode(',', $ranges);
}

function getServerStatus($url) {
    if (!file_exists(STATUS_FILE)) {
        return [
            'status' => 'unknown',
            'last_check' => 'Never',
            'latency' => 0,
            'status_code' => 0,
            'checks'=> 0,
            'ip_address'=> 'Unknown'
        ];
    }
    
    $statusData = json_decode(file_get_contents(STATUS_FILE), true);
    $serverData = $statusData['servers'][$url] ?? null;
    //print_r( $serverData);
    if (!$serverData) {
        return [
            'status' => 'unknown',
            'last_check' => 'Never',
            'latency' => 0,
            'status_code' => 0,
            'checks'=>0,
            'ip_address'=> 'Unknown'
        ];
    }
    //return $serverData;
    $current_status = $serverData['current_status'];
    return [
        'status' => $current_status['status'] ?? 'unknown',
        'last_check' => $current_status['timestamp']?? 'Never',
        'latency' => $current_status['latency_ms'] ?? 0,
        'status_code' => $current_status['status_code'] ?? 0,
        'checks'=>$serverData['total_checks']??0,
        'ip_address'=> $current_status['ip_address']??'Unknown'
    ];
}

function ensureHealthCheckCronJob() {
    // Default health check cron job configuration
    $healthCheckJob = [
        'schedule' => '*/5 * * * *',
        'command' => '/usr/bin/php ' . __DIR__ .'/health.php',
        'output' => __DIR__ . '/logs/healthcheck.log',
        'description' => 'System health check'
    ];

    // Load existing cron jobs from JSON file
    $cronJobs = [];
    if (file_exists(CRON_FILE)) {
        $cronJobs = json_decode(file_get_contents(CRON_FILE), true) ?: [];
    }

    $healthCheckExists = false;
    foreach ($cronJobs as $job) {
        if (strpos($job['command'],  __DIR__ .'/health.php') !== false) {
            $healthCheckExists = true;
            break;
        }
    }

      if (!$healthCheckExists) {
        $cronJobs[] = [
          'server_index' => -1,
          'schedule' => $healthCheckJob['schedule'],
          'command' => $healthCheckJob['command'],
          'enabled' => true,
          'system' => true,
        ];

        file_put_contents(CRON_FILE, json_encode($cronJobs, JSON_PRETTY_PRINT));
        
        // Also add to system crontab
        addCronJob(
            $healthCheckJob['schedule'],
            $healthCheckJob['command'],
            $healthCheckJob['output']
        );
        
        return true;
    }

    return false;

}

/**
 * Add a new cron job
 * 
 * @param string $schedule The cron schedule (e.g., "* * * * *")
 * @param string $command The command to execute
 * @param string $output Where to send output (optional)
 * @return bool True on success, false on failure
 */
function addCronJob($schedule, $command, $output = '/dev/null') {
    // Escape all arguments
    $schedule = escapeshellarg($schedule);
    $command = escapeshellarg($command);
    $output = escapeshellarg($output);
    
    // Check if the job already exists
    $existingJobs = shell_exec('crontab -l');
    if (strpos($existingJobs, $command) !== false) {
        return false; // Job already exists
    }
    
    // Add the new job
    $newJob = "$schedule $command > $output 2>&1";
    file_put_contents('/tmp/crontab.txt', $existingJobs . $newJob . PHP_EOL);
    exec('crontab /tmp/crontab.txt');
    
    return true;
}

function listCronJobs() {
    $output = shell_exec('crontab -l');
    return explode(PHP_EOL, trim($output));
}

function removeCronJob($searchPattern) {
    $allJobs = shell_exec('crontab -l');
    $newJobs = preg_replace("/.*$searchPattern.*\n/", "", $allJobs);
    
    file_put_contents('/tmp/crontab.txt', $newJobs);
    exec('crontab /tmp/crontab.txt');
    
    return true;
}



// HTML START
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <title>Server Dashboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    :root {
      --tomato: #ff6347;
      --tomato-light: #ff8c7a;
      --tomato-dark: #e6392b;
      --bg-color: #fff5f2;
      --card-bg: #ffffff;
      --text-color: #333333;
      --text-light: #666666;
    }

    body {
      background: var(--bg-color);
      color: var(--text-color);
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }

    .tomato-bg {
      background-color: var(--tomato);
      color: white;
    }

    .tomato-text {
      color: var(--tomato);
    }

    .tomato-border {
      border-color: var(--tomato);
    }

    .btn-tomato {
      background-color: var(--tomato);
      color: white;
      border: none;
    }

    .btn-tomato:hover {
      background-color: var(--tomato-dark);
      color: white;
    }

    .btn-outline-tomato {
      border-color: var(--tomato);
      color: var(--tomato);
    }

    .btn-outline-tomato:hover {
      background-color: var(--tomato);
      color: white;
    }

    .form-control:focus,
    .form-select:focus,
    .btn:focus {
      box-shadow: 0 0 0 0.25rem rgba(255, 99, 71, 0.25);
      border-color: var(--tomato);
    }

    .dashboard-card {
      transition: all 0.3s ease;
      background: var(--card-bg);
      border-radius: 12px;
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
      border: none;
      overflow: hidden;
    }

    .dashboard-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 10px 15px rgba(0, 0, 0, 0.1);
    }

    .card-disabled {
      opacity: 0.6;
      background-color: #f8f9fa;
    }

    .modal-content {
      border-radius: 12px;
      overflow: hidden;
      border: none;
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
    }

    .emoji-picker {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      max-height: 200px;
      overflow-y: auto;
      padding: 10px;
      background: #f8f9fa;
      border-radius: 8px;
      margin-top: 10px;
    }

    .emoji-option {
      font-size: 24px;
      cursor: pointer;
      padding: 5px;
      border-radius: 50%;
      transition: all 0.2s;
    }

    .emoji-option:hover {
      background-color: var(--tomato-light);
      transform: scale(1.2);
    }

    .emoji-option.selected {
      background-color: var(--tomato);
      color: white;
    }

    .status-code-selector {
      display: flex;
      flex-wrap: wrap;
      gap: 5px;
    }

    .status-code-chip {
      padding: 3px 8px;
      background: #e9ecef;
      border-radius: 20px;
      font-size: 12px;
      cursor: pointer;
      transition: all 0.2s;
    }

    .status-code-chip:hover {
      background: #dee2e6;
    }

    .status-code-chip.selected {
      background: var(--tomato);
      color: white;
    }

    .login-container {
      max-width: 400px;
      margin: 0 auto;
      padding: 2rem;
      background: white;
      border-radius: 12px;
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
      position: relative;
      overflow: hidden;
    }

    .animal-carousel {
      height: 100px;
      margin-bottom: 20px;
      position: relative;
      overflow: hidden;
    }

    .animal-track {
      display: flex;
      position: absolute;
      left: 0;
      top: 0;
      transition: transform 0.5s ease;
    }

    .animal {
      font-size: 60px;
      min-width: 130px;
      text-align: center;
      transition: all 0.3s ease;
    }

    .pulse {
      animation: pulse 2s infinite;
    }

    @keyframes pulse {
      0% {
        transform: scale(1);
      }

      50% {
        transform: scale(1.1);
      }

      100% {
        transform: scale(1);
      }
    }

    .bounce {
      animation: bounce 1.5s infinite;
    }

    @keyframes bounce {

      0%,
      100% {
        transform: translateY(0);
      }

      50% {
        transform: translateY(-15px);
      }
    }

    .wobble {
      animation: wobble 2s infinite;
    }

    @keyframes wobble {

      0%,
      100% {
        transform: rotate(0deg);
      }

      25% {
        transform: rotate(-5deg);
      }

      75% {
        transform: rotate(5deg);
      }
    }

    .floating {
      animation: floating 3s ease-in-out infinite;
    }

    @keyframes floating {

      0%,
      100% {
        transform: translateY(0px);
      }

      50% {
        transform: translateY(-10px);
      }
    }

    .server-icon {
      font-size: 2rem;
      margin-bottom: 10px;
      color: var(--tomato);
    }

    .server-url {
      word-break: break-all;
      font-size: 0.9rem;
      color: var(--text-light);
    }

    .status-badge {
      font-size: 0.8rem;
      padding: 3px 8px;
      border-radius: 10px;
    }

    .success-badge {
      background-color: rgba(40, 167, 69, 0.2);
      color: #28a745;
    }

    .error-badge {
      background-color: rgba(220, 53, 69, 0.2);
      color: #dc3545;
    }

    .warning-badge {
      background-color: rgba(255, 193, 7, 0.2);
      color: #ffc107;
    }

    .info-badge {
      background-color: rgba(23, 162, 184, 0.2);
      color: #17a2b8;
    }

    .action-buttons {
      position: absolute;
      top: 10px;
      right: 10px;
      opacity: 0;
      transition: opacity 0.3s;
    }

    .dashboard-card:hover .action-buttons {
      opacity: 1;
    }

    .form-switch .form-check-input:checked {
      background-color: var(--tomato);
      border-color: var(--tomato);
    }

    .form-switch .form-check-input:focus {
      box-shadow: 0 0 0 0.25rem rgba(255, 99, 71, 0.25);
    }

    .health-indicator {
      width: 12px;
      height: 12px;
      border-radius: 50%;
      display: inline-block;
      margin-right: 5px;
    }

    .health-up {
      background-color: #28a745;
    }

    .health-down {
      background-color: #dc3545;
    }

    .health-unknown {
      background-color: #6c757d;
    }

    .chart-container {
      position: relative;
      height: 300px;
      margin-bottom: 20px;
    }

    .nav-tabs .nav-link.active {
      color: var(--tomato);
      border-color: var(--tomato) var(--tomato) #fff;
    }

    .nav-tabs .nav-link {
      color: var(--text-light);
    }

    .nav-tabs .nav-link:hover {
      border-color: #e9ecef #e9ecef #dee2e6;
      color: var(--tomato);
    }

    /* Responsive adjustments */
    @media (max-width: 768px) {
      .animal {
        font-size: 40px;
      }

      .dashboard-card {
        margin-bottom: 15px;
      }

      .action-buttons {
        opacity: 1;
      }
    }
  </style>
</head>

<body class="animate__animated animate__fadeIn">

  <?php if (!($_SESSION['loggedin'] ?? false)): ?>
    <!-- Login Page -->
    <div class="container d-flex align-items-center justify-content-center" style="min-height: 100vh;">
      <div class="login-container animate__animated animate__fadeInUp">
        <div class="text-center mb-4">
          <div class="animal-carousel">
            <div class="animal-track" id="animalTrack">
              <?php
              $animals = ['🦊', '🐶', '🐱', '🐭', '🐹', '🐰', '🦝', '🐻', '🐼', '🦘'];
              foreach ($animals as $animal): ?>
                <div class="animal bounce"><?= $animal ?></div>
              <?php endforeach; ?>
            </div>
          </div>
          <h2 class="tomato-text mb-3">Server Dashboard</h2>
          <p class="text-muted">Monitor your servers with ease</p>
        </div>

        <?php if (isset($error)): ?>
          <div class="alert alert-danger animate__animated animate__shakeX"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post">
          <div class="mb-3">
            <label for="username" class="form-label">Username</label>
            <input type="text" class="form-control" id="username" name="username" required onfocus="startAnimalCarousel()"
              onblur="stopAnimalCarousel()">
          </div>
          <div class="mb-3">
            <label for="password" class="form-label">Password</label>
            <input type="password" class="form-control" id="password" name="password" required
              onfocus="startAnimalCarousel()" onblur="stopAnimalCarousel()">
          </div>
          <button type="submit" name="login" class="btn btn-tomato w-100 py-2 mt-2">
            <i class="fas fa-sign-in-alt me-2"></i> Login
          </button>
        </form>
      </div>
    </div>

    <script>
      let carouselInterval;
      let currentAnimal = 0;
      const animalCount = <?= count($animals) ?>;
      const track = document.getElementById('animalTrack');
      //console.log(currentAnimal, animalCount)

      function startAnimalCarousel() {
        if (carouselInterval) {clearInterval(carouselInterval)};
        carouselInterval = setInterval(() => {
          currentAnimal = ((currentAnimal + 1) % animalCount );
          track.style.transform = `translateX(-${currentAnimal * 130}px)`;
        }, 1000);
      }

      function stopAnimalCarousel() {
        if (carouselInterval) {clearInterval(carouselInterval)};
        track.style.transform = 'translateX(0)';
        currentAnimal = 0;
      }
    </script>

  <?php else: ?>
    <!-- Dashboard Page -->
    <div class="container-fluid py-4">
      <!-- Header -->
      <div class="row mb-4">
        <div class="col-md-8">
          <h1 class="tomato-text mb-0"><i class="fas fa-server me-2"></i> Server Dashboard</h1>
          <p class="text-muted">Monitor and manage your servers</p>
        </div>
        <div class="col-md-4 d-flex align-items-center justify-content-end">
          <button class="btn btn-tomato me-2" data-bs-toggle="modal" data-bs-target="#addModal">
            <i class="fas fa-plus me-1"></i> Add Server
          </button>
          <a href="?logout" class="btn btn-outline-tomato">
            <i class="fas fa-sign-out-alt me-1"></i> Logout
          </a>
        </div>
      </div>

      <!-- Main Content -->
      <div class="row">
        <div class="col-lg-8">
          <!-- Servers Grid -->
          <div class="row g-4 mb-4">
            <?php if (empty($servers)): ?>
              <div class="col-12">
                <div class="card dashboard-card text-center py-5">
                  <i class="fas fa-server fa-4x tomato-text mb-3"></i>
                  <h3>No servers added yet</h3>
                  <p class="text-muted">Click the "Add Server" button to get started</p>
                  <button class="btn btn-tomato" data-bs-toggle="modal" data-bs-target="#addModal">
                    <i class="fas fa-plus me-1"></i> Add Your First Server
                  </button>
                </div>
              </div>
            <?php else: ?>
              <?php foreach ($servers as $i => $server):
                $status = getServerStatus($server['url']);
                ?>
                <div class="col-xl-6 col-lg-6 col-md-6">
                  <div
                    class="card dashboard-card h-100 p-3 position-relative <?= $server['enabled'] ? '' : 'card-disabled' ?>">
                    <div class="action-buttons">
                      <button class="btn btn-sm btn-outline-secondary me-1" data-bs-toggle="modal"
                        data-bs-target="#editModal<?= $i ?>">
                        <i class="fas fa-edit"></i>
                      </button>
                      <a href="?delete=<?= $i ?>" class="btn btn-sm btn-outline-danger"
                        onclick="return confirm('Are you sure you want to delete this server?')">
                        <i class="fas fa-trash"></i>
                      </a>
                    </div>

                    <div class="d-flex flex-column h-100">
                      <div class="text-center mb-3">
                        <div class="server-icon">
                          <?php if (strpos($server['icon'], 'fa-') === 0): ?>
                            <i class="<?= htmlspecialchars($server['icon']) ?>"></i>
                          <?php else: ?>
                            <span style="font-size: 2rem;"><?= htmlspecialchars($server['icon']) ?></span>
                          <?php endif; ?>
                        </div>
                        <?php if (!empty($server['name'])): ?>
                          <h5 class="mb-1"><?= htmlspecialchars($server['name']) ?></h5>
                        <?php endif; ?>
                        <div class="server-url text-truncate" title="<?= htmlspecialchars($server['url']) ?>">
                          <?= htmlspecialchars($server['url']) ?>
                        </div>
                      </div>

                      <div class="mt-auto">
                        <div class="d-flex justify-content-between mb-2">
                          <div>
                            <span class="health-indicator <?=
                              $status['status'] === 'up' ? 'health-up' :
                              ($status['status'] === 'down' ? 'health-down' : 'health-unknown')
                              ?>"></span>
                            <span class="text-muted small">Status: </span>
                            <span class="fw-bold <?=
                              $status['status'] === 'up' ? 'text-success' :
                              ($status['status'] === 'down' ? 'text-danger' : 'text-secondary')
                              ?>">
                              <?= ucfirst($status['status']) ?>
                            </span>
                          </div>
                          <div>
                            <span class="text-muted small">Latency: </span>
                            <span class="fw-bold"><?= $status['latency'] ?>ms</span>
                          </div>
                        </div>

                        <div class="d-flex justify-content-between mb-2">
                          <div>
                            <span class="text-muted small">Last Check: </span>
                            <span class="fw-bold"><?= $status['last_check'] ?></span>
                          </div>
                          <div>
                            <span class="text-muted small">Interval: </span>
                            <span class="fw-bold"><?= $server['check_interval'] ?> min</span>
                          </div>
                        </div>

                        <div class="d-flex justify-content-between mb-2">
                          <div>
                            <span class="text-muted small">Total Checks: </span>
                            <span class="fw-bold"><?= $status['checks'] ?></span>
                          </div>
                          <div>
                            <span class="text-muted small">Server IP: </span>
                            <span class="fw-bold"><?= $status['ip_address'] ?></span>
                          </div>
                        </div>

                        <div class="d-flex flex-wrap gap-1 mb-2">
                          <span class="badge bg-success bg-opacity-10 text-success">
                            <i class="fas fa-check-circle me-1"></i> Success
                          </span>
                          <span class="status-badge success-badge">
                            <?= htmlspecialchars(formatStatusCodes($server['success'], $server['short_success'] ?? '')) ?>
                          </span>
                        </div>

                        <div class="d-flex flex-wrap gap-1 mb-3">
                          <span class="badge bg-danger bg-opacity-10 text-danger">
                            <i class="fas fa-times-circle me-1"></i> Errors
                          </span>
                          <span class="status-badge error-badge">
                            <?= htmlspecialchars(formatStatusCodes($server['errors'], $server['short_errors'] ?? '')) ?>
                          </span>
                        </div>

                        <div class="d-flex gap-2">
                          <a href="?toggle=<?= $i ?>"
                            class="btn btn-sm flex-grow-1 <?= $server['enabled'] ? 'btn-outline-secondary' : 'btn-outline-success' ?>">
                            <i class="fas fa-power-off me-1"></i> <?= $server['enabled'] ? 'Disable' : 'Enable' ?>
                          </a>
                          <a href="index.php?server=<?= urlencode($server['url']) ?>" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-chart-line me-1"></i> Stats
                          </a>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Edit Modal for each server -->
                <div class="modal fade" id="editModal<?= $i ?>" tabindex="-1" aria-hidden="true">
                  <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                      <div class="modal-header tomato-bg text-white">
                        <h5 class="modal-title"><i class="fas fa-edit me-2"></i> Edit Server</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                          aria-label="Close"></button>
                      </div>
                      <form method="post">
                        <input type="hidden" name="index" value="<?= $i ?>">
                        <div class="modal-body">
                          <div class="row g-3">
                            <div class="col-md-6">
                              <label class="form-label">Server Name</label>
                              <input type="text" class="form-control" name="name"
                                value="<?= htmlspecialchars($server['name'] ?? '') ?>" placeholder="My Server">
                            </div>
                            <div class="col-md-6">
                              <label class="form-label">Server URL</label>
                              <input type="url" class="form-control" name="url"
                                value="<?= htmlspecialchars($server['url']) ?>" required>
                            </div>

                            <div class="col-md-12">
                              <label class="form-label">Icon</label>
                              <div class="input-group">
                                <input type="text" class="form-control" name="icon" id="editIcon<?= $i ?>"
                                  value="<?= htmlspecialchars($server['icon']) ?>" required>
                                <button class="btn btn-outline-secondary" type="button" data-bs-toggle="collapse"
                                  data-bs-target="#editIconPicker<?= $i ?>">
                                  <i class="fas fa-icons"></i> Pick Icon
                                </button>
                              </div>

                              <div class="collapse mt-2" id="editIconPicker<?= $i ?>">
                                <div class="card card-body p-3">
                                  <ul class="nav nav-tabs" id="editIconTabs<?= $i ?>" role="tablist">
                                    <li class="nav-item" role="presentation">
                                      <button class="nav-link active" id="edit-emoji-tab<?= $i ?>" data-bs-toggle="tab"
                                        data-bs-target="#edit-emoji-panel<?= $i ?>" type="button">Emoji</button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                      <button class="nav-link" id="edit-fa-tab<?= $i ?>" data-bs-toggle="tab"
                                        data-bs-target="#edit-fa-panel<?= $i ?>" type="button">Font Awesome</button>
                                    </li>
                                  </ul>
                                  <div class="tab-content p-2 border border-top-0 rounded-bottom">
                                    <div class="tab-pane fade show active" id="edit-emoji-panel<?= $i ?>" role="tabpanel">
                                      <div class="emoji-picker">
                                        <?php foreach (['🖥️', '📡', '📶', '🔌', '💻', '📱', '🛰️', '📟', '📲', '🌐', '🚀', '🖱️', '⌨️', '🖨️', '💾', '📀', '🔋', '🔌', '💡', '🔦', '🕹️', '🎮', '👾', '🤖', '🧮'] as $emoji): ?>
                                          <span class="emoji-option <?= $emoji === $server['icon'] ? 'selected' : '' ?>"
                                            onclick="selectEditIcon('<?= $i ?>', '<?= $emoji ?>')">
                                            <?= $emoji ?>
                                          </span>
                                        <?php endforeach; ?>
                                      </div>
                                    </div>
                                    <div class="tab-pane fade" id="edit-fa-panel<?= $i ?>" role="tabpanel">
                                      <select class="form-select" onchange="selectEditIcon('<?= $i ?>', this.value)">
                                        <option value="">Select Font Awesome Icon</option>
                                        <?php
                                        $faIcons = [
                                          'fa-server',
                                          'fa-desktop',
                                          'fa-laptop',
                                          'fa-mobile-alt',
                                          'fa-database',
                                          'fa-network-wired',
                                          'fa-wifi',
                                          'fa-cloud',
                                          'fa-globe',
                                          'fa-satellite-dish',
                                          'fa-hdd',
                                          'fa-memory',
                                          'fa-microchip',
                                          'fa-ethernet',
                                          'fa-plug',
                                          'fa-power-off',
                                          'fa-shield-alt',
                                          'fa-lock',
                                          'fa-unlock',
                                          'fa-key',
                                          'fa-code-branch',
                                          'fa-code',
                                          'fa-terminal',
                                          'fa-bug',
                                          'fa-rocket'
                                        ];
                                        foreach ($faIcons as $icon): ?>
                                          <option value="fas fa-<?= $icon ?>" <?= "fas fa-$icon" === $server['icon'] ? 'selected' : '' ?>>
                                            <?= str_replace('-', ' ', $icon) ?>
                                          </option>
                                        <?php endforeach; ?>
                                      </select>
                                    </div>
                                  </div>
                                </div>
                              </div>
                            </div>

                            <div class="col-md-6">
                              <label class="form-label">Success Status Codes</label>
                              <input type="text" class="form-control" name="success"
                                value="<?= htmlspecialchars(formatStatusCodes($server['success'])) ?>"
                                placeholder="e.g. 200,201 or 100-399" required>
                              <small class="text-muted">Comma separated or ranges with hyphen</small>
                            </div>

                            <div class="col-md-6">
                              <label class="form-label">Short Success Display</label>
                              <input type="text" class="form-control" name="short_success"
                                value="<?= htmlspecialchars($server['short_success'] ?? '') ?>" placeholder="e.g. 2xx">
                              <small class="text-muted">What to show on dashboard</small>
                            </div>

                            <div class="col-md-6">
                              <label class="form-label">Error Status Codes</label>
                              <input type="text" class="form-control" name="errors"
                                value="<?= htmlspecialchars(formatStatusCodes($server['errors'])) ?>"
                                placeholder="e.g. 400,404 or 400-599" required>
                              <small class="text-muted">Comma separated or ranges with hyphen</small>
                            </div>

                            <div class="col-md-6">
                              <label class="form-label">Short Error Display</label>
                              <input type="text" class="form-control" name="short_errors"
                                value="<?= htmlspecialchars($server['short_errors'] ?? '') ?>" placeholder="e.g. 4xx,5xx">
                              <small class="text-muted">What to show on dashboard</small>
                            </div>

                            <div class="col-md-6">
                              <label class="form-label">Check Interval (minutes)</label>
                              <input type="number" class="form-control" name="check_interval"
                                value="<?= htmlspecialchars($server['check_interval'] ?? 5) ?>" min="1" max="1440">
                            </div>

                            <div class="col-md-6">
                              <div class="form-check form-switch pt-4">
                                <input class="form-check-input" type="checkbox" role="switch" id="editEnabled<?= $i ?>"
                                  name="enabled" <?= $server['enabled'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="editEnabled<?= $i ?>">Enabled</label>
                              </div>

                              <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="editCronEnabled<?= $i ?>"
                                  name="cron_enabled" <?= ($server['cron_enabled'] ?? false) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="editCronEnabled<?= $i ?>">Enable Cron Checks</label>
                              </div>
                            </div>
                          </div>
                        </div>
                        <div class="modal-footer">
                          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                          <button type="submit" name="edit_server" class="btn btn-tomato">Save Changes</button>
                        </div>
                      </form>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <!-- Cron Jobs Section -->
          <div class="card mb-4">
            <div class="card-header tomato-bg text-white">
              <h5 class="mb-0"><i class="fas fa-clock me-2"></i> Cron Jobs</h5>
            </div>
            <div class="card-body">
              <?php if (empty($cronJobs)): ?>
                <div class="text-center py-3">
                  <i class="fas fa-clock fa-3x text-muted mb-3"></i>
                  <p>No cron jobs configured</p>
                  <button class="btn btn-tomato" data-bs-toggle="modal" data-bs-target="#addCronModal">
                    <i class="fas fa-plus me-1"></i> Add Cron Job
                  </button>
                </div>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table">
                    <thead>
                      <tr>
                        <th>Server</th>
                        <th>Schedule</th>
                        <th>Command</th>
                        <th>Status</th>
                        <th>Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($cronJobs as $i => $cron):
                        $server = $servers[$cron['server_index']] ?? null;
                        ?>
                        <tr>
                          <td>
                            <?php if ($server): ?>
                              <i class="<?= htmlspecialchars($server['icon']) ?> me-2"></i>
                              <?= htmlspecialchars($server['name'] ?? $server['url']) ?>
                            <?php else: ?>
                              <span class="text-muted">Server not found</span>
                            <?php endif; ?>
                          </td>
                          <td><?= htmlspecialchars($cron['schedule']) ?></td>
                          <td><?= htmlspecialchars($cron['command']) ?></td>
                          <td>
                            <span class="badge <?= $cron['enabled'] ? 'bg-success' : 'bg-secondary' ?>">
                              <?= $cron['enabled'] ? 'Active' : 'Disabled' ?>
                            </span>
                          </td>
                          <td>
                            <a href="?delete_cron=<?= $i ?>" class="btn btn-sm btn-outline-danger"
                              onclick="return confirm('Are you sure you want to delete this cron job?')">
                              <i class="fas fa-trash"></i>
                            </a>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <div class="text-end mt-3">
                  <button class="btn btn-tomato" data-bs-toggle="modal" data-bs-target="#addCronModal">
                    <i class="fas fa-plus me-1"></i> Add Cron Job
                  </button>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Right Sidebar -->
        <div class="col-lg-4">
          <!-- Health Overview -->
          <div class="card mb-4">
            <div class="card-header tomato-bg text-white">
              <h5 class="mb-0"><i class="fas fa-heartbeat me-2"></i> Health Overview</h5>
            </div>
            <div class="card-body">
              <div class="chart-container">
                <canvas id="healthChart"></canvas>
              </div>
              <div class="d-flex justify-content-between">
                <div>
                  <span class="health-indicator health-up"></span>
                  <span>Up:
                    <?= count(array_filter($servers, fn($s) => (getServerStatus($s['url'])['status'] === 'up'))) ?></span>
                </div>
                <div>
                  <span class="health-indicator health-down"></span>
                  <span>Down:
                    <?= count(array_filter($servers, fn($s) => (getServerStatus($s['url'])['status'] === 'down'))) ?></span>
                </div>
                <div>
                  <span class="health-indicator health-unknown"></span>
                  <span>Unknown:
                    <?= count(array_filter($servers, fn($s) => (getServerStatus($s['url'])['status'] === 'unknown'))) ?></span>
                </div>
              </div>
            </div>
          </div>

          <!-- Latency Stats -->
          <div class="card mb-4">
            <div class="card-header tomato-bg text-white">
              <h5 class="mb-0"><i class="fas fa-tachometer-alt me-2"></i> Latency Statistics</h5>
            </div>
            <div class="card-body">
              <div class="chart-container">
                <canvas id="latencyChart"></canvas>
              </div>
              <div class="d-flex justify-content-between mt-3">
                <div class="text-center">
                  <div class="text-muted small">Fastest</div>
                  <div class="fw-bold">
                    <?php
                    if(count($servers)){
                      $fastest = min(array_map(fn($s) => (getServerStatus($s['url'])['latency']), $servers));
                    }else{
                      $fastest = 0;
                    }
                    echo $fastest > 0 ? $fastest . 'ms' : 'N/A';
                    ?>
                  </div>
                </div>
                <div class="text-center">
                  <div class="text-muted small">Average</div>
                  <div class="fw-bold">
                    <?php
                    $latencies = array_filter(array_map(fn($s) => (getServerStatus($s['url'])['latency']), $servers));
                    $average = count($latencies) > 0 ? array_sum($latencies) / count($latencies) : 0;
                    echo $average > 0 ? round($average) . 'ms' : 'N/A';
                    ?>
                  </div>
                </div>
                <div class="text-center">
                  <div class="text-muted small">Slowest</div>
                  <div class="fw-bold">
                    <?php
                    if(count($servers)){
                      $slowest = max(array_map(fn($s) => (getServerStatus($s['url'])['latency']), $servers));
                    }else{
                      $slowest = 0;
                    }
                    echo $slowest > 0 ? $slowest . 'ms' : 'N/A';
                    ?>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Recent Activity -->
          <div class="card">
            <div class="card-header tomato-bg text-white">
              <h5 class="mb-0"><i class="fas fa-history me-2"></i> Recent Activity</h5>
            </div>
            <div class="card-body">
              <ul class="list-group list-group-flush">
                <?php
                $allStatuses = [];
                foreach ($servers as $server) {
                  $status = getServerStatus($server['url']);
                  $status['url'] = $server['url'];
                  $status['name'] = $server['name'] ?? $server['url'];
                  $allStatuses[] = $status;
                }

                // Sort by last check time (newest first)
                usort($allStatuses, fn($a, $b) => strtotime($b['last_check']) - strtotime($a['last_check']));

                // Display top 5
                foreach (array_slice($allStatuses, 0, 5) as $status):
                  ?>
                  <li class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                      <span class="health-indicator <?=
                        $status['status'] === 'up' ? 'health-up' :
                        ($status['status'] === 'down' ? 'health-down' : 'health-unknown')
                        ?>"></span>
                      <span class="fw-bold"><?= htmlspecialchars($status['name']) ?></span>
                    </div>
                    <div class="text-end">
                      <div class="small text-muted"><?= $status['last_check'] ?></div>
                      <div class="small"><?= $status['latency'] ?>ms</div>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Add Server Modal -->
    <div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header tomato-bg text-white">
            <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i> Add New Server</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form method="post">
            <div class="modal-body">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Server Name</label>
                  <input type="text" class="form-control" name="name" placeholder="My Server">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Server URL</label>
                  <input type="url" class="form-control" name="url" placeholder="https://example.com" required>
                </div>

                <div class="col-md-12">
                  <label class="form-label">Icon</label>
                  <div class="input-group">
                    <input type="text" class="form-control" name="icon" id="addIcon" value="🖥️" required>
                    <button class="btn btn-outline-secondary" type="button" data-bs-toggle="collapse"
                      data-bs-target="#addIconPicker">
                      <i class="fas fa-icons"></i> Pick Icon
                    </button>
                  </div>

                  <div class="collapse mt-2" id="addIconPicker">
                    <div class="card card-body p-3">
                      <ul class="nav nav-tabs" id="addIconTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                          <button class="nav-link active" id="add-emoji-tab" data-bs-toggle="tab"
                            data-bs-target="#add-emoji-panel" type="button">Emoji</button>
                        </li>
                        <li class="nav-item" role="presentation">
                          <button class="nav-link" id="add-fa-tab" data-bs-toggle="tab" data-bs-target="#add-fa-panel"
                            type="button">Font Awesome</button>
                        </li>
                      </ul>
                      <div class="tab-content p-2 border border-top-0 rounded-bottom">
                        <div class="tab-pane fade show active" id="add-emoji-panel" role="tabpanel">
                          <div class="emoji-picker">
                            <?php foreach (['🖥️', '📡', '📶', '🔌', '💻', '📱', '🛰️', '📟', '📲', '🌐', '🚀', '🖱️', '⌨️', '🖨️', '💾', '📀', '🔋', '🔌', '💡', '🔦', '🕹️', '🎮', '👾', '🤖', '🧮'] as $emoji): ?>
                              <span class="emoji-option" onclick="selectAddIcon('<?= $emoji ?>')">
                                <?= $emoji ?>
                              </span>
                            <?php endforeach; ?>
                          </div>
                        </div>
                        <div class="tab-pane fade" id="add-fa-panel" role="tabpanel">
                          <select class="form-select" onchange="selectAddIcon(this.value)">
                            <option value="">Select Font Awesome Icon</option>
                            <?php
                            $faIcons = [
                              'fa-server',
                              'fa-desktop',
                              'fa-laptop',
                              'fa-mobile-alt',
                              'fa-database',
                              'fa-network-wired',
                              'fa-wifi',
                              'fa-cloud',
                              'fa-globe',
                              'fa-satellite-dish',
                              'fa-hdd',
                              'fa-memory',
                              'fa-microchip',
                              'fa-ethernet',
                              'fa-plug',
                              'fa-power-off',
                              'fa-shield-alt',
                              'fa-lock',
                              'fa-unlock',
                              'fa-key',
                              'fa-code-branch',
                              'fa-code',
                              'fa-terminal',
                              'fa-bug',
                              'fa-rocket'
                            ];
                            foreach ($faIcons as $icon): ?>
                              <option value="fas fa-<?= $icon ?>">
                                <?= str_replace('-', ' ', $icon) ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Success Status Codes</label>
                  <input type="text" class="form-control" name="success" placeholder="e.g. 200,201 or 100-399" required>
                  <small class="text-muted">Comma separated or ranges with hyphen</small>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Short Success Display</label>
                  <input type="text" class="form-control" name="short_success" placeholder="e.g. 2xx">
                  <small class="text-muted">What to show on dashboard</small>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Error Status Codes</label>
                  <input type="text" class="form-control" name="errors" placeholder="e.g. 400,404 or 400-599" required>
                  <small class="text-muted">Comma separated or ranges with hyphen</small>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Short Error Display</label>
                  <input type="text" class="form-control" name="short_errors" placeholder="e.g. 4xx,5xx">
                  <small class="text-muted">What to show on dashboard</small>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Check Interval (minutes)</label>
                  <input type="number" class="form-control" name="check_interval" value="5" min="1" max="1440">
                </div>

                <div class="col-md-6">
                  <div class="form-check form-switch pt-4">
                    <input class="form-check-input" type="checkbox" role="switch" id="addEnabled" name="enabled" checked>
                    <label class="form-check-label" for="addEnabled">Enabled</label>
                  </div>

                  <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="addCronEnabled" name="cron_enabled">
                    <label class="form-check-label" for="addCronEnabled">Enable Cron Checks</label>
                  </div>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" name="save_server" class="btn btn-tomato">Add Server</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Add Cron Job Modal -->
    <div class="modal fade" id="addCronModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header tomato-bg text-white">
            <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i> Add Cron Job</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form method="post">
            <div class="modal-body">
              <div class="mb-3">
                <label class="form-label">Server</label>
                <select class="form-select" name="server_index" required>
                  <option value="">Select Server</option>
                  <?php foreach ($servers as $i => $server): ?>
                    <option value="<?= $i ?>">
                      <?= htmlspecialchars($server['name'] ?? $server['url']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">Schedule</label>
                <input type="text" class="form-control" name="schedule" placeholder="* * * * *" required>
                <small class="text-muted">Cron schedule format (minute hour day month weekday)</small>
              </div>
              <div class="mb-3">
                <label class="form-label">Command</label>
                <input type="text" class="form-control" name="command" placeholder="curl -s https://example.com/health"
                  required>
              </div>
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="cronEnabled" name="enabled" checked>
                <label class="form-check-label" for="cronEnabled">Enabled</label>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" name="add_cron" class="btn btn-tomato">Add Cron Job</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
      // Icon selection functions
      function selectAddIcon(icon) {
        document.getElementById('addIcon').value = icon;
        // Close the picker if it's open (for mobile)
        const picker = new bootstrap.Collapse(document.getElementById('addIconPicker'), { toggle: false });
        picker.hide();
      }

      function selectEditIcon(index, icon) {
        document.getElementById('editIcon' + index).value = icon;
        // Close the picker if it's open (for mobile)
        const picker = new bootstrap.Collapse(document.getElementById('editIconPicker' + index), { toggle: false });
        picker.hide();

        // Update selected state for emoji options
        const emojiOptions = document.querySelectorAll('#editIconPicker' + index + ' .emoji-option');
        emojiOptions.forEach(option => {
          option.classList.remove('selected');
          if (option.textContent.trim() === icon.trim()) {
            option.classList.add('selected');
          }
        });
      }

      // Health Chart
      const healthCtx = document.getElementById('healthChart').getContext('2d');
      const healthChart = new Chart(healthCtx, {
        type: 'doughnut',
        data: {
          labels: ['Up', 'Down', 'Unknown'],
          datasets: [{
            data: [
              <?= count(array_filter($servers, fn($s) => (getServerStatus($s['url'])['status'] === 'up'))) ?>,
              <?= count(array_filter($servers, fn($s) => (getServerStatus($s['url'])['status'] === 'down'))) ?>,
              <?= count(array_filter($servers, fn($s) => (getServerStatus($s['url'])['status'] === 'unknown'))) ?>
            ],
            backgroundColor: [
              '#28a745',
              '#dc3545',
              '#6c757d'
            ],
            borderWidth: 1
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              position: 'bottom'
            }
          }
        }
      });

      // Latency Chart
      const latencyCtx = document.getElementById('latencyChart').getContext('2d');
      const latencyChart = new Chart(latencyCtx, {
        type: 'bar',
        data: {
          labels: <?= json_encode(array_map(fn($s) => $s['name'] ?? parse_url($s['url'], PHP_URL_HOST), $servers)) ?>,
          datasets: [{
            label: 'Latency (ms)',
            data: <?= json_encode(array_map(fn($s) => (getServerStatus($s['url'])['latency']), $servers)) ?>,
            backgroundColor: 'rgba(255, 99, 71, 0.7)',
            borderColor: 'rgba(255, 99, 71, 1)',
            borderWidth: 1
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          scales: {
            y: {
              beginAtZero: true,
              title: {
                display: true,
                text: 'Milliseconds'
              }
            }
          },
          plugins: {
            legend: {
              display: false
            }
          }
        }
      });
    </script>
  <?php endif; ?>
</body>

</html>