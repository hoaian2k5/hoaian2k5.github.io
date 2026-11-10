<?php /* Lecturer layout closing */ ?>
</div><!-- /.lec-content -->
</main><!-- /.lec-main -->

</div><!-- /.lec-layout -->

<script>
(function () {
    'use strict';
    // Sidebar hamburger toggle
    var btn = document.getElementById('lecHamburger');
    var sb  = document.getElementById('lecSidebar');
    if (btn && sb) {
        btn.addEventListener('click', function () {
            sb.classList.toggle('open');
            btn.setAttribute('aria-expanded', sb.classList.contains('open'));
        });
        document.addEventListener('click', function (e) {
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
