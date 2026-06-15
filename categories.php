<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Role check
if (!in_array($_SESSION['role'], ['admin', 'warehouse_manager', 'shop_manager', 'stock_manager'])) {
    header('Location: dashboard.php');
    exit();
}

$current_business_id = (int)($_SESSION['current_business_id'] ?? 0);
$success = $error = '';

if ($current_business_id <= 0) {
    header('Location: select_shop.php');
    exit();
}

/* -----------------------------
   Category image upload helpers
----------------------------- */
if (!function_exists('category_image_upload')) {
    function category_image_upload(string $fieldName, int $business_id): array
    {
        if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
            return ['ok' => true, 'path' => null, 'message' => ''];
        }

        if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'path' => null, 'message' => 'Image upload failed. Please try again.'];
        }

        $upload_dir = 'uploads/categories/';
        if (!is_dir($upload_dir)) {
            @mkdir($upload_dir, 0755, true);
        }

        if (!is_dir($upload_dir) || !is_writable($upload_dir)) {
            return ['ok' => false, 'path' => null, 'message' => 'Category upload folder is not writable: uploads/categories/'];
        }

        $allowed_ext = ['jpg', 'jpeg', 'png', 'webp'];
        $allowed_mime = ['image/jpeg', 'image/png', 'image/webp'];

        $original = $_FILES[$fieldName]['name'] ?? '';
        $tmp = $_FILES[$fieldName]['tmp_name'] ?? '';
        $size = (int)($_FILES[$fieldName]['size'] ?? 0);
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));

        if ($size > (2 * 1024 * 1024)) {
            return ['ok' => false, 'path' => null, 'message' => 'Category image size must be below 2MB.'];
        }

        if (!in_array($ext, $allowed_ext, true)) {
            return ['ok' => false, 'path' => null, 'message' => 'Category image must be JPG, JPEG, PNG, or WEBP.'];
        }

        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = (string)finfo_file($finfo, $tmp);
                finfo_close($finfo);
            }
        }

        if ($mime !== '' && !in_array($mime, $allowed_mime, true)) {
            return ['ok' => false, 'path' => null, 'message' => 'Invalid image file type.'];
        }

        $file_name = 'cat_' . $business_id . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
        $target = $upload_dir . $file_name;

        if (!move_uploaded_file($tmp, $target)) {
            return ['ok' => false, 'path' => null, 'message' => 'Unable to save category image.'];
        }

        return ['ok' => true, 'path' => $target, 'message' => ''];
    }
}

if (!function_exists('delete_category_image_file')) {
    function delete_category_image_file(?string $path): void
    {
        $path = trim((string)$path);
        if ($path !== '' && strpos($path, 'uploads/categories/') === 0 && file_exists($path)) {
            @unlink($path);
        }
    }
}


