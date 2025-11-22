            </div>
        </div>
    </div>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- jQuery (for DataTables) -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    
    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    
    <!-- Base Path Configuration -->
    <script>
        <?php
        if (!function_exists('getBasePath')) {
            require_once __DIR__ . '/../../app/helpers.php';
        }
        $basePath = getBasePath();
        ?>
        window.APP_CONFIG = {
            basePath: '<?php echo $basePath; ?>',
            apiBase: '<?php echo $basePath; ?>/api',
            assetBase: '<?php echo $basePath; ?>/public/assets'
        };
    </script>
    
    <!-- API Helper -->
    <?php
    if (!function_exists('asset')) {
        require_once __DIR__ . '/../../app/helpers.php';
    }
    ?>
    <script src="<?php echo asset('js/api.js'); ?>"></script>
    
    <?php if (isset($adminScripts)): ?>
        <?php foreach ($adminScripts as $script): ?>
            <script src="<?php echo htmlspecialchars($script); ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>

