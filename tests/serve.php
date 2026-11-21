<?php
declare(strict_types=1);
// Test-only process group, so teardown terminates only this server and its workers.
if (PHP_SAPI!=='cli' || !function_exists('posix_setsid')) exit(1);
posix_setsid();
pcntl_exec(PHP_BINARY,['-S','127.0.0.1:'.$argv[1],'-t',$argv[2]]);
exit(1);