/* -----------------------------
   Helper: delete category + child subcategories + linked products
   Only allow delete if linked products are NOT used in invoice_items
----------------------------- */
if (!function_exists('delete_categories_with_links')) {
    function delete_categories_with_links(PDO $pdo, int $business_id, array $root_ids): array
    {
        $root_ids = array_values(array_unique(array_filter(array_map('intval', $root_ids), function ($id) {
            return $id > 0;
        })));

        if (empty($root_ids)) {
            return ['ok' => false, 'message' => 'No valid category selected.'];
        }

        $pdo->beginTransaction();

        try {
            // Step 1: collect selected categories + direct child subcategories
            $all_category_ids = $root_ids;

            $root_placeholders = implode(',', array_fill(0, count($root_ids), '?'));

            $child_stmt = $pdo->prepare("
                SELECT id
                FROM categories
                WHERE parent_id IN ($root_placeholders)
                  AND business_id = ?
            ");
            $child_stmt->execute(array_merge($root_ids, [$business_id]));
            $child_ids = $child_stmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($child_ids)) {
                $all_category_ids = array_merge($all_category_ids, array_map('intval', $child_ids));
            }

            $all_category_ids = array_values(array_unique(array_filter($all_category_ids)));

            // Step 2: collect all products linked to selected categories / subcategories
            $product_ids = [];
            if (!empty($all_category_ids)) {
                $all_placeholders = implode(',', array_fill(0, count($all_category_ids), '?'));

                $product_stmt = $pdo->prepare("
                    SELECT id
                    FROM products
                    WHERE business_id = ?
                      AND (
                            category_id IN ($all_placeholders)
                            OR subcategory_id IN ($all_placeholders)
                          )
                ");
                $product_stmt->execute(array_merge([$business_id], $all_category_ids, $all_category_ids));
                $product_ids = $product_stmt->fetchAll(PDO::FETCH_COLUMN);
                $product_ids = array_values(array_unique(array_filter(array_map('intval', $product_ids))));
            }

            // Step 3: allow delete only if no linked product is used in invoice_items
            if (!empty($product_ids)) {
                $product_placeholders = implode(',', array_fill(0, count($product_ids), '?'));

                $invoice_check_stmt = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM invoice_items
                    WHERE product_id IN ($product_placeholders)
                ");
                $invoice_check_stmt->execute($product_ids);
                $invoice_item_count = (int)$invoice_check_stmt->fetchColumn();

                if ($invoice_item_count > 0) {
                    $pdo->rollBack();
                    return [
                        'ok' => false,
                        'message' => 'Cannot delete: one or more linked products are used in invoice items.'
                    ];
                }

                // Optional linked product tables
                try {
                    $stmt = $pdo->prepare("
                        DELETE FROM product_stocks
                        WHERE product_id IN ($product_placeholders)
                    ");
                    $stmt->execute($product_ids);
                } catch (Exception $e) {
                    // ignore if table / FK not present
                }

                try {
                    $stmt = $pdo->prepare("
                        DELETE FROM purchase_items
                        WHERE product_id IN ($product_placeholders)
                    ");
                    $stmt->execute($product_ids);
                } catch (Exception $e) {
                    // ignore if table / FK not present
                }

                try {
                    $stmt = $pdo->prepare("
                        DELETE FROM stock_movements
                        WHERE product_id IN ($product_placeholders)
                    ");
                    $stmt->execute($product_ids);
                } catch (Exception $e) {
                    // ignore if table / FK not present
                }

                try {
                    $stmt = $pdo->prepare("
                        DELETE FROM invoice_item_taxes
                        WHERE invoice_item_id IN (
                            SELECT id FROM invoice_items WHERE product_id IN ($product_placeholders)
                        )
                    ");
                    $stmt->execute($product_ids);
                } catch (Exception $e) {
                    // ignore if table / FK not present
                }

                // Delete products
                $delete_products = $pdo->prepare("
                    DELETE FROM products
                    WHERE id IN ($product_placeholders)
                      AND business_id = ?
                ");
                $delete_products->execute(array_merge($product_ids, [$business_id]));
            }

            // Step 4: collect and delete category images from upload folder
            if (!empty($all_category_ids)) {
                $img_placeholders = implode(',', array_fill(0, count($all_category_ids), '?'));
                try {
                    $img_stmt = $pdo->prepare("
                        SELECT category_image
                        FROM categories
                        WHERE id IN ($img_placeholders)
                          AND business_id = ?
                          AND category_image IS NOT NULL
                          AND category_image <> ''
                    ");
                    $img_stmt->execute(array_merge($all_category_ids, [$business_id]));
                    $image_paths = $img_stmt->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($image_paths as $image_path) {
                        delete_category_image_file($image_path);
                    }
                } catch (Exception $e) {
                    // ignore image cleanup errors
                }
            }

            // Step 4: delete child subcategories first
            if (!empty($all_category_ids)) {
                $all_placeholders = implode(',', array_fill(0, count($all_category_ids), '?'));

                $delete_children = $pdo->prepare("
                    DELETE FROM categories
                    WHERE id IN ($all_placeholders)
                      AND parent_id IS NOT NULL
                      AND business_id = ?
                ");
                $delete_children->execute(array_merge($all_category_ids, [$business_id]));
            }

            // Step 5: delete selected main categories
            $delete_main = $pdo->prepare("
                DELETE FROM categories
                WHERE id IN ($root_placeholders)
                  AND business_id = ?
            ");
            $delete_main->execute(array_merge($root_ids, [$business_id]));

            $pdo->commit();

            return [
                'ok' => true,
                'message' => count($root_ids) . " category(s), linked subcategories, and products deleted successfully!"
            ];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return [
                'ok' => false,
                'message' => 'Delete failed: ' . $e->getMessage()
            ];
        }
    }
}

/* -----------------------------
   Single toggle status
----------------------------- */
if (isset($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    try {
        $stmt = $pdo->prepare("
            UPDATE categories
            SET status = IF(status = 'active', 'inactive', 'active')
            WHERE id = ? AND business_id = ?
        ");
        $stmt->execute([$id, $current_business_id]);
        $success = "Category status updated!";
    } catch (Exception $e) {
        $error = "Error updating status.";
    }
}

/* -----------------------------
   Single delete
----------------------------- */
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $result = delete_categories_with_links($pdo, $current_business_id, [$id]);
    if ($result['ok']) {
        $success = $result['message'];
    } else {
        $error = $result['message'];
    }
}

/* -----------------------------
   Save category (add/update)
----------------------------- */
if (isset($_POST['save_category'])) {
    $id = !empty($_POST['id']) ? (int)$_POST['id'] : 0;
    $name = trim($_POST['category_name'] ?? '');
    $parent_id = (isset($_POST['parent_id']) && (int)$_POST['parent_id'] === 0) ? null : (int)($_POST['parent_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $remove_image = isset($_POST['remove_category_image']) ? 1 : 0;

    if ($name === '') {
        $error = "Category name is required.";
    } else {
        try {
            $uploadResult = category_image_upload('category_image', $current_business_id);
            if (!$uploadResult['ok']) {
                throw new Exception($uploadResult['message']);
            }
            $uploadedImagePath = $uploadResult['path'];

            if ($id > 0) {
                $old_stmt = $pdo->prepare("
                    SELECT category_image
                    FROM categories
                    WHERE id = ? AND business_id = ?
                    LIMIT 1
                ");
                $old_stmt->execute([$id, $current_business_id]);
                $old_category = $old_stmt->fetch(PDO::FETCH_ASSOC);
                $old_image = $old_category['category_image'] ?? '';

                if ($uploadedImagePath !== null) {
                    $stmt = $pdo->prepare("
                        UPDATE categories
                        SET category_name = ?, parent_id = ?, description = ?, category_image = ?
                        WHERE id = ? AND business_id = ?
                    ");
                    $stmt->execute([$name, $parent_id, $description, $uploadedImagePath, $id, $current_business_id]);
                    delete_category_image_file($old_image);
                } elseif ($remove_image) {
                    $stmt = $pdo->prepare("
                        UPDATE categories
                        SET category_name = ?, parent_id = ?, description = ?, category_image = NULL
                        WHERE id = ? AND business_id = ?
                    ");
                    $stmt->execute([$name, $parent_id, $description, $id, $current_business_id]);
                    delete_category_image_file($old_image);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE categories
                        SET category_name = ?, parent_id = ?, description = ?
                        WHERE id = ? AND business_id = ?
                    ");
                    $stmt->execute([$name, $parent_id, $description, $id, $current_business_id]);
                }

                $success = "Category updated successfully!";
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO categories (category_name, parent_id, description, category_image, created_by, business_id, status)
                    VALUES (?, ?, ?, ?, ?, ?, 'active')
                ");
                $stmt->execute([$name, $parent_id, $description, $uploadedImagePath, $_SESSION['user_id'], $current_business_id]);
                $success = "Category '$name' added successfully!";
            }
        } catch (PDOException $e) {
            if ($e->getCode() == '23000') {
                $error = "Category name already exists.";
            } else {
                $error = "Database error: " . $e->getMessage();
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

/* -----------------------------
   Bulk actions
----------------------------- */
if (isset($_POST['bulk_action'])) {
    $action = $_POST['bulk_action'] ?? '';
    $selected_ids = [];

    if (!empty($_POST['selected_ids']) && is_array($_POST['selected_ids'])) {
        $selected_ids = array_map('intval', $_POST['selected_ids']);
    } elseif (!empty($_POST['selected_ids_json'])) {
        $decoded = json_decode($_POST['selected_ids_json'], true);
        if (is_array($decoded)) {
            $selected_ids = array_map('intval', $decoded);
        }
    }

    $selected_ids = array_values(array_filter($selected_ids, function ($id) {
        return $id > 0;
    }));

    if (empty($selected_ids)) {
        $error = "Please select at least one category.";
    } else {
        $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));

        try {
            switch ($action) {
                case 'activate':
                    $stmt = $pdo->prepare("
                        UPDATE categories
                        SET status = 'active'
                        WHERE id IN ($placeholders) AND business_id = ?
                    ");
                    $stmt->execute(array_merge($selected_ids, [$current_business_id]));
                    $success = count($selected_ids) . " category(s) activated.";
                    break;

                case 'deactivate':
                    $stmt = $pdo->prepare("
                        UPDATE categories
                        SET status = 'inactive'
                        WHERE id IN ($placeholders) AND business_id = ?
                    ");
                    $stmt->execute(array_merge($selected_ids, [$current_business_id]));
                    $success = count($selected_ids) . " category(s) deactivated.";
                    break;

                case 'delete':
                    $result = delete_categories_with_links($pdo, $current_business_id, $selected_ids);
                    if ($result['ok']) {
                        $success = $result['message'];
                    } else {
                        $error = $result['message'];
                    }
                    break;

                default:
                    $error = "Please select a valid bulk action.";
                    break;
            }
        } catch (Exception $e) {
            if ($action === 'delete') {
                $error = "Bulk delete failed: " . $e->getMessage();
            } else {
                $error = "Bulk action failed.";
            }
        }
    }
}

/* -----------------------------
   Fetch categories
----------------------------- */
$categories_stmt = $pdo->prepare("
    SELECT c.*,
           p.category_name as parent_name,
           (
               SELECT COUNT(*)
               FROM products pr
               WHERE pr.category_id = c.id
                 AND pr.business_id = ?
           ) as product_count
    FROM categories c
    LEFT JOIN categories p ON c.parent_id = p.id
    WHERE c.business_id = ?
    ORDER BY COALESCE(c.parent_id, 0), c.category_name
");
$categories_stmt->execute([$current_business_id, $current_business_id]);
$categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

/* -----------------------------
   Fetch main categories
----------------------------- */
$main_cats_stmt = $pdo->prepare("
    SELECT id, category_name
    FROM categories
    WHERE parent_id IS NULL
      AND status = 'active'
      AND business_id = ?
    ORDER BY category_name
");
$main_cats_stmt->execute([$current_business_id]);
$main_cats = $main_cats_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<?php $page_title = "Product Categories"; ?>
<?php include('includes/head.php') ?>

<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include('includes/topbar.php') ?>

    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include('includes/sidebar.php') ?>
        </div>
    </div>

    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">

                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">

                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h4 class="card-title mb-0">
                                        <i class="bx bx-category me-2"></i> Product Categories
                                        <span class="badge bg-primary fs-6"><?= count($categories) ?></span>
                                    </h4>
                                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#categoryModal">
                                        <i class="bx bx-plus me-1"></i> Add Category
                                    </button>
                                </div>

                                <?php if ($success): ?>
                                    <div class="alert alert-success alert-dismissible fade show">
                                        <i class="bx bx-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                    </div>
                                <?php endif; ?>

                                <?php if ($error): ?>
                                    <div class="alert alert-danger alert-dismissible fade show">
                                        <i class="bx bx-error-circle me-2"></i><?= htmlspecialchars($error) ?>
                                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                    </div>
                                <?php endif; ?>

                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div></div>
                                    <form method="POST" class="d-flex gap-2" id="bulkActionForm">
                                        <input type="hidden" name="selected_ids_json" id="selectedIdsJson">

                                        <select name="bulk_action" class="form-select form-select-sm" style="width:auto;">
                                            <option value="">Bulk Actions</option>
                                            <option value="activate">Activate Selected</option>
                                            <option value="deactivate">Deactivate Selected</option>
                                            <option value="delete">Delete Selected</option>
                                        </select>

                                        <button type="submit" class="btn btn-sm btn-outline-primary">
                                            <i class="bx bx-play-circle me-1"></i> Apply
                                        </button>

                                        <button type="button" class="btn btn-sm btn-outline-danger" id="deleteSelectedBtn">
                                            <i class="bx bx-trash me-1"></i> Delete Selected
                                        </button>
                                    </form>
                                </div>

                                <div class="table-responsive">
                                    <table id="categoriesTable" class="table table-hover table-bordered align-middle w-100">
                                        <thead class="table-light">
                                        <tr>
                                            <th style="width:50px;">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="selectAll">
                                                </div>
                                            </th>
                                            <th class="text-center">Image</th>
                                            <th>Category Name</th>
                                            <th>Parent Category</th>
                                            <th class="text-center">Status</th>
                                            <th class="text-center">Products</th>
                                            <th>Description</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php if (count($categories) > 0): ?>
                                            <?php foreach ($categories as $cat): ?>
                                                <tr>
                                                    <td>
                                                        <div class="form-check">
                                                            <input class="form-check-input select-checkbox"
                                                                   type="checkbox"
                                                                   value="<?= (int)$cat['id'] ?>">
                                                        </div>
                                                    </td>

                                                    <td class="text-center">
                                                        <?php if (!empty($cat['category_image']) && file_exists($cat['category_image'])): ?>
                                                            <img src="<?= htmlspecialchars($cat['category_image']) ?>"
                                                                 alt="<?= htmlspecialchars($cat['category_name']) ?>"
                                                                 class="category-thumb">
                                                        <?php else: ?>
                                                            <span class="text-muted">—</span>
                                                        <?php endif; ?>
                                                    </td>

                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <?php if ($cat['parent_id']): ?>
                                                                <i class="bx bx-subdirectory-right text-muted me-2"></i>
                                                            <?php endif; ?>
                                                            <strong><?= htmlspecialchars($cat['category_name']) ?></strong>
                                                            <?php if ($cat['parent_id']): ?>
                                                                <span class="badge bg-info ms-2">Sub-category</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>

                                                    <td>
                                                        <?= $cat['parent_name'] ? htmlspecialchars($cat['parent_name']) : '<em class="text-muted">Main Category</em>' ?>
                                                    </td>

                                                    <td class="text-center">
                                                        <span class="badge bg-<?= ($cat['status'] ?? 'active') === 'active' ? 'success' : 'secondary' ?> rounded-pill">
                                                            <?= ucfirst($cat['status'] ?? 'active') ?>
                                                        </span>
                                                    </td>

                                                    <td class="text-center">
                                                        <span class="badge bg-<?= $cat['product_count'] > 0 ? 'success' : 'secondary' ?> rounded-pill">
                                                            <?= (int)$cat['product_count'] ?>
                                                        </span>
                                                    </td>

                                                    <td class="text-muted small">
                                                        <?php
                                                        $desc = $cat['description'] ?? '';
                                                        if (!empty($desc)) {
                                                            echo htmlspecialchars(mb_substr($desc, 0, 60));
                                                            echo (mb_strlen($desc) > 60) ? '...' : '';
                                                        } else {
                                                            echo '—';
                                                        }
                                                        ?>
                                                    </td>

                                                    <td class="text-center">
                                                        <div class="btn-group btn-group-sm">
                                                            <button class="btn btn-outline-warning"
                                                                    type="button"
                                                                    onclick="editCategory(<?= (int)$cat['id'] ?>, '<?= addslashes(htmlspecialchars($cat['category_name'])) ?>', <?= $cat['parent_id'] ?: '0' ?>, '<?= addslashes(htmlspecialchars($cat['description'] ?? '')) ?>', '<?= addslashes(htmlspecialchars($cat['category_image'] ?? '')) ?>')">
                                                                <i class="bx bx-edit"></i>
                                                            </button>

                                                            <a href="?toggle=<?= (int)$cat['id'] ?>" class="btn btn-outline-info toggle-status" title="Toggle Status">
                                                                <i class="bx <?= ($cat['status'] ?? 'active') === 'active' ? 'bx-hide' : 'bx-show' ?>"></i>
                                                            </a>

                                                            <a href="?delete=<?= (int)$cat['id'] ?>" class="btn btn-outline-danger delete-category">
                                                                <i class="bx bx-trash"></i>
                                                            </a>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <?php include('includes/footer.php') ?>
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="categoryModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" enctype="multipart/form-data" class="modal-content" id="categoryForm">
            <input type="hidden" name="id" id="editId">

            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">
                    <i class="bx bx-plus-circle"></i> Add Category
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label"><strong>Category Name <span class="text-danger">*</span></strong></label>
                    <input type="text" name="category_name" id="catName" class="form-control" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Parent Category</label>
                    <select name="parent_id" id="catParent" class="form-select">
                        <option value="0">None (Main Category)</option>
                        <?php foreach ($main_cats as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['category_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea name="description" id="catDesc" class="form-control" rows="3"></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label">Category Image</label>
                    <input type="file"
                           name="category_image"
                           id="catImage"
                           class="form-control"
                           accept="image/jpeg,image/jpg,image/png,image/webp">
                    <small class="text-muted">Allowed: JPG, JPEG, PNG, WEBP. Max 2MB.</small>
                </div>

                <div class="mb-3 d-none" id="currentImageBox">
                    <label class="form-label">Current Image</label>
                    <div class="d-flex align-items-center gap-3">
                        <img src="" alt="Current Category Image" id="currentCatImage" class="category-thumb-lg">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" name="remove_category_image" id="removeCategoryImage" value="1">
                            <label class="form-check-label" for="removeCategoryImage">Remove current image</label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="save_category" id="saveCategoryBtn" class="btn btn-primary">
                    <i class="bx bx-save me-2"></i> Save Category
                </button>
            </div>
        </form>
    </div>
</div>

<?php include('includes/rightbar.php') ?>
<?php include('includes/scripts.php') ?>

<script>
$(document).ready(function() {
    var table = $('#categoriesTable').DataTable({
        responsive: true,
        pageLength: 25,
        order: [[1, 'asc']],
        columnDefs: [
            { orderable: false, targets: [0, 1, 7] }
        ],
        language: {
            search: "Search categories:",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ categories",
            emptyTable: "No categories found",
            paginate: {
                previous: "<i class='bx bx-chevron-left'></i>",
                next: "<i class='bx bx-chevron-right'></i>"
            }
        }
    });

    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true,
        didOpen: (toast) => {
            toast.addEventListener('mouseenter', Swal.stopTimer);
            toast.addEventListener('mouseleave', Swal.resumeTimer);
        }
    });

    function getSelectedIds() {
        let ids = [];
        $('.select-checkbox:checked').each(function() {
            ids.push($(this).val());
        });
        return ids;
    }

    function updateSelectAllState() {
        let total = $('.select-checkbox').length;
        let checked = $('.select-checkbox:checked').length;

        if (checked === 0) {
            $('#selectAll').prop('checked', false).prop('indeterminate', false);
        } else if (checked === total) {
            $('#selectAll').prop('checked', true).prop('indeterminate', false);
        } else {
            $('#selectAll').prop('checked', false).prop('indeterminate', true);
        }
    }

    $('#selectAll').on('change', function() {
        $('.select-checkbox').prop('checked', this.checked);
        updateSelectAllState();
    });

    $(document).on('change', '.select-checkbox', function() {
        updateSelectAllState();
    });

    table.on('draw', function() {
        updateSelectAllState();
    });

    $('#bulkActionForm').on('submit', function(e) {
        const action = $('select[name="bulk_action"]').val();
        const selectedIds = getSelectedIds();
        const selectedCount = selectedIds.length;

        if (!action) {
            e.preventDefault();
            Toast.fire({
                icon: 'warning',
                title: 'Please select a bulk action'
            });
            return false;
        }

        if (selectedCount === 0) {
            e.preventDefault();
            Toast.fire({
                icon: 'warning',
                title: 'Please select at least one category'
            });
            return false;
        }

        $('#selectedIdsJson').val(JSON.stringify(selectedIds));

        if (action === 'delete') {
            e.preventDefault();

            Swal.fire({
                title: 'Are you sure?',
                text: `Deleting ${selectedCount} category(s) will also delete linked subcategories and products. Delete is allowed only when those products are not used in invoice items.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, delete them!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    $('#selectedIdsJson').val(JSON.stringify(selectedIds));
                    document.getElementById('bulkActionForm').submit();
                }
            });

            return false;
        }

        if (action === 'activate' || action === 'deactivate') {
            e.preventDefault();

            Swal.fire({
                title: 'Confirm Action',
                text: `You are about to ${action} ${selectedCount} category(s)`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, continue',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    $('#selectedIdsJson').val(JSON.stringify(selectedIds));
                    document.getElementById('bulkActionForm').submit();
                }
            });

            return false;
        }

        return true;
    });

    $('#deleteSelectedBtn').on('click', function() {
        $('select[name="bulk_action"]').val('delete');
        $('#bulkActionForm').trigger('submit');
    });

    $(document).on('click', '.delete-category', function(e) {
        e.preventDefault();
        let deleteUrl = $(this).attr('href');

        Swal.fire({
            title: 'Are you sure?',
            text: "Delete this category? Linked subcategories and products will also be deleted. Delete is allowed only when those products are not used in invoice items.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, delete it!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = deleteUrl;
            }
        });
    });

    $(document).on('click', '.toggle-status', function(e) {
        e.preventDefault();
        let toggleUrl = $(this).attr('href');

        Swal.fire({
            title: 'Toggle Status',
            text: "Are you sure you want to change this category's status?",
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, toggle it!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = toggleUrl;
            }
        });
    });

    <?php if ($success): ?>
    Toast.fire({
        icon: 'success',
        title: '<?= addslashes($success) ?>'
    });
    <?php endif; ?>

    <?php if ($error): ?>
    Toast.fire({
        icon: 'error',
        title: '<?= addslashes($error) ?>'
    });
    <?php endif; ?>
});

function editCategory(id, name, parent, desc, imagePath = '') {
    document.getElementById('modalTitle').innerHTML = '<i class="bx bx-edit"></i> Edit Category';
    document.getElementById('editId').value = id;
    document.getElementById('catName').value = name;
    document.getElementById('catParent').value = parent;
    document.getElementById('catDesc').value = desc;
    document.getElementById('catImage').value = '';
    document.getElementById('removeCategoryImage').checked = false;

    const currentImageBox = document.getElementById('currentImageBox');
    const currentCatImage = document.getElementById('currentCatImage');

    if (imagePath && imagePath.trim() !== '') {
        currentImageBox.classList.remove('d-none');
        currentCatImage.src = imagePath;
    } else {
        currentImageBox.classList.add('d-none');
        currentCatImage.src = '';
    }

    document.getElementById('saveCategoryBtn').innerHTML = '<i class="bx bx-save me-2"></i> Update Category';
    new bootstrap.Modal(document.getElementById('categoryModal')).show();
}

document.getElementById('categoryModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('modalTitle').innerHTML = '<i class="bx bx-plus-circle"></i> Add Category';
    document.querySelector('#categoryModal form').reset();
    document.getElementById('editId').value = '';
    document.getElementById('catImage').value = '';
    document.getElementById('removeCategoryImage').checked = false;
    document.getElementById('currentImageBox').classList.add('d-none');
    document.getElementById('currentCatImage').src = '';
    document.getElementById('saveCategoryBtn').innerHTML = '<i class="bx bx-save me-2"></i> Save Category';
});
</script>

<style>
.table th { font-weight: 600; background-color: #f8f9fa; }
.btn-group .btn { padding: 0.375rem 0.75rem; }

.category-thumb {
    width: 48px;
    height: 48px;
    object-fit: cover;
    border-radius: 8px;
    border: 1px solid #dee2e6;
    background: #fff;
    padding: 2px;
}
.category-thumb-lg {
    width: 90px;
    height: 90px;
    object-fit: cover;
    border-radius: 10px;
    border: 1px solid #dee2e6;
    background: #fff;
    padding: 3px;
}


.swal-toast-small {
    font-size: 0.875rem !important;
}
.swal-toast-small .swal2-popup {
    font-size: 0.875rem !important;
    padding: 0.5rem !important;
    width: auto !important;
    min-width: 250px !important;
}
.swal-toast-small .swal2-title {
    font-size: 1rem !important;
    margin: 0 !important;
    padding: 0 0 0.25rem 0 !important;
}
.swal-toast-small .swal2-html-container {
    font-size: 0.875rem !important;
    margin: 0 !important;
    padding: 0 !important;
}
.swal-toast-small .swal2-actions {
    margin: 0.25rem 0 0 0 !important;
}
.swal-toast-small .swal2-confirm,
.swal-toast-small .swal2-cancel {
    font-size: 0.75rem !important;
    padding: 0.25rem 0.5rem !important;
}
</style>
</body>
</html>