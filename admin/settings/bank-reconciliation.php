<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'settings', 'view');

// ============================================
// Settings - Bank Reconciliation
// - Upload a bank statement CSV (flexible column names supported - see
//   parseCsvRow() below)
// - Auto-match pass: for every unmatched bank row, look for an internal
//   record (sale / sales_order_payment / vendor_payment / expense /
//   credit_ledger repayment) with the same date + exact amount + correct
//   direction that isn't already linked to some other bank row. Exactly one
//   candidate -> auto-link. Zero or multiple candidates -> leave for manual
//   matching on bank-match.php.
// - Nothing here changes the underlying sales/expenses/etc. records - this
//   is a read-only cross-check layer on top of them.
// ============================================

define('BANK_TXN_TABLES', ['sale', 'sales_order_payment', 'vendor_payment', 'expense', 'credit_repayment']);

$error = '';
$success = '';

/**
 * Reads an uploaded CSV and returns rows as [date, description, amount, direction].
 * Supports two common bank-statement layouts:
 *  - Date, Description, Amount            (signed: negative = debit, positive = credit)
 *  - Date, Description, Debit, Credit     (two separate columns, one is blank per row)
 * Column names are matched loosely (case-insensitive, partial match) since
 * every bank exports slightly different headers.
 */
function parseBankCsv($filePath) {
    $rows = [];
    $fh = fopen($filePath, 'r');
    if (!$fh) return $rows;

    $header = fgetcsv($fh);
    if (!$header) { fclose($fh); return $rows; }

    $header = array_map(fn($h) => strtolower(trim($h)), $header);
    $findCol = function ($needles) use ($header) {
        foreach ($header as $i => $h) {
            foreach ($needles as $needle) {
                if (strpos($h, $needle) !== false) return $i;
            }
        }
        return null;
    };

    $dateCol = $findCol(['date']);
    $descCol = $findCol(['narration', 'description', 'particulars', 'remarks']);
    $debitCol = $findCol(['debit', 'withdrawal']);
    $creditCol = $findCol(['credit', 'deposit']);
    $amountCol = $findCol(['amount']);

    while (($line = fgetcsv($fh)) !== false) {
        if ($dateCol === null || !isset($line[$dateCol]) || trim($line[$dateCol]) === '') continue;

        $rawDate = trim($line[$dateCol]);
        $date = DateTime::createFromFormat('d/m/Y', $rawDate) ?: DateTime::createFromFormat('d-m-Y', $rawDate) ?: DateTime::createFromFormat('Y-m-d', $rawDate);
        if (!$date) continue;

        $description = $descCol !== null ? trim($line[$descCol] ?? '') : '';

        if ($debitCol !== null && $creditCol !== null) {
            $debit = (float)str_replace(',', '', $line[$debitCol] ?? '0');
            $credit = (float)str_replace(',', '', $line[$creditCol] ?? '0');
            if ($debit > 0) {
                $rows[] = [$date->format('Y-m-d'), $description, $debit, 'debit'];
            } elseif ($credit > 0) {
                $rows[] = [$date->format('Y-m-d'), $description, $credit, 'credit'];
            }
        } elseif ($amountCol !== null) {
            $amount = (float)str_replace(',', '', $line[$amountCol] ?? '0');
            if ($amount != 0) {
                $rows[] = [$date->format('Y-m-d'), $description, abs($amount), $amount < 0 ? 'debit' : 'credit'];
            }
        }
    }

    fclose($fh);
    return $rows;
}

/**
 * Returns unmatched internal candidate records for a given direction, as
 * [source, id, date, amount, label], excluding anything already linked to
 * some other bank_transactions row.
 */
