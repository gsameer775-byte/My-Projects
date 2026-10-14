<?php
// Common sidebar navigation - included on every admin page
?>
<nav class="sidebar">
    <ul>
        <li><a href="<?php echo BASE_URL; ?>admin/index.php">Dashboard</a></li>
        <li><a href="<?php echo BASE_URL; ?>admin/staff/mark-attendance.php">Mark My Attendance</a></li>
        <li><a href="<?php echo BASE_URL; ?>admin/inventory/list.php">Inventory</a></li>
        <li><a href="<?php echo BASE_URL; ?>admin/sales/new-sale.php">Sales / POS</a></li>
        <li><a href="<?php echo BASE_URL; ?>admin/sales/finishing.php">Order Finishing</a></li>
        <li><a href="<?php echo BASE_URL; ?>admin/sales/enquiries.php">Enquiries</a></li>
        <li><a href="<?php echo BASE_URL; ?>admin/sales-orders/list.php">Bulk Orders</a></li>
        <li><a href="<?php echo BASE_URL; ?>admin/customers/list.php">Customers</a></li>
        <li class="nav-sub-parent">CRM
            <ul class="nav-sub">
                <li><a href="<?php echo BASE_URL; ?>admin/crm/loyalty-settings.php">Loyalty Program</a></li>
                <li><a href="<?php echo BASE_URL; ?>admin/crm/campaigns.php">Campaigns</a></li>
                <li><a href="<?php echo BASE_URL; ?>admin/crm/reminders.php">Reminders</a></li>
            </ul>
        </li>
        <li><a href="<?php echo BASE_URL; ?>admin/staff/list.php">Staff</a></li>
        <li><a href="<?php echo BASE_URL; ?>admin/vendors/list.php">Vendors</a></li>
        <li><a href="<?php echo BASE_URL; ?>admin/expenses/list.php">Expenses</a></li>
        <li><a href="<?php echo BASE_URL; ?>admin/reports/sales-report.php">Reports</a></li>
        <li><a href="<?php echo BASE_URL; ?>admin/settings/general.php">Settings</a></li>
    </ul>
    <div class="sidebar-footer">
        <?php if (!empty($_SESSION['staff_name'])): ?>
            <p class="sidebar-user"><?php echo htmlspecialchars($_SESSION['staff_name']); ?></p>
        <?php endif; ?>
        <a href="<?php echo BASE_URL; ?>admin/logout.php" class="sidebar-logout" onclick="return confirm('Logout karna hai?');">Logout</a>
    </div>
</nav>