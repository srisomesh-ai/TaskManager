<?php
// Diagnostic: check what documents/payment info a task has.
// Usage: https://salmon-goldfish-110661.hostingersite.com/check_task_docs.php?task=ID-2026-0003&key=BGPS_CHECK_2026
// DELETE THIS FILE after you're done — it's only for debugging.

if (($_GET['key'] ?? '') !== 'BGPS_CHECK_2026') { http_response_code(403); die('Unauthorized'); }
require_once __DIR__ . '/api/db.php';
$pdo = getDB();

$taskRef = trim($_GET['task'] ?? '');
if ($taskRef === '') die('Add ?task=ID-2026-0003&key=BGPS_CHECK_2026');

// Find the task (by task_id string or numeric id)
$q = is_numeric($taskRef)
    ? $pdo->prepare("SELECT * FROM tasks WHERE id=?")
    : $pdo->prepare("SELECT * FROM tasks WHERE task_id=?");
$q->execute([$taskRef]);
$t = $q->fetch(PDO::FETCH_ASSOC);

header('Content-Type: text/plain');
if (!$t) { die("No task found for: $taskRef"); }

echo "TASK: {$t['task_id']} (db id {$t['id']})\n";
echo "Status: {$t['task_status']}\n";
echo "Job: {$t['device_details']}\n";
echo "Payment mode: {$t['payment_mode']}\n";
echo "Amount collected: {$t['amount_collected']}\n";
echo "Payment verify status: " . ($t['payment_verify_status'] ?? '(none)') . "\n";
echo "Cash deposit status: " . ($t['cash_deposit_status'] ?? '(none)') . "\n";
echo str_repeat('-', 40) . "\n";

// Self-heal ALL tasks: any document with a blank doc_type is a payment screenshot (only type app uploads)
$globalFix = $pdo->query("UPDATE task_documents SET doc_type='payment_screenshot' WHERE doc_type IS NULL OR doc_type=''");
$gfCount = $globalFix ? $globalFix->rowCount() : 0;
if ($gfCount > 0) echo "GLOBAL REPAIR: fixed {$gfCount} document(s) across all tasks with blank doc_type -> payment_screenshot\n";

// Documents
$d = $pdo->prepare("SELECT * FROM task_documents WHERE task_id=?");
$d->execute([$t['id']]);
$docs = $d->fetchAll(PDO::FETCH_ASSOC);
echo "DOCUMENTS: " . count($docs) . "\n";
foreach ($docs as $doc) {
    $path = __DIR__ . '/uploads/task_' . $t['id'] . '/' . $doc['filename'];
    $exists = file_exists($path) ? 'EXISTS on disk' : 'MISSING on disk';
    echo "  - type={$doc['doc_type']} | file={$doc['filename']} | orig={$doc['original_name']} | $exists\n";
    echo "    URL: https://salmon-goldfish-110661.hostingersite.com/uploads/task_{$t['id']}/{$doc['filename']}\n";
}
if (!count($docs)) {
    echo "  (No documents attached to this task — nothing for the admin panel to show.)\n";
    echo "  → This means no screenshot was uploaded, most likely the technician was on the OLD APK\n";
    echo "    where the file picker did not work, or the payment was recorded without a screenshot.\n";
}
