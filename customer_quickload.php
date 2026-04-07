<?php
/**
 * customer_quickload.php
 * Returns full customer data as JSON for the quick-edit panel.
 */
require_once 'config/bootstrap.php';
requireAuth();
header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);
if (!$id) { echo json_encode(['success'=>false,'message'=>'No ID.']); exit; }

try {
    $pdo = db();

    $row = $pdo->prepare("SELECT * FROM customers WHERE id=?");
    $row->execute([$id]);
    $c = $row->fetch();
    if (!$c) { echo json_encode(['success'=>false,'message'=>'Customer not found.']); exit; }

    $s = $pdo->prepare("SELECT * FROM customer_contact_persons WHERE customer_id=? ORDER BY id");
    $s->execute([$id]); $persons = $s->fetchAll();

    $s = $pdo->prepare("SELECT * FROM customer_contact_addresses WHERE customer_id=? ORDER BY id");
    $s->execute([$id]); $addresses = $s->fetchAll();

    $s = $pdo->prepare("SELECT email FROM customer_emails WHERE customer_id=? ORDER BY id");
    $s->execute([$id]); $emails = array_column($s->fetchAll(), 'email');

    $s = $pdo->prepare("SELECT country_code, phone_number FROM customer_phones WHERE customer_id=? ORDER BY id");
    $s->execute([$id]); $phones = $s->fetchAll();

    // Also return default billing/shipping for invoice field update
    $defBillingPerson  = ''; $defShippingPerson  = '';
    $defBillingAddr    = null; $defShippingAddr  = null;
    foreach ($persons as $p) {
        if ($p['default_billing']  && !$defBillingPerson)  $defBillingPerson  = strtoupper(trim($p['first_name'].' '.$p['last_name']));
        if ($p['default_shipping'] && !$defShippingPerson) $defShippingPerson = strtoupper(trim($p['first_name'].' '.$p['last_name']));
    }
    foreach ($addresses as $a) {
        if ($a['default_billing']  && !$defBillingAddr)  $defBillingAddr  = $a;
        if ($a['default_shipping'] && !$defShippingAddr) $defShippingAddr = $a;
    }

    echo json_encode([
        'success'  => true,
        'customer' => $c,
        'persons'  => $persons,
        'addresses'=> $addresses,
        'emails'   => $emails,
        'phones'   => $phones,
        'default_billing_person'   => $defBillingPerson,
        'default_shipping_person'  => $defShippingPerson,
        'default_billing_address'  => $defBillingAddr,
        'default_shipping_address' => $defShippingAddr,
    ]);

} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
