<?php
/**
 * HumHub Integrity Check Hook
 * Replaces 50-validate-humhub.sh for Distroless / Pure PHP execution.
 */

declare(strict_types=1);

// Get the integrity check option (default to '1' if not set or empty)
$integrityCheck = getenv('HUMHUB_INTEGRITY_CHECK');
if ($integrityCheck === false || $integrityCheck === '') {
    $integrityCheck = '1';
}

if ($integrityCheck !== 'false') {
    echo "[hook 50-validate-humhub] Validating integrity...\n";

    // We leverage the runYii function already defined globally in entrypoint.php
    $exitCode = runYii(['integrity/run', '--interactive', '0']);

    if ($exitCode !== 0) {
        echo "[hook 50-validate-humhub] ERROR: Validation failed!\n";
        exit(1);
    }
} else {
    echo "[hook 50-humhub-validate] Validation skipped.\n";
}
