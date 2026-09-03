<?php
// ==========================================
// 1. DATABASE SETUP & AUTO-UPDATES
// ==========================================
$host = "localhost";
$user = "root";
$pass = "";

$conn = new mysqli($host, $user, $pass);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

$conn->query("CREATE DATABASE IF NOT EXISTS lab_project");
$conn->select_db("lab_project");

$tableQuery = "CREATE TABLE IF NOT EXISTS bug_reports (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    reporter_name VARCHAR(100) NOT NULL,
    bug_title VARCHAR(150) NOT NULL,
    description TEXT NOT NULL,
    severity ENUM('Low', 'Medium', 'High', 'Critical') NOT NULL,
    status ENUM('Open', 'In Progress', 'Resolved') DEFAULT 'Open',
    attachment VARCHAR(255) DEFAULT NULL,
    report_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
$conn->query($tableQuery);

$checkColumn = $conn->query("SHOW COLUMNS FROM bug_reports LIKE 'attachment'");
if($checkColumn->num_rows == 0) {
    $conn->query("ALTER TABLE bug_reports ADD COLUMN attachment VARCHAR(255) DEFAULT NULL AFTER status");
}

$uploadDir = 'uploads/';
if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }

// ==========================================
// 2. HANDLE CRUD ACTIONS
// ==========================================
$message = "";
$messageType = "";

// ACTION: Create (Submit Form)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_bug'])) {
    $reporter = htmlspecialchars(trim($_POST['reporter_name']));
    $title = htmlspecialchars(trim($_POST['bug_title']));
    $desc = htmlspecialchars(trim($_POST['description']));
    $severity = htmlspecialchars(trim($_POST['severity']));

    $attachmentPath = NULL;
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] == 0) {
        $fileName = time() . '_' . preg_replace("/[^a-zA-Z0-9.-]/", "_", basename($_FILES['attachment']['name']));
        if (move_uploaded_file($_FILES['attachment']['tmp_name'], $uploadDir . $fileName)) {
            $attachmentPath = $uploadDir . $fileName;
        }
    }

    if (!empty($reporter) && !empty($title) && !empty($desc)) {
        $stmt = $conn->prepare("INSERT INTO bug_reports (reporter_name, bug_title, description, severity, status, attachment) VALUES (?, ?, ?, ?, 'Open', ?)");
        $stmt->bind_param("sssss", $reporter, $title, $desc, $severity, $attachmentPath);
        if ($stmt->execute()) {
            $message = "Success: Bug report submitted!";
            $messageType = "alert-success";
        }
        $stmt->close();
    }
}

// ACTION: Update Status
if (isset($_POST['update_status'])) {
    $id = (int)$_POST['bug_id'];
    $newStatus = $_POST['new_status'];
    $conn->query("UPDATE bug_reports SET status = '$newStatus' WHERE id = $id");
    $message = "Status updated successfully.";
    $messageType = "alert-success";
}

// ACTION: Delete
if (isset($_POST['delete_bug'])) {
    $id = (int)$_POST['bug_id'];
    $conn->query("DELETE FROM bug_reports WHERE id = $id");
    $message = "Record deleted successfully.";
    $messageType = "alert-danger";
}

// ==========================================
// 3. FETCH DATA (Search, Filter, Stats)
// ==========================================
$stats = [
    'total' => $conn->query("SELECT COUNT(*) as c FROM bug_reports")->fetch_assoc()['c'],
    'critical' => $conn->query("SELECT COUNT(*) as c FROM bug_reports WHERE severity = 'Critical'")->fetch_assoc()['c'],
    'resolved' => $conn->query("SELECT COUNT(*) as c FROM bug_reports WHERE status = 'Resolved'")->fetch_assoc()['c'],
    'open' => $conn->query("SELECT COUNT(*) as c FROM bug_reports WHERE status = 'Open'")->fetch_assoc()['c']
];

$searchQuery = "";
$filterStatus = "";
$sql = "SELECT * FROM bug_reports WHERE 1=1";

if (!empty($_GET['search'])) {
    $searchQuery = $conn->real_escape_string($_GET['search']);
    $sql .= " AND (bug_title LIKE '%$searchQuery%' OR reporter_name LIKE '%$searchQuery%')";
}
if (!empty($_GET['filter_status'])) {
    $filterStatus = $conn->real_escape_string($_GET['filter_status']);
    $sql .= " AND status = '$filterStatus'";
}