function getCandidates($pdo, $direction, $date = null, $amount = null) {
    $already = $pdo->query(
        "SELECT matched_source, matched_id FROM bank_transactions WHERE match_status = 'matched'"
    )->fetchAll();
    $usedKeys = array_map(fn($r) => $r['matched_source'] . ':' . $r['matched_id'], $already);

    $candidates = [];

    if ($direction === 'credit') {
        foreach ($pdo->query("SELECT sale_id, total_amount, created_at, invoice_no FROM sales WHERE sale_status = 'completed'")->fetchAll() as $r) {
            if (in_array('sale:' . $r['sale_id'], $usedKeys, true)) continue;
            $candidates[] = ['source' => 'sale', 'id' => $r['sale_id'], 'date' => substr($r['created_at'], 0, 10), 'amount' => (float)$r['total_amount'], 'label' => 'Sale ' . $r['invoice_no']];
        }
        foreach ($pdo->query("SELECT payment_id, amount, paid_on, order_id FROM sales_order_payments")->fetchAll() as $r) {
            if (in_array('sales_order_payment:' . $r['payment_id'], $usedKeys, true)) continue;
            $candidates[] = ['source' => 'sales_order_payment', 'id' => $r['payment_id'], 'date' => $r['paid_on'], 'amount' => (float)$r['amount'], 'label' => 'Bulk Order Payment #' . $r['payment_id']];
        }
        foreach ($pdo->query("SELECT ledger_id, amount, created_at FROM credit_ledger WHERE entry_type = 'repayment'")->fetchAll() as $r) {
            if (in_array('credit_repayment:' . $r['ledger_id'], $usedKeys, true)) continue;
            $candidates[] = ['source' => 'credit_repayment', 'id' => $r['ledger_id'], 'date' => substr($r['created_at'], 0, 10), 'amount' => (float)$r['amount'], 'label' => 'Credit Repayment #' . $r['ledger_id']];
        }
    } else {
        foreach ($pdo->query("SELECT vendor_payment_id, amount, paid_on, vendor_id FROM vendor_payments")->fetchAll() as $r) {
            if (in_array('vendor_payment:' . $r['vendor_payment_id'], $usedKeys, true)) continue;
            $candidates[] = ['source' => 'vendor_payment', 'id' => $r['vendor_payment_id'], 'date' => $r['paid_on'], 'amount' => (float)$r['amount'], 'label' => 'Vendor Payment #' . $r['vendor_payment_id']];
        }
        foreach ($pdo->query("SELECT expense_id, amount, expense_date FROM expenses")->fetchAll() as $r) {
            if (in_array('expense:' . $r['expense_id'], $usedKeys, true)) continue;
            $candidates[] = ['source' => 'expense', 'id' => $r['expense_id'], 'date' => $r['expense_date'], 'amount' => (float)$r['amount'], 'label' => 'Expense #' . $r['expense_id']];
        }
    }

    if ($date !== null) {
        $candidates = array_filter($candidates, fn($c) => abs(strtotime($c['date']) - strtotime($date)) <= 7 * 86400);
    }
    if ($amount !== null) {
        $candidates = array_filter($candidates, fn($c) => abs($c['amount'] - $amount) < 0.01);
    }

    return array_values($candidates);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission($pdo, 'settings', 'edit');
    $action = $_POST['action'] ?? '';

    if ($action === 'upload_csv' && isset($_FILES['statement_csv'])) {
        if ($_FILES['statement_csv']['error'] !== UPLOAD_ERR_OK) {
            $error = 'File upload fail hui.';
        } else {
            $rows = parseBankCsv($_FILES['statement_csv']['tmp_name']);
            if (empty($rows)) {
                $error = 'CSV se koi valid row nahi mili. Column names check karo (Date, Description, Debit/Credit ya Amount honi chahiye).';
            } else {
                $insertStmt = $pdo->prepare(
                    "INSERT INTO bank_transactions (transaction_date, description, amount, direction, imported_by) VALUES (:d, :desc, :amt, :dir, :by)"
                );
                foreach ($rows as [$date, $desc, $amount, $direction]) {
                    $insertStmt->execute(['d' => $date, 'desc' => $desc, 'amt' => $amount, 'dir' => $direction, 'by' => $_SESSION['staff_id']]);
                }
                $success = count($rows) . ' transactions import ho gayi.';
            }
        }
    }

    if ($action === 'auto_match') {
        $unmatched = $pdo->query("SELECT * FROM bank_transactions WHERE match_status = 'unmatched'")->fetchAll();
        $matchedCount = 0;

        foreach ($unmatched as $txn) {
            $candidates = getCandidates($pdo, $txn['direction'], $txn['transaction_date'], $txn['amount']);
            if (count($candidates) === 1) {
                $c = $candidates[0];
                $pdo->prepare("UPDATE bank_transactions SET match_status = 'matched', matched_source = :src, matched_id = :id WHERE bank_txn_id = :txn")
                    ->execute(['src' => $c['source'], 'id' => $c['id'], 'txn' => $txn['bank_txn_id']]);
                $matchedCount++;
            }
        }
        $success = "$matchedCount transactions automatically match ho gayi.";
    }

    if ($action === 'ignore') {
        $pdo->prepare("UPDATE bank_transactions SET match_status = 'ignored' WHERE bank_txn_id = :id")
            ->execute(['id' => (int)($_POST['bank_txn_id'] ?? 0)]);
        $success = 'Transaction ignore kar diya.';
    }

    if ($action === 'unmatch') {
        $pdo->prepare("UPDATE bank_transactions SET match_status = 'unmatched', matched_source = NULL, matched_id = NULL WHERE bank_txn_id = :id")
            ->execute(['id' => (int)($_POST['bank_txn_id'] ?? 0)]);
        $success = 'Match hata diya.';
    }
}

