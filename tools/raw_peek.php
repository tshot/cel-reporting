<?php
/**
 * raw_peek.php — show the RAW bytes around a string in a CSV, with control
 * characters made visible, so you can see exactly how a field was escaped.
 *
 * Usage: php raw_peek.php /tmp/fpd.csv "Baby Menimal Handling" [context]
 */
$file   = $argv[1] ?? exit("Usage: php raw_peek.php <file.csv> <needle> [context]\n");
$needle = $argv[2] ?? exit("Need a search string\n");
$ctx    = (int)($argv[3] ?? 250);

$fh = fopen($file, 'rb') ?: exit("Cannot open\n");
$buf = ''; $offset = 0; $found = null;

while (!feof($fh)) {
    $buf .= fread($fh, 1 << 20);
    $pos = strpos($buf, $needle);
    if ($pos !== false) { $found = $offset + $pos; break; }
    // keep a tail in case the needle straddles a read boundary
    $keep = strlen($needle) + $ctx;
    if (strlen($buf) > $keep) {
        $offset += strlen($buf) - $keep;
        $buf = substr($buf, -$keep);
    }
}

if ($found === null) { fclose($fh); exit("Needle not found.\n"); }

$start = max(0, $found - $ctx);
fseek($fh, $start);
$chunk = fread($fh, $ctx * 2 + strlen($needle));
fclose($fh);

$vis = strtr($chunk, [
    "\r" => '[CR]',
    "\n" => "[LF]\n",
    '"'  => '«"»',          // make every quote character obvious
]);

echo "byte offset of needle : {$found}\n";
echo "quotes in this window : " . substr_count($chunk, '"') . "\n";
echo "newlines in window    : " . substr_count($chunk, "\n") . "\n";
echo str_repeat('-', 70) . "\n";
echo $vis . "\n";
echo str_repeat('-', 70) . "\n";
echo "Reading guide:\n";
echo "  «\"» marks a literal quote byte.\n";
echo "  A correctly escaped field looks like:  ,\"he said «\"»«\"»hello«\"»«\"» ok\",\n";
echo "  A BROKEN field looks like:             ,\"he said «\"»hello«\"» ok\",\n";
echo "  i.e. inner quotes must appear DOUBLED. Single inner quotes are the bug.\n";
