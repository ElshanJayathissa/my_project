<?php

require_once __DIR__ . '/../includes/functions.php';
requireRole('collector');
$page_title = 'Scan QR Card';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row">
    <div class="col-md-8 mx-auto">
        <div class="card card-custom">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="bi bi-qr-code-scan me-2"></i>Scan QR Card</h5>
            </div>
            <div class="card-body">
                <div id="qr-reader" class="qr-scanner-container" style="width: 100%; min-height: 300px; background: #f8f9fa; border-radius: 8px; border: 2px dashed #dee2e6; display: flex; align-items: center; justify-content: center;">
                    <div class="text-center text-muted p-4">
                        <i class="bi bi-camera fs-1 d-block mb-2"></i>
                        <p>Camera will appear here</p>
                    </div>
                </div>
                <div id="scan-result" class="mt-3"></div>
                <div class="d-grid gap-2 mt-3">
                    <button id="scan-btn" class="btn btn-success btn-lg"><i class="bi bi-qr-code-scan me-2"></i>Start Scanning</button>
                    <button id="stop-btn" class="btn btn-outline-secondary d-none"><i class="bi bi-stop-circle me-2"></i>Stop Scanning</button>
                </div>
                <div class="mt-3 text-center">
                    <a href="search.php" class="text-muted"><i class="bi bi-search me-1"></i>Or search manually</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