$unmatchedRows = $pdo->query("SELECT * FROM bank_transactions WHERE match_status = 'unmatched' ORDER BY transaction_date DESC")->fetchAll();
$matchedRows = $pdo->query("SELECT * FROM bank_transactions WHERE match_status = 'matched' ORDER BY transaction_date DESC LIMIT 50")->fetchAll();
$ignoredCount = (int)$pdo->query("SELECT COUNT(*) FROM bank_transactions WHERE match_status = 'ignored'")->fetchColumn();

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>Settings - Bank Reconciliation</h1>

    <div class="toolbar">
        <a href="general.php">General</a>
        <a href="users.php">Users</a>
        <a href="audit-log.php">Audit Log</a>
        <a href="backups.php">Backups</a>
        <a href="sessions.php">Sessions</a>
        <a href="bank-reconciliation.php"><strong>Bank Reconciliation</strong></a>
    </div>

    <?php if ($error): ?><div class="alert alert-error"><?php echo sanitize($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo sanitize($success); ?></div><?php endif; ?>

    <div class="card">
        <h2>Upload Bank Statement (CSV)</h2>
        <p style="font-size:13px; color:#666; margin-bottom:10px;">
            Apne bank ki website se statement CSV format me download karo (Excel/PDF nahi, CSV chahiye). Columns me Date + Description + (Amount, ya Debit/Credit alag-alag) hone chahiye - naam thoda alag ho toh bhi chalega.
        </p>
        <form method="POST" action="" enctype="multipart/form-data" class="inline-form">
            <input type="hidden" name="action" value="upload_csv">
            <input type="file" name="statement_csv" accept=".csv" required>
            <button type="submit">Upload & Import</button>
        </form>
    </div>

    <div class="card">
        <h2>Unmatched (<?php echo count($unmatchedRows); ?>)</h2>
        <?php if (!empty($unmatchedRows)): ?>
            <form method="POST" action="" style="margin-bottom:12px;">
                <input type="hidden" name="action" value="auto_match">
                <button type="submit">Run Auto-Match</button>
            </form>
        <?php endif; ?>
        <table class="data-table">
            <thead><tr><th>Date</th><th>Description</th><th>Amount (₹)</th><th>Direction</th><th>Action</th></tr></thead>
            <tbody>
                <?php if (empty($unmatchedRows)): ?>
                    <tr><td colspan="5">Koi unmatched transaction nahi hai.</td></tr>
                <?php else: ?>
                    <?php foreach ($unmatchedRows as $u): ?>
                    <tr>
                        <td><?php echo sanitize($u['transaction_date']); ?></td>
                        <td><?php echo sanitize($u['description']); ?></td>
                        <td>₹<?php echo number_format($u['amount'], 2); ?></td>
                        <td><?php echo $u['direction'] === 'credit' ? 'In' : 'Out'; ?></td>
                        <td>
                            <a href="bank-match.php?id=<?php echo (int)$u['bank_txn_id']; ?>">Find Match</a>
                            <form method="POST" action="" style="display:inline;">
                                <input type="hidden" name="action" value="ignore">
                                <input type="hidden" name="bank_txn_id" value="<?php echo (int)$u['bank_txn_id']; ?>">
                                <button type="submit" style="background:#888;">Ignore</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Matched (recent 50)</h2>
        <table class="data-table">
            <thead><tr><th>Date</th><th>Description</th><th>Amount (₹)</th><th>Matched To</th><th>Action</th></tr></thead>
            <tbody>
                <?php if (empty($matchedRows)): ?>
                    <tr><td colspan="5">Koi matched transaction nahi hai.</td></tr>
                <?php else: ?>
                    <?php foreach ($matchedRows as $m): ?>
                    <tr>
                        <td><?php echo sanitize($m['transaction_date']); ?></td>
                        <td><?php echo sanitize($m['description']); ?></td>
                        <td>₹<?php echo number_format($m['amount'], 2); ?></td>
                        <td><?php echo sanitize(ucwords(str_replace('_', ' ', $m['matched_source']))) . ' #' . (int)$m['matched_id']; ?></td>
                        <td>
                            <form method="POST" action="" onsubmit="return confirm('Match hatana hai?');">
                                <input type="hidden" name="action" value="unmatch">
                                <input type="hidden" name="bank_txn_id" value="<?php echo (int)$m['bank_txn_id']; ?>">
                                <button type="submit" style="background:#888;">Unmatch</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <p style="font-size:12px; color:#888;">Ignored: <?php echo $ignoredCount; ?></p>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>