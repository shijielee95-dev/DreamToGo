<?php
/**
 * customer_quicksave.php
 * Insert (new) or Update (edit) a customer from the invoice panel. Returns JSON.
 */
require_once 'config/bootstrap.php';
requireAuth();
header('Content-Type: application/json');

function qs(?string $v): string { return trim($v ?? ''); }
function qu(?string $v): string { return strtoupper(trim($v ?? '')); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success'=>false,'message'=>'Invalid request.']); exit;
}

$editId           = (int)($_POST['qa_customer_id'] ?? 0);
$customer_name    = qu($_POST['qa_legalname']        ?? '');
$other_name       = qs($_POST['qa_othername']        ?? '');
$id_type          = qs($_POST['qa_id_type']          ?? 'BRN');
$reg_no           = qu($_POST['qa_reg_no']           ?? '');
$old_reg_no       = qu($_POST['qa_old_reg_no']       ?? '');
$tin              = qu($_POST['qa_tin']              ?? '');
$sst_reg_no       = qu($_POST['qa_sst_reg_no']       ?? '');
$currency         = qs($_POST['qa_currency']              ?? 'MYR');
$einvoice_control = qs($_POST['qa_einvoice_control']      ?? 'individual');
$credit_limit_raw = qs($_POST['qa_credit_limit']          ?? '');
$credit_limit     = $credit_limit_raw !== '' ? (float)$credit_limit_raw : null;
$default_payment_mode = in_array($_POST['qa_default_payment_mode'] ?? '', ['cash','credit'])
    ? $_POST['qa_default_payment_mode'] : 'cash';
$remarks          = qs($_POST['qa_remarks']               ?? '');

$persons   = json_decode(qs($_POST['qa_persons_json']   ?? '[]'), true) ?: [];
$addresses = json_decode(qs($_POST['qa_addresses_json'] ?? '[]'), true) ?: [];
$emails    = json_decode(qs($_POST['qa_emails_json']    ?? '[]'), true) ?: [];
$phones    = json_decode(qs($_POST['qa_phones_json']    ?? '[]'), true) ?: [];

if ($customer_name === '') {
    echo json_encode(['success'=>false,'message'=>'Legal name is required.']); exit;
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    if ($editId > 0) {
        // ── UPDATE ──────────────────────────────────────────────
        $pdo->prepare("
            UPDATE customers SET
                customer_name=?, other_name=?, id_type=?, reg_no=?, old_reg_no=?,
                tin=?, sst_reg_no=?, currency=?, einvoice_control=?,
                credit_limit=?, default_payment_mode=?, remarks=?
            WHERE id=?
        ")->execute([
            $customer_name, $other_name, $id_type, $reg_no, $old_reg_no,
            $tin, $sst_reg_no, $currency, $einvoice_control,
            $credit_limit, $default_payment_mode, $remarks, $editId
        ]);
        $id = $editId;

        // Delete related records and re-insert
        $pdo->prepare("DELETE FROM customer_contact_persons   WHERE customer_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM customer_contact_addresses WHERE customer_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM customer_emails            WHERE customer_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM customer_phones            WHERE customer_id=?")->execute([$id]);

    } else {
        // ── INSERT ──────────────────────────────────────────────
        $pdo->prepare("
            INSERT INTO customers
                (customer_name, other_name, id_type, reg_no, old_reg_no, tin, sst_reg_no,
                 currency, einvoice_control, credit_limit, default_payment_mode, remarks)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $customer_name, $other_name, $id_type, $reg_no, $old_reg_no,
            $tin, $sst_reg_no, $currency, $einvoice_control, $credit_limit,
            $default_payment_mode, $remarks
        ]);
        $id = (int)$pdo->lastInsertId();
    }

    // Contact persons
    if (!empty($persons)) {
        $stmtP = $pdo->prepare("
            INSERT INTO customer_contact_persons
                (customer_id, first_name, last_name, default_billing, default_shipping)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($persons as $p) {
            $fn = qs($p['first_name'] ?? ''); $ln = qs($p['last_name'] ?? '');
            if ($fn !== '' || $ln !== '') {
                $stmtP->execute([$id, $fn, $ln,
                    !empty($p['default_billing'])  ? 1 : 0,
                    !empty($p['default_shipping']) ? 1 : 0,
                ]);
            }
        }
    }

    // Contact addresses
    if (!empty($addresses)) {
        $stmtA = $pdo->prepare("
            INSERT INTO customer_contact_addresses
                (customer_id, address_name, street_address, city, postcode, country, state,
                 default_billing, default_shipping)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($addresses as $a) {
            $an = qs($a['address_name'] ?? '');
            if ($an !== '') {
                $stmtA->execute([$id, $an,
                    qs($a['street_address'] ?? ''), qs($a['city'] ?? ''),
                    qs($a['postcode'] ?? ''),       qs($a['country'] ?? ''),
                    qs($a['state'] ?? ''),
                    !empty($a['default_billing'])  ? 1 : 0,
                    !empty($a['default_shipping']) ? 1 : 0,
                ]);
            }
        }
    }

    // Emails
    if (!empty($emails)) {
        $stmtE = $pdo->prepare("INSERT INTO customer_emails (customer_id, email) VALUES (?, ?)");
        foreach ($emails as $em) {
            $em = strtolower(trim($em));
            if ($em !== '') $stmtE->execute([$id, $em]);
        }
    }

    // Phones
    if (!empty($phones)) {
        $stmtPh = $pdo->prepare("INSERT INTO customer_phones (customer_id, country_code, phone_number) VALUES (?, ?, ?)");
        foreach ($phones as $ph) {
            $num = qs($ph['number'] ?? '');
            if ($num !== '') $stmtPh->execute([$id, qs($ph['country_code'] ?? '+60'), $num]);
        }
    }

    $pdo->commit();

    // Return enough data to refresh the invoice form
    // Compute default billing/shipping from the saved data
    $defBillingPerson = ''; $defShippingPerson = '';
    $defBillingAddr   = null; $defShippingAddr = null;
    foreach ($persons as $p) {
        $fn = qs($p['first_name'] ?? ''); $ln = qs($p['last_name'] ?? '');
        $name = strtoupper(trim($fn.' '.$ln));
        if (!empty($p['default_billing'])  && !$defBillingPerson)  $defBillingPerson  = $name;
        if (!empty($p['default_shipping']) && !$defShippingPerson) $defShippingPerson = $name;
    }
    foreach ($addresses as $a) {
        if (!empty($a['default_billing'])  && !$defBillingAddr)  $defBillingAddr  = $a;
        if (!empty($a['default_shipping']) && !$defShippingAddr) $defShippingAddr = $a;
    }

    echo json_encode([
        'success'                  => true,
        'id'                       => $id,
        'customer_name'            => $customer_name,
        'tin'                      => $tin,
        'reg_no'                   => $reg_no,
        'currency'                 => $currency,
        'default_payment_mode'     => $default_payment_mode,
        'email'                    => !empty($emails[0]) ? $emails[0] : '',
        'phone'                    => !empty($phones[0]['number']) ? $phones[0]['number'] : '',
        'address_line_0'           => '',
        'address_line_1'           => '',
        'city'                     => '',
        'postal_code'              => '',
        'default_billing_person'   => $defBillingPerson,
        'default_shipping_person'  => $defShippingPerson,
        'default_billing_address'  => $defBillingAddr,
        'default_shipping_address' => $defShippingAddr,
    ]);

} catch (Exception $e) {
    if (isset($pdo)) $pdo->rollBack();
    echo json_encode(['success'=>false,'message'=>'Save failed: '.$e->getMessage()]);
}
