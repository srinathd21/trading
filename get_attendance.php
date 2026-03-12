<?php
session_start();
require_once '../config/database.php';

$attendance_id = $_GET['id'] ?? 0;

$sql = "SELECT a.*, u.full_name, u.employee_id FROM attendance a 
        JOIN users u ON a.employee_id = u.id 
        WHERE a.id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$attendance_id]);
$attendance = $stmt->fetch();

if (!$attendance) {
    echo '<div class="alert alert-danger">Attendance record not found</div>';
    exit;
}

$check_in = $attendance['check_in'] ? date('H:i', strtotime($attendance['check_in'])) : '';
$check_out = $attendance['check_out'] ? date('H:i', strtotime($attendance['check_out'])) : '';
?>
<input type="hidden" name="attendance_id" value="<?= $attendance_id ?>">
<div class="mb-3">
    <label class="form-label">Employee</label>
    <input type="text" class="form-control" value="<?= htmlspecialchars($attendance['full_name']) ?> (<?= $attendance['employee_id'] ?>)" readonly>
</div>
<div class="mb-3">
    <label class="form-label">Date</label>
    <input type="date" name="attendance_date" class="form-control" value="<?= date('Y-m-d', strtotime($attendance['attendance_date'])) ?>" required>
</div>
<div class="row mb-3">
    <div class="col-md-6">
        <label class="form-label">Check-in Time</label>
        <input type="time" name="check_in" class="form-control" value="<?= $check_in ?>">
    </div>
    <div class="col-md-6">
        <label class="form-label">Check-out Time</label>
        <input type="time" name="check_out" class="form-control" value="<?= $check_out ?>">
    </div>
</div>
<div class="mb-3">
    <label class="form-label">Status</label>
    <select name="status" class="form-select" required>
        <option value="present" <?= $attendance['status'] == 'present' ? 'selected' : '' ?>>Present</option>
        <option value="late" <?= $attendance['status'] == 'late' ? 'selected' : '' ?>>Late</option>
        <option value="half_day" <?= $attendance['status'] == 'half_day' ? 'selected' : '' ?>>Half Day</option>
        <option value="absent" <?= $attendance['status'] == 'absent' ? 'selected' : '' ?>>Absent</option>
        <option value="leave" <?= $attendance['status'] == 'leave' ? 'selected' : '' ?>>Leave</option>
    </select>
</div>
<div class="mb-3">
    <label class="form-label">Notes</label>
    <textarea name="notes" class="form-control" rows="3"><?= htmlspecialchars($attendance['notes'] ?? '') ?></textarea>
</div>