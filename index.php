<?php
// index.php
// Automated Timetable Scheduler - single-file implementation (demo)
// Requirements: PHP 7+, MySQL, mysqli extension enabled

// ---------------------- Database Configuration / Setup ----------------------
class Database {
    private $host = "localhost";
    private $username = "root";
    private $password = "";
    private $database = "timetable_scheduler";
    private $connection;

    public function __construct() {
        $this->connection = new mysqli($this->host, $this->username, $this->password);
        if ($this->connection->connect_error) {
            die("Connection failed: " . $this->connection->connect_error);
        }
        // Create DB if not exists
        $this->connection->query("CREATE DATABASE IF NOT EXISTS `{$this->database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->connection->select_db($this->database);
    }

    public function getConnection() {
        return $this->connection;
    }

    public function createTables() {
        $queries = [
            "CREATE TABLE IF NOT EXISTS users (
                id INT PRIMARY KEY AUTO_INCREMENT,
                username VARCHAR(50) UNIQUE NOT NULL,
                email VARCHAR(100) UNIQUE NOT NULL,
                password VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",

            "CREATE TABLE IF NOT EXISTS teachers (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(100) UNIQUE NOT NULL,
                department VARCHAR(100) NOT NULL,
                hours_per_week INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",

            "CREATE TABLE IF NOT EXISTS subjects (
                id INT PRIMARY KEY AUTO_INCREMENT,
                subject_name VARCHAR(100) NOT NULL,
                type ENUM('Theory','Lab') NOT NULL,
                hours_per_week INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",

            "CREATE TABLE IF NOT EXISTS classrooms (
                id INT PRIMARY KEY AUTO_INCREMENT,
                classroom_name VARCHAR(50) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",

            "CREATE TABLE IF NOT EXISTS subject_allocation (
                id INT PRIMARY KEY AUTO_INCREMENT,
                teacher_id INT NOT NULL,
                subject_id INT NOT NULL,
                classroom_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_allocation (teacher_id, subject_id, classroom_id),
                FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
                FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
                FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE
            ) ENGINE=InnoDB",

            "CREATE TABLE IF NOT EXISTS timeslots (
                id INT PRIMARY KEY AUTO_INCREMENT,
                day VARCHAR(10) NOT NULL,
                period_no INT NOT NULL,
                start_time TIME NOT NULL,
                end_time TIME NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_day_period (day, period_no)
            ) ENGINE=InnoDB",

            "CREATE TABLE IF NOT EXISTS timetable (
                id INT PRIMARY KEY AUTO_INCREMENT,
                classroom_id INT NOT NULL,
                subject_id INT NOT NULL,
                teacher_id INT NOT NULL,
                timeslot_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_slot_classroom (classroom_id, timeslot_id),
                UNIQUE KEY unique_slot_teacher (teacher_id, timeslot_id),
                FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE,
                FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
                FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
                FOREIGN KEY (timeslot_id) REFERENCES timeslots(id) ON DELETE CASCADE
            ) ENGINE=InnoDB",

            "CREATE TABLE IF NOT EXISTS attendance (
                id INT PRIMARY KEY AUTO_INCREMENT,
                teacher_id INT NOT NULL,
                `date` DATE NOT NULL,
                status ENUM('Present','Absent') NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_teacher_date (teacher_id, `date`),
                FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
            ) ENGINE=InnoDB"
        ];

        foreach ($queries as $query) {
            $this->connection->query($query);
        }

        // Insert default timeslots if none
        $this->insertDefaultTimeslots();
    }

    private function insertDefaultTimeslots() {
        $check = $this->connection->query("SELECT COUNT(*) as count FROM timeslots");
        if ($check && $check->fetch_assoc()['count'] == 0) {
            $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $periods = [
                1 => ['09:30:00', '10:25:00'],
                // break (10:25-10:40) not stored as timeslot
                2 => ['10:40:00', '11:35:00'],
                3 => ['11:35:00', '12:30:00'],
                // lunch (12:30-13:25)
                4 => ['13:25:00', '14:20:00'],
                5 => ['14:20:00', '15:15:00']
            ];

            $stmt = $this->connection->prepare("INSERT INTO timeslots (day, period_no, start_time, end_time) VALUES (?, ?, ?, ?)");
            foreach ($days as $day) {
                foreach ($periods as $period_no => $times) {
                    $stmt->bind_param("siss", $day, $period_no, $times[0], $times[1]);
                    $stmt->execute();
                }
            }
            $stmt->close();
        }
    }
}

// Initialize DB and tables
$db = new Database();
$db->createTables();
$conn = $db->getConnection();

// ---------------------- Session Management ----------------------
session_start();

// handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// ---------------------- Helper: sanitize ----------------------
function e($v) {
    return htmlspecialchars($v, ENT_QUOTES);
}

// ---------------------- Authentication: Login / Register / Reset ----------------------
$login_error = $register_error = $register_success = $reset_error = $reset_success = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // login
    if (isset($_POST['login'])) {
        $username = trim($_POST['username']);
        $password = $_POST['password'];

        $stmt = $conn->prepare("SELECT id, password FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows > 0) {
            $user = $res->fetch_assoc();
            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $username;
                header("Location: index.php?page=dashboard");
                exit;
            } else {
                $login_error = "Invalid credentials";
            }
        } else {
            $login_error = "User not found";
        }
        $stmt->close();
    }

    // register (for demo)
    if (isset($_POST['register'])) {
        $username = trim($_POST['reg_username']);
        $email = trim($_POST['reg_email']);
        $pass = $_POST['reg_password'];
        if (empty($username) || empty($email) || empty($pass)) {
            $register_error = "All fields required.";
        } else {
            $password_hash = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $username, $email, $password_hash);
            if ($stmt->execute()) {
                $register_success = "Registration successful! Please login.";
            } else {
                $register_error = "Registration failed: " . e($stmt->error);
            }
            $stmt->close();
        }
    }

    // reset password
    if (isset($_POST['reset_password'])) {
        $email = trim($_POST['reset_email']);
        $username = trim($_POST['reset_username']);
        $new = $_POST['new_password'];
        $confirm = $_POST['confirm_password'];
        if ($new !== $confirm) {
            $reset_error = "Passwords do not match.";
        } else {
            $new_hash = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ? AND username = ?");
            $stmt->bind_param("sss", $new_hash, $email, $username);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $reset_success = "Password reset successful!";
            } else {
                $reset_error = "Reset failed. Check email & username.";
            }
            $stmt->close();
        }
    }
}

