<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

if (!in_array($_SESSION['role'], ['admin', 'warehouse_manager'])) {
    header('Location: dashboard.php');
    exit();
}

$success = $error = '';

// Add/Edit Location
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_location'])) {
    $id = (int)($_POST['id'] ?? 0);
    $location_name = trim($_POST['location_name']);
    $location_type = $_POST['location_type'] ?? 'warehouse';
    $address = trim($_POST['address'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if (empty($location_name)) {
        $error = "Location name is required.";
    } else {
        try {
            if ($id) {
                // Update
                $stmt = $pdo->prepare("UPDATE stock_locations SET location_name=?, location_type=?, address=?, phone=?, is_active=? WHERE id=?");
                $stmt->execute([$location_name, $location_type, $address, $phone, $is_active, $id]);
                $success = "Location updated successfully!";
            } else {
                // Insert
                $stmt = $pdo->prepare("INSERT INTO stock_locations (location_name, location_type, address, phone, is_active) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$location_name, $location_type, $address, $phone, $is_active]);
                $success = "Location added successfully!";
            }
        } catch (PDOException $e) {
            $error = "Name already exists or error occurred.";
        }
    }
}

// Delete (deactivate)
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    try {
        $pdo->prepare("UPDATE stock_locations SET is_active = 0 WHERE id = ?")->execute([$id]);
        $success = "Location deactivated!";
    } catch (Exception $e) {
        $error = "Cannot deactivate: location has stock or transfers.";
    }
}

// Fetch all locations
$locations = $pdo->query("
    SELECT l.*, 
           COALESCE(SUM(ps.quantity), 0) as total_stock,
           COUNT(DISTINCT ps.product_id) as product_count
    FROM stock_locations l
    LEFT JOIN product_stocks ps ON ps.location_id = l.id
    GROUP BY l.id
    ORDER BY l.is_active DESC, l.location_name
")->fetchAll();
?>

<!doctype html>
<html lang="en">
<?php $page_title = "Warehouses & Branches"; ?>
<?php include('includes/head.php'); ?>
<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include('includes/topbar.php'); ?>
    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include('includes/sidebar.php')?>
        </div>
    </div>

    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">

                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <h4 class="mb-0">
                                Warehouses & Branches
                                <span class="badge bg-primary fs-6 ms-2"><?= count($locations) ?></span>
                            </h4>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#locationModal">
                                Add New Location
                            </button>
                        </div>
                    </div>
                </div>

                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <?= $success ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <div class="row">
                    <?php foreach($locations as $loc): 
                        $type_badge = $loc['location_type'] === 'warehouse' ? 'primary' : 'success';
                        $type_text = ucfirst($loc['location_type']);
                    ?>
                    <div class "col-xl-4 col-lg-6">
                        <div class="card location-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div>
                                        <h5 class="card-title mb-1">
                                            <?= htmlspecialchars($loc['location_name']) ?>
                                            <span class="badge bg-<?= $type_badge ?> ms-2"><?= $type_text ?></span>
                                        </h5>
                                        <?php if ($loc['address']): ?>
                                        <p class="text-muted small mb-2">
                                            <?= nl2br(htmlspecialchars($loc['address'])) ?>
                                        </p>
                                        <?php endif; ?>
                                        <?php if ($loc['phone']): ?>
                                        <p class="mb-0">
                                            <i class="bx bx-phone"></i> 
                                            <a href="tel:<?= $loc['phone'] ?>"><?= $loc['phone'] ?></a>
                                        </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="dropdown">
                                        <button class="btn btn-link text-muted p-0" data-bs-toggle="dropdown">
                                            <i class="bx bx-dots-vertical-rounded fs-5"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li>
                                                <button class="dropdown-item" onclick="editLocation(<?= $loc['id'] ?>, '<?= htmlspecialchars($loc['location_name'], ENT_QUOTES) ?>', '<?= $loc['location_type'] ?>', '<?= htmlspecialchars($loc['address'] ?? '', ENT_QUOTES) ?>', '<?= $loc['phone'] ?>', <?= $loc['is_active'] ?>)">
                                                    Edit
                                                </button>
                                            </li>
                                            <?php if ($loc['is_active']): ?>
                                            <li>
                                                <a href="?delete=<?= $loc['id'] ?>" class="dropdown-item text-danger"
                                                   onclick="return confirm('Deactivate this location?')">
                                                    Deactivate
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </div>

                                <div class="row text-center g-3 mt-3">
                                    <div class="col-6">
                                        <div class="border rounded p-3 bg-light">
                                            <h4 class="mb-1 text-primary"><?= $loc['total_stock'] ?></h4>
                                            <small>Total Stock</small>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="border rounded p-3 bg-light">
                                            <h4 class="mb-1 text-info"><?= $loc['product_count'] ?></h4>
                                            <small>Products</small>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-3 d-flex justify-content-between align-items-center">
                                    <span class="badge bg-<?= $loc['is_active'] ? 'success' : 'secondary' ?>">
                                        <?= $loc['is_active'] ? 'ACTIVE' : 'INACTIVE' ?>
                                    </span>
                                    <div>
                                        <a href="location_stock.php?id=<?= $loc['id'] ?>" class="btn btn-outline-primary btn-sm">
                                            View Stock
                                        </a>
                                        <a href="stock_transfers.php?from=<?= $loc['id'] ?>" class="btn btn-success btn-sm ms-2">
                                            Transfer From Here
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if (empty($locations)): ?>
                <div class="text-center py-5">
                    <i class="bx bx-building-house display-1 text-muted d-block mb-3"></i>
                    <h4>No locations found</h4>
                    <button class="btn btn-primary mt-3" data-bs-toggle="modal" data-bs-target="#locationModal">
                        Add Your First Location
                    </button>
                </div>
                <?php endif; ?>

            </div>
        </div>
        <?php include('includes/footer.php'); ?>
    </div>
</div>

<!-- Add/Edit Location Modal -->
<div class="modal fade" id="locationModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST">
            <input type="hidden" name="id" id="locId">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add New Location</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label"><strong>Location Name <span class="text-danger">*</span></strong></label>
                            <input type="text" name="location_name" id="locName" class="form-control form-control-lg" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><strong>Type</strong></label>
                            <select name="location_type" id="locType" class="form-select">
                                <option value="warehouse">Warehouse</option>
                                <option value="shop">Shop / Branch</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Address</label>
                            <textarea name="address" id="locAddress" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" id="locPhone" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch mt-4">
                                <input class="form-check-input" type="checkbox" name="is_active" id="locActive" checked>
                                <label class="form-check-label" for="locActive">Active Location</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="save_location" class="btn btn-primary">
                        Save Location
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<script>
function editLocation(id, name, type, address, phone, active) {
    document.getElementById('modalTitle').innerHTML = 'Edit Location';
    document.getElementById('locId').value = id;
    document.getElementById('locName').value = name;
    document.getElementById('locType').value = type;
    document.getElementById('locAddress').value = address;
    document.getElementById('locPhone').value = phone;
    document.getElementById('locActive').checked = active;
    new bootstrap.Modal(document.getElementById('locationModal')).show();
}

// Reset modal
document.getElementById('locationModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('modalTitle').innerHTML = 'Add New Location';
    document.querySelector('form').reset();
    document.getElementById('locId').value = '';
    document.getElementById('locActive').checked = true;
});
</script>

<style>
.location-card {
    transition: all 0.3s ease;
    border: 1px solid #e9ecef;
}
.location-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 25px rgba(0,0,0,0.1);
}
</style>
</body>
</html>