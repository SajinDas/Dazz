<?php
require 'vendor/autoload.php';
require 'src/Config.php';
require 'src/Mailersk.php';

use Dazz\Legacy\Config;
use Dazz\Legacy\Mailersk;

$db = Config::getDB();
$mailer = new Mailersk($db);
$message = "";

// Handle Manual Form Submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $senderIds = [5, 4, 3, 1, 6, 2];
    $sender = $mailer->getValidSender($senderIds, 400);
    
    if ($sender) {
        $status = $mailer->triggerEmailSending(
            $_POST['email'], 
            $sender['id'], 
            $_POST['company'], 
            $_POST['contact'] ?? 'Hiring Manager', 
            $_POST['job']
        );
        $message = "<div class='alert alert-info'>Manual Dispatch Status: $status</div>";
    }
}

// Stats Queries
$today = date('Y-m-d');
$daily_stats = $db->query("SELECT COUNT(*) as total FROM AgentsEmailTable WHERE  SendDate >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetch_assoc();
$total_stats = $db->query("SELECT COUNT(*) as total FROM AgentsEmailTable WHERE  SendDate >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetch_assoc();
?>

<!DOCTYPE html>
<html>
<head>
    <title>EURES Scraper Control Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<nav class="navbar navbar-dark bg-dark mb-4">
    <div class="container">
        <span class="navbar-brand mb-0 h1">🚀 LeadGen Dashboard</span>
        <span class="text-muted">Status: <span class="badge bg-success">System Active</span></span>
    </div>
</nav>

<div class="container">
    <div class="row text-center">
        <div class="col-md-4">
            <div class="card p-3">
                <h5>Today's Outbound</h5>
                <div class="stat-value"><?php echo $daily_stats['total']; ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card p-3">
                <h5>Total Pipeline</h5>
                <div class="stat-value"><?php echo $total_stats['total']; ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card p-3">
                <h5>Active Senders</h5>
                <div class="stat-value text-info">6</div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-5">
            <div class="card p-4">
                <h4 class="mb-4">Manual Outreach</h4>
                <?php echo $message; ?>
                <form method="POST">
                    <div class="mb-3">
                        <label>Target Email</label>
                        <input type="email" name="email" class="form-control" placeholder="hr@company.sk" required>
                    </div>
                    <div class="mb-3">
                        <label>Company Name</label>
                        <input type="text" name="company" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label>Job Title / Occupation</label>
                        <input type="text" name="job" class="form-control" placeholder="Production Operator" required>
                    </div>
                    <div class="mb-3">
                        <label>Contact Person (Optional)</label>
                        <input type="text" name="contact" class="form-control">
                    </div>
                    <button type="submit" class="btn btn-primary w-100 py-2">Trigger `Mailersk` Logic</button>
                </form>
            </div>
        </div>

        <div class="col-md-7">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4 class="mb-0">Recent Activity</h4>
                    <button onclick="location.reload()" class="btn btn-sm btn-outline-secondary">Refresh</button>
                </div>
                <div class="table-responsive">
                    <table class="table table-dark table-striped mb-0">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Company</th>
                                <th>Role</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            
                            <tr>
                                <td> </td>
                                <td> </td>
                                <td> </small></td>
                                <td> </td>
                            </tr>
                             
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

</body>
</html>