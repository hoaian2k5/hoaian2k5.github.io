<?php /* Admin layout closing */ ?>
</div><!-- /.adm-content -->
</main>

</div><!-- /.adm-layout -->

<script>
(function(){
    var btn = document.getElementById('admHam');
    var sb  = document.getElementById('admSidebar');
    if (btn && sb) {
        btn.addEventListener('click', function(){
            sb.classList.toggle('open');
        });
        document.addEventListener('click', function(e){
            if (window.innerWidth > 900) return;
            if (!sb.contains(e.target) && !btn.contains(e.target)) sb.classList.remove('open');
        });
    }
})();
</script>
<?php if (!empty($extraJs)): ?>
<script src="<?= $JS ?><?= htmlspecialchars($extraJs) ?>"></script>
<?php endif; ?>
<?php if (!empty($inlineJs)): ?>
<script><?= $inlineJs ?></script>
<?php endif; ?>
</body>
</html>
