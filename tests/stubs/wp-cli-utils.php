<?php

namespace WP_CLI\Utils;

if (! function_exists('WP_CLI\\Utils\\make_progress_bar')) {
    function make_progress_bar($message, $count)
    {
        return new class {
            public function tick(): void {}

            public function finish(): void {}
        };
    }
}
