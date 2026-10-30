<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/excel_export.php';

try {
    exportPosWorkbook($pdo);

    $filePath = getenv('LOCALAPPDATA')
        . DIRECTORY_SEPARATOR
        . 'PhoneElectronicsPOS'
        . DIRECTORY_SEPARATOR
        . 'POS_Business_Records.xlsx';

    if (!is_file($filePath)) {
        throw new RuntimeException(
            'The Excel file was not created. Check the PHP error log.'
        );
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="POS_Business_Records.xlsx"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: no-store');

    readfile($filePath);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo '<h2>Excel export failed</h2>';
    echo '<p>' . htmlspecialchars(
        $e->getMessage(),
        ENT_QUOTES,
        'UTF-8'
    ) . '</p>';
    echo '<p><a href="dashboard.php">Return to Dashboard</a></p>';
}