$sql .= " ORDER BY id DESC";
$reports = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Professional Bug Tracker</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap');
        
        :root { --primary: #2563eb; --primary-hover: #1d4ed8; --sidebar: #1e293b; --bg: #f8fafc; --text-dark: #0f172a; --text-light: #64748b; --white: #ffffff; --danger: #ef4444; --success: #10b981; --warning: #f59e0b; }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; scroll-behavior: smooth; }
        body { display: flex; background-color: var(--bg); color: var(--text-dark); min-height: 100vh; }
        
        /* LAYOUT */
        .sidebar { width: 250px; background-color: var(--sidebar); color: var(--white); padding: 2rem 1.5rem; position: fixed; height: 100vh; }
        .brand { font-size: 1.5rem; font-weight: 700; margin-bottom: 2rem; display: block; text-align: center; }
        .nav-links { list-style: none; }
        .nav-links a { color: #cbd5e1; text-decoration: none; display: block; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 0.5rem; transition: 0.3s; }
        .nav-links a:hover { background-color: var(--primary); color: var(--white); }
        .main-content { margin-left: 250px; padding: 2rem 3rem; width: calc(100% - 250px); }
        section { margin-bottom: 4rem; padding-top: 1rem; }
        
        /* UI ELEMENTS */
        .card { background: var(--white); padding: 2rem; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .hero-banner { background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%); color: white; padding: 3rem; border-radius: 12px; margin-bottom: 2rem; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
        .stat-card { background: var(--white); padding: 1.5rem; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); text-align: center; border-bottom: 4px solid var(--primary); }
        .stat-card.danger { border-bottom-color: var(--danger); } .stat-card.success { border-bottom-color: var(--success); }
        
        /* FORMS & TABLES */
        .form-group { margin-bottom: 1.5rem; }
        .form-group label { display: block; margin-bottom: 0.5rem; font-weight: 600; text-align: left; }
        .form-control { width: 100%; padding: 0.75rem; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 1rem; }
        .btn { background: var(--primary); color: white; padding: 0.75rem 1.5rem; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; width: 100%; transition: 0.2s;}
        .btn:hover { background: var(--primary-hover); }
        .btn-small { padding: 0.4rem 0.8rem; font-size: 0.85rem; width: auto; }
        .btn-danger { background: var(--danger); } .btn-warning { background: var(--warning); }
        
        .alert { padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; text-align: left;}
        .alert-success { background: #dcfce7; color: #166534; } .alert-danger { background: #fee2e2; color: #991b1b; }
        
        .data-table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        .data-table th, .data-table td { padding: 1rem; text-align: left; border-bottom: 1px solid #e2e8f0; }
        .badge { padding: 0.25rem 0.75rem; border-radius: 999px; font-size: 0.85rem; font-weight: 600; }
        
        /* MODAL */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.7); z-index: 1000; justify-content: center; align-items: center; }
        .modal-content { background: var(--white); padding: 2.5rem; border-radius: 12px; width: 90%; max-width: 600px; position: relative; }
        .close-btn { position: absolute; top: 1rem; right: 1.5rem; font-size: 1.8rem; cursor: pointer; border: none; background: none; }
    </style>
</head>
<body>

    <aside class="sidebar">
        <span class="brand">Lab WebApp</span>
        <ul class="nav-links">
            <li><a href="#dashboard">📊 Dashboard</a></li>
            <li><a href="#submit">📝 Submit Bug</a></li>
            <li><a href="#manage">⚙️ Manage DB</a></li>
        </ul>
    </aside>

    <main class="main-content">
        <?php if(!empty($message)): ?>
            <div class="alert <?php echo $messageType; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <section id="dashboard">
            <div class="hero-banner">
                <h1>System Overview</h1>
                <p>Welcome to the dashboard. Here are the live statistics from the MySQL database.</p>
            </div>
            <div class="stats-grid">
                <div class="stat-card"><p>Total Records</p><h3><?php echo $stats['total']; ?></h3></div>
                <div class="stat-card danger"><p>Critical Issues</p><h3 style="color: var(--danger);"><?php echo $stats['critical']; ?></h3></div>
                <div class="stat-card warning"><p>Open Tickets</p><h3 style="color: var(--warning);"><?php echo $stats['open']; ?></h3></div>
                <div class="stat-card success"><p>Resolved</p><h3 style="color: var(--success);"><?php echo $stats['resolved']; ?></h3></div>
            </div>
        </section>

        <section id="submit">
            <div class="card">
                <h2>Submit New Bug Report</h2>
                <hr style="border: 0; border-bottom: 1px solid #e2e8f0; margin: 1rem 0 2rem;">
                <form method="POST" action="index.php#submit" enctype="multipart/form-data">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                        <div class="form-group"><label>Reporter Name</label><input type="text" name="reporter_name" class="form-control" required></div>
                        <div class="form-group"><label>Severity Level</label>
                            <select name="severity" class="form-control" required>
                                <option value="Low">Low</option><option value="Medium">Medium</option>
                                <option value="High">High</option><option value="Critical">Critical</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group"><label>Bug Title</label><input type="text" name="bug_title" class="form-control" required></div>
                    <div class="form-group"><label>Description</label><textarea name="description" class="form-control" rows="3" required></textarea></div>
                    <div class="form-group"><label>Attach Evidence (File/Image)</label><input type="file" name="attachment" class="form-control"></div>
                    <button type="submit" name="submit_bug" class="btn" style="width: auto;">Submit Record</button>
                </form>
            </div>
        </section>

        <section id="manage">
            <div class="card">
                <h2>Manage Database Records</h2>
                
                <form method="GET" action="index.php#manage" style="display:flex; gap:1rem; margin: 1.5rem 0; background: #f8fafc; padding: 1rem; border-radius:8px;">
                    <input type="text" name="search" class="form-control" placeholder="Search title or name..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                    <select name="filter_status" class="form-control" style="width: 200px;">
                        <option value="">All Statuses</option>
                        <option value="Open" <?php if($filterStatus=='Open') echo 'selected';?>>Open</option>
                        <option value="In Progress" <?php if($filterStatus=='In Progress') echo 'selected';?>>In Progress</option>
                        <option value="Resolved" <?php if($filterStatus=='Resolved') echo 'selected';?>>Resolved</option>
                    </select>
                    <button type="submit" class="btn btn-small">Filter</button>
                    <a href="index.php#manage" class="btn btn-small" style="background:#cbd5e1; color:#0f172a; text-decoration:none; display:flex; align-items:center; justify-content:center;">Clear</a>
                </form>

                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Bug Details</th>
                                <th>Severity</th>
                                <th>Status</th>
                                <th>QA Plan</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if ($reports->num_rows > 0) {
                                while($row = $reports->fetch_assoc()) {
                                    $fileUrl = !empty($row['attachment']) ? htmlspecialchars($row['attachment']) : '';
                                    
                                    echo "<tr>";
                                    echo "<td><strong>" . htmlspecialchars($row['bug_title']) . "</strong><br><small>#" . $row['id'] . " | by " . htmlspecialchars($row['reporter_name']) . "</small></td>";
                                    
                                    echo "<td>" . $row['severity'] . "</td>";
                                    
                                    // Status Form
                                    echo "<td>
                                        <form method='POST' style='display:flex; gap:0.5rem;'>
                                            <input type='hidden' name='bug_id' value='".$row['id']."'>
                                            <select name='new_status' class='form-control' style='padding:0.25rem; font-size:0.85rem;' onchange='this.form.submit()'>
                                                <option value='Open' ".($row['status']=='Open'?'selected':'').">Open</option>
                                                <option value='In Progress' ".($row['status']=='In Progress'?'selected':'').">In Progress</option>
                                                <option value='Resolved' ".($row['status']=='Resolved'?'selected':'').">Resolved</option>
                                            </select>
                                            <input type='hidden' name='update_status' value='1'>
                                        </form>
                                    </td>";

                                    // Evaluation Modal Trigger
                                    echo "<td><button class='btn btn-small' style='background:transparent; border:1px solid var(--primary); color:var(--primary);' onclick='openEvalModal(".$row['id'].", \"$fileUrl\")'>View Plan</button></td>";

                                    // Delete Action
                                    echo "<td>
                                        <form method='POST' onsubmit='return confirm(\"Are you sure you want to delete this bug?\");'>
                                            <input type='hidden' name='bug_id' value='".$row['id']."'>
                                            <button type='submit' name='delete_bug' class='btn btn-small btn-danger'>Delete</button>
                                        </form>
                                    </td>";
                                    echo "</tr>";
                                }
                            } else {
                                echo "<tr><td colspan='5' style='text-align:center;'>No records match your criteria.</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>

    <div id="evalModal" class="modal-overlay">
        <div class="modal-content">
            <button class="close-btn" onclick="closeEvalModal()">&times;</button>
            <h2 style="color: var(--primary); margin-bottom: 0.5rem;">QA Verification Plan</h2>
            <div id="modalBody" style="background: #f8fafc; border-left: 4px solid var(--primary); padding: 1.5rem; margin-top: 1rem; border-radius: 4px; line-height: 1.6;"></div>
        </div>
    </div>

    <script>
        function openEvalModal(id, fileUrl) {
            const body = document.getElementById('modalBody');
            let html = `<strong>Target: Issue #${id}</strong><br><br>`;
            
            if (fileUrl) {
                html += `1. <strong>Analyze Evidence:</strong> Open <a href='${fileUrl}' target='_blank' style='color:var(--primary);'>Attached Evidence</a> to verify.<br>
                         2. <strong>Replication:</strong> Reproduce bug locally on XAMPP.<br>
                         3. <strong>Correction:</strong> Apply PHP/MySQL patch based on visual data.<br>
                         4. <strong>Validation:</strong> Confirm fix directly against the attachment.`;
            } else {
                html += `1. <strong>Warning:</strong> <span style='color:var(--danger);'>No File Attached.</span><br>
                         2. <strong>Action:</strong> Attempt blind manual testing.<br>
                         3. <strong>Correction:</strong> Patch based on textual assumptions.<br>
                         4. <strong>Notice:</strong> Return to reporter if replication fails.`;
            }
            body.innerHTML = html;
            document.getElementById('evalModal').style.display = 'flex';
        }
        function closeEvalModal() { document.getElementById('evalModal').style.display = 'none'; }
        window.onclick = function(e) { if(e.target == document.getElementById('evalModal')) closeEvalModal(); }
    </script>

</body>
</html>