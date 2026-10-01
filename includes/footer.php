                </div>
            </main>
        </div>
    </div>
    
    <div class="modal fade" id="qrCardModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content" id="qrCardModalContent">
                <div class="modal-header">
                    <h5 class="modal-title">QR Card</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0" id="qrCardModalBody">
                    <iframe id="qrCardIframe" src="" style="width:100%;height:600px;border:none;" sandbox=""></iframe>
                </div>
            </div>
        </div>
    </div>
    
    <style>
        .modal-backdrop.show {
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            background-color: rgba(0, 0, 0, 0.5);
        }
        #qrCardModal .modal-content {
            border-radius: 15px;
            border: none;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
    </style>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="<?php echo asset('js/app.js'); ?>"></script>
    <script>
        function openQrCardModal(token, print) {
            const modal = new bootstrap.Modal(document.getElementById('qrCardModal'));
            const iframe = document.getElementById('qrCardIframe');
            iframe.src = '<?php echo APP_URL; ?>/qr_view.php?token=' + encodeURIComponent(token) + (print ? '&print=1' : '');
            modal.show();
        }
        
        document.getElementById('qrCardModal').addEventListener('hidden.bs.modal', function () {
            document.getElementById('qrCardIframe').src = '';
        });
    </script>
    <?php if (isset($extra_js)) echo $extra_js; ?>
</body>
</html>