// ---------------------- CRUD and other operations ----------------------
$messages = [];

function flash($msg, $type = 'success') {
    global $messages;
    $messages[] = ['text' => $msg, 'type' => $type];
}

// Handle operation POSTs (Teachers, Subjects, Classrooms, Allocation, Attendance)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Teachers
    if (isset($_POST['add_teacher'])) {
        $name = $_POST['teacher_name'];
        $email = $_POST['teacher_email'];
        $department = $_POST['teacher_department'];
        $hours = intval($_POST['teacher_hours']);

        $stmt = $conn->prepare("INSERT INTO teachers (name, email, department, hours_per_week) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("sssi", $name, $email, $department, $hours);
        if ($stmt->execute()) flash("Teacher added.");
        else flash("Failed to add teacher: " . e($stmt->error), 'error');
        $stmt->close();
        header("Location: index.php?page=manage_teacher");
        exit;
    }
    if (isset($_POST['edit_teacher'])) {
        $id = intval($_POST['teacher_id']);
        $name = $_POST['teacher_name'];
        $email = $_POST['teacher_email'];
        $department = $_POST['teacher_department'];
        $hours = intval($_POST['teacher_hours']);

        $stmt = $conn->prepare("UPDATE teachers SET name=?, email=?, department=?, hours_per_week=? WHERE id=?");
        $stmt->bind_param("sssii", $name, $email, $department, $hours, $id);
        if ($stmt->execute()) flash("Teacher updated.");
        else flash("Failed to update teacher: " . e($stmt->error), 'error');
        $stmt->close();
        header("Location: index.php?page=manage_teacher");
        exit;
    }
    if (isset($_POST['delete_teacher'])) {
        $id = intval($_POST['teacher_id']);
        $stmt = $conn->prepare("DELETE FROM teachers WHERE id=?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) flash("Teacher deleted.");
        else flash("Failed to delete teacher.", 'error');
        $stmt->close();
        header("Location: index.php?page=manage_teacher");
        exit;
    }

    // Subjects
    if (isset($_POST['add_subject'])) {
        $name = $_POST['subject_name'];
        $type = $_POST['subject_type'];
        $hours = intval($_POST['subject_hours']);

        $stmt = $conn->prepare("INSERT INTO subjects (subject_name, type, hours_per_week) VALUES (?, ?, ?)");
        $stmt->bind_param("ssi", $name, $type, $hours);
        if ($stmt->execute()) flash("Subject added.");
        else flash("Failed to add subject: " . e($stmt->error), 'error');
        $stmt->close();
        header("Location: index.php?page=manage_subject");
        exit;
    }
    if (isset($_POST['edit_subject'])) {
        $id = intval($_POST['subject_id']);
        $name = $_POST['subject_name'];
        $type = $_POST['subject_type'];
        $hours = intval($_POST['subject_hours']);

        $stmt = $conn->prepare("UPDATE subjects SET subject_name=?, type=?, hours_per_week=? WHERE id=?");
        $stmt->bind_param("ssii", $name, $type, $hours, $id);
        if ($stmt->execute()) flash("Subject updated.");
        else flash("Failed to update subject.", 'error');
        $stmt->close();
        header("Location: index.php?page=manage_subject");
        exit;
    }
    if (isset($_POST['delete_subject'])) {
        $id = intval($_POST['subject_id']);
        $stmt = $conn->prepare("DELETE FROM subjects WHERE id=?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) flash("Subject deleted.");
        else flash("Failed to delete subject.", 'error');
        $stmt->close();
        header("Location: index.php?page=manage_subject");
        exit;
    }

    // Classrooms
    if (isset($_POST['add_classroom'])) {
        $name = $_POST['classroom_name'];
        $stmt = $conn->prepare("INSERT INTO classrooms (classroom_name) VALUES (?)");
        $stmt->bind_param("s", $name);
        if ($stmt->execute()) flash("Classroom added.");
        else flash("Failed to add classroom.", 'error');
        $stmt->close();
        header("Location: index.php?page=manage_classroom");
        exit;
    }
    if (isset($_POST['edit_classroom'])) {
        $id = intval($_POST['classroom_id']);
        $name = $_POST['classroom_name'];
        $stmt = $conn->prepare("UPDATE classrooms SET classroom_name=? WHERE id=?");
        $stmt->bind_param("si", $name, $id);
        if ($stmt->execute()) flash("Classroom updated.");
        else flash("Failed to update classroom.", 'error');
        $stmt->close();
        header("Location: index.php?page=manage_classroom");
        exit;
    }
    if (isset($_POST['delete_classroom'])) {
        $id = intval($_POST['classroom_id']);
        $stmt = $conn->prepare("DELETE FROM classrooms WHERE id=?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) flash("Classroom deleted.");
        else flash("Failed to delete classroom.", 'error');
        $stmt->close();
        header("Location: index.php?page=manage_classroom");
        exit;
    }

    // Subject Allocation
    if (isset($_POST['add_allocation'])) {
        $teacher_id = intval($_POST['allocation_teacher']);
        $subject_id = intval($_POST['allocation_subject']);
        $classroom_id = intval($_POST['allocation_classroom']);

        $stmt = $conn->prepare("INSERT IGNORE INTO subject_allocation (teacher_id, subject_id, classroom_id) VALUES (?, ?, ?)");
        $stmt->bind_param("iii", $teacher_id, $subject_id, $classroom_id);
        if ($stmt->execute()) flash("Allocation saved.");
        else flash("Failed to save allocation.", 'error');
        $stmt->close();
        header("Location: index.php?page=subject_allocation");
        exit;
    }
    if (isset($_POST['delete_allocation'])) {
        $id = intval($_POST['allocation_id']);
        $stmt = $conn->prepare("DELETE FROM subject_allocation WHERE id=?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) flash("Allocation removed.");
        else flash("Failed to remove allocation.", 'error');
        $stmt->close();
        header("Location: index.php?page=subject_allocation");
        exit;
    }

    // Attendance
    if (isset($_POST['mark_attendance'])) {
        $teacher_id = intval($_POST['attendance_teacher']);
        $status = $_POST['attendance_status'];
        $date = date('Y-m-d');

        $stmt = $conn->prepare("INSERT INTO attendance (teacher_id, `date`, status) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status)");
        $stmt->bind_param("iss", $teacher_id, $date, $status);
        if ($stmt->execute()) flash("Attendance recorded.");
        else flash("Failed to mark attendance.", 'error');
        $stmt->close();
        header("Location: index.php?page=staff_attendance");
        exit;
    }

    // Generate Timetable
    if (isset($_POST['generate_timetable'])) {
        // call function below
        generateTimetable($conn);
        flash("Timetable generated.");
        header("Location: index.php?page=class_timetable");
        exit;
    }
}

// ---------------------- Timetable Generation Logic ----------------------
function generateTimetable($conn) {
    // Clear existing timetable
    $conn->query("TRUNCATE TABLE timetable");

    // Fetch timeslots ordered by day & period
    $timeslots = $conn->query("SELECT * FROM timeslots ORDER BY FIELD(day,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), period_no")->fetch_all(MYSQLI_ASSOC);

    // Fetch classrooms
    $classrooms = $conn->query("SELECT * FROM classrooms")->fetch_all(MYSQLI_ASSOC);
    if (count($classrooms) == 0) return;

    // Fetch allocations joined with subject hours
    $allocations = $conn->query("
        SELECT sa.*, t.hours_per_week as teacher_hours, s.hours_per_week as subject_hours
        FROM subject_allocation sa
        JOIN teachers t ON sa.teacher_id = t.id
        JOIN subjects s ON sa.subject_id = s.id
        ORDER BY sa.classroom_id, sa.id
    ")->fetch_all(MYSQLI_ASSOC);

    // Organize allocations by classroom
    $alloc_by_class = [];
    foreach ($allocations as $a) {
        $alloc_by_class[$a['classroom_id']][] = $a;
    }

    // For each classroom, fill timeslots sequentially, respecting constraints
    foreach ($classrooms as $class) {
        $cid = $class['id'];
        $slots_filled = []; // timeslot ids used for this classroom

        if (!isset($alloc_by_class[$cid])) continue;

        // For each allocation in this classroom
        foreach ($alloc_by_class[$cid] as $alloc) {
            // number of required hours for this subject in the classroom (subject_hours)
            $hours_needed = intval($alloc['subject_hours']);
            $teacher_id = intval($alloc['teacher_id']);
            $subject_id = intval($alloc['subject_id']);

            // iterate timeslots and assign where possible
            foreach ($timeslots as $ts) {
                if ($hours_needed <= 0) break;

                $tsid = intval($ts['id']);

                // if classroom already scheduled at ts -> skip
                $stmt = $conn->prepare("SELECT COUNT(*) as c FROM timetable WHERE classroom_id=? AND timeslot_id=?");
                $stmt->bind_param("ii", $cid, $tsid);
                $stmt->execute();
                $conf = $stmt->get_result()->fetch_assoc()['c'];
                $stmt->close();
                if ($conf > 0) continue;

                // if teacher already scheduled at ts -> skip
                $stmt = $conn->prepare("SELECT COUNT(*) as c FROM timetable WHERE teacher_id=? AND timeslot_id=?");
                $stmt->bind_param("ii", $teacher_id, $tsid);
                $stmt->execute();
                $conf2 = $stmt->get_result()->fetch_assoc()['c'];
                $stmt->close();
                if ($conf2 > 0) continue;

                // assign
                $stmt = $conn->prepare("INSERT INTO timetable (classroom_id, subject_id, teacher_id, timeslot_id) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("iiii", $cid, $subject_id, $teacher_id, $tsid);
                $stmt->execute();
                $stmt->close();

                $hours_needed--;
            }
        }
    }
}

// ---------------------- Utility functions to render timetable matrix ----------------------
function getTimeslotMatrix($conn) {
    // Returns days list and period numbers with timeslot ids
    $days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    $periods = $conn->query("SELECT DISTINCT period_no, start_time, end_time FROM timeslots ORDER BY period_no")->fetch_all(MYSQLI_ASSOC);
    // build matrix [day][period_no] => timeslot row (id, times)
    $matrix = [];
    $stmt = $conn->prepare("SELECT * FROM timeslots WHERE day = ? ORDER BY period_no");
    foreach ($days as $day) {
        $stmt->bind_param("s", $day);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $matrix[$day] = [];
        foreach ($res as $r) {
            $matrix[$day][intval($r['period_no'])] = $r;
        }
    }
    $stmt->close();
    return ['days'=>$days, 'periods'=>$periods, 'matrix'=>$matrix];
}

// ---------------------- Page Routing ----------------------
$page = isset($_GET['page']) ? $_GET['page'] : 'home';

// ---------------------- HTML + UI ----------------------
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Automated Timetable Scheduler</title>
<style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:Segoe UI,Arial;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);min-height:100vh;color:#fff}
    .container{max-width:1200px;margin:20px; auto;padding:20px}
    .header{background:rgba(255,255,255,0.08);padding:20px;border-radius:12px;text-align:center}
    .header h1{font-size:28px}
    .nav{margin-top:12px;display:flex;justify-content:center;gap:10px;flex-wrap:wrap}
    .nav a,.btn{background:rgba(255,255,255,0.15);padding:10px 16px;border-radius:24px;color:#fff;text-decoration:none}
    .nav a:hover{transform:translateY(-2px)}
    .dashboard{display:grid;grid-template-columns:260px 1fr;gap:20px;margin-top:20px}
    .sidebar{background:rgba(255,255,255,0.06);padding:16px;border-radius:12px}
    .sidebar h3{margin-bottom:8px}
    .sidebar a{display:block;color:#fff;padding:8px;border-radius:8px;text-decoration:none;margin:4px 0}
    .content{background:rgba(255,255,255,0.06);padding:20px;border-radius:12px}
    .table{width:100%;background:#fff;border-radius:8px;color:#000;overflow:hidden}
    .table th,.table td{padding:10px;border-bottom:1px solid #eee;text-align:left}
    .form-group{margin-bottom:12px}
    .form-group input,.form-group select{width:100%;padding:10px;border-radius:6px;border:none}
    .btn-primary{background:linear-gradient(45deg,#667eea,#764ba2);padding:10px 16px;border-radius:24px;border:none;color:#fff;cursor:pointer}
    .btn-danger{background:linear-gradient(45deg,#ff6b6b,#ee5a52);padding:8px;border-radius:10px;border:none;color:#fff}
    .alert{padding:10px;border-radius:6px;margin-bottom:10px}
    .alert-success{background:#4CAF50;color:#fff}
    .alert-error{background:#f44336;color:#fff}
    .timetable-grid{display:grid;grid-template-columns:150px repeat(5,1fr);gap:1px;background:#ddd;border-radius:8px;overflow:hidden;margin-top:12px}
    .timetable-cell{background:#fff;padding:10px;min-height:60px;color:#000;display:flex;flex-direction:column;justify-content:center}
    .timetable-header{background:linear-gradient(45deg,#667eea,#764ba2);color:#fff;font-weight:700}
    .small{font-size:13px;color:rgba(0,0,0,0.7)}
    @media(max-width:900px){.dashboard{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Automated Timetable Scheduler</h1>
        <div class="nav">
            <a href="?page=home">Home</a>
            <a href="?page=about"> About</a>
            <?php if (isset($_SESSION['user_id'])): ?>
                <a href="?page=dashboard"> Dashboard</a>
                <a href="?logout=1">Logout</a>
            <?php else: ?>
                <a href="#" onclick="document.getElementById('loginModal').style.display='block'"> Login</a>
            <?php endif; ?>
        </div>
    </div>

    <?php
    // Flash messages
    foreach ($messages as $m) {
        $class = $m['type'] === 'error' ? 'alert-error' : 'alert-success';
        echo "<div class='alert $class'>" . e($m['text']) . "</div>";
    }
    if ($register_success) echo "<div class='alert alert-success'>{$register_success}</div>";
    if ($register_error) echo "<div class='alert alert-error'>{$register_error}</div>";
    if ($login_error) echo "<div class='alert alert-error'>{$login_error}</div>";
    if ($reset_success) echo "<div class='alert alert-success'>{$reset_success}</div>";
    if ($reset_error) echo "<div class='alert alert-error'>{$reset_error}</div>";
    ?>

    <?php if ($page === 'home' && !isset($_SESSION['user_id'])): ?>
        <div class="content">
            <h2>Welcome</h2>
            <p style="color: #fff; margin-top:10px; line-height:1.6;">
                Simplify timetable management for your institution. Upload your background image via CSS (see the header CSS in this file).
            </p>
            <div style="margin-top:12px;">
                <button class="btn-primary" onclick="document.getElementById('loginModal').style.display='block'">Get Started</button>
            </div>
        </div>

    <?php elseif ($page === 'about'): ?>
        <div class="content">
            <h2>About This Project</h2>
            <div style="color:#fff;line-height:1.6;margin-top:10px">
                <p><strong> Automated Timetable Scheduler</strong></p>
                <p>This web application simplifies timetable creation for educational institutions.</p>
                <ul style="margin-top:8px">
                    <li> Assign subjects, teachers, classrooms</li>
                    <li> Auto-generate conflict-free timetables</li>
                    <li> Track staff attendance</li>
                    <li> Built with PHP, MySQL, and a clean dashboard UI</li>
                </ul>
            </div>
        </div>

    <?php elseif (isset($_SESSION['user_id'])): ?>
        <div class="dashboard">
            <div class="sidebar">
                <h3>Navigation</h3>
                <a href="?page=dashboard">Dashboard</a>

                <strong>Teacher</strong>
                <a href="?page=add_teacher">Add Teacher</a>
                <a href="?page=manage_teacher">Manage Teachers</a>

                <strong>Subject</strong>
                <a href="?page=add_subject">Add Subject</a>
                <a href="?page=manage_subject">Manage Subjects</a>

                <strong>Classroom</strong>
                <a href="?page=add_classroom">Add Classroom</a>
                <a href="?page=manage_classroom">Manage Classroom</a>

                <strong>Allocation</strong>
                <a href="?page=subject_allocation">Subject Allocation</a>

                <strong>Timetable</strong>
                <a href="?page=timeslot">Timeslot</a>
                <a href="?page=class_timetable">Class Timetable</a>

                <strong>Staff</strong>
                <a href="?page=staff_attendance">Staff Attendance</a>
                <a href="?page=staff_timetable">Staff Timetable</a>
            </div>

            <div class="content">
                <?php
                switch ($page) {
                    case 'dashboard':
                        echo "<h2>Dashboard</h2>";
                        $teacher_count = $conn->query("SELECT COUNT(*) as c FROM teachers")->fetch_assoc()['c'];
                        $subject_count = $conn->query("SELECT COUNT(*) as c FROM subjects")->fetch_assoc()['c'];
                        $classroom_count = $conn->query("SELECT COUNT(*) as c FROM classrooms")->fetch_assoc()['c'];
                        echo "<div style='display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-top:12px'>";
                        echo "<div style='background:rgba(255,255,255,0.06);padding:12px;border-radius:8px;text-align:center'><h3>{$teacher_count}</h3><div class='small'>Teachers</div></div>";
                        echo "<div style='background:rgba(255,255,255,0.06);padding:12px;border-radius:8px;text-align:center'><h3>{$subject_count}</h3><div class='small'>Subjects</div></div>";
                        echo "<div style='background:rgba(255,255,255,0.06);padding:12px;border-radius:8px;text-align:center'><h3>{$classroom_count}</h3><div class='small'>Classrooms</div></div>";
                        echo "</div>";
                        break;

                    // -------------------- TEACHER --------------------
                    case 'add_teacher':
                        ?>
                        <h2>Add Teacher</h2>
                        <form method="POST">
                            <div class="form-group"><label>Name</label><input name="teacher_name" required></div>
                            <div class="form-group"><label>Email</label><input type="email" name="teacher_email" required></div>
                            <div class="form-group"><label>Department</label><input name="teacher_department" required></div>
                            <div class="form-group"><label>Hours per Week</label><input type="number" name="teacher_hours" min="1" max="40" required></div>
                            <button class="btn-primary" name="add_teacher">Add Teacher</button>
                        </form>
                        <?php
                        break;

                    case 'manage_teacher':
                        echo "<h2>Manage Teachers</h2>";
                        $res = $conn->query("SELECT * FROM teachers ORDER BY name");
                        echo "<table class='table'><tr><th>Name</th><th>Email</th><th>Department</th><th>Hours</th><th>Actions</th></tr>";
                        while ($r = $res->fetch_assoc()) {
                            $id = $r['id'];
                            echo "<tr>";
                            echo "<td>" . e($r['name']) . "</td>";
                            echo "<td>" . e($r['email']) . "</td>";
                            echo "<td>" . e($r['department']) . "</td>";
                            echo "<td>" . e($r['hours_per_week']) . "</td>";
                            echo "<td>
                                <form method='GET' style='display:inline'><input type='hidden' name='page' value='edit_teacher'><input type='hidden' name='id' value='{$id}'><button class='btn-danger' type='submit'>Edit</button></form>
                                <form method='POST' style='display:inline' onsubmit=\"return confirm('Are you sure?')\">
                                    <input type='hidden' name='teacher_id' value='{$id}'>
                                    <button class='btn-danger' name='delete_teacher' type='submit'>Delete</button>
                                </form>
                            </td>";
                            echo "</tr>";
                        }
                        echo "</table>";
                        break;

                    case 'edit_teacher':
                        $id = intval($_GET['id']);
                        $stmt = $conn->prepare("SELECT * FROM teachers WHERE id=?");
                        $stmt->bind_param("i",$id);
                        $stmt->execute();
                        $t = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                        if (!$t) { echo "<div class='alert alert-error'>Teacher not found.</div>"; break; }
                        ?>
                        <h2>Edit Teacher</h2>
                        <form method="POST">
                            <input type="hidden" name="teacher_id" value="<?php echo $t['id'] ?>">
                            <div class="form-group"><label>Name</label><input name="teacher_name" value="<?php echo e($t['name']) ?>" required></div>
                            <div class="form-group"><label>Email</label><input type="email" name="teacher_email" value="<?php echo e($t['email']) ?>" required></div>
                            <div class="form-group"><label>Department</label><input name="teacher_department" value="<?php echo e($t['department']) ?>" required></div>
                            <div class="form-group"><label>Hours per Week</label><input type="number" name="teacher_hours" min="1" max="40" value="<?php echo e($t['hours_per_week']) ?>" required></div>
                            <button class="btn-primary" name="edit_teacher">Save</button>
                        </form>
                        <?php
                        break;

                    // -------------------- SUBJECT --------------------
                    case 'add_subject':
                        ?>
                        <h2>Add Subject</h2>
                        <form method="POST">
                            <div class="form-group"><label>Subject Name</label><input name="subject_name" required></div>
                            <div class="form-group"><label>Type</label><select name="subject_type"><option>Theory</option><option>Lab</option></select></div>
                            <div class="form-group"><label>Hours per Week</label><input type="number" name="subject_hours" min="1" max="20" required></div>
                            <button class="btn-primary" name="add_subject">Add Subject</button>
                        </form>
                        <?php
                        break;

                    case 'manage_subject':
                        echo "<h2>Manage Subjects</h2>";
                        $res = $conn->query("SELECT * FROM subjects ORDER BY subject_name");
                        echo "<table class='table'><tr><th>Name</th><th>Type</th><th>Hours</th><th>Actions</th></tr>";
                        while ($r = $res->fetch_assoc()) {
                            $id = $r['id'];
                            echo "<tr>";
                            echo "<td>" . e($r['subject_name']) . "</td>";
                            echo "<td>" . e($r['type']) . "</td>";
                            echo "<td>" . e($r['hours_per_week']) . "</td>";
                            echo "<td>
                                <form method='GET' style='display:inline'><input type='hidden' name='page' value='edit_subject'><input type='hidden' name='id' value='{$id}'><button class='btn-danger' type='submit'>Edit</button></form>
                                <form method='POST' style='display:inline' onsubmit=\"return confirm('Are you sure?')\">
                                    <input type='hidden' name='subject_id' value='{$id}'>
                                    <button class='btn-danger' name='delete_subject' type='submit'>Delete</button>
                                </form>
                            </td>";
                            echo "</tr>";
                        }
                        echo "</table>";
                        break;

                    case 'edit_subject':
                        $id = intval($_GET['id']);
                        $stmt = $conn->prepare("SELECT * FROM subjects WHERE id=?");
                        $stmt->bind_param("i",$id);
                        $stmt->execute();
                        $s = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                        if (!$s) { echo "<div class='alert alert-error'>Subject not found.</div>"; break; }
                        ?>
                        <h2>Edit Subject</h2>
                        <form method="POST">
                            <input type="hidden" name="subject_id" value="<?php echo $s['id'] ?>">
                            <div class="form-group"><label>Subject Name</label><input name="subject_name" value="<?php echo e($s['subject_name']) ?>" required></div>
                            <div class="form-group"><label>Type</label>
                                <select name="subject_type">
                                    <option value="Theory" <?php if($s['type']=='Theory') echo 'selected';?>>Theory</option>
                                    <option value="Lab" <?php if($s['type']=='Lab') echo 'selected';?>>Lab</option>
                                </select>
                            </div>
                            <div class="form-group"><label>Hours per Week</label><input type="number" name="subject_hours" min="1" max="20" value="<?php echo e($s['hours_per_week']) ?>" required></div>
                            <button class="btn-primary" name="edit_subject">Save</button>
                        </form>
                        <?php
                        break;

                    // -------------------- CLASSROOM --------------------
                    case 'add_classroom':
                        ?>
                        <h2>Add Classroom</h2>
                        <form method="POST">
                            <div class="form-group"><label>Classroom Name</label><input name="classroom_name" required></div>
                            <button class="btn-primary" name="add_classroom">Add Classroom</button>
                        </form>
                        <?php
                        break;

                    case 'manage_classroom':
                        echo "<h2>Manage Classrooms</h2>";
                        $res = $conn->query("SELECT * FROM classrooms ORDER BY classroom_name");
                        echo "<table class='table'><tr><th>Name</th><th>Actions</th></tr>";
                        while ($r = $res->fetch_assoc()) {
                            $id = $r['id'];
                            echo "<tr>";
                            echo "<td>" . e($r['classroom_name']) . "</td>";
                            echo "<td>
                                <form method='GET' style='display:inline'><input type='hidden' name='page' value='edit_classroom'><input type='hidden' name='id' value='{$id}'><button class='btn-danger' type='submit'>Edit</button></form>
                                <form method='POST' style='display:inline' onsubmit=\"return confirm('Are you sure?')\">
                                    <input type='hidden' name='classroom_id' value='{$id}'>
                                    <button class='btn-danger' name='delete_classroom' type='submit'>Delete</button>
                                </form>
                            </td>";
                            echo "</tr>";
                        }
                        echo "</table>";
                        break;

                    case 'edit_classroom':
                        $id = intval($_GET['id']);
                        $stmt = $conn->prepare("SELECT * FROM classrooms WHERE id=?");
                        $stmt->bind_param("i",$id);
                        $stmt->execute();
                        $c = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                        if (!$c) { echo "<div class='alert alert-error'>Classroom not found.</div>"; break; }
                        ?>
                        <h2>Edit Classroom</h2>
                        <form method="POST">
                            <input type="hidden" name="classroom_id" value="<?php echo $c['id'] ?>">
                            <div class="form-group"><label>Classroom Name</label><input name="classroom_name" value="<?php echo e($c['classroom_name']) ?>" required></div>
                            <button class="btn-primary" name="edit_classroom">Save</button>
                        </form>
                        <?php
                        break;

                    // -------------------- SUBJECT ALLOCATION --------------------
                    case 'subject_allocation':
                        echo "<h2>Subject Allocation</h2>";
                        // allocation form
                        $teachers = $conn->query("SELECT * FROM teachers ORDER BY name")->fetch_all(MYSQLI_ASSOC);
                        $subjects = $conn->query("SELECT * FROM subjects ORDER BY subject_name")->fetch_all(MYSQLI_ASSOC);
                        $classrooms = $conn->query("SELECT * FROM classrooms ORDER BY classroom_name")->fetch_all(MYSQLI_ASSOC);
                        ?>
                        <form method="POST">
                            <div class="form-group"><label>Teacher</label>
                                <select name="allocation_teacher" required>
                                    <?php foreach ($teachers as $t) echo "<option value='{$t['id']}'>".e($t['name'])."</option>"; ?>
                                </select>
                            </div>
                            <div class="form-group"><label>Subject</label>
                                <select name="allocation_subject" required>
                                    <?php foreach ($subjects as $s) echo "<option value='{$s['id']}'>".e($s['subject_name'])."</option>"; ?>
                                </select>
                            </div>
                            <div class="form-group"><label>Classroom</label>
                                <select name="allocation_classroom" required>
                                    <?php foreach ($classrooms as $c) echo "<option value='{$c['id']}'>".e($c['classroom_name'])."</option>"; ?>
                                </select>
                            </div>
                            <button class="btn-primary" name="add_allocation">Save Allocation</button>
                        </form>

                        <?php
                        // list allocations
                        $res = $conn->query("SELECT sa.*, t.name as teacher_name, s.subject_name, c.classroom_name FROM subject_allocation sa JOIN teachers t ON sa.teacher_id=t.id JOIN subjects s ON sa.subject_id=s.id JOIN classrooms c ON sa.classroom_id=c.id ORDER BY c.classroom_name");
                        echo "<h3 style='margin-top:12px'>Existing Allocations</h3>";
                        echo "<table class='table'><tr><th>Teacher</th><th>Subject</th><th>Classroom</th><th>Actions</th></tr>";
                        while ($r = $res->fetch_assoc()) {
                            echo "<tr>";
                            echo "<td>".e($r['teacher_name'])."</td>";
                            echo "<td>".e($r['subject_name'])."</td>";
                            echo "<td>".e($r['classroom_name'])."</td>";
                            echo "<td>
                                <form method='POST' style='display:inline' onsubmit=\"return confirm('Remove allocation?')\">
                                    <input type='hidden' name='allocation_id' value='".intval($r['id'])."'>
                                    <button class='btn-danger' name='delete_allocation'>Delete</button>
                                </form>
                            </td>";
                            echo "</tr>";
                        }
                        echo "</table>";
                        break;

                    // -------------------- TIMESLOT --------------------
                    case 'timeslot':
                        echo "<h2>Timeslots</h2>";
                        $ts = getTimeslotMatrix($conn);
                        echo "<div style='margin-bottom:10px'>Default periods: 1: 09:30-10:25, 2:10:40-11:35, 3:11:35-12:30, 4:13:25-14:20, 5:14:20-15:15</div>";
                        $res = $conn->query("SELECT * FROM timeslots ORDER BY FIELD(day,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), period_no");
                        echo "<table class='table'><tr><th>Day</th><th>Period</th><th>Start</th><th>End</th></tr>";
                        while ($r = $res->fetch_assoc()) {
                            echo "<tr><td>".e($r['day'])."</td><td>".e($r['period_no'])."</td><td>".e($r['start_time'])."</td><td>".e($r['end_time'])."</td></tr>";
                        }
                        echo "</table>";
                        break;

                    // -------------------- CLASS TIMETABLE --------------------
                    case 'class_timetable':
                        echo "<h2>Class Timetable</h2>";
                        echo "<form method='POST' style='margin-bottom:12px'><button class='btn-primary' name='generate_timetable'>Generate Timetable</button></form>";

                        // show timetable per classroom
                        $classrooms = $conn->query("SELECT * FROM classrooms ORDER BY classroom_name")->fetch_all(MYSQLI_ASSOC);
                        $tsinfo = getTimeslotMatrix($conn);
                        foreach ($classrooms as $class) {
                            echo "<h3 style='margin-top:14px'>Class: " . e($class['classroom_name']) . "</h3>";
                            // grid headers
                            echo "<div class='timetable-grid'>";
                            echo "<div class='timetable-cell timetable-header'>Period / Day</div>";
                            // show 5 periods columns
                            $periods = array_column($tsinfo['periods'], 'period_no');
                            $periodRows = $tsinfo['periods'];
                            foreach ($periodRows as $p) {
                                echo "<div class='timetable-cell timetable-header'>Period " . e($p['period_no']) . "<div class='small'>" . e(substr($p['start_time'],0,5)) . " - " . e(substr($p['end_time'],0,5)) . "</div></div>";
                            }

                            // rows per day
                            foreach ($tsinfo['days'] as $day) {
                                echo "<div class='timetable-cell timetable-header'>" . e($day) . "</div>";
                                for ($pn=1;$pn<=5;$pn++) {
                                    // find timeslot id for this day & period
                                    if (isset($tsinfo['matrix'][$day][$pn])) $tid = intval($tsinfo['matrix'][$day][$pn]['id']);
                                    else { echo "<div class='timetable-cell'>-</div>"; continue; }

                                    // fetch timetable entry
                                    $stmt = $conn->prepare("SELECT tt.*, s.subject_name, t.name as teacher_name FROM timetable tt JOIN subjects s ON tt.subject_id=s.id JOIN teachers t ON tt.teacher_id=t.id WHERE tt.classroom_id=? AND tt.timeslot_id=? LIMIT 1");
                                    $stmt->bind_param("ii", $class['id'], $tid);
                                    $stmt->execute();
                                    $row = $stmt->get_result()->fetch_assoc();
                                    $stmt->close();
                                    if ($row) {
                                        echo "<div class='timetable-cell'><strong>" . e($row['subject_name']) . "</strong><div class='small'>".e($row['teacher_name'])."</div></div>";
                                    } else {
                                        echo "<div class='timetable-cell'>-</div>";
                                    }
                                }
                            }
                            echo "</div>"; // grid
                        }
                        break;

                    // -------------------- STAFF ATTENDANCE --------------------
                    case 'staff_attendance':
                        echo "<h2>Staff Attendance</h2>";
                        $teachers = $conn->query("SELECT * FROM teachers ORDER BY name")->fetch_all(MYSQLI_ASSOC);
                        $today = date('Y-m-d');
                        ?>
                        <form method="POST">
                            <div class="form-group"><label>Select Teacher</label>
                                <select name="attendance_teacher" required>
                                    <?php foreach ($teachers as $t) echo "<option value='{$t['id']}'>".e($t['name'])."</option>"; ?>
                                </select>
                            </div>
                            <div class="form-group"><label>Status</label><div style="display:flex;gap:12px">
                                <label><input type="radio" name="attendance_status" value="Present" checked> Present</label>
                                <label><input type="radio" name="attendance_status" value="Absent"> Absent</label>
                            </div></div>
                            <button class="btn-primary" name="mark_attendance">Save</button>
                        </form>

                        <h3 style="margin-top:12px">Today's Attendance (<?php echo $today ?>)</h3>
                        <?php
                        $att = $conn->query("SELECT a.*, t.name FROM attendance a JOIN teachers t ON a.teacher_id=t.id WHERE a.`date`='". $conn->real_escape_string($today) ."'");
                        echo "<table class='table'><tr><th>Teacher</th><th>Status</th></tr>";
                        while ($r = $att->fetch_assoc()) {
                            echo "<tr><td>".e($r['name'])."</td><td>".e($r['status'])."</td></tr>";
                        }
                        echo "</table>";
                        break;

                    // -------------------- STAFF TIMETABLE --------------------
                    case 'staff_timetable':
                        echo "<h2>Staff Timetable</h2>";
                        // choose teacher
                        $teachers = $conn->query("SELECT * FROM teachers ORDER BY name")->fetch_all(MYSQLI_ASSOC);
                        $sel_teacher = isset($_GET['teacher_id']) ? intval($_GET['teacher_id']) : ($teachers[0]['id'] ?? 0);
                        ?>
                        <form method="GET">
                            <input type="hidden" name="page" value="staff_timetable">
                            <div style="display:flex;gap:8px;align-items:center">
                                <label>Select Teacher</label>
                                <select name="teacher_id">
                                    <?php foreach ($teachers as $t) echo "<option value='{$t['id']}' ".($t['id']==$sel_teacher?'selected':'').">".e($t['name'])."</option>"; ?>
                                </select>
                                <button class="btn-primary" type="submit">Show</button>
                            </div>
                        </form>
                        <?php
                        if ($sel_teacher) {
                            $tsinfo = getTimeslotMatrix($conn);
                            echo "<h3 style='margin-top:12px'>Timetable for " . e(($conn->query("SELECT name FROM teachers WHERE id=".intval($sel_teacher))->fetch_assoc()['name'])) . "</h3>";
                            echo "<div class='timetable-grid'>";
                            echo "<div class='timetable-cell timetable-header'>Period / Day</div>";
                            foreach ($tsinfo['periods'] as $p) echo "<div class='timetable-cell timetable-header'>Period " . e($p['period_no']) . "<div class='small'>" . e(substr($p['start_time'],0,5)) . " - " . e(substr($p['end_time'],0,5)) . "</div></div>";

                            foreach ($tsinfo['days'] as $day) {
                                echo "<div class='timetable-cell timetable-header'>".e($day)."</div>";
                                for ($pn=1;$pn<=5;$pn++) {
                                    if (isset($tsinfo['matrix'][$day][$pn])) $tid = intval($tsinfo['matrix'][$day][$pn]['id']); else { echo "<div class='timetable-cell'>-</div>"; continue; }
                                    $stmt = $conn->prepare("SELECT tt.*, s.subject_name, c.classroom_name FROM timetable tt JOIN subjects s ON tt.subject_id=s.id JOIN classrooms c ON tt.classroom_id=c.id WHERE tt.teacher_id=? AND tt.timeslot_id=? LIMIT 1");
                                    $stmt->bind_param("ii", $sel_teacher, $tid);
                                    $stmt->execute();
                                    $row = $stmt->get_result()->fetch_assoc();
                                    $stmt->close();
                                    if ($row) echo "<div class='timetable-cell'><strong>".e($row['subject_name'])."</strong><div class='small'>".e($row['classroom_name'])."</div></div>";
                                    else echo "<div class='timetable-cell'>-</div>";
                                }
                            }
                            echo "</div>";
                        }
                        break;

                    default:
                        echo "<h2>Welcome, " . e($_SESSION['username']) . "</h2>";
                        echo "<p style='color:#fff; margin-top:8px'>Use the left menu to manage teachers, subjects, classrooms, allocations and generate timetables.</p>";
                        break;
                }
                ?>
            </div>
        </div>
    <?php else: ?>
        <!-- Not logged in and not home: show login CTA -->
        <div class="content">
            <h2>Please login to continue</h2>
            <p style="margin-top:10px">Use the Login button in the header.</p>
        </div>
    <?php endif; ?>

</div>

<!-- LOGIN Modal -->
<div id="loginModal" style="display:none;position:fixed;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:999;">
    <div style="background:#fff;color:#000;padding:20px;border-radius:10px;width:90%;max-width:420px;margin:5% auto;position:relative">
        <span style="position:absolute;right:12px;top:8px;cursor:pointer;font-size:18px" onclick="document.getElementById('loginModal').style.display='none'">&times;</span>
        <h3>Login</h3>
        <form method="POST">
            <div class="form-group"><label>Username</label><input name="username" required></div>
            <div class="form-group"><label>Password</label><input type="password" name="password" required></div>
            <div style="display:flex;gap:8px">
                <button class="btn-primary" name="login">Login</button>
                <button class="btn-primary" type="button" onclick="document.getElementById('registerModal').style.display='block'">Register</button>
                <button class="btn-primary" type="button" onclick="document.getElementById('resetModal').style.display='block'">Reset Password</button>
            </div>
        </form>
    </div>
</div>

<!-- REGISTER Modal -->
<div id="registerModal" style="display:none;position:fixed;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:999;">
    <div style="background:#fff;color:#000;padding:20px;border-radius:10px;width:90%;max-width:420px;margin:5% auto;position:relative">
        <span style="position:absolute;right:12px;top:8px;cursor:pointer;font-size:18px" onclick="document.getElementById('registerModal').style.display='none'">&times;</span>
        <h3>Register</h3>
        <form method="POST">
            <div class="form-group"><label>Username</label><input name="reg_username" required></div>
            <div class="form-group"><label>Email</label><input type="email" name="reg_email" required></div>
            <div class="form-group"><label>Password</label><input type="password" name="reg_password" required></div>
            <div style="display:flex;gap:8px"><button class="btn-primary" name="register">Register</button></div>
        </form>
    </div>
</div>

<!-- RESET Modal -->
<div id="resetModal" style="display:none;position:fixed;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:999;">
    <div style="background:#fff;color:#000;padding:20px;border-radius:10px;width:90%;max-width:420px;margin:5% auto;position:relative">
        <span style="position:absolute;right:12px;top:8px;cursor:pointer;font-size:18px" onclick="document.getElementById('resetModal').style.display='none'">&times;</span>
        <h3>Reset Password</h3>
        <form method="POST">
            <div class="form-group"><label>Email</label><input type="email" name="reset_email" required></div>
            <div class="form-group"><label>Username</label><input name="reset_username" required></div>
            <div class="form-group"><label>New Password</label><input type="password" name="new_password" required></div>
            <div class="form-group"><label>Confirm Password</label><input type="password" name="confirm_password" required></div>
            <div style="display:flex;gap:8px"><button class="btn-primary" name="reset_password">Reset</button></div>
        </form>
    </div>
</div>

<script>
    // close modals on outside click
    window.onclick = function(e){
        ['loginModal','registerModal','resetModal'].forEach(function(id){
            var el = document.getElementById(id);
            if (!el) return;
            if (e.target === el) el.style.display='none';
        });
    }
    // open login modal if URL has ?showlogin
    (function(){
        const params = new URLSearchParams(window.location.search);
        if (params.get('showlogin')) document.getElementById('loginModal').style.display='block';
    })();
</script>
</body>
</html>