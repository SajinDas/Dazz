<?php
require 'vendor/autoload.php';
require_once 'src/Config.php';
require_once 'src/Mailer.php';
require_once 'src/BounceScanner.php';

use Dazz\Legacy\Config;
use Dazz\Legacy\Mailer;
use Dazz\Legacy\BounceScanner;

$db = Config::getDB();
$mailer = new Mailer($db);
$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? 'send_manual';

    if ($action === 'scan_bounces') {
        ob_start();
        $scanner = new BounceScanner($db);
        $summary = $scanner->runFullBounceAndBlockSync(true, 7);
        $scanLog = ob_get_clean();
        $message = "<div class='alert alert-warning'><strong>Thunderbird & IMAP Bounce Sync Complete:</strong> "
                 . "Found {$summary['thunderbird_found']} Thunderbird bounces and {$summary['imap_found']} IMAP bounces. "
                 . "Updated {$summary['db_updated']} existing records and inserted {$summary['db_inserted']} blocked records (Statuss=2).</div>";
    } else {
        $senderIds = Config::DEFAULT_SENDERS;
        $sender = $mailer->getValidSender($senderIds, Config::DAILY_SENDER_LIMIT);

        if ($sender) {
            $lang    = $_POST['lang'] ?? 'en';
            $email   = trim($_POST['email'] ?? '');
            $company = trim($_POST['company'] ?? 'Hiring Company');
            $contact = !empty($_POST['contact']) ? trim($_POST['contact']) : 'Hiring Manager';
            $job     = trim($_POST['job'] ?? 'Skilled Personnel');

            $status = $mailer->triggerEmailSending($email, $sender['id'], $lang, $company, $contact, $job);
            $message = "<div class='alert alert-info'>Manual Dispatch Status: <strong>" . htmlspecialchars($status) . "</strong> (Sender #{$sender['id']})</div>";
        } else {
            $message = "<div class='alert alert-danger'>All sender accounts have reached their 24-hour limit.</div>";
        }
    }
}

$daily_stats   = $db->query("SELECT COUNT(*) as total FROM AgentsEmailTable WHERE SendDate >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND Statuss = '1'")->fetch_assoc();
$blocked_stats = $db->query("SELECT COUNT(*) as total FROM AgentsEmailTable WHERE Statuss = '2'")->fetch_assoc();
$recent_rows   = $db->query("SELECT EmailId, SendDate, Statuss, SendedEmail FROM AgentsEmailTable ORDER BY SendDate DESC LIMIT 12");
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Dazz Legacy - Multi-Country B2B LeadGen Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-4">
    <div class="container">
        <span class="navbar-brand mb-0 h1">Dazz Legacy — Pan-European B2B Recruitment Control Panel</span>
        <form method="POST" class="d-inline">
            <input type="hidden" name="action" value="scan_bounces">
            <button type="submit" class="btn btn-warning btn-sm fw-bold">Scan Thunderbird & IMAP Bounces Now</button>
        </form>
    </div>
</nav>

<div class="container">
    <?php echo $message; ?>
    <div class="row text-center mb-4">
        <div class="col-md-4">
            <div class="card p-3 shadow-sm">
                <h5>Last 24h Outbound</h5>
                <div class="fs-2 fw-bold text-primary"><?php echo (int)($daily_stats['total'] ?? 0); ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card p-3 shadow-sm">
                <h5>Blocked / Bounced (Statuss=2)</h5>
                <div class="fs-2 fw-bold text-danger"><?php echo (int)($blocked_stats['total'] ?? 0); ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card p-3 shadow-sm">
                <h5>Target EU Countries</h5>
                <div class="fs-2 fw-bold text-info"><?php echo count(Config::COUNTRIES); ?></div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-5">
            <div class="card p-4 shadow-sm">
                <h4 class="mb-3">Manual B2B Outreach</h4>
                <form method="POST">
                    <input type="hidden" name="action" value="send_manual">
                    <div class="mb-3">
                        <label class="form-label">Target Country / Language</label>
                        <select name="lang" class="form-select" required>
                            <option value="en">English (Malta / EU General)</option>
                            <option value="sk">Slovakia (Slovak)</option>
                            <option value="cs">Czechia (Czech)</option>
                            <option value="sl">Slovenia (Slovenian)</option>
                            <option value="hr">Croatia (Croatian)</option>
                            <option value="lv">Latvia (Latvian)</option>
                            <option value="lt">Lithuania (Lithuanian)</option>
                            <option value="et">Estonia (Estonian)</option>
                            <option value="pl">Poland (Polish)</option>
                            <option value="ro">Romania (Romanian)</option>
                            <option value="hu">Hungary (Hungarian)</option>
                            <option value="de">Germany / Austria (German)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Target Employer Email</label>
                        <input type="email" name="email" class="form-control" placeholder="hr@company.eu" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Company Name</label>
                        <input type="text" name="company" class="form-control" placeholder="e.g. MetalWerk s.r.o." required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Job Title / Occupation</label>
                        <input type="text" name="job" class="form-control" placeholder="e.g. MIG/MAG Welder / CNC Operator" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Contact Person (Optional)</label>
                        <input type="text" name="contact" class="form-control" placeholder="Hiring Manager">
                    </div>
                    <button type="submit" class="btn btn-primary w-100 py-2">Send Localized B2B Proposal</button>
                </form>
            </div>
        </div>

        <div class="col-md-7">
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center bg-white">
                    <h4 class="mb-0">Recent Outbound & Blocked Activity</h4>
                    <button onclick="location.reload()" class="btn btn-sm btn-outline-secondary">Refresh</button>
                </div>
                <div class="table-responsive">
                    <table class="table table-striped mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Date</th>
                                <th>Email Address</th>
                                <th>Sender ID</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recent_rows): while ($row = $recent_rows->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['SendDate'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['EmailId'] ?? ''); ?></td>
                                <td>#<?php echo (int)($row['SendedEmail'] ?? 0); ?></td>
                                <td>
                                    <?php if (($row['Statuss'] ?? '') == '1'): ?>
                                        <span class="badge bg-success">Sent (1)</span>
                                    <?php elseif (($row['Statuss'] ?? '') == '2'): ?>
                                        <span class="badge bg-danger">Blocked / Bounced (2)</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><?php echo htmlspecialchars($row['Statuss'] ?? ''); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
