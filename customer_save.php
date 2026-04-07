<?php
require_once 'config/bootstrap.php';

function s(?string $v): string { return trim($v ?? ''); }
function u(?string $v): string { return strtoupper(trim($v ?? '')); }

$id              = (int)($_POST['id'] ?? 0);
$customer_name   = u($_POST['cust_legalname']   ?? '');
$tin             = u($_POST['cust_tin']             ?? '');
$id_type         = s($_POST['id_type']         ?? '');
$reg_no          = u($_POST['cust_regNo']          ?? '');
$sst_reg_no      = u($_POST['cust_sstRegNo']      ?? '');
$email           = s($_POST['cust_email']           ?? '');
$phone           = s($_POST['cust_phone']           ?? '');
$address_line_0  = s($_POST['address_line_0']  ?? '');
$address_line_1  = s($_POST['address_line_1']  ?? '');
$city            = s($_POST['city']            ?? '');
$postal_code     = s($_POST['postal_code']     ?? '');
$state_code      = s($_POST['state_code']      ?? '');
$country_code    = s($_POST['country_code']    ?? 'MYS');
$remarks         = s($_POST['cust_remarks']         ?? '');
$other_name      = s($_POST['cust_othername']      ?? '');
$old_reg_no        = u($_POST['cust_oldRegNo']        ?? '');
$currency          = s($_POST['cust_currency']               ?? 'MYR');
$einvoice_control  = s($_POST['cust_einvoice_control']       ?? 'individual');
$credit_limit_raw  = trim($_POST['cust_credit_limit']        ?? '');
$credit_limit      = $credit_limit_raw !== '' ? (float)$credit_limit_raw : null;
$default_payment_mode = in_array($_POST['cust_default_payment_mode'] ?? '', ['cash','credit'])
    ? $_POST['cust_default_payment_mode'] : 'cash';
$payment_term_id_raw = (int)($_POST['cust_payment_term_id'] ?? 0);
$payment_term_id     = $payment_term_id_raw > 0 ? $payment_term_id_raw : null;

// Related data
$contact_persons_raw   = $_POST['contact_persons']   ?? [];
$contact_addresses_raw = $_POST['contact_addresses'] ?? [];

$emails_json = s($_POST['emails_json'] ?? '');
$emails = [];
if ($emails_json !== '') {
    $decoded = json_decode($emails_json, true);
    if (is_array($decoded)) $emails = $decoded;
}

$phones_json = s($_POST['phones_json'] ?? '');
$phones = [];
if ($phones_json !== '') {
    $decoded = json_decode($phones_json, true);
    if (is_array($decoded)) $phones = $decoded;
}

