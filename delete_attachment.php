<?php
/**
 * delete_attachment.php
 * Removes an attachment record and its file from disk.
 */
require_once 'config/bootstrap.php';
requireAuth();

$attId     = (int)($_GET['id']      ?? 0);
$invoiceId = (int)($_GET['invoice'] ?? 0);

if (!$attId || !$invoiceId) redirect('invoice.php');

try {
    $stmt = db()->prepare("SELECT * FROM invoice_attachments WHERE id = ? AND invoice_id = ?");
    $stmt->execute([$attId, $invoiceId]);
    $att = $stmt->fetch();

    if ($att) {
        // Delete physical file
        $filePath = APP_ROOT . '/storage/attachments/' . $att['stored_name'];
        if (file_exists($filePath)) unlink($filePath);

        // Delete DB record
        db()->prepare("DELETE FROM invoice_attachments WHERE id = ?")->execute([$attId]);

        auditLog('DELETE_ATTACHMENT', 'invoice_attachments', $attId, [
            'old' => ['file' => $att['original_name'], 'invoice_id' => $invoiceId],
        ]);

        flash('success', 'Attachment removed.');
    }
} catch (Exception $e) {
    flash('error', 'Could not remove attachment.');
}

redirect("invoice.php?action=edit&id=$invoiceId");
