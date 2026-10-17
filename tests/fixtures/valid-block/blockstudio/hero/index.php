<?php
/** @var array<string, mixed> $a */

echo $a['title'];
echo $a['subtitle'];
echo $a['cta']['url'];
echo $a['background']['value'];
foreach ($a['items'] as $item) {
    echo $item['label'];
}
