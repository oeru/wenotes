// This is to be run from command line.
// e.g. `php scripts/jsonencode.php`
<?php

$design_doc = json_encode(file_get_contents('includes/design_ids.json'));

print_r($design_doc);

file_put_contents('includes/design_ids-encoded.json', $design_doc);

?>
