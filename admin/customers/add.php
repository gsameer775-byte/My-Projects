<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'customers', 'view');

$error = '';
$success = '';

$customer_id = (int)($_GET['id'] ?? $_POST['customer_id'] ?? 0);
$isEdit = $customer_id > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission($pdo, 'customers', 'edit');
    $name        = sanitize($_POST['name'] ?? '');
    $phone       = sanitize($_POST['phone'] ?? '');
    $email       = sanitize($_POST['email'] ?? '');
    $address     = sanitize($_POST['address'] ?? '');
    $city        = sanitize($_POST['city'] ?? '');
    $birthday    = $_POST['birthday'] !== '' ? $_POST['birthday'] : null;
    $anniversary = $_POST['anniversary'] !== '' ? $_POST['anniversary'] : null;
    $is_active   = isset($_POST['is_active']) ? 1 : 1; // default active

    if ($name === '' || $phone === '') {
        $error = 'Naam aur phone number zaroori hain.';
    } else {
        // Check duplicate phone (excluding self if editing)
        $dupSql = "SELECT COUNT(*) AS cnt FROM customers WHERE phone = :phone";
        $dupParams = ['phone' => $phone];
        if ($isEdit) {
            $dupSql .= " AND customer_id != :id";
            $dupParams['id'] = $customer_id;
        }
        $dup = $pdo->prepare($dupSql);
        $dup->execute($dupParams);

        if ($dup->fetch()['cnt'] > 0) {
            $error = 'Ye phone number already kisi aur customer ka registered hai.';
        } else {
            if ($isEdit) {
                $stmt = $pdo->prepare(
                    "UPDATE customers SET
                        name = :name, phone = :phone, email = :email, address = :address,
                        city = :city, birthday = :birthday, anniversary = :anniversary
                     WHERE customer_id = :id"
                );
                $stmt->execute([
                    'name' => $name, 'phone' => $phone, 'email' => $email ?: null,
                    'address' => $address ?: null, 'city' => $city ?: null,
                    'birthday' => $birthday, 'anniversary' => $anniversary,
                    'id' => $customer_id,
                ]);
                $success = 'Customer update ho gaya.';
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO customers (name, phone, email, address, city, birthday, anniversary)
                     VALUES (:name, :phone, :email, :address, :city, :birthday, :anniversary)"
                );
                $stmt->execute([
                    'name' => $name, 'phone' => $phone, 'email' => $email ?: null,
                    'address' => $address ?: null, 'city' => $city ?: null,
                    'birthday' => $birthday, 'anniversary' => $anniversary,
                ]);
                $customer_id = $pdo->lastInsertId();
                $isEdit = true;
                $success = 'Customer add ho gaya.';
            }
        }
    }
}

// Fetch current data for edit form
$customer = [
    'name' => '', 'phone' => '', 'email' => '', 'address' => '',
    'city' => '', 'birthday' => '', 'anniversary' => '',
];
if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE customer_id = :id");
    $stmt->execute(['id' => $customer_id]);
    $row = $stmt->fetch();
    if ($row) {
        $customer = $row;
    } else {
        redirect('admin/customers/list.php');
    }
}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1><?php echo $isEdit ? 'Edit Customer' : 'Add New Customer'; ?></h1>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo sanitize($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo sanitize($success); ?></div>
    <?php endif; ?>

    <div class="toolbar">
        <a href="list.php">Back to Customer List</a>
        <?php if ($isEdit): ?>
            <a href="profile.php?id=<?php echo (int)$customer_id; ?>">View Full Profile</a>
        <?php endif; ?>
    </div>

    <div class="card">
        <form method="POST" action="" class="form-grid">
            <?php if ($isEdit): ?>
                <input type="hidden" name="customer_id" value="<?php echo (int)$customer_id; ?>">
            <?php endif; ?>

            <div>
                <label>Name *</label>
                <input type="text" name="name" value="<?php echo sanitize($customer['name']); ?>" required>
            </div>
            <div>
                <label>Phone *</label>
                <input type="text" name="phone" value="<?php echo sanitize($customer['phone']); ?>" required>
            </div>
            <div>
                <label>Email</label>
                <input type="email" name="email" value="<?php echo sanitize($customer['email'] ?? ''); ?>">
            </div>
            <div>
                <label>City</label>
                <input type="text" name="city" value="<?php echo sanitize($customer['city'] ?? ''); ?>">
            </div>
            <div style="grid-column: span 2;">
                <label>Address</label>
                <input type="text" name="address" value="<?php echo sanitize($customer['address'] ?? ''); ?>">
            </div>
            <div>
                <label>Birthday</label>
                <input type="date" name="birthday" value="<?php echo sanitize($customer['birthday'] ?? ''); ?>">
            </div>
            <div>
                <label>Anniversary</label>
                <input type="date" name="anniversary" value="<?php echo sanitize($customer['anniversary'] ?? ''); ?>">
            </div>
            <div>
                <button type="submit"><?php echo $isEdit ? 'Update Customer' : 'Save Customer'; ?></button>
            </div>
        </form>
    </div>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>