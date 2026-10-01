document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('qr-reader')) {
        initQRScanner();
    }
});

function initQRScanner() {
    const reader = new Html5Qrcode('qr-reader');
    const scanBtn = document.getElementById('scan-btn');
    const stopBtn = document.getElementById('stop-btn');
    const resultDiv = document.getElementById('scan-result');
    
    if (scanBtn) {
        scanBtn.addEventListener('click', function() {
            reader.start(
                { facingMode: "environment" },
                { fps: 10, qrbox: { width: 250, height: 250 } },
                function(decodedText) {
                    resultDiv.innerHTML = '<div class="alert alert-success">Scanned: ' + decodedText + '</div>';
                    setTimeout(function() {
                        window.location.href = 'payment.php?token=' + encodeURIComponent(decodedText);
                    }, 500);
                },
                function(error) {}
            ).catch(function(err) {
                resultDiv.innerHTML = '<div class="alert alert-warning">Camera access failed. Please use manual search.</div>';
            });
        });
    }
    
    if (stopBtn) {
        stopBtn.addEventListener('click', function() {
            reader.stop().then(function() {
                resultDiv.innerHTML = '';
            });
        });
    }
}

function showAlert(message, type) {
    const alertDiv = document.createElement('div');
    alertDiv.className = 'alert alert-' + (type || 'info') + ' alert-dismissible fade show';
    alertDiv.innerHTML = message + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    document.querySelector('.container-fluid .p-4').prepend(alertDiv);
    setTimeout(function() { alertDiv.remove(); }, 5000);
}

function handleFormSubmit(formId, callback) {
    const form = document.getElementById(formId);
    if (!form) return;
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(form);
        fetch(form.action, {
            method: 'POST',
            body: formData
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                showAlert(data.message || 'Success!', 'success');
                if (callback) callback(data);
            } else {
                showAlert(data.error || 'Error occurred', 'danger');
            }
        })
        .catch(function(error) {
            showAlert('Network error. Please try again.', 'danger');
        });
    });
}
