<?php

use Model\PageBuilder\Renderer;

// Explicit page break between two siblings. No config, no common keys.
// Byte-identical to the JS render() preview output (render-parity invariant).
echo '<div class="pb-page-break" style="break-after:page"></div>';