if ($customer_name === '') {
    header('Location: customer.php' . ($id ? "?action=edit&id=$id&error=name_required" : '?action=new&error=name_required'));
    exit;
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    if ($id > 0) {
        $pdo->prepare("
            UPDATE customers SET
                customer_name  = ?, tin = ?, id_type = ?, reg_no = ?,
                sst_reg_no = ?, email = ?, phone = ?,
                address_line_0 = ?, address_line_1 = ?,
                city = ?, postal_code = ?, state_code = ?, country_code = ?,
                other_name = ?, old_reg_no = ?, remarks = ?,
                currency = ?, einvoice_control = ?, credit_limit = ?,
                default_payment_mode = ?, payment_term_id = ?
            WHERE id = ?
        ")->execute([
            $customer_name, $tin, $id_type, $reg_no,
            $sst_reg_no, $email, $phone,
            $address_line_0, $address_line_1,
            $city, $postal_code, $state_code, $country_code,
            $other_name, $old_reg_no, $remarks,
            $currency, $einvoice_control, $credit_limit,
            $default_payment_mode, $payment_term_id,
            $id
        ]);

        $pdo->prepare("DELETE FROM customer_contact_persons   WHERE customer_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM customer_contact_addresses WHERE customer_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM customer_emails            WHERE customer_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM customer_phones            WHERE customer_id = ?")->execute([$id]);

    } else {
        $pdo->prepare("
            INSERT INTO customers
                (customer_name, tin, id_type, reg_no, sst_reg_no, email, phone,
                 address_line_0, address_line_1, city, postal_code, state_code, country_code,
                 other_name, old_reg_no, remarks, currency, einvoice_control, credit_limit,
                 default_payment_mode, payment_term_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $customer_name, $tin, $id_type, $reg_no,
            $sst_reg_no, $email, $phone,
            $address_line_0, $address_line_1,
            $city, $postal_code, $state_code, $country_code,
            $other_name, $old_reg_no, $remarks, $currency, $einvoice_control, $credit_limit,
            $default_payment_mode, $payment_term_id
        ]);
        $id = (int)$pdo->lastInsertId();
    }

    // Contact persons
    if (!empty($contact_persons_raw)) {
        $stmtP = $pdo->prepare("
            INSERT INTO customer_contact_persons (customer_id, first_name, last_name, default_billing, default_shipping)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($contact_persons_raw as $p) {
            $fn = s($p['first_name'] ?? ''); $ln = s($p['last_name'] ?? '');
            if ($fn !== '' || $ln !== '') {
                $pb = (!empty($p['default_billing'])  && $p['default_billing']  !== '0') ? 1 : 0;
                $ps = (!empty($p['default_shipping']) && $p['default_shipping'] !== '0') ? 1 : 0;
                $stmtP->execute([$id, $fn, $ln, $pb, $ps]);
            }
        }
    }

    // Contact addresses
    if (!empty($contact_addresses_raw)) {
        $stmtA = $pdo->prepare("
            INSERT INTO customer_contact_addresses
                (customer_id, address_name, street_address, city, postcode, country, state, default_billing, default_shipping)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($contact_addresses_raw as $a) {
            $an = s($a['address_name'] ?? ''); $sa = s($a['street_address'] ?? '');
            if ($an !== '' || $sa !== '') {
                $db  = (!empty($a['default_billing'])  && $a['default_billing']  !== '0') ? 1 : 0;
                $ds  = (!empty($a['default_shipping']) && $a['default_shipping'] !== '0') ? 1 : 0;
                $stmtA->execute([$id, $an, $sa, s($a['city'] ?? ''), s($a['postcode'] ?? ''), s($a['country'] ?? ''), s($a['state'] ?? ''), $db, $ds]);
            }
        }
    }

    // Emails
    if (!empty($emails)) {
        $stmtE = $pdo->prepare("INSERT INTO customer_emails (customer_id, email) VALUES (?, ?)");
        foreach ($emails as $e) { $e = strtolower(trim($e)); if ($e !== '') $stmtE->execute([$id, $e]); }
    }

    // Phones
    if (!empty($phones)) {
        $stmtPh = $pdo->prepare("INSERT INTO customer_phones (customer_id, country_code, phone_number) VALUES (?, ?, ?)");
        foreach ($phones as $ph) {
            $num = s($ph['number'] ?? '');
            if ($num !== '') $stmtPh->execute([$id, s($ph['country_code'] ?? '+60'), $num]);
        }
    }

    // ── Attachments ───────────────────────────────────────────────
    if (!empty($_FILES['attachments']['name'][0])) {
        $storageDir = defined('APP_ROOT') ? APP_ROOT . '/storage/attachments/' : __DIR__ . '/storage/attachments/';
        if (!is_dir($storageDir)) mkdir($storageDir, 0755, true);
        $allowed = ['application/pdf','image/jpeg','image/png','image/gif',
                    'application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        $stmtAtt = $pdo->prepare("INSERT INTO customer_attachments (customer_id, original_name, stored_name, uploaded_by) VALUES (?,?,?,?)");
        foreach ($_FILES['attachments']['tmp_name'] as $k => $tmp) {
            if ($_FILES['attachments']['error'][$k] !== UPLOAD_ERR_OK) continue;
            $mime = mime_content_type($tmp);
            if (!in_array($mime, $allowed)) continue;
            if ($_FILES['attachments']['size'][$k] > 10 * 1024 * 1024) continue;
            $orig   = basename($_FILES['attachments']['name'][$k]);
            $stored = uniqid('catt_', true) . '.' . pathinfo($orig, PATHINFO_EXTENSION);
            if (move_uploaded_file($tmp, $storageDir . $stored)) {
                $uid = authUser()['id'] ?? null;
                $stmtAtt->execute([$id, $orig, $stored, $uid]);
            }
        }
    }

    // ── Delete soft-deleted attachments ──────────────────────────────
    $deletedIds = s($_POST['deleted_attachments'] ?? '');
    if ($deletedIds !== '') {
        $storageDir = defined('APP_ROOT') ? APP_ROOT . '/storage/attachments/' : __DIR__ . '/storage/attachments/';
        foreach (explode(',', $deletedIds) as $delId) {
            $delId = (int)trim($delId);
            if ($delId <= 0) continue;
            $rowD = $pdo->prepare("SELECT stored_name FROM customer_attachments WHERE id=? AND customer_id=?");
            $rowD->execute([$delId, $id]);
            $attRow = $rowD->fetch();
            if ($attRow) {
                $path = $storageDir . $attRow['stored_name'];
                if (file_exists($path)) @unlink($path);
                $pdo->prepare("DELETE FROM customer_attachments WHERE id=?")->execute([$delId]);
            }
        }
    }

    $pdo->commit();
    header("Location: customer.php?action=edit&id={$id}&saved=1");
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    header('Location: customer.php' . ($id ? "?action=edit&id=$id&error=save_failed" : '?action=new&error=save_failed'));
    exit;
}
