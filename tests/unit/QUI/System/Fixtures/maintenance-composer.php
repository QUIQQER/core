<?php

if (($argv[1] ?? '') === 'self-update') {
    exit(0);
}

echo json_encode(array_slice($argv, 1), JSON_THROW_ON_ERROR);
exit((int)getenv('QUIQQER_TEST_COMPOSER_STATUS'));
